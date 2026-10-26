<?php

namespace App\Services\ClientBuilder;

use App\Models\ClientBuild;
use App\Models\ClientDownload;
use App\Models\ConsoleAudit;
use App\Models\EnrollmentKey;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Drives the rdgen fork on GitHub Actions in "release mode".
 *
 * The console is usually unreachable from the internet, so instead of serving
 * its inputs over HTTP the way rdgen's own web app does, it:
 *
 *   1. creates a draft release on the fork and attaches secrets.zip (the
 *      settings, AES-encrypted with the shared ZIP_PASSWORD), icon.png and
 *      logo.png;
 *   2. dispatches generator-windows.yml with {"release_id": …};
 *   3. polls the run; on success downloads the .exe/.msi the workflow
 *      attached to the same release into Client Downloads;
 *   4. deletes the draft release either way.
 *
 * Only outbound HTTPS to api.github.com / uploads.github.com is needed.
 */
class GithubBuilder
{
    private const API = 'https://api.github.com';

    private const UPLOADS = 'https://uploads.github.com';

    private const API_VERSION = '2026-03-10';

    /** Give up on a run that has not finished after this long. */
    private const TIMEOUT_HOURS = 6;

    // ---- dispatch -----------------------------------------------------------

    /** Start a queued build. Never throws: failures are recorded on the row. */
    public function start(ClientBuild $build): void
    {
        try {
            $problems = BuilderSettings::problems();
            if ($problems !== []) {
                throw new RuntimeException(implode(' ', $problems));
            }

            $release = $this->createDraftRelease($build);
            $build->update(['github_release_id' => $release['id']]);

            $this->uploadAsset($release['id'], 'secrets.zip', $this->secretsZip($build, $release['id']), 'application/zip');

            if ($build->icon_path) {
                $this->uploadAsset($release['id'], 'icon.png', $this->disk()->get($build->icon_path), 'image/png');
            }
            if ($build->logo_path) {
                $this->uploadAsset($release['id'], 'logo.png', $this->disk()->get($build->logo_path), 'image/png');
            }

            $run = $this->dispatch($build, $release['id']);

            $build->update([
                'status' => ClientBuild::STATUS_RUNNING,
                'github_run_id' => $run['id'] ?? null,
                'github_run_url' => $run['url'] ?? null,
                'started_at' => now(),
                'message' => 'Building on GitHub Actions (usually 30–60 minutes).',
            ]);
        } catch (Throwable $e) {
            $this->fail($build, 'Could not start the build: '.$e->getMessage());
        }
    }

    /** @return array{id: int} */
    private function createDraftRelease(ClientBuild $build): array
    {
        $response = $this->github()->post($this->repoPath('/releases'), [
            'tag_name' => 'cortendesk-build-'.$build->id.'-'.Str::lower(Str::random(6)),
            'target_commitish' => BuilderSettings::branch(),
            'name' => 'CortenDesk build #'.$build->id.' ('.$build->label.')',
            'body' => 'Temporary inputs and outputs of a client build started from CortenDesk. Deleted automatically.',
            'draft' => true,
        ]);
        $this->ensureOk($response, 'create the draft release');

        return ['id' => (int) $response->json('id')];
    }

    private function uploadAsset(int $releaseId, string $name, string $bytes, string $type): void
    {
        $response = $this->github()
            ->withBody($bytes, $type)
            ->post(self::UPLOADS.$this->repoPath('/releases/'.$releaseId.'/assets').'?name='.rawurlencode($name));
        $this->ensureOk($response, 'upload '.$name);
    }

    /** @return array{id: ?int, url: ?string} */
    private function dispatch(ClientBuild $build, int $releaseId): array
    {
        $since = now()->subSeconds(5);

        $response = $this->github()->post(
            $this->repoPath('/actions/workflows/'.BuilderSettings::WORKFLOW_WINDOWS.'/dispatches'),
            [
                'ref' => BuilderSettings::branch(),
                'inputs' => [
                    'version' => $build->rustdesk_version,
                    'zip_url' => json_encode(['release_id' => $releaseId]),
                    'app_version' => '',
                ],
                'return_run_details' => true,
            ],
        );
        $this->ensureOk($response, 'start the workflow');

        $runId = $response->json('workflow_run_id');
        if ($runId) {
            return ['id' => (int) $runId, 'url' => $response->json('html_url')];
        }

        // Older API behaviour (204, no body): find the run we just started.
        for ($i = 0; $i < 6; $i++) {
            sleep(5);
            $runs = $this->github()->get(
                $this->repoPath('/actions/workflows/'.BuilderSettings::WORKFLOW_WINDOWS.'/runs'),
                ['event' => 'workflow_dispatch', 'branch' => BuilderSettings::branch(), 'per_page' => 5],
            )->json('workflow_runs') ?? [];
            foreach ($runs as $run) {
                if (strtotime((string) $run['created_at']) >= $since->getTimestamp()) {
                    return ['id' => (int) $run['id'], 'url' => $run['html_url'] ?? null];
                }
            }
        }

        return ['id' => null, 'url' => null];
    }

