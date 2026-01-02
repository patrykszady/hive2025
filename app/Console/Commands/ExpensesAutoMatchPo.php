<?php

namespace App\Console\Commands;

use App\Http\Controllers\ExpenseAutoMatchController;
use App\Models\Expense;
use App\Models\Vendor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpensesAutoMatchPo extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'expenses:auto-match-po
        {--commit : Persist DB/Scout changes (default: dry run)}
        {--vendor=* : Limit to one or more hive vendor IDs}
        {--summary-all : Include all expense vendors seen in candidates (even if no valid PO was extracted)}
        {--include-null-status : Treat NULL expense_status as "No Project" when selecting candidates}
        {--include-null-splits : Treat NULL has_splits as false when selecting candidates}
        {--preview : Output per-expense match rows (matched only)}
        {--preview-all : Include skipped rows in preview table (no_po / no_match / ambiguous)}
        {--preview-limit=0 : Max per-expense rows to print (0 = unlimited)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Auto-match "No Project" expenses by PO (default: dry run with summary table)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $commit = (bool) $this->option('commit');
        $summaryAll = (bool) $this->option('summary-all');
        $includeNullStatus = (bool) $this->option('include-null-status');
        $includeNullSplits = (bool) $this->option('include-null-splits');
        $previewAll = (bool) $this->option('preview-all');
        $preview = (bool) $this->option('preview') || $previewAll;
        $previewLimit = max(0, (int) $this->option('preview-limit'));

        $onlyBelongsToVendorIds = collect((array) $this->option('vendor'))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->values()
            ->all();

        $summaryRows = [];
        $matchRows = [];
        $onSummary = function (array $context) use (&$summaryRows): void {
            $summaryRows[] = [
                'belongs_to_vendor_id' => (int) ($context['belongs_to_vendor_id'] ?? 0),
                'expense_vendor_id' => (int) ($context['expense_vendor_id'] ?? 0),
                'candidates_seen' => (int) ($context['candidates_seen'] ?? 0),
                'candidates_considered' => (int) ($context['candidates_considered'] ?? 0),
                'valid_po_seen' => (int) ($context['valid_po_seen'] ?? 0),
                'matched_distribution' => (int) ($context['matched_distribution'] ?? 0),
                'matched_project' => (int) ($context['matched_project'] ?? 0),
                'skipped_no_po' => (int) ($context['skipped_no_po'] ?? 0),
                'skipped_no_match' => (int) ($context['skipped_no_match'] ?? 0),
                'skipped_ambiguous' => (int) ($context['skipped_ambiguous'] ?? 0),
            ];
        };

        $runner = function () use ($onlyBelongsToVendorIds, $onSummary, $summaryAll, $includeNullStatus, $includeNullSplits, $preview, $previewAll, $previewLimit, &$matchRows): void {
            $onDecision = null;

            if ($preview) {
                $onDecision = function (array $row) use ($previewAll, $previewLimit, &$matchRows): void {
                    if ($previewLimit > 0 && count($matchRows) >= $previewLimit) {
                        return;
                    }

                    if ($previewAll && (($row['reason'] ?? null) === 'no_po')) {
                        return;
                    }

                    if (! $previewAll && (($row['result'] ?? null) !== 'matched')) {
                        return;
                    }

                    $matchRows[] = $row;
                };
            }

            app(ExpenseAutoMatchController::class)->runNoProjectExpenseAutoMatch(
                $onlyBelongsToVendorIds === [] ? null : $onlyBelongsToVendorIds,
                $onDecision,
                $onSummary,
                $summaryAll,
                $includeNullStatus,
                $includeNullSplits
            );
        };

        $execute = function () use ($runner): void {
            if (method_exists(Expense::class, 'withoutSyncingToSearch')) {
                Expense::withoutSyncingToSearch($runner);
                return;
            }

            if (method_exists(Expense::class, 'disableSearchSyncing')) {
                Expense::disableSearchSyncing();
                try {
                    $runner();
                } finally {
                    if (method_exists(Expense::class, 'enableSearchSyncing')) {
                        Expense::enableSearchSyncing();
                    }
                }

                return;
            }

            $runner();
        };

        if ($commit) {
            $this->info('Running PO auto-match (COMMIT mode) ...');
            $execute();
        } else {
            $this->info('Running PO auto-match (DRY RUN: transaction rollback) ...');
            DB::beginTransaction();
            try {
                $execute();
            } finally {
                DB::rollBack();
            }
        }

        if ($summaryRows !== []) {
            usort($summaryRows, function (array $a, array $b): int {
                $aHive = (int) ($a['belongs_to_vendor_id'] ?? 0);
                $bHive = (int) ($b['belongs_to_vendor_id'] ?? 0);
                if ($aHive !== $bHive) {
                    return $aHive <=> $bHive;
                }

                return ((int) ($a['expense_vendor_id'] ?? 0)) <=> ((int) ($b['expense_vendor_id'] ?? 0));
            });

            $allVendorIds = collect($summaryRows)
                ->flatMap(fn (array $row) => [(int) ($row['belongs_to_vendor_id'] ?? 0), (int) ($row['expense_vendor_id'] ?? 0)])
                ->filter()
                ->unique()
                ->values()
                ->all();

            $vendorNames = Vendor::withoutGlobalScopes()
                ->whereIn('id', $allVendorIds)
                ->pluck('business_name', 'id')
                ->all();

            $rows = array_map(function (array $row) use ($vendorNames): array {
                $hiveId = (int) ($row['belongs_to_vendor_id'] ?? 0);
                $expenseVendorId = (int) ($row['expense_vendor_id'] ?? 0);
                $expenseVendorName = trim((string) ($vendorNames[$expenseVendorId] ?? ''));

                $hiveLabel = (string) $hiveId;
                $expenseVendorLabel = $expenseVendorName !== '' ? ($expenseVendorName." (#{$expenseVendorId})") : "#{$expenseVendorId}";

                return [
                    $hiveLabel,
                    $expenseVendorLabel,
                    (int) ($row['candidates_seen'] ?? 0),
                    (int) ($row['candidates_considered'] ?? 0),
                    (int) ($row['valid_po_seen'] ?? 0),
                    (int) ($row['matched_distribution'] ?? 0),
                    (int) ($row['matched_project'] ?? 0),
                    (int) ($row['skipped_no_po'] ?? 0),
                    (int) ($row['skipped_no_match'] ?? 0),
                    (int) ($row['skipped_ambiguous'] ?? 0),
                ];
            }, $summaryRows);

            $this->table(
                ['HiveVendor', 'ExpenseVendor', 'Seen', 'Considered', 'ValidPO', 'MatchDist', 'MatchProj', 'NoPO', 'NoMatch', 'Ambig'],
                $rows
            );
        } elseif (! $preview) {
            $this->warn('No summary rows logged.');
        }

        if ($preview) {
            $this->newLine();
            $this->info($previewAll ? 'Expenses (preview, including skipped):' : 'Matched expenses (preview):');

            if ($matchRows === []) {
                $this->warn('No preview rows captured. This usually means there were no candidate expenses.');
            } else {

            $previewVendorIds = collect($matchRows)
                ->flatMap(fn (array $row) => [(int) ($row['belongs_to_vendor_id'] ?? 0), (int) ($row['expense_vendor_id'] ?? 0)])
                ->filter()
                ->unique()
                ->values()
                ->all();

            $previewVendorNames = Vendor::withoutGlobalScopes()
                ->whereIn('id', $previewVendorIds)
                ->pluck('business_name', 'id')
                ->all();

            $previewRows = array_map(function (array $row) use ($previewVendorNames): array {
                $hiveId = (int) ($row['belongs_to_vendor_id'] ?? 0);
                $expenseVendorId = (int) ($row['expense_vendor_id'] ?? 0);
                $expenseVendorName = trim((string) ($previewVendorNames[$expenseVendorId] ?? ''));

                $hiveLabel = (string) $hiveId;
                $expenseVendorLabel = $expenseVendorName !== '' ? ($expenseVendorName." (#{$expenseVendorId})") : "#{$expenseVendorId}";

                return [
                    $hiveLabel,
                    $expenseVendorLabel,
                    (int) ($row['expense_id'] ?? 0),
                    (string) ($row['purchase_order'] ?? ''),
                    (string) ($row['result'] ?? ''),
                    (string) ($row['reason'] ?? ''),
                    (string) ($row['matched_type'] ?? ''),
                    (int) ($row['matched_id'] ?? 0),
                    (string) ($row['matched_name'] ?? ''),
                    (! array_key_exists('score', $row) || $row['score'] === null) ? '' : number_format((float) $row['score'], 3),
                ];
            }, $matchRows);

            $this->table(
                ['HiveVendor', 'ExpenseVendor', 'ExpenseId', 'PO', 'Result', 'Reason', 'Type', 'MatchedId', 'Matched', 'Score'],
                $previewRows
            );

            if ($previewLimit > 0 && count($matchRows) >= $previewLimit) {
                $this->warn("Preview limited to {$previewLimit} rows. Use --preview-limit to change.");
            }
            }
        }

        if (! $commit) {
            $this->line('Dry run completed: DB rolled back; Scout syncing disabled.');
        }

        return self::SUCCESS;
    }
}
