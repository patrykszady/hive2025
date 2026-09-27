<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per unique JS-error group signature (see App\Support\JsErrorGroups
 * for how the group itself — message/source/occurrences/first+last seen —
 * is computed live from `site_events`). This table holds only the
 * resolved/deleted state that can't be derived from the raw events
 * themselves, and gives every group a stable integer id for the
 * resolve/unresolve/delete routes ss-systems' JsErrorsBoard calls. Ported
 * from dawnsellshomes.com's App\Models\JsErrorState (the working contract).
 */
class JsErrorState extends Model
{
    protected $fillable = [
        'signature',
        'resolved_at',
        'deleted_before',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'deleted_before' => 'datetime',
    ];
}