    // ---- settings zip -------------------------------------------------------

    /**
     * The inputs rdgen's Windows workflow reads, as an AES-256 zip holding
     * secrets.json (what pyzipper in the workflow's decrypt step expects).
     */
    public function secretsZip(ClientBuild $build, int $releaseId): string
    {
        $json = json_encode($this->workflowInputs($build, $releaseId), JSON_UNESCAPED_SLASHES);

        return AesZip::single('secrets.json', $json, BuilderSettings::zipPassword());
    }

    /**
     * Mirrors rdgen's generate_custom_client() for the options the console
     * exposes. Every value is a string: the workflow writes them to GITHUB_ENV.
     *
     * @return array<string, string>
     */
    public function workflowInputs(ClientBuild $build, int $releaseId): array
    {
        $o = $build->options ?? [];

        return [
            'server' => BuilderSettings::serverHost(),
            'serverPort' => (string) BuilderSettings::serverPort(),
            'key' => BuilderSettings::publicKey(),
            'apiServer' => BuilderSettings::apiServer(),
            'custom' => base64_encode(json_encode($this->customConfig($build), JSON_UNESCAPED_SLASHES)),
            'uuid' => (string) Str::uuid(),
            'iconlink_url' => $build->icon_path ? 'release' : 'false',
            'iconlink_uuid' => 'false',
            'iconlink_file' => 'false',
            'logolink_url' => $build->logo_path ? 'release' : 'false',
            'logolink_uuid' => 'false',
            'logolink_file' => 'false',
            'privacylink_url' => 'false',
            'privacylink_uuid' => 'false',
            'privacylink_file' => 'false',
            'appname' => $build->app_name,
            'genurl' => '',
            'urlLink' => (string) ($o['url_link'] ?? '') ?: 'https://rustdesk.com',
            'downloadLink' => (string) ($o['download_link'] ?? '') ?: 'https://rustdesk.com/download',
            'delayFix' => 'true',
            'rdgen' => 'release',
            'release_id' => (string) $releaseId,
            'xOffline' => 'false',
            'removeNewVersionNotif' => ! empty($o['remove_update_notice']) ? 'true' : 'false',
            'update_public_key' => '',
            'compname' => str_replace('&', '\\&', BuilderSettings::company() ?: 'Purslane Ltd'),
            'androidappid' => 'com.carriez.flutter_hbb',
            'filename' => $build->exe_name,
            'token' => '',
        ];
    }

