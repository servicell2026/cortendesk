<?php

use App\Livewire\EnrollmentKeyManager;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\EnrollmentKey;
use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;

function sysinfo(array $extra = [], string $id = '111222333', string $uuid = 'dXVpZC0x'): string
{
    return test()->postJson('/api/sysinfo', array_merge([
        'id' => $id,
        'uuid' => $uuid,
        'hostname' => 'PC-'.$id,
        'os' => 'Windows 11',
        'username' => 'office',
        'version' => '1.5.0',
    ], $extra))->getContent();
}

beforeEach(function () {
    $this->owner = User::create(['username' => 'firmax', 'name' => 'Firma X', 'password' => 'secret-pass-1', 'is_active' => true]);
    $this->group = DeviceGroup::create(['name' => 'Firma X']);
    // The approval gate on: without a key a new device would wait as pending.
    Setting::put('require_device_approval', '1');
});

it('enrolls a new device into the key owner account, approved', function () {
    [$key, $plain] = EnrollmentKey::issue($this->owner, 'Firma X', $this->group->id);

    expect(sysinfo(['preset-user-name' => $plain]))->toBe('SYSINFO_UPDATED');

    $device = Device::where('rustdesk_id', '111222333')->first();
    expect($device->user_id)->toBe($this->owner->id)
        ->and($device->device_group_id)->toBe($this->group->id)
        ->and($device->status)->toBe(Device::STATUS_ACTIVE)
        ->and($device->enrollment_key_id)->toBe($key->id)
        ->and($key->fresh()->last_used_at)->not->toBeNull();
});

it('leaves a device pending when the key is unknown', function () {
    sysinfo(['preset-user-name' => 'ek_'.str_repeat('x', 32)]);

    $device = Device::where('rustdesk_id', '111222333')->first();
    expect($device->user_id)->toBeNull()
        ->and($device->status)->toBe(Device::STATUS_PENDING);
});

it('ignores a plain user name in preset-user-name', function () {
    sysinfo(['preset-user-name' => 'firmax']);

    expect(Device::where('rustdesk_id', '111222333')->first()->user_id)->toBeNull();
});

it('refuses revoked, expired and full keys', function () {
    [$revoked, $p1] = EnrollmentKey::issue($this->owner, 'revoked');
    $revoked->update(['revoked_at' => now()]);
    [, $p2] = EnrollmentKey::issue($this->owner, 'expired', null, null, now()->subDay());
    [$full, $p3] = EnrollmentKey::issue($this->owner, 'full', null, 1);

    sysinfo(['preset-user-name' => $p1], '100000001', 'dTE=');
    sysinfo(['preset-user-name' => $p2], '100000002', 'dTI=');
    sysinfo(['preset-user-name' => $p3], '100000003', 'dTM=');
    sysinfo(['preset-user-name' => $p3], '100000004', 'dTQ=');

    expect(Device::where('rustdesk_id', '100000001')->value('user_id'))->toBeNull()
        ->and(Device::where('rustdesk_id', '100000002')->value('user_id'))->toBeNull()
        ->and(Device::where('rustdesk_id', '100000003')->value('user_id'))->toBe($this->owner->id)
        ->and(Device::where('rustdesk_id', '100000004')->value('user_id'))->toBeNull()
        ->and($full->fresh()->isFull())->toBeTrue();
});

it('approves and claims an existing unowned pending device', function () {
    sysinfo();
    expect(Device::where('rustdesk_id', '111222333')->value('status'))->toBe(Device::STATUS_PENDING);

    [, $plain] = EnrollmentKey::issue($this->owner, 'Firma X');
    sysinfo(['preset-user-name' => $plain]);

    $device = Device::where('rustdesk_id', '111222333')->first();
    expect($device->user_id)->toBe($this->owner->id)
        ->and($device->status)->toBe(Device::STATUS_ACTIVE);
});

it('never moves a device that another user owns', function () {
    $other = User::create(['username' => 'other', 'password' => 'secret-pass-2', 'is_active' => true]);
    sysinfo();
    Device::where('rustdesk_id', '111222333')->update(['user_id' => $other->id, 'status' => Device::STATUS_ACTIVE]);

    [, $plain] = EnrollmentKey::issue($this->owner, 'Firma X');
    sysinfo(['preset-user-name' => $plain]);

    expect(Device::where('rustdesk_id', '111222333')->value('user_id'))->toBe($other->id);
});

it('is idempotent across repeated sysinfo uploads', function () {
    [$key, $plain] = EnrollmentKey::issue($this->owner, 'Firma X', null, 1);

    sysinfo(['preset-user-name' => $plain]);
    sysinfo(['preset-user-name' => $plain]);
    sysinfo(['preset-user-name' => $plain]);

    expect($key->devices()->count())->toBe(1)
        ->and(Device::where('rustdesk_id', '111222333')->value('user_id'))->toBe($this->owner->id);
});

it('stores the key encrypted and can show it again', function () {
    [$key, $plain] = EnrollmentKey::issue($this->owner, 'Firma X');

    expect($key->getRawOriginal('token_enc'))->not->toContain($plain)
        ->and($key->fresh()->plaintext())->toBe($plain)
        ->and($key->clientSettingLine())->toBe('preset-user-name='.$plain);
});

it('lets only a super-admin manage keys', function () {
    $admin = User::create(['username' => 'boss', 'password' => 'secret-pass-3', 'is_admin' => true, 'is_active' => true]);

    $this->actingAs($admin)->get('/enrollment-keys')->assertOk()->assertSee('Enrollment Keys');

    Livewire::actingAs($admin)->test(EnrollmentKeyManager::class)
        ->set('name', 'Firma X sediu')
        ->set('userId', $this->owner->id)
        ->set('deviceGroupId', $this->group->id)
        ->set('maxDevices', 10)
        ->call('save')
        ->assertHasNoErrors();

    $key = EnrollmentKey::first();
    expect($key->user_id)->toBe($this->owner->id)->and($key->max_devices)->toBe(10);

    $this->actingAs($this->owner)->get('/enrollment-keys')->assertRedirect(route('overview'));
    Livewire::actingAs($this->owner)->test(EnrollmentKeyManager::class)->assertForbidden();
});
