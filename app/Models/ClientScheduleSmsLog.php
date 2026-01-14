<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientScheduleSmsLog extends Model
{
    protected $fillable = [
        'project_id',
        'user_id',
        'type',
        'target_date',
        'tasks_hash',
    ];

    protected function casts(): array
    {
        return [
            'target_date' => 'date',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if a "changed" SMS was recently sent (within throttle window).
     */
    public static function wasRecentlyNotified(int $projectId, int $userId, int $throttleMinutes = 30): bool
    {
        return static::where('project_id', $projectId)
            ->where('user_id', $userId)
            ->where('type', 'changed')
            ->where('created_at', '>=', now()->subMinutes($throttleMinutes))
            ->exists();
    }

    /**
     * Check if today/tomorrow SMS was already sent for this date.
     */
    public static function wasAlreadySent(int $projectId, int $userId, string $type, string $targetDate): bool
    {
        return static::where('project_id', $projectId)
            ->where('user_id', $userId)
            ->where('type', $type)
            ->where('target_date', $targetDate)
            ->exists();
    }

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