    /** The custom.txt the client reads at start (rdgen's decodedCustom). */
    public function customConfig(ClientBuild $build): array
    {
        $o = $build->options ?? [];
        $yn = fn ($v) => $v ? 'Y' : 'N';

        $custom = [];
        $direction = (string) ($o['direction'] ?? 'both');
        if (in_array($direction, ['incoming', 'outgoing'], true)) {
            $custom['conn-type'] = $direction;
        }
        if (! empty($o['disable_installation'])) {
            $custom['disable-installation'] = 'Y';
        }
        if (! empty($o['disable_settings'])) {
            $custom['disable-settings'] = 'Y';
        }
        if (strcasecmp($build->app_name, 'rustdesk') !== 0) {
            $custom['app-name'] = $build->app_name;
        }
        if (($o['permanent_password'] ?? '') !== '') {
            $custom['password'] = (string) $o['permanent_password'];
        }
        $custom['enable-lan-discovery'] = $yn(empty($o['deny_lan']));
        $custom['allow-auto-disconnect'] = $yn(! empty($o['auto_close']));

        $hideCm = ! empty($o['hide_cm']);
        $defaults = [
            'access-mode' => 'custom',
            'enable-keyboard' => 'Y',
            'enable-clipboard' => 'Y',
            'enable-file-transfer' => 'Y',
            'enable-audio' => 'Y',
            'enable-tunnel' => 'Y',
            'enable-remote-restart' => 'Y',
            'enable-record-session' => 'Y',
            'enable-block-input' => 'Y',
            'allow-remote-config-modification' => $yn(! empty($o['remote_config'])),
            'direct-server' => 'N',
            'verification-method' => $hideCm ? 'use-permanent-password' : 'use-both-passwords',
            'approve-mode' => in_array($o['approve_mode'] ?? '', ['password', 'click', 'password-click'], true)
                ? $o['approve_mode'] : 'password-click',
            'allow-hide-cm' => $yn($hideCm),
            'allow-remove-wallpaper' => 'Y',
            'enable-remote-printer' => 'Y',
            'enable-camera' => 'Y',
            'enable-terminal' => 'Y',
        ];
        $theme = (string) ($o['theme'] ?? 'system');
        if (in_array($theme, ['light', 'dark'], true)) {
            $defaults['theme'] = $theme;
        }

        $overrides = [];
        if ($build->enrollmentKey !== null) {
            // A built-in option: the stock client sends it with every sysinfo,
            // and KeyEnrollment turns it into the device's owner.
            $overrides[EnrollmentKey::CLIENT_OPTION] = $build->enrollmentKey->plaintext();
        }

        $custom['default-settings'] = $defaults;
        $custom['override-settings'] = $overrides === [] ? (object) [] : $overrides;

        return $custom;
    }

    // ---- polling ------------------------------------------------------------

    /** Advance one running build. Never throws. */
    public function poll(ClientBuild $build): void
    {
        try {
            if ($build->status === ClientBuild::STATUS_QUEUED) {
                $this->start($build);

                return;
            }

            if ($build->started_at && $build->started_at->lt(now()->subHours(self::TIMEOUT_HOURS))) {
                $this->fail($build, 'The build did not finish within '.self::TIMEOUT_HOURS.' hours.');

                return;
            }

            if (! $build->github_run_id) {
                $this->fail($build, 'GitHub did not report which run belongs to this build. Check the Actions tab of the repository.');

                return;
            }

            $run = $this->github()->get($this->repoPath('/actions/runs/'.$build->github_run_id));
            $this->ensureOk($run, 'read the workflow run');

            if ($run->json('html_url') && ! $build->github_run_url) {
                $build->update(['github_run_url' => $run->json('html_url')]);
            }

            if ($run->json('status') !== 'completed') {
                return;
            }

            if ($run->json('conclusion') !== 'success') {
                $this->fail($build, 'The GitHub build ended with "'.$run->json('conclusion').'". Open the run for the log.');

                return;
            }

            $build->update(['status' => ClientBuild::STATUS_COLLECTING, 'message' => 'Downloading the installers…']);
            $this->collect($build);
        } catch (Throwable $e) {
            Log::warning('Client build poll failed', ['build' => $build->id, 'error' => $e->getMessage()]);
            $build->update(['message' => 'Last check failed: '.Str::limit($e->getMessage(), 300).' (will retry)']);
        }
    }

    private function collect(ClientBuild $build): void
    {
        $assets = $this->github()->get($this->repoPath('/releases/'.$build->github_release_id.'/assets'), ['per_page' => 100]);
        $this->ensureOk($assets, 'list the release files');

        $ids = [];
        foreach ($assets->json() as $asset) {
            $name = (string) $asset['name'];
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (! in_array($ext, ['exe', 'msi'], true)) {
                continue;
            }

            $stored = Str::slug($build->exe_name).'-'.$build->id.'-'.Str::lower(Str::random(6)).'.'.$ext;
            $target = $this->disk()->path($stored);

            $download = $this->github()
                ->withHeaders(['Accept' => 'application/octet-stream'])
                ->timeout(600)
                ->sink($target)
                ->get($this->repoPath('/releases/assets/'.$asset['id']));
            if (! $download->successful()) {
                @unlink($target);
                throw new RuntimeException('could not download '.$name.' (HTTP '.$download->status().')');
            }

            $row = ClientDownload::create([
                'label' => $build->label.($ext === 'msi' ? ' (MSI)' : ''),
                'platform' => 'windows',
                'arch' => 'x64',
                'version' => $build->rustdesk_version,
                'notes' => $build->enrollmentKey
                    ? 'Enrolls into '.($build->enrollmentKey->user?->username ?? '?').' — do not publish.'
                    : 'Built with Client Builder #'.$build->id,
                'filename' => $stored,
                'original_name' => $name,
                'size' => filesize($target) ?: 0,
                'sha256' => hash_file('sha256', $target) ?: null,
                // A build carrying an enrollment key enrolls whoever runs it
                // into that account, so it never goes on the public page.
                'is_published' => $build->publish && $build->enrollment_key_id === null,
                'sort_order' => (int) ClientDownload::max('sort_order') + 1,
                'uploaded_by' => $build->created_by,
            ]);
            $ids[] = $row->id;
        }

        if ($ids === []) {
            $this->fail($build, 'The build finished but attached no installer. Open the run for the log.');

            return;
        }

        $build->update([
            'status' => ClientBuild::STATUS_DONE,
            'client_download_ids' => $ids,
            'finished_at' => now(),
            'message' => count($ids).' installer(s) added to Client Downloads.',
        ]);

        ConsoleAudit::system('client_build.done', 'Client build #'.$build->id.' ('.$build->label.') finished', 'client_build', (string) $build->id);

        $this->deleteRelease($build);
    }

