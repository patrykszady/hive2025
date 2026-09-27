<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One query x day row from Bing Webmaster Tools' GetQueryStats. Ported
 * from dawnsellshomes' identical model.
 */
class BingTrafficStat extends Model
{
    protected $table = 'bing_traffic_stats';

    protected $fillable = [
        'date', 'site_url', 'query', 'impressions', 'clicks', 'position', 'dim_hash',
    ];

    protected $casts = [
        'date' => 'date',
        'impressions' => 'integer',
        'clicks' => 'integer',
        'position' => 'float',
    ];
}
