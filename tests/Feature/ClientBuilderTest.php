<?php

use App\Livewire\ClientBuilder;
use App\Models\ClientBuild;
use App\Models\ClientDownload;
use App\Models\EnrollmentKey;
use App\Models\Setting;
use App\Models\User;
use App\Services\ClientBuilder\BuilderSettings;
use App\Services\ClientBuilder\GithubBuilder;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake(ClientDownload::DISK);

    $this->admin = User::create(['username' => 'boss', 'password' => 'secret-pass-3', 'is_admin' => true, 'is_active' => true]);

    Setting::put(BuilderSettings::REPO, 'Servicell2026/rdgen');
    Setting::put(BuilderSettings::SERVER_HOST, 'firma.myqnapcloud.com');
    Setting::put(BuilderSettings::API_SERVER, 'https://firma.myqnapcloud.com:8090');
    Setting::put('id_server', '192.168.1.102:21116');
    Setting::put('public_key', 'PUBKEY123=');
    BuilderSettings::putSecret(BuilderSettings::TOKEN, 'github_pat_test');
    BuilderSettings::putSecret(BuilderSettings::ZIP_PASSWORD, 'zip-pass-1');
});

function fakeGithub(array &$uploads, string $conclusion = 'success', string $status = 'completed'): void
{
    Http::fake(function (Request $request) use (&$uploads, $conclusion, $status) {
        $url = $request->url();
        $method = $request->method();

        if ($method === 'POST' && str_ends_with($url, '/repos/Servicell2026/rdgen/releases')) {
            return Http::response(['id' => 777], 201);
        }
        if ($method === 'POST' && str_starts_with($url, 'https://uploads.github.com/repos/Servicell2026/rdgen/releases/777/assets')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $uploads[$q['name']] = $request->body();

            return Http::response(['id' => count($uploads)], 201);
        }
        if ($method === 'POST' && str_contains($url, '/actions/workflows/generator-windows.yml/dispatches')) {
            $uploads['__dispatch'] = $request->data();

            return Http::response(['workflow_run_id' => 4242, 'html_url' => 'https://github.com/Servicell2026/rdgen/actions/runs/4242'], 200);
        }
        if ($method === 'GET' && str_ends_with($url, '/actions/runs/4242')) {
            return Http::response(['status' => $status, 'conclusion' => $conclusion, 'html_url' => 'https://github.com/x']);
        }
        if ($method === 'GET' && str_contains($url, '/releases/777/assets')) {
            return Http::response([
                ['id' => 1, 'name' => 'secrets.zip'],
                ['id' => 2, 'name' => 'servicell-remote.exe'],
                ['id' => 3, 'name' => 'servicell-remote.msi'],
            ]);
        }
        if ($method === 'GET' && preg_match('#/releases/assets/(\d+)$#', $url, $m)) {
            return Http::response('MZ-binary-'.$m[1], 200, ['Content-Type' => 'application/octet-stream']);
        }
        if ($method === 'DELETE' && str_ends_with($url, '/releases/777')) {
            $uploads['__deleted'] = true;

            return Http::response(null, 204);
        }

        return Http::response(['message' => 'unexpected '.$method.' '.$url], 500);
    });
}

function decryptSecrets(string $zip, string $password): array
{
    $path = tempnam(sys_get_temp_dir(), 'z');
    file_put_contents($path, $zip);
    $out = shell_exec('python3 -c '.escapeshellarg(
        'import sys,pyzipper;z=pyzipper.AESZipFile(sys.argv[1]);z.setpassword(sys.argv[2].encode());sys.stdout.write(z.read("secrets.json").decode())'
    ).' '.escapeshellarg($path).' '.escapeshellarg($password));
    unlink($path);

    return json_decode((string) $out, true) ?? [];
}

it('starts a build: draft release, encrypted inputs, images, dispatch', function () {
    $uploads = [];
    fakeGithub($uploads);

    $owner = User::create(['username' => 'firmax', 'password' => 'secret-pass-1', 'is_active' => true]);
    [$key, $plain] = EnrollmentKey::issue($owner, 'Firma X');

    Livewire::actingAs($this->admin)->test(ClientBuilder::class)
        ->set('label', 'Firma X')
        ->set('appName', 'SERVICELL Remote')
        ->set('exeName', 'servicell-remote')
        ->set('enrollmentKeyId', $key->id)
        ->set('icon', UploadedFile::fake()->createWithContent('icon.png', file_get_contents(__DIR__.'/../fixtures/icon-256.png')))
        ->set('direction', 'incoming')
        ->set('publish', true)
        ->call('build')
        ->assertHasNoErrors();

    $build = ClientBuild::first();
    expect($build->status)->toBe(ClientBuild::STATUS_RUNNING)
        ->and($build->github_run_id)->toBe(4242)
        ->and($build->publish)->toBeFalse() // never public with a key
        ->and(array_keys($uploads))->toContain('secrets.zip', 'icon.png', '__dispatch')
        ->and($uploads)->not->toHaveKey('logo.png');

    expect($uploads['__dispatch']['inputs']['zip_url'])->toBe('{"release_id":777}')
        ->and($uploads['__dispatch']['inputs']['version'])->toBe('1.5.0');

    if (trim((string) shell_exec('python3 -c "import pyzipper" 2>&1')) !== '') {
        $this->markTestIncomplete('pyzipper not installed: skipped decrypting the inputs');
    }

    $inputs = decryptSecrets($uploads['secrets.zip'], 'zip-pass-1');
    expect($inputs['server'])->toBe('firma.myqnapcloud.com')
        ->and($inputs['serverPort'])->toBe('21116')
        ->and($inputs['key'])->toBe('PUBKEY123=')
        ->and($inputs['apiServer'])->toBe('https://firma.myqnapcloud.com:8090')
        ->and($inputs['rdgen'])->toBe('release')
        ->and($inputs['release_id'])->toBe('777')
        ->and($inputs['iconlink_url'])->toBe('release')
        ->and($inputs['logolink_url'])->toBe('false')
        ->and($inputs['appname'])->toBe('SERVICELL Remote')
        ->and($inputs['filename'])->toBe('servicell-remote');

    $custom = json_decode(base64_decode($inputs['custom']), true);
    expect($custom['conn-type'])->toBe('incoming')
        ->and($custom['app-name'])->toBe('SERVICELL Remote')
        ->and($custom['override-settings']['preset-user-name'])->toBe($plain);
});

