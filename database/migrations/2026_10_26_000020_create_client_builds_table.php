<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom client builds started from the console (Client Builder).
 *
 * The build runs on GitHub Actions in the operator's rdgen fork. Because the
 * console is usually on a private network, the inputs travel on a draft
 * release of that fork and the installers come back the same way; this row
 * tracks one build from dispatch to the installers landing in Client
 * Downloads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_builds', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('platform', 32)->default('windows');
            $table->string('rustdesk_version', 32);
            $table->string('app_name', 64);
            $table->string('exe_name', 64);
            $table->foreignId('enrollment_key_id')->nullable()->constrained('enrollment_keys')->nullOnDelete();
            // The form, minus the images (kept so a build can be repeated).
            $table->json('options')->nullable();
            // Paths on the local disk; kept so "build again" reuses them.
            $table->string('icon_path')->nullable();
            $table->string('logo_path')->nullable();
            // queued → running → collecting → done | failed
            $table->string('status', 16)->default('queued')->index();
            $table->string('message', 500)->nullable();
            $table->unsignedBigInteger('github_release_id')->nullable();
            $table->unsignedBigInteger('github_run_id')->nullable();
            $table->string('github_run_url')->nullable();
            $table->boolean('publish')->default(false);
            $table->json('client_download_ids')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_builds');
    }
};
