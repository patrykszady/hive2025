<?php

namespace App\Console\Commands;

use App\Models\Distribution;
use App\Models\ExpenseReceipts;
use Illuminate\Console\Command;

class FixAmazonExpenseDistributions extends Command
{
    protected $signature = 'amazon:fix-distributions {--dry-run : Show what would be updated without making changes} {--fix : Actually apply the fixes}';

    protected $description = 'Match Amazon expense purchase orders to distributions and update expenses missing a distribution';

    public function handle(): int
    {
        $dryRun = ! $this->option('fix');

        if ($dryRun) {
            $this->info('DRY RUN — showing affected expenses. Pass --fix to apply changes.');
        }

        $receipts = ExpenseReceipts::whereNotNull('receipt_items->purchase_order')
            ->where('receipt_items->purchase_order', '!=', '')
            ->whereHas('expense', fn ($q) => $q->withoutGlobalScopes()
                ->where('vendor_id', 54)
                ->whereNull('distribution_id')
                ->whereNull('deleted_at')
            )
            ->with(['expense' => fn ($q) => $q->withoutGlobalScopes()])
            ->get();

        if ($receipts->isEmpty()) {
            $this->info('No Amazon expenses with PO but missing distribution found.');

            return self::SUCCESS;
        }

        $this->info("Found {$receipts->count()} Amazon expenses with PO but no distribution.");

        // Build normalized distribution map per belongs_to_vendor_id
        $distCache = [];
        $tableRows = [];
        $fixed = 0;

        foreach ($receipts as $receipt) {
            $expense = $receipt->expense;
            $po = $receipt->receipt_items['purchase_order'] ?? '';
            $vendorId = $expense->belongs_to_vendor_id;

            if (! isset($distCache[$vendorId])) {
                $distributions = Distribution::withoutGlobalScopes()
                    ->where('vendor_id', $vendorId)
                    ->get();

                $distCache[$vendorId] = [];
                foreach ($distributions as $d) {
                    $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', $d->name));
                    $distCache[$vendorId][$normalized] = $d;
                }
            }

            $normalizedPO = strtolower(preg_replace('/[^a-z0-9]/i', '', $po));
            $matchedDist = $distCache[$vendorId][$normalizedPO] ?? null;

            $tableRows[] = [
                'expense_id' => $expense->id,
                'date' => $expense->date?->format('Y-m-d') ?? $expense->date,
                'amount' => '$' . number_format((float) $expense->amount, 2),
                'purchase_order' => substr($po, 0, 30),
                'matched_dist' => $matchedDist ? "{$matchedDist->name} (#{$matchedDist->id})" : 'NO MATCH',
            ];

            if ($matchedDist && ! $dryRun) {
                $expense->distribution_id = $matchedDist->id;
                $expense->save();
                $fixed++;
            }
        }

        $this->table(
            ['Expense ID', 'Date', 'Amount', 'Purchase Order', 'Distribution Match'],
            $tableRows
        );

        $matchedCount = collect($tableRows)->filter(fn ($r) => ! str_contains($r['matched_dist'], 'NO MATCH'))->count();
        $unmatchedCount = count($tableRows) - $matchedCount;

        if ($dryRun) {
            $this->info("{$matchedCount} would be fixed, {$unmatchedCount} have no matching distribution.");
        } else {
            $this->info("Fixed {$fixed} expenses. {$unmatchedCount} remain unmatched.");
        }

        return self::SUCCESS;
    }
}
