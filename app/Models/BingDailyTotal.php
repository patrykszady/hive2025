<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * True site-wide Bing Webmaster Tools totals for one day. Ported from
 * dawnsellshomes' identical model.
 */
class BingDailyTotal extends Model
{
    protected $table = 'bing_daily_totals';

    protected $fillable = [
        'date', 'site_url', 'clicks', 'impressions', 'ctr',
    ];

    protected $casts = [
        'date' => 'date',
        'clicks' => 'integer',
        'impressions' => 'integer',
        'ctr' => 'float',
    ];
}
