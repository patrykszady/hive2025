<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One rich-result (structured data) validation issue on a tracked URL.
 * Ported from dawnsellshomes' identical model.
 */
class GscRichResultIssue extends Model
{
    protected $table = 'gsc_rich_result_issues';

    protected $fillable = [
        'url',
        'rich_result_type',
        'issue_severity',
        'issue_message',
        'issue_type',
        'verdict',
        'inspected_at',
    ];

    protected $casts = [
        'inspected_at' => 'datetime',
    ];
}
