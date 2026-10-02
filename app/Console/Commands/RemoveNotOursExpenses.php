<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Models\Transaction;
use App\Support\NotOursPurchaseOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sorts Amazon expenses whose purchase order says "Not <name> home", every
 * night (Patryk, 2026-10-02):
 *
 *  - charged to a business card: that person's home purchase, paid by the
 *    business. It goes to their "<Name> - Home" distribution. A refund of
 *    such an order follows its order there, and waits for its credit.
 *  - no charge linked a week after it was imported: paid with someone's
 *    own card, not the company's. It is deleted exactly as the Expenses
 *    screen deletes (Expense::deleteWithAssociations, soft deletes).
 *  - younger than that: left alone until its week is up.
 *
 * Until April 2025 these were deleted by hand; about sixty had piled up.
 */
class RemoveNotOursExpenses extends Command
{
    /** ReceiptController imports Amazon orders under this vendor. */
    public const AMAZON_VENDOR_ID = 54;

    protected $signature = 'expenses:remove-not-ours
        {--days=7 : Days an expense must have existed with no charge before it is deleted}
        {--dry-run : List what would change}';

    protected $description = 'Amazon "Not <name> home" expenses: business-paid ones to that Home distribution, the rest deleted after a week';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');
        $removed = $placed = 0;

        Expense::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('vendor_id', self::AMAZON_VENDOR_ID)
            ->whereHas('receipts')
            ->with('receipts')
            ->chunkById(200, function ($expenses) use ($cutoff, $dryRun, &$removed, &$placed) {
                foreach ($expenses as $expense) {
                    $purchaseOrder = NotOursPurchaseOrder::purchaseOrderOn($expense);
                    if ($purchaseOrder === null) {
                        continue;
                    }

                    if ($this->businessPaid($expense)) {
                        $placed += (int) $this->placeOnHomeDistribution($expense, $purchaseOrder, $dryRun);

                        continue;
                    }

                    if ($expense->created_at === null || $expense->created_at->gt($cutoff)) {
                        continue;
                    }

                    $this->line(sprintf('%s expense %d ($%s, %s, "%s") — no charge after a week', $dryRun ? 'Would remove' : 'Removing', $expense->id, number_format((float) $expense->amount, 2), $expense->date?->toDateString() ?? '—', $purchaseOrder));

                    if (! $dryRun) {
                        $expense->deleteWithAssociations();
                        Log::info('expenses:remove-not-ours: removed an Amazon "Not …" expense with no charge after a week', [
                            'expense_id' => $expense->id, 'amount' => $expense->amount, 'date' => $expense->date?->toDateString(), 'purchase_order' => $purchaseOrder,
                        ]);
                    }

                    $removed++;
                }
            });

        $this->info(sprintf('%d placed on a Home distribution, %d removed%s.', $placed, $removed, $dryRun ? ' (dry run)' : ''));

        return self::SUCCESS;
    }

    /**
     * Did the business pay for it: a charge or check linked to it, or, for
     * a refund, to the order it refunds (same Amazon order number)?
     */
    protected function businessPaid(Expense $expense): bool
    {
        if ($this->charged($expense)) {
            return true;
        }

        if ((float) $expense->amount >= 0 || blank($expense->invoice)) {
            return false;
        }

        return Expense::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('vendor_id', self::AMAZON_VENDOR_ID)
            ->where('belongs_to_vendor_id', $expense->belongs_to_vendor_id)
            ->where('invoice', $expense->invoice)
            ->where('amount', '>', 0)
            ->get()
            ->contains(fn (Expense $order) => $this->charged($order));
    }

    protected function charged(Expense $expense): bool
    {
        return $expense->check_id !== null
            || Transaction::withoutGlobalScopes()->whereNull('deleted_at')->where('expense_id', $expense->id)->exists()
            || $expense->checks()->exists();
    }

    /** True when it was (or on a dry run, would be) moved onto the Home distribution. */
    protected function placeOnHomeDistribution(Expense $expense, string $purchaseOrder, bool $dryRun): bool
    {
        $distributionId = NotOursPurchaseOrder::homeDistributionId($purchaseOrder, (int) $expense->belongs_to_vendor_id);

        if ($distributionId === null) {
            $this->warn(sprintf('Expense %d: "%s" names no single Home distribution — left for a person', $expense->id, $purchaseOrder));

            return false;
        }

        if ((int) $expense->distribution_id === $distributionId || $expense->project_id || $expense->splits()->exists()) {
            return false;
        }

        $this->line(sprintf('%s expense %d ($%s, %s, "%s") on distribution %d — paid by the business', $dryRun ? 'Would place' : 'Placing', $expense->id, number_format((float) $expense->amount, 2), $expense->date?->toDateString() ?? '—', $purchaseOrder, $distributionId));

        if (! $dryRun) {
            $expense->update(['distribution_id' => $distributionId]);
            Log::info('expenses:remove-not-ours: placed a business-paid Amazon "Not …" expense on its Home distribution', [
                'expense_id' => $expense->id, 'distribution_id' => $distributionId, 'purchase_order' => $purchaseOrder,
            ]);
        }

        return true;
    }
}
