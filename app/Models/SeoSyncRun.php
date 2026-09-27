<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use SsSystems\Platform\Seo\Concerns\RecordsSyncRuns;

/**
 * One row per sync key ('search_console', 'bing'), replaced whole on every
 * run — see the seo_sync_runs migration's docblock for why this table
 * exists instead of piggybacking on an OAuth token row the way
 * jpeterson-design/gsc do. record()/summary() now come from the kit's
 * RecordsSyncRuns trait (0.11.0) — ported verbatim from this model, which
 * was itself ported from dawnsellshomes' identical model — this class
 * keeps only the parts the kit says are the site's own to own: the table
 * name and the cast.
 *
 * @property array<string, mixed>|null $summary
 */
class SeoSyncRun extends Model
{
    use RecordsSyncRuns;

    protected $table = 'seo_sync_runs';

    protected $fillable = ['sync_key', 'summary'];

    protected $casts = [
        'summary' => 'array',
    ];
}
