<?php

namespace App\Services\ClientBuilder;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Where and how custom clients are built: the rdgen fork on GitHub, the token
 * that drives it, and the public addresses baked into every build.
 *
 * Secrets (the GitHub token, the zip password) are stored encrypted, like the
 * SMTP and OIDC secrets, and are never sent back to the browser.
 */
class BuilderSettings
{
    public const REPO = 'builder_github_repo';

    public const BRANCH = 'builder_github_branch';

    public const TOKEN = 'builder_github_token';

    public const ZIP_PASSWORD = 'builder_zip_password';

    public const SERVER_HOST = 'builder_server_host';

    public const API_SERVER = 'builder_api_server';

    public const COMPANY = 'builder_company';

    public const WORKFLOW_WINDOWS = 'generator-windows.yml';

    public static function repo(): string
    {
        return trim((string) Setting::get(self::REPO, (string) config('cortendesk.builder_repo', '')));
    }

    public static function branch(): string
    {
        return trim((string) Setting::get(self::BRANCH, '')) ?: 'master';
    }

    public static function token(): string
    {
        return self::secret(self::TOKEN);
    }

    public static function zipPassword(): string
    {
        return self::secret(self::ZIP_PASSWORD);
    }

    public static function hasToken(): bool
    {
        return self::token() !== '';
    }

    public static function hasZipPassword(): bool
    {
        return self::zipPassword() !== '';
    }

    /**
     * The host clients use for the ID server. Defaults to the host part of the
     * configured ID server — which is often a LAN address on a fresh install,
     * hence the separate setting for the public name (DDNS).
     */
    public static function serverHost(): string
    {
        $saved = trim((string) Setting::get(self::SERVER_HOST, ''));
        if ($saved !== '') {
            return $saved;
        }

        return self::hostOf(Setting::server('id_server'));
    }

    /** Rendezvous port; the relay and WebSocket ports follow it (+1, +2, +3). */
    public static function serverPort(): int
    {
        $id = Setting::server('id_server');
        if (preg_match('/:(\d+)$/', $id, $m)) {
            return (int) $m[1];
        }

        return 21116;
    }

    /** The console URL clients reach for the API (heartbeat, sysinfo, sign-in). */
    public static function apiServer(): string
    {
        $saved = rtrim(trim((string) Setting::get(self::API_SERVER, '')), '/');

        return $saved !== '' ? $saved : rtrim((string) config('app.url'), '/');
    }

    public static function publicKey(): string
    {
        return Setting::server('public_key');
    }

    public static function company(): string
    {
        return trim((string) Setting::get(self::COMPANY, ''));
    }

    /** Problems that stop a build from starting, as human-readable strings. */
    public static function problems(): array
    {
        $problems = [];
        if (! preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', self::repo())) {
            $problems[] = 'Set the GitHub repository of your rdgen fork (owner/name).';
        }
        if (! self::hasToken()) {
            $problems[] = 'Add a GitHub token for that repository.';
        }
        if (! self::hasZipPassword()) {
            $problems[] = 'Add the zip password (the ZIP_PASSWORD secret of the rdgen repository).';
        }
        if (self::serverHost() === '') {
            $problems[] = 'Set the public server address clients connect to.';
        }
        if (self::publicKey() === '') {
            $problems[] = 'The server public key is unknown (Settings → Server).';
        }

        return $problems;
    }

    public static function putSecret(string $key, string $value): void
    {
        Setting::put($key, $value === '' ? null : Crypt::encryptString($value));
    }

    private static function secret(string $key): string
    {
        $stored = Setting::get($key);
        if ($stored === null || $stored === '') {
            return '';
        }

        try {
            return (string) Crypt::decryptString($stored);
        } catch (Throwable) {
            return '';
        }
    }

    private static function hostOf(string $address): string
    {
        $address = trim($address);
        if ($address === '') {
            return '';
        }
        if (str_contains($address, '://')) {
            return (string) parse_url($address, PHP_URL_HOST);
        }
        // host:port, but leave a bare IPv6 literal alone
        if (substr_count($address, ':') === 1) {
            return explode(':', $address)[0];
        }

        return $address;
    }
}