    private function fail(ClientBuild $build, string $message): void
    {
        $build->update([
            'status' => ClientBuild::STATUS_FAILED,
            'message' => Str::limit($message, 480),
            'finished_at' => now(),
        ]);

        $this->deleteRelease($build);
    }

    /** The draft release holds the encrypted settings: never leave it behind. */
    public function deleteRelease(ClientBuild $build): void
    {
        if (! $build->github_release_id) {
            return;
        }

        try {
            $response = $this->github()->delete($this->repoPath('/releases/'.$build->github_release_id));
            if ($response->successful() || $response->status() === 404) {
                $build->update(['github_release_id' => null]);
            }
        } catch (Throwable $e) {
            Log::warning('Could not delete the build release', ['build' => $build->id, 'error' => $e->getMessage()]);
        }
    }

    /** Cancel a running build on GitHub and clean up. */
    public function cancel(ClientBuild $build): void
    {
        if ($build->github_run_id) {
            try {
                $this->github()->post($this->repoPath('/actions/runs/'.$build->github_run_id.'/cancel'));
            } catch (Throwable) {
                // Cancelling is best effort; the release is removed regardless.
            }
        }

        $this->fail($build, 'Cancelled.');
    }

    /** Check the token and repository; returns null when fine, else the problem. */
    public function checkAccess(): ?string
    {
        try {
            $repo = $this->github()->get($this->repoPath(''));
            if ($repo->status() === 404) {
                return 'Repository not found, or the token cannot see it.';
            }
            $this->ensureOk($repo, 'read the repository');

            $wf = $this->github()->get($this->repoPath('/actions/workflows/'.BuilderSettings::WORKFLOW_WINDOWS));
            if (! $wf->successful()) {
                return 'The repository has no '.BuilderSettings::WORKFLOW_WINDOWS.' workflow (is it an rdgen fork?).';
            }
            if ($wf->json('state') !== 'active') {
                return 'GitHub Actions is not enabled on the repository (state: '.$wf->json('state').'). Open its Actions tab and enable workflows.';
            }

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    // ---- helpers ------------------------------------------------------------

    private function github(): PendingRequest
    {
        return Http::withToken(BuilderSettings::token())
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => self::API_VERSION,
                'User-Agent' => 'CortenDesk-ClientBuilder',
            ])
            ->baseUrl(self::API)
            ->timeout(60);
    }

    private function repoPath(string $suffix): string
    {
        return '/repos/'.BuilderSettings::repo().$suffix;
    }

    private function ensureOk(Response $response, string $what): void
    {
        if ($response->successful()) {
            return;
        }

        $detail = (string) ($response->json('message') ?? Str::limit($response->body(), 200));
        $hint = match ($response->status()) {
            401 => ' The GitHub token is invalid or expired.',
            403 => ' The token lacks permission (needs Contents and Actions: read and write).',
            404 => ' Check the repository name and that the token can access it.',
            default => '',
        };

        throw new RuntimeException('GitHub refused to '.$what.' (HTTP '.$response->status().': '.$detail.').'.$hint);
    }

    private function disk(): Filesystem
    {
        return Storage::disk(ClientDownload::DISK);
    }
}
