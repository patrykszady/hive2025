<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One PageSpeed Insights check for one URL + strategy on one day. Written
 * by App\Console\Commands\SeoPsiSync, read by App\Support\Seo\Reports\
 * EloquentPsiSnapshotReader for the kit's CwvTemplateReport ('psi_snapshots'
 * capability). Single-tenant, so unlike gs.construction's identical model
 * there is no BelongsToSite trait.
 */
class PsiSnapshot extends Model
{
    protected $table = 'psi_snapshots';

    protected $fillable = [
        'date', 'url', 'strategy',
        'performance', 'accessibility', 'best_practices', 'seo',
        'lab_lcp_ms', 'lab_fcp_ms', 'lab_tbt_ms', 'lab_cls', 'lab_si_ms',
        'field_lcp_ms', 'field_inp_ms', 'field_cls', 'field_overall',
    ];

    protected $casts = [
        'date' => 'date',
        'performance' => 'integer',
        'accessibility' => 'integer',
        'best_practices' => 'integer',
        'seo' => 'integer',
        'lab_lcp_ms' => 'integer',
        'lab_fcp_ms' => 'integer',
        'lab_tbt_ms' => 'integer',
        'lab_cls' => 'float',
        'lab_si_ms' => 'integer',
        'field_lcp_ms' => 'integer',
        'field_inp_ms' => 'integer',
        'field_cls' => 'float',
    ];
}
