<?php

namespace App\Livewire;

use App\Models\ClientBuild;
use App\Models\ClientDownload;
use App\Models\ConsoleAudit;
use App\Models\EnrollmentKey;
use App\Models\Setting;
use App\Services\ClientBuilder\BuilderSettings;
use App\Services\ClientBuilder\GithubBuilder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Client Builder: build a branded RustDesk client (name, icon, logo, server,
 * optional enrollment key) on GitHub Actions and receive the installers in
 * Client Downloads — without leaving the console or exposing it publicly.
 */
class ClientBuilder extends Component
{
    use WithFileUploads;

    // ---- builder settings ----
    public bool $showSettings = false;

    public string $repo = '';

    public string $branch = 'master';

    /** Write-only: blank keeps the saved value. */
    public string $token = '';

    public string $zipPassword = '';

    public string $serverHost = '';

    public string $apiServer = '';

    public string $company = '';

    public ?string $accessResult = null;

    public bool $accessOk = false;

    // ---- build form ----
    public string $label = '';

    public string $appName = '';

    public string $exeName = '';

    public string $version = '1.5.0';

    public ?int $enrollmentKeyId = null;

    public $icon = null;

    public $logo = null;

    /** Image paths carried over by "build again" when no new file is picked. */
    public ?string $iconFrom = null;

    public ?string $logoFrom = null;

    public string $direction = 'both';

    public string $approveMode = 'password-click';

    public string $permanentPassword = '';

    public bool $hideCm = false;

    public bool $disableSettings = false;

    public bool $disableInstallation = false;

    public string $theme = 'system';

    public bool $removeUpdateNotice = true;

    public bool $publish = false;

    public function mount(): void
    {
        $this->authorizeAdmin();
        $this->loadSettings();
        $this->showSettings = BuilderSettings::problems() !== [];
    }

    // ---- settings -------------------------------------------------------------

    private function loadSettings(): void
    {
        $this->repo = BuilderSettings::repo();
        $this->branch = BuilderSettings::branch();
        $this->serverHost = (string) Setting::get(BuilderSettings::SERVER_HOST, '');
        $this->apiServer = (string) Setting::get(BuilderSettings::API_SERVER, '');
        $this->company = BuilderSettings::company();
        $this->token = '';
        $this->zipPassword = '';
    }

    public function saveSettings(): void
    {
        $this->authorizeAdmin();

        $this->validate([
            'repo' => ['required', 'regex:#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#'],
            'branch' => ['required', 'string', 'max:100'],
            'token' => ['nullable', 'string', 'max:255'],
            'zipPassword' => ['nullable', 'string', 'max:255'],
            'serverHost' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-\[\]:]+$/'],
            'apiServer' => ['nullable', 'url:http,https', 'max:255'],
            'company' => ['nullable', 'string', 'max:100', 'regex:/^[^\\\\|\'"$`\r\n]*$/'],
        ], [
            'repo.regex' => 'Use the form owner/name, e.g. Servicell2026/rdgen.',
            'company.regex' => 'The company name cannot contain \\ | \' " $ or `.',
        ]);

        Setting::put(BuilderSettings::REPO, trim($this->repo));
        Setting::put(BuilderSettings::BRANCH, trim($this->branch));
        Setting::put(BuilderSettings::SERVER_HOST, trim($this->serverHost));
        Setting::put(BuilderSettings::API_SERVER, rtrim(trim($this->apiServer), '/'));
        Setting::put(BuilderSettings::COMPANY, trim($this->company));
        if ($this->token !== '') {
            BuilderSettings::putSecret(BuilderSettings::TOKEN, trim($this->token));
        }
        if ($this->zipPassword !== '') {
            BuilderSettings::putSecret(BuilderSettings::ZIP_PASSWORD, $this->zipPassword);
        }

        ConsoleAudit::record('client_builder.settings', 'Updated Client Builder settings', 'setting', 'client_builder');

