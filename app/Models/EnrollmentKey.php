<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * A per-user enrollment key (see the create_enrollment_keys_table migration).
 *
 * The client carries it in the built-in option `preset-user-name`, which the
 * stock RustDesk client sends with every sysinfo upload — so no client patch
 * is needed, only a custom build (rdgen override setting) that sets it.
 */
#[Fillable([
    'name', 'user_id', 'device_group_id', 'token_hash', 'token_prefix', 'token_enc',
    'max_devices', 'expires_at', 'revoked_at', 'last_used_at', 'created_by',
])]
#[Hidden(['token_hash', 'token_enc'])]
class EnrollmentKey extends Model
{
    /** Every key starts with this, so sysinfo can tell a key from a user name. */
    public const PREFIX = 'ek_';

    /** The sysinfo field (and rdgen override setting) that carries the key. */
    public const CLIENT_OPTION = 'preset-user-name';

    protected function casts(): array
    {
        return [
            'max_devices' => 'integer',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deviceGroup(): BelongsTo
    {
        return $this->belongsTo(DeviceGroup::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /**
     * Create a key and return [model, plaintext].
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(
        User $owner,
        string $name,
        ?int $deviceGroupId = null,
        ?int $maxDevices = null,
        ?\DateTimeInterface $expiresAt = null,
        ?User $creator = null,
    ): array {
        $plain = self::PREFIX.Str::random(32);

        $key = static::create([
            'name' => $name,
            'user_id' => $owner->id,
            'device_group_id' => $deviceGroupId,
            'token_hash' => hash('sha256', $plain),
            'token_prefix' => substr($plain, 0, 10),
            'token_enc' => Crypt::encryptString($plain),
            'max_devices' => $maxDevices,
            'expires_at' => $expiresAt,
            'created_by' => $creator?->id,
        ]);

        return [$key, $plain];
    }

    public static function looksLikeKey(string $value): bool
    {
        return str_starts_with($value, self::PREFIX) && strlen($value) > strlen(self::PREFIX) + 8;
    }

    /** The key a plaintext names, whatever its state (null if unknown). */
    public static function findByPlaintext(string $plaintext): ?self
    {
        if (! self::looksLikeKey($plaintext)) {
            return null;
        }

        return static::query()->where('token_hash', hash('sha256', $plaintext))->first();
    }

    public function plaintext(): string
    {
        return Crypt::decryptString($this->token_enc);
    }

    /** The line to paste into rdgen's override settings, or a build's custom.txt. */
    public function clientSettingLine(): string
    {
        return self::CLIENT_OPTION.'='.$this->plaintext();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isFull(): bool
    {
        return $this->max_devices !== null && $this->devices()->count() >= $this->max_devices;
    }

    /** Why the key cannot enroll a new device right now, or null if it can. */
    public function refusal(): ?string
    {
        return match (true) {
            $this->isRevoked() => 'revoked',
            $this->isExpired() => 'expired',
            ! ($this->user?->is_active ?? false) => 'owner inactive',
            $this->isFull() => 'device limit reached',
            default => null,
        };
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->isRevoked() => 'Revoked',
            $this->isExpired() => 'Expired',
            $this->isFull() => 'Full',
            default => 'Active',
        };
    }
}
