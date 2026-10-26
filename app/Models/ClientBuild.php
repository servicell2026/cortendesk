<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/** One custom client build (see the create_client_builds_table migration). */
#[Fillable([
    'label', 'platform', 'rustdesk_version', 'app_name', 'exe_name', 'enrollment_key_id',
    'options', 'icon_path', 'logo_path', 'status', 'message', 'github_release_id',
    'github_run_id', 'github_run_url', 'publish', 'client_download_ids', 'created_by',
    'started_at', 'finished_at',
])]
class ClientBuild extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COLLECTING = 'collecting';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    /** Statuses the poller still has to look at. */
    public const ACTIVE = [self::STATUS_QUEUED, self::STATUS_RUNNING, self::STATUS_COLLECTING];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'publish' => 'boolean',
            'client_download_ids' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function enrollmentKey(): BelongsTo
    {
        return $this->belongsTo(EnrollmentKey::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    /** @return Collection<int, ClientDownload> */
    public function downloads()
    {
        return ClientDownload::query()->whereIn('id', $this->client_download_ids ?? [])->get();
    }
}
