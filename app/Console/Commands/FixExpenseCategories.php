<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixExpenseCategories extends Command
{
    protected $signature = 'fix:expense-categories';

    protected $description = 'One-time fix for expense categories and vendor assignments (safe to re-run)';

    public function handle(): int
    {
        $this->info('Starting expense category fixes...');

        // 1. Specific expense ID fixes
        $this->fixSpecificExpenses();

        // 2. CapitalOne uncategorized → CC Payment / Other Bank Fees
        $this->fixCapitalOneUncategorized();

        // 3. CapitalOne interest charges misclassified as CC Payment
        $this->fixCapitalOneInterestCharges();

        // 4. Most-used category per vendor for remaining uncategorized
        $this->fixByMostUsedCategory();

        // 5. Business-type-based categorization for remaining uncategorized
        $this->fixByBusinessType();

        // 6. CapitalOne Travel vendor reassignment
        $this->fixCapitalOneTravel();

        $this->info('All fixes complete.');

        return self::SUCCESS;
    }

    private function fixSpecificExpenses(): void
    {
        // PAST DUE FEE → Late Fees (232)
        $count = DB::table('expenses')
            ->whereNull('deleted_at')
            ->whereIn('id', [24346, 24772, 25894])
            ->where('category_id', '!=', 232)
            ->update(['category_id' => 232]);
        $this->line("  PAST DUE FEE → Late Fees: {$count}");

        // INTEREST CHARGE ADJUSTMENT → Interest Earned (106)
        $count = DB::table('expenses')
            ->whereNull('deleted_at')
            ->where('id', 24580)
            ->where('category_id', '!=', 106)
            ->update(['category_id' => 106]);
        $this->line("  INTEREST CHARGE ADJUSTMENT → Interest Earned: {$count}");

        // Returned payment 16448 → CC Payment (124)
        $count = DB::table('expenses')
            ->whereNull('deleted_at')
            ->where('id', 16448)
            ->where('category_id', '!=', 124)
            ->update(['category_id' => 124]);
        $this->line("  Returned payment 16448 → CC Payment: {$count}");
    }

    private function fixCapitalOneUncategorized(): void
    {
        // $59 amounts → Other Bank Fees (134) — CAPITAL ONE MEMBER FEE
        $count = DB::table('expenses')
            ->whereNull('deleted_at')
            ->where('vendor_id', 77)
            ->whereNull('category_id')
            ->where('amount', 59)
            ->update(['category_id' => 134]);
        $this->line("  CapitalOne $59 → Other Bank Fees: {$count}");

        // All remaining uncategorized CapitalOne → CC Payment (124)
        $count = DB::table('expenses')
            ->whereNull('deleted_at')
            ->where('vendor_id', 77)
            ->whereNull('category_id')
            ->update(['category_id' => 124]);
        $this->line("  CapitalOne remaining → CC Payment: {$count}");
    }

    private function fixCapitalOneInterestCharges(): void
    {
        // Non-round amounts on day 20-26, positive, not 300.09 → Interest Charge (132)
        $count = DB::table('expenses')
            ->whereNull('deleted_at')
            ->where('vendor_id', 77)
            ->where('category_id', 124)
            ->where('amount', '>', 0)
            ->where('amount', '!=', 300.09)
            ->whereRaw('amount != ROUND(amount, 0)')
            ->whereRaw('DAY(date) >= 20')
            ->whereRaw('DAY(date) <= 26')
            ->update(['category_id' => 132]);
        $this->line("  CapitalOne interest charges → Interest Charge: {$count}");
    }

    private function fixByMostUsedCategory(): void
    {
        // For each vendor: find most-used category, apply to uncategorized expenses
        $vendors = DB::table('expenses')
            ->whereNull('deleted_at')
            ->whereNull('category_id')
            ->select('vendor_id')
            ->distinct()
            ->pluck('vendor_id');

        $total = 0;
        foreach ($vendors as $vendorId) {
            if (! $vendorId) {
                continue;
            }

            $mostUsed = DB::table('expenses')
                ->whereNull('deleted_at')
                ->where('vendor_id', $vendorId)
                ->whereNotNull('category_id')
                ->select('category_id', DB::raw('COUNT(*) as cnt'))
                ->groupBy('category_id')
                ->orderByDesc('cnt')
                ->first();

            if ($mostUsed) {
                $count = DB::table('expenses')
                    ->whereNull('deleted_at')
                    ->where('vendor_id', $vendorId)
                    ->whereNull('category_id')
                    ->update(['category_id' => $mostUsed->category_id]);
                $total += $count;
            }
        }
        $this->line("  Most-used category per vendor: {$total}");
    }

    private function fixByBusinessType(): void
    {
        $mappings = [
            ['type' => 'Sub', 'category' => 122],       // Other Transfer Out
            ['type' => '1099', 'category' => 121],       // Account Transfer
            ['type' => 'DBA', 'category' => 122],        // Other Transfer Out
            ['type' => 'Retail', 'category' => 163],     // Hardware
            ['type' => 'Materials', 'category' => 163],  // Hardware
        ];

        foreach ($mappings as $mapping) {
            $type = $mapping['type'];
            $categoryId = $mapping['category'];
            $count = DB::table('expenses')
                ->whereNull('expenses.deleted_at')
                ->whereNull('expenses.category_id')
                ->join('vendors', 'expenses.vendor_id', '=', 'vendors.id')
                ->where('vendors.business_type', $type)
                ->update(['expenses.category_id' => $categoryId]);
            $this->line("  {$type} → category {$categoryId}: {$count}");
        }
    }

    private function fixCapitalOneTravel(): void
    {
        // Move expenses with "TRAVEL" in transaction description to vendor 840
        $count = DB::table('expenses')
            ->whereNull('expenses.deleted_at')
            ->where('expenses.vendor_id', 77)
            ->whereIn('expenses.id', function ($query) {
                $query->select('t.expense_id')
                    ->from('transactions as t')
                    ->whereNull('t.deleted_at')
                    ->where(function ($q) {
                        $q->where('t.plaid_merchant_name', 'LIKE', '%TRAVEL%')
                            ->orWhere('t.plaid_merchant_description', 'LIKE', '%TRAVEL%');
                    });
            })
            ->update(['expenses.vendor_id' => 840]);
        $this->line("  CapitalOne Travel → vendor 840: {$count}");
    }
}
