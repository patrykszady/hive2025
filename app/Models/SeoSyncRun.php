<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per sync key ('search_console', 'bing'), replaced whole on every
 * run — see the seo_sync_runs migration's docblock for why this table
 * exists instead of piggybacking on an OAuth token row the way
 * jpeterson-design/gsc do. Ported from dawnsellshomes' identical model.
 *
 * @property array<string, mixed>|null $summary
 */
class SeoSyncRun extends Model
{
    protected $table = 'seo_sync_runs';

    protected $fillable = ['sync_key', 'summary'];

    protected $casts = [
        'summary' => 'array',
    ];

    /** @param  array<string, mixed>  $summary */
    public static function record(string $key, array $summary): void
    {
        static::query()->updateOrCreate(['sync_key' => $key], ['summary' => $summary]);
    }

    /** @return array<string, mixed>|null */
    public static function summary(string $key): ?array
    {
        // ->first()->summary, not ->value('summary'): value() reads the
        // raw column through the query builder, bypassing this model's
        // 'array' cast entirely.
        return static::query()->where('sync_key', $key)->first()?->summary;
    }
}
