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
 *  - paid in full by a personal card, and still no charge linked a week
 *    after it was imported: not the company's. It is deleted exactly as
 *    the Expenses screen deletes (Expense::deleteWithAssociations, soft
 *    deletes).
 *  - anything else is left alone: paid even partly by gift card or points
 *    (a payment with no card number), no payment details recorded, or
 *    younger than a week.
 *
 * Amazon records each payment's card (receipt_items.charges[].
 * paymentInstrumentLast4Digits). A company card is one that has ever had a
 * bank charge linked to an Amazon expense (4849 and 4842 on 2026-10-02);
 * an order paid with one counts as business-paid even before its charge
 * links.
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
        $companyCards = $this->companyCards();

        Expense::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('vendor_id', self::AMAZON_VENDOR_ID)
            ->whereHas('receipts')
            ->with('receipts')
            ->chunkById(200, function ($expenses) use ($cutoff, $dryRun, $companyCards, &$removed, &$placed) {
                foreach ($expenses as $expense) {
                    $purchaseOrder = NotOursPurchaseOrder::purchaseOrderOn($expense);
                    if ($purchaseOrder === null) {
                        continue;
                    }

                    $paidWith = $this->paidWith($expense, $companyCards);

                    if ($paidWith === 'company_card' || $this->businessPaid($expense)) {
                        $placed += (int) $this->placeOnHomeDistribution($expense, $purchaseOrder, $dryRun);

                        continue;
                    }

                    if ($paidWith !== 'personal_card') {
                        $this->line(sprintf('Keeping expense %d ($%s, "%s") — %s', $expense->id, number_format((float) $expense->amount, 2), $purchaseOrder, $paidWith === 'gift_card_or_points' ? 'paid at least partly by gift card or points' : 'no payment details recorded'));

                        continue;
                    }

                    if ($expense->created_at === null || $expense->created_at->gt($cutoff)) {
                        continue;
                    }

                    $this->line(sprintf('%s expense %d ($%s, %s, "%s") — paid by a personal card, no charge after a week', $dryRun ? 'Would remove' : 'Removing', $expense->id, number_format((float) $expense->amount, 2), $expense->date?->toDateString() ?? '—', $purchaseOrder));

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

    /**
     * How Amazon says the order was paid, from its receipt's charges:
     * 'company_card', 'personal_card' (card payments cover the whole order),
     * 'gift_card_or_points' (money paid with no card number), or 'unknown'
     * (no payment details). A $0.00 line with no card is an authorization,
     * not a payment.
     *
     * @param  array<int, string>  $companyCards
     */
    protected function paidWith(Expense $expense, array $companyCards): string
    {
        $charges = $this->charges($expense);
        if ($charges === []) {
            return 'unknown';
        }

        $byCard = $withoutCard = 0.0;
        foreach ($charges as $charge) {
            $amount = abs((float) ($charge['amount'] ?? 0));
            $card = trim((string) ($charge['paymentInstrumentLast4Digits'] ?? ''));

            if ($card !== '' && in_array($card, $companyCards, true)) {
                return 'company_card';
            }

            $card === '' ? $withoutCard += $amount : $byCard += $amount;
        }

        if ($withoutCard >= 0.005) {
            return 'gift_card_or_points';
        }

        return $byCard + 0.01 >= abs((float) $expense->amount) ? 'personal_card' : 'unknown';
    }

    /** @return array<int, array{amount?: mixed, paymentInstrumentLast4Digits?: mixed}> */
    protected function charges(Expense $expense): array
    {
        foreach ($expense->receipts as $receipt) {
            $charges = is_array($receipt->receipt_items) ? ($receipt->receipt_items['charges'] ?? null) : null;

            if (is_array($charges) && $charges !== []) {
                return array_values(array_filter($charges, 'is_array'));
            }
        }

        return [];
    }

    /**
     * Last four digits of every card the business has paid Amazon with:
     * any card on an Amazon expense that has a bank charge linked.
     *
     * @return array<int, string>
     */
    protected function companyCards(): array
    {
        $cards = [];

        Expense::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('vendor_id', self::AMAZON_VENDOR_ID)
            ->whereHas('transactions')
            ->with('receipts')
            ->chunkById(500, function ($expenses) use (&$cards) {
                foreach ($expenses as $expense) {
                    foreach ($this->charges($expense) as $charge) {
                        $card = trim((string) ($charge['paymentInstrumentLast4Digits'] ?? ''));
                        if ($card !== '') {
                            $cards[$card] = true;
                        }
                    }
                }
            });

        // Numeric keys come back as integers ('4849' => 4849); compare as strings.
        return array_map('strval', array_keys($cards));
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
