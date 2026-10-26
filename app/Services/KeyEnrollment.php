<?php

namespace App\Services;

use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\EnrollmentKey;
use Illuminate\Support\Facades\Log;

/**
 * Attaches a device to the owner of the enrollment key its client presents.
 *
 * The key arrives in sysinfo as `preset-user-name` (a built-in option of a
 * custom client build). Sysinfo is tokenless, so the key itself is the only
 * credential: it is matched by hash, and a valid one can only ever attach a
 * device to its own owner — never move a device another user already owns.
 */
class KeyEnrollment
{
    public const RESULT_ENROLLED = 'enrolled';

    public const RESULT_UNCHANGED = 'unchanged';

    public const RESULT_REFUSED = 'refused';

    public const RESULT_NONE = 'none';

    /** The key named by a sysinfo payload, or null when there is none. */
    public static function presented(array $payload): ?string
    {
        $value = trim((string) ($payload[EnrollmentKey::CLIENT_OPTION] ?? ''));

        return EnrollmentKey::looksLikeKey($value) ? $value : null;
    }

    /**
     * Attributes a brand-new device gets from a valid key, merged before the
     * row is created (so the approval gate, default group and notifications
     * see the final state). Returns [] when the key is missing or unusable.
     *
     * @return array<string, mixed>
     */
    public static function attributesForNew(?string $plaintext): array
    {
        $key = self::usableKey($plaintext);
        if ($key === null) {
            return [];
        }

        $attributes = [
            'user_id' => $key->user_id,
            'enrollment_key_id' => $key->id,
            'status' => Device::STATUS_ACTIVE,
        ];
        if ($key->device_group_id !== null) {
            $attributes['device_group_id'] = $key->device_group_id;
        }

        return $attributes;
    }

    /**
     * Apply a key to a device that already exists (or was just created with
     * attributesForNew, in which case this only records the use).
     */
    public static function apply(Device $device, ?string $plaintext, ?string $ip = null): string
    {
        if ($plaintext === null) {
            return self::RESULT_NONE;
        }

        $key = EnrollmentKey::findByPlaintext($plaintext);
        if ($key === null) {
            Log::warning('Unknown enrollment key presented', ['device' => $device->rustdesk_id, 'ip' => $ip]);

            return self::RESULT_REFUSED;
        }

        if ($device->enrollment_key_id === $key->id && $device->user_id === $key->user_id) {
            if ($device->wasRecentlyCreated) {
                self::recordUse($key, $device, $ip);

                return self::RESULT_ENROLLED;
            }

            return self::RESULT_UNCHANGED;
        }

        // Never take a device away from somebody else.
        if ($device->user_id !== null && $device->user_id !== $key->user_id) {
            Log::warning('Enrollment key ignored: device belongs to another user', [
                'device' => $device->rustdesk_id, 'key' => $key->token_prefix,
            ]);

            return self::RESULT_REFUSED;
        }

        $reason = $key->refusal();
        if ($reason !== null) {
            Log::warning('Enrollment key refused: '.$reason, ['device' => $device->rustdesk_id, 'key' => $key->token_prefix]);

            return self::RESULT_REFUSED;
        }

        $changes = [
            'user_id' => $key->user_id,
            'enrollment_key_id' => $key->id,
        ];
        if ($device->isPending()) {
            $changes['status'] = Device::STATUS_ACTIVE;
        }
        if ($device->device_group_id === null && $key->device_group_id !== null) {
            $changes['device_group_id'] = $key->device_group_id;
        }
        $device->update($changes);

        self::recordUse($key, $device, $ip);

        return self::RESULT_ENROLLED;
    }

    private static function usableKey(?string $plaintext): ?EnrollmentKey
    {
        if ($plaintext === null) {
            return null;
        }

        $key = EnrollmentKey::findByPlaintext($plaintext);

        return $key !== null && $key->refusal() === null ? $key : null;
    }

    private static function recordUse(EnrollmentKey $key, Device $device, ?string $ip): void
    {
        $key->forceFill(['last_used_at' => now()])->saveQuietly();

        ConsoleAudit::system(
            'enrollment_key.enroll',
            'Device '.$device->rustdesk_id.' enrolled to '.($key->user?->username ?? '?').' with key '.$key->name
                .($ip ? ' from '.$ip : ''),
            'device',
            (string) $device->id,
        );
    }
}