        $this->loadSettings();
        $this->testAccess();
    }

    public function testAccess(): void
    {
        $this->authorizeAdmin();

        $problem = BuilderSettings::problems();
        if ($problem !== []) {
            $this->accessOk = false;
            $this->accessResult = implode(' ', $problem);

            return;
        }

        $error = app(GithubBuilder::class)->checkAccess();
        $this->accessOk = $error === null;
        $this->accessResult = $error ?? 'Connected: the repository and its Windows workflow are ready.';
    }

    // ---- builds ---------------------------------------------------------------

    public function updatedEnrollmentKeyId(): void
    {
        if ($this->enrollmentKeyId) {
            $this->publish = false;
        }
    }

    public function build(): void
    {
        $this->authorizeAdmin();

        $this->validate([
            'label' => ['required', 'string', 'max:100'],
            // Interpolated into sed scripts and file names by the workflow.
            'appName' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9 ._-]*$/'],
            'exeName' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/'],
            'version' => ['required', 'string', 'max:20', 'regex:/^(master|\d+\.\d+\.\d+)$/'],
            'enrollmentKeyId' => ['nullable', 'integer', 'exists:enrollment_keys,id'],
            'icon' => ['nullable', 'image', 'mimes:png', 'max:2048', 'dimensions:min_width=128,min_height=128'],
            'logo' => ['nullable', 'image', 'mimes:png', 'max:2048'],
            'direction' => ['required', 'in:both,incoming,outgoing'],
            'approveMode' => ['required', 'in:password,click,password-click'],
            'permanentPassword' => ['nullable', 'string', 'min:8', 'max:64'],
            'theme' => ['required', 'in:system,light,dark'],
        ], [
            'appName.regex' => 'Letters, digits, spaces, dots, dashes and underscores only.',
            'exeName.regex' => 'Letters, digits, dashes and underscores only (no spaces).',
            'version.regex' => 'A RustDesk release such as 1.5.0, or "master" for nightly.',
            'icon.dimensions' => 'Use a square PNG of at least 256×256 (128×128 minimum).',
        ]);

        if (BuilderSettings::problems() !== []) {
            $this->showSettings = true;
            $this->addError('label', 'Finish the builder settings first.');

            return;
        }

        $key = $this->enrollmentKeyId ? EnrollmentKey::query()->find($this->enrollmentKeyId) : null;
        if ($key && $key->refusal() !== null) {
            $this->addError('enrollmentKeyId', 'This key cannot enroll devices ('.$key->refusal().').');

            return;
        }

        $build = ClientBuild::create([
            'label' => trim($this->label),
            'platform' => 'windows',
            'rustdesk_version' => $this->version,
            'app_name' => trim($this->appName),
            'exe_name' => trim($this->exeName),
            'enrollment_key_id' => $key?->id,
            'options' => [
                'direction' => $this->direction,
                'approve_mode' => $this->approveMode,
                'permanent_password' => $this->permanentPassword,
                'hide_cm' => $this->hideCm,
                'disable_settings' => $this->disableSettings,
                'disable_installation' => $this->disableInstallation,
                'theme' => $this->theme,
                'remove_update_notice' => $this->removeUpdateNotice,
            ],
            'publish' => $this->publish && $key === null,
            'status' => ClientBuild::STATUS_QUEUED,
            'message' => 'Starting…',
            'created_by' => auth()->id(),
        ]);

        $build->update([
            'icon_path' => $this->storeImage($build, 'icon', $this->icon, $this->iconFrom),
            'logo_path' => $this->storeImage($build, 'logo', $this->logo, $this->logoFrom),
        ]);

        ConsoleAudit::record('client_build.start', 'Started client build #'.$build->id.' ('.$build->label.')', 'client_build', (string) $build->id);

        app(GithubBuilder::class)->start($build);

        $this->reset('icon', 'logo', 'iconFrom', 'logoFrom', 'permanentPassword');
    }

    public function buildAgain(int $id): void
    {
        $this->authorizeAdmin();

        $build = ClientBuild::query()->findOrFail($id);
        $o = $build->options ?? [];

        $this->label = $build->label;
        $this->appName = $build->app_name;
        $this->exeName = $build->exe_name;
        $this->version = $build->rustdesk_version;
        $this->enrollmentKeyId = $build->enrollment_key_id;
        $this->direction = $o['direction'] ?? 'both';
        $this->approveMode = $o['approve_mode'] ?? 'password-click';
        $this->permanentPassword = (string) ($o['permanent_password'] ?? '');
        $this->hideCm = (bool) ($o['hide_cm'] ?? false);
        $this->disableSettings = (bool) ($o['disable_settings'] ?? false);
        $this->disableInstallation = (bool) ($o['disable_installation'] ?? false);
        $this->theme = $o['theme'] ?? 'system';
        $this->removeUpdateNotice = (bool) ($o['remove_update_notice'] ?? true);
        $this->publish = $build->publish;
        $this->iconFrom = $build->icon_path;
        $this->logoFrom = $build->logo_path;
        $this->reset('icon', 'logo');
        $this->resetValidation();

        $this->dispatch('rd-scroll-top');
    }

    public function cancel(int $id): void
    {
        $this->authorizeAdmin();

        $build = ClientBuild::query()->findOrFail($id);
        if ($build->isActive()) {
            app(GithubBuilder::class)->cancel($build);
            ConsoleAudit::record('client_build.cancel', 'Cancelled client build #'.$build->id, 'client_build', (string) $build->id);
        }
    }

    public function deleteBuild(int $id): void
    {
        $this->authorizeAdmin();

        $build = ClientBuild::query()->findOrFail($id);
        if ($build->isActive()) {
            app(GithubBuilder::class)->cancel($build);
        }
        app(GithubBuilder::class)->deleteRelease($build);

        foreach ([$build->icon_path, $build->logo_path] as $path) {
            if ($path && ! ClientBuild::query()->whereKeyNot($build->id)
                ->where(fn ($q) => $q->where('icon_path', $path)->orWhere('logo_path', $path))->exists()) {
                Storage::disk(ClientDownload::DISK)->delete($path);
            }
        }
        $build->delete();
    }

    /** Called by wire:poll while a build is in flight. */
    public function refreshBuilds(): void
    {
        $this->authorizeAdmin();

        $builder = app(GithubBuilder::class);
        ClientBuild::query()
            ->whereIn('status', [ClientBuild::STATUS_RUNNING, ClientBuild::STATUS_QUEUED])
            ->where('updated_at', '<', now()->subSeconds(20))
            ->get()
            ->each(fn (ClientBuild $b) => $builder->poll($b));
    }

    private function storeImage(ClientBuild $build, string $kind, $upload, ?string $from): ?string
    {
        $disk = Storage::disk(ClientDownload::DISK);

        if ($upload) {
            $path = 'builder/'.$build->id.'-'.$kind.'-'.Str::lower(Str::random(6)).'.png';
            $disk->put($path, file_get_contents($upload->getRealPath()));

            return $path;
        }

        return $from && $disk->exists($from) ? $from : null;
    }

    private function authorizeAdmin(): void
    {
        abort_unless((bool) auth()->user()?->is_admin, 403);
    }

    public function render()
    {
        $builds = ClientBuild::query()->with('enrollmentKey.user')->orderByDesc('id')->limit(30)->get();

        return view('livewire.client-builder', [
            'builds' => $builds,
            'anyActive' => $builds->contains(fn (ClientBuild $b) => $b->isActive()),
            'keys' => EnrollmentKey::query()->with('user')->whereNull('revoked_at')->orderBy('name')->get(),
            'problems' => BuilderSettings::problems(),
            'hasToken' => BuilderSettings::hasToken(),
            'hasZipPassword' => BuilderSettings::hasZipPassword(),
            'effectiveHost' => BuilderSettings::serverHost(),
            'effectiveApi' => BuilderSettings::apiServer(),
            'publicKey' => BuilderSettings::publicKey(),
        ]);
    }
}