it('collects the installers when the run succeeds, unpublished for a key build', function () {
    $uploads = [];
    fakeGithub($uploads);

    $owner = User::create(['username' => 'firmax', 'password' => 'secret-pass-1', 'is_active' => true]);
    [$key] = EnrollmentKey::issue($owner, 'Firma X');

    $build = ClientBuild::create([
        'label' => 'Firma X', 'rustdesk_version' => '1.5.0', 'app_name' => 'SERVICELL Remote',
        'exe_name' => 'servicell-remote', 'enrollment_key_id' => $key->id, 'status' => ClientBuild::STATUS_RUNNING,
        'github_release_id' => 777, 'github_run_id' => 4242, 'started_at' => now(), 'created_by' => $this->admin->id,
    ]);

    $this->artisan('cortendesk:poll-client-builds')->assertSuccessful();

    $build->refresh();
    expect($build->status)->toBe(ClientBuild::STATUS_DONE)
        ->and($build->client_download_ids)->toHaveCount(2)
        ->and($build->github_release_id)->toBeNull()
        ->and($uploads['__deleted'] ?? false)->toBeTrue();

    $downloads = ClientDownload::all();
    expect($downloads->pluck('is_published')->unique()->all())->toBe([false])
        ->and($downloads->pluck('original_name')->sort()->values()->all())->toBe(['servicell-remote.exe', 'servicell-remote.msi']);
    Storage::disk(ClientDownload::DISK)->assertExists($downloads->first()->filename);

    // An operator can fetch the unpublished file; the public cannot.
    $this->get(route('downloads.show', $downloads->first()))->assertNotFound();
    $this->actingAs($this->admin)->get(route('downloads.show', $downloads->first()))->assertOk();
});

it('leaves a running build alone and fails a broken one, deleting its release', function () {
    $uploads = [];
    fakeGithub($uploads, 'failure');

    $build = ClientBuild::create([
        'label' => 'X', 'rustdesk_version' => '1.5.0', 'app_name' => 'X', 'exe_name' => 'x',
        'status' => ClientBuild::STATUS_RUNNING, 'github_release_id' => 777, 'github_run_id' => 4242, 'started_at' => now(),
    ]);

    app(GithubBuilder::class)->poll($build);

    expect($build->fresh()->status)->toBe(ClientBuild::STATUS_FAILED)
        ->and($uploads['__deleted'] ?? false)->toBeTrue();
});

it('keeps polling while the run is in progress', function () {
    $uploads = [];
    fakeGithub($uploads, '', 'in_progress');

    $build = ClientBuild::create([
        'label' => 'X', 'rustdesk_version' => '1.5.0', 'app_name' => 'X', 'exe_name' => 'x',
        'status' => ClientBuild::STATUS_RUNNING, 'github_release_id' => 777, 'github_run_id' => 4242, 'started_at' => now(),
    ]);

    app(GithubBuilder::class)->poll($build);

    expect($build->fresh()->status)->toBe(ClientBuild::STATUS_RUNNING);
});

it('refuses to build until the settings are complete', function () {
    Setting::put(BuilderSettings::TOKEN, null);
    Http::fake();

    Livewire::actingAs($this->admin)->test(ClientBuilder::class)
        ->set('label', 'X')->set('appName', 'X')->set('exeName', 'x')
        ->call('build')
        ->assertHasErrors('label');

    expect(ClientBuild::count())->toBe(0);
    Http::assertNothingSent();
});

it('rejects names that would break the workflow', function () {
    Livewire::actingAs($this->admin)->test(ClientBuilder::class)
        ->set('label', 'X')->set('appName', 'Bad"Name$')->set('exeName', 'bad name')
        ->call('build')
        ->assertHasErrors(['appName', 'exeName']);
});

it('renders for a super-admin only', function () {
    $this->actingAs($this->admin)->get('/client-builder')->assertOk()->assertSee('New Windows build');

    $user = User::create(['username' => 'u', 'password' => 'secret-pass-4', 'is_active' => true]);
    $this->actingAs($user)->get('/client-builder')->assertRedirect(route('overview'));
});
