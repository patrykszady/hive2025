<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One run of the AI estimate generator over a whole estimate. A queued job
 * drafts it (App\Jobs\DraftEstimateSections); the generator's window follows
 * this row while the lines land, and each section the run fills keeps its
 * own EstimateAiDraft.
 */
class EstimateAiRun extends Model
{
    public const QUEUED = 'queued';

    public const DRAFTING = 'drafting';

    public const STOPPING = 'stopping';

    public const FINISHED = 'finished';

    public const FAILED = 'failed';

    public const STOPPED = 'stopped';

    /** A run nobody has heard from for this long is taken to have died with its worker. */
    public const SILENT_AFTER_SECONDS = 300;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'plan' => 'array',
            'floorplan' => 'array',
            'sections' => 'array',
            'usage' => 'array',
            'started_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withoutGlobalScopes();
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class)->withoutGlobalScopes();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function drafts(): HasMany
    {
        return $this->hasMany(EstimateAiDraft::class, 'run_id');
    }

    public function isRunning(): bool
    {
        return in_array($this->status, [self::QUEUED, self::DRAFTING, self::STOPPING], true);
    }

    /** Still marked as running, but silent for too long: its worker is gone. */
    public function hasGoneSilent(): bool
    {
        $lastHeard = $this->heartbeat_at ?? $this->started_at ?? $this->created_at;

        return $this->isRunning() && $lastHeard !== null && $lastHeard->diffInSeconds(now()) > self::SILENT_AFTER_SECONDS;
    }
}
