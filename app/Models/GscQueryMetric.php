<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One query x page x country x device x day row from the Search Console
 * Search Analytics API. Ported from dawnsellshomes' identical model.
 */
class GscQueryMetric extends Model
{
    protected $table = 'gsc_query_metrics';

    protected $fillable = [
        'date', 'site_url', 'query', 'page', 'country', 'device',
        'impressions', 'clicks', 'ctr', 'position', 'dim_hash',
    ];

    protected $casts = [
        'date' => 'date',
        'impressions' => 'integer',
        'clicks' => 'integer',
        'ctr' => 'float',
        'position' => 'float',
    ];
}
