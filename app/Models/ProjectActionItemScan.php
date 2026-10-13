<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * How far ProjectActionItemExtractor has read one of a project's
 * conversations: the last call id (kind `calls`, source 0) or the last
 * message id of one thread (kind `sms_thread`, source = thread id).
 */
class ProjectActionItemScan extends Model
{
    public const KIND_CALLS = 'calls';

    public const KIND_SMS_THREAD = 'sms_thread';

    protected $fillable = ['project_id', 'kind', 'source_id', 'last_source_id', 'scanned_at'];

    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'last_source_id' => 'integer',
            'scanned_at' => 'datetime',
        ];
    }

    public static function lastIdFor(int $projectId, string $kind, int $sourceId = 0): int
    {
        return (int) self::query()
            ->where('project_id', $projectId)
            ->where('kind', $kind)
            ->where('source_id', $sourceId)
            ->value('last_source_id');
    }

    public static function record(int $projectId, string $kind, int $sourceId, int $lastSourceId): void
    {
        self::query()->updateOrCreate(
            ['project_id' => $projectId, 'kind' => $kind, 'source_id' => $sourceId],
            ['last_source_id' => $lastSourceId, 'scanned_at' => now()],
        );
    }
}
