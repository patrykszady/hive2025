<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsLog extends Model
{
    // Channel constants
    public const CHANNEL_CLIENT = 'client';
    public const CHANNEL_TEAM = 'team';
    public const CHANNEL_VENDOR = 'vendor';

    // Type constants
    public const TYPE_TODAY = 'today';
    public const TYPE_TOMORROW = 'tomorrow';
    public const TYPE_CHANGED = 'changed';
    public const TYPE_REMINDER = 'reminder';
    public const TYPE_UPDATE = 'update';
    public const TYPE_AVAILABILITY = 'availability';

    protected $fillable = [
        'channel',
        'type',
        'user_id',
        'project_id',
        'task_id',
        'vendor_id',
        'target_date',
        'content_hash',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'target_date' => 'date',
            'sent_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    // -------------------------------------------------------------------------
    // Query Helpers
    // -------------------------------------------------------------------------

    /**
     * Check if an SMS was recently sent (within throttle window).
     */
    public static function wasRecentlyNotified(
        string $channel,
        int $userId,
        int $throttleMinutes = 30,
        ?int $projectId = null
    ): bool {
        return static::where('channel', $channel)
            ->where('user_id', $userId)
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->where('created_at', '>=', now()->subMinutes($throttleMinutes))
            ->exists();
    }

    /**
     * Check if a scheduled SMS (today/tomorrow) was already sent for this date.
     */
    public static function wasAlreadySent(
        string $channel,
        string $type,
        int $userId,
        string $targetDate,
        ?int $projectId = null
    ): bool {
        return static::where('channel', $channel)
            ->where('type', $type)
            ->where('user_id', $userId)
            ->where('target_date', $targetDate)
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->exists();
    }

    /**
     * Get the last log entry for a specific channel/user/project combo.
     */
    public static function getLastLog(
        string $channel,
        int $userId,
        ?int $projectId = null
    ): ?self {
        return static::where('channel', $channel)
            ->where('user_id', $userId)
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->latest()
            ->first();
    }

    /**
     * Log an SMS that was sent.
     */
    public static function logSent(array $data): self
    {
        return static::create(array_merge($data, [
            'sent_at' => now(),
        ]));
    }

    // -------------------------------------------------------------------------
    // Hash Generation
    // -------------------------------------------------------------------------

    /**
     * Generate a hash of tasks for change detection.
     */
    public static function generateTasksHash(iterable $tasks): string
    {
        $data = collect($tasks)->map(function ($task) {
            $options = is_object($task->options) ? $task->options : (object) ($task->options ?? []);
            $dates = $options->dates ?? [];
            $timeSettings = $options->time_settings ?? [];

            return [
                'id' => $task->id ?? null,
                'title' => $task->title ?? '',
                'dates' => $dates,
                'time_settings' => $timeSettings,
            ];
        })->sortBy('id')->values()->toJson();

        return md5($data);
    }
}
