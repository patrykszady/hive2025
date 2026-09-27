<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * True site-wide Search Console totals for one day. Ported from
 * dawnsellshomes' identical model.
 */
class GscDailyTotal extends Model
{
    protected $table = 'gsc_daily_totals';

    protected $fillable = [
        'date', 'site_url', 'clicks', 'impressions', 'ctr', 'position',
    ];

    protected $casts = [
        'date' => 'date',
        'clicks' => 'integer',
        'impressions' => 'integer',
        'ctr' => 'float',
        'position' => 'float',
    ];
}
