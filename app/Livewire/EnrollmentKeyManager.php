<?php

namespace App\Livewire;

use App\Models\ConsoleAudit;
use App\Models\DeviceGroup;
use App\Models\EnrollmentKey;
use App\Models\User;
use Livewire\Component;

/**
 * Enrollment Keys: one key per customer account, baked into that customer's
 * client build. Super-admin only — a key hands devices to an account, which is
 * a user-and-device decision no delegated role covers on its own.
 */
class EnrollmentKeyManager extends Component
{
    public bool $showModal = false;

    public string $name = '';

    public ?int $userId = null;

    public ?int $deviceGroupId = null;

    public ?int $maxDevices = null;

    public ?int $expiresDays = null;

    /** The key whose plaintext is currently revealed. */
    public ?int $revealed = null;

    public function mount(): void
    {
        $this->authorizeAdmin();
    }

    public function create(?int $userId = null): void
    {
        $this->authorizeAdmin();

        $this->resetForm();
        $this->userId = $userId;
        if ($userId !== null) {
            $this->name = (string) User::query()->find($userId)?->username;
        }
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->authorizeAdmin();

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'userId' => ['required', 'integer', 'exists:users,id'],
            'deviceGroupId' => ['nullable', 'integer', 'exists:device_groups,id'],
            'maxDevices' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'expiresDays' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ], [], [
            'userId' => 'account',
            'deviceGroupId' => 'device group',
            'maxDevices' => 'device limit',
        ]);

        [$key] = EnrollmentKey::issue(
            User::query()->findOrFail($this->userId),
            trim($this->name),
            $this->deviceGroupId ?: null,
            $this->maxDevices ?: null,
            $this->expiresDays ? now()->addDays($this->expiresDays) : null,
            auth()->user(),
        );

        ConsoleAudit::record('enrollment_key.create',
            'Created enrollment key '.$key->name.' for '.$key->user->username, 'enrollment_key', (string) $key->id);

        $this->showModal = false;
        $this->resetForm();
        $this->revealed = $key->id;
    }

    public function reveal(int $id): void
    {
        $this->authorizeAdmin();

        $this->revealed = $this->revealed === $id ? null : $id;
    }

    public function revoke(int $id): void
    {
        $this->authorizeAdmin();

        $key = EnrollmentKey::query()->findOrFail($id);
        $key->update(['revoked_at' => now()]);

        ConsoleAudit::record('enrollment_key.revoke', 'Revoked enrollment key '.$key->name, 'enrollment_key', (string) $id);
    }

    public function restore(int $id): void
    {
        $this->authorizeAdmin();

        $key = EnrollmentKey::query()->findOrFail($id);
        $key->update(['revoked_at' => null]);

        ConsoleAudit::record('enrollment_key.restore', 'Re-enabled enrollment key '.$key->name, 'enrollment_key', (string) $id);
    }

    public function deleteKey(int $id): void
    {
        $this->authorizeAdmin();

        $key = EnrollmentKey::query()->findOrFail($id);
        $name = $key->name;
        // Devices keep their owner; only the link to the key is cleared.
        $key->delete();

        if ($this->revealed === $id) {
            $this->revealed = null;
        }

        ConsoleAudit::record('enrollment_key.delete', 'Deleted enrollment key '.$name, 'enrollment_key', (string) $id);
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset('name', 'userId', 'deviceGroupId', 'maxDevices', 'expiresDays');
        $this->resetValidation();
    }

    private function authorizeAdmin(): void
    {
        abort_unless((bool) auth()->user()?->is_admin, 403);
    }

    public function render()
    {
        return view('livewire.enrollment-key-manager', [
            'keys' => EnrollmentKey::query()->with(['user', 'deviceGroup'])->withCount('devices')
                ->orderByDesc('id')->get(),
            'users' => User::query()->where('is_active', true)->orderBy('username')->get(['id', 'username', 'name']),
            'groups' => DeviceGroup::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
