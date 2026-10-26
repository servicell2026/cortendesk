<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enrollment keys: a per-user secret baked into a custom client build. A
 * device whose sysinfo carries a valid key lands in that user's account
 * (and optionally a device group), approved, with nothing else to do.
 *
 * Unlike an admin-API token the key can do exactly one thing — attach new
 * devices to its owner — so leaking one out of an installer only lets someone
 * add machines to that account, which the operator sees and can delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Whose account enrolled devices land in.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('device_group_id')->nullable()->constrained('device_groups')->nullOnDelete();
            // sha256 of the plaintext, for lookup on every sysinfo.
            $table->string('token_hash', 64)->unique();
            $table->string('token_prefix', 16);
            // Laravel-encrypted plaintext, so the key can be shown again and
            // embedded in a client build later (listed in the backup re-key set).
            $table->text('token_enc');
            $table->unsignedInteger('max_devices')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->foreignId('enrollment_key_id')->nullable()->after('device_group_id')
                ->constrained('enrollment_keys')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('enrollment_key_id');
        });

        Schema::dropIfExists('enrollment_keys');
    }
};
