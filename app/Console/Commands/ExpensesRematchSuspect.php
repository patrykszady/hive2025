<?php

namespace App\Console\Commands;

use App\Http\Controllers\ExpenseAutoMatchController;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Vendor;
use Illuminate\Console\Command;
use ReflectionClass;

class ExpensesRematchSuspect extends Command
{
    protected $signature = 'expenses:rematch-suspect
        {--apply : Null out project_id on flagged expenses so the regular matcher re-runs them (default: dry run)}
        {--vendor=* : Limit to one or more hive vendor IDs}
        {--expense=* : Limit to specific expense IDs}
        {--max-score=0.55 : Flag expenses whose current project scores AT OR BELOW this against the PO/notes}
        {--since= : Only consider expenses with date >= YYYY-MM-DD}
        {--limit=0 : Cap number of flagged expenses (0 = unlimited)}';

    protected $description = 'Find expenses whose linked project poorly matches their PO/handwritten notes (legacy matcher fallout). Use --apply to null project_id so the current matcher re-runs them.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $maxScore = (float) $this->option('max-score');
        $limit = max(0, (int) $this->option('limit'));
        $since = $this->option('since');

        $vendorIds = collect((array) $this->option('vendor'))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->values()
            ->all();

        $expenseIds = collect((array) $this->option('expense'))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->values()
            ->all();

        $controller = app(ExpenseAutoMatchController::class);
        $ref = new ReflectionClass($controller);

        $invoke = function (string $method, array $args) use ($controller, $ref) {
            $m = $ref->getMethod($method);
            $m->setAccessible(true);
            return $m->invoke($controller, ...$args);
        };

        $hiveVendorsQuery = Vendor::hiveVendors();
        if ($vendorIds !== []) {
            $hiveVendorsQuery->whereIn('id', $vendorIds);
        }
        $hiveVendors = $hiveVendorsQuery->get();

        $rows = [];
        $flaggedIds = [];

        foreach ($hiveVendors as $hiveVendor) {
            $projects = Project::withoutGlobalScopes()
                ->where('belongs_to_vendor_id', $hiveVendor->id)
                ->get(['id', 'project_name', 'address']);

            $variantsByProjectId = [];
            foreach ($projects as $project) {
                $variants = [];
                $address = $invoke('normalizeText', [(string) ($project->address ?? '')]);
                if ($address !== '') {
                    $variants[] = $address;
                }
                $full = $invoke('normalizeText', [trim(((string) ($project->address ?? '')).' '.((string) ($project->project_name ?? '')))]);
                if ($full !== '' && ! in_array($full, $variants, true)) {
                    $variants[] = $full;
                }
                $name = $invoke('normalizeText', [(string) ($project->project_name ?? '')]);
                if ($name !== '' && ! in_array($name, $variants, true)) {
                    $variants[] = $name;
                }
                if ($variants !== []) {
                    $variantsByProjectId[(int) $project->id] = $variants;
                }
            }

            $subVendorIds = Vendor::withoutGlobalScopes()
                ->where('business_type', 'Sub')
                ->where('id', '!=', $hiveVendor->id)
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
            $matchableVendorIds = array_values(array_unique(array_merge([(int) $hiveVendor->id], $subVendorIds)));

            $query = Expense::withoutGlobalScopes()
                ->whereIn('belongs_to_vendor_id', $matchableVendorIds)
                ->whereNotNull('project_id')
                ->where('project_id', '!=', 0)
                ->with(['receipts:id,expense_id,receipt_items']);

            if ($expenseIds !== []) {
                $query->whereIn('id', $expenseIds);
            }
            if ($since) {
                $query->where('date', '>=', $since);
            }

            $shouldStop = false;
            $query->orderBy('id')->chunkById(500, function ($chunk) use (
                $invoke, $variantsByProjectId, $maxScore, $limit, &$rows, &$flaggedIds, &$shouldStop
            ): bool {
                foreach ($chunk as $expense) {
                    $projectId = (int) $expense->project_id;
                    $variants = $variantsByProjectId[$projectId] ?? null;
                    if (! $variants) {
                        continue;
                    }

                    $candidates = $invoke('extractPurchaseOrderCandidates', [$expense]);
                    $distOnly = $invoke('extractDistributionOnlyCandidates', [$expense]);
                    $allCandidates = array_values(array_unique(array_merge($candidates, $distOnly)));

                    if ($allCandidates === []) {
                        continue;
                    }

                    $bestScore = 0.0;
                    $bestCandidate = '';
                    foreach ($allCandidates as $candidate) {
                        $po = $invoke('normalizeText', [(string) $candidate]);
                        if ($po === '') {
                            continue;
                        }
                        $poStreet = $invoke('hasHouseNumberPrefix', [$po]) ? $invoke('extractStreetToken', [$po]) : '';

                        $score = 0.0;
                        foreach ($variants as $variant) {
                            $score = max($score, $invoke('similarityScore', [$po, $variant]));
                            $score = max($score, $invoke('houseNumberOcrBoostScore', [$po, $variant, $poStreet]));
                        }

                        if ($score > $bestScore) {
                            $bestScore = $score;
                            $bestCandidate = (string) $candidate;
                        }
                    }

                    if ($bestScore <= $maxScore) {
                        $rows[] = [
                            'expense_id' => (int) $expense->id,
                            'project_id' => $projectId,
                            'po_or_notes' => $bestCandidate !== '' ? $bestCandidate : $allCandidates[0],
                            'score' => number_format($bestScore, 3),
                        ];
                        $flaggedIds[] = (int) $expense->id;

                        if ($limit > 0 && count($flaggedIds) >= $limit) {
                            $shouldStop = true;
                            return false;
                        }
                    }
                }

                return true;
            });

            if ($shouldStop) {
                break;
            }
        }

        if ($rows === []) {
            $this->info('No suspect expenses found.');
            return self::SUCCESS;
        }

        $this->table(['expense_id', 'project_id', 'po_or_notes', 'score'], $rows);
        $this->newLine();
        $this->line(sprintf('Found %d suspect expense(s) (threshold score <= %s).', count($rows), number_format($maxScore, 2)));

        if (! $apply) {
            $this->newLine();
            $this->comment('Dry run. Re-run with --apply to null project_id on these expenses.');
            $this->comment('After applying, run: php artisan expenses:auto-match-po --commit');
            return self::SUCCESS;
        }

        if (! $this->confirm(sprintf('Null project_id on %d expense(s)?', count($flaggedIds)), false)) {
            $this->warn('Aborted.');
            return self::SUCCESS;
        }

        $affected = Expense::withoutGlobalScopes()
            ->whereIn('id', $flaggedIds)
            ->update(['project_id' => null]);

        $this->info(sprintf('Nulled project_id on %d expense(s).', $affected));
        $this->comment('Now run: php artisan expenses:auto-match-po --commit');

        return self::SUCCESS;
    }
}
