<?php

namespace App\Support\Seo\Reports;

use App\Models\PsiSnapshot;
use SsSystems\Platform\Reports\Contracts\PsiSnapshotReader;

/**
 * Wraps psi_snapshots for CwvTemplateReport's `psi_snapshots` capability —
 * ported from gs.construction's identical class, minus tenant scoping
 * (this app has no BelongsToSite). Always bound in AppServiceProvider (so
 * `php artisan seo:cwv-template` resolves directly), but 'psi_snapshots'
 * only appears in ReportCapabilities::provided() once a PageSpeed key is
 * saved — see that class's docblock for why.
 */
final class EloquentPsiSnapshotReader implements PsiSnapshotReader
{
    public function snapshots(string $from, string $to): array
    {
        return PsiSnapshot::query()
            ->whereBetween('date', [$from, $to])
            ->get(['url', 'strategy', 'field_lcp_ms', 'field_inp_ms', 'field_cls', 'lab_lcp_ms', 'performance'])
            ->map(fn (PsiSnapshot $row): array => [
                'url' => (string) $row->url,
                'strategy' => (string) $row->strategy,
                'field_lcp_ms' => $row->field_lcp_ms,
                'field_inp_ms' => $row->field_inp_ms,
                'field_cls' => $row->field_cls,
                'lab_lcp_ms' => $row->lab_lcp_ms,
                'performance' => $row->performance,
            ])
            ->all();
    }
}
