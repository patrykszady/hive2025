<?php

namespace App\Services\Expenses;

use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\OutsideCard;
use Illuminate\Support\Carbon;

/**
 * Which cards on a company's receipts are not the company's own. Card ••1854
 * paid two Amazon orders in March 2026 (expenses 26499 and 26525), is on
 * none of GS Construction's bank accounts, and was never charged there — so
 * both sat at "No Transaction" while the matcher hunted for a charge that
 * could never come. Such a payment now reads like a bank charge with a red
 * "Not GS Construction" pill and counts as paid.
 *
 * A card is someone else's when a person said so (OutsideCard), or — nobody
 * having ruled — when it is none of the company's account numbers, no
 * expense paid with it was ever linked to one of the company's bank charges,
 * and the payment is over UNMATCHED_DAYS old. The matcher only looks 21 days
 * past an expense, so a younger payment may still find its charge.
 *
 * Bound scoped (AppServiceProvider): answers are kept for one request or job.
 */
class OutsideCardService
{
    /** Days a card payment waits for its bank charge before it counts as someone else's. */
    public const UNMATCHED_DAYS = 30;

    /** @var array<int, array<string, bool>> tenant => last four => is_outside */
    private array $overrides = [];

    /** @var array<string, bool> "tenant:last four" => the company's own */
    private array $companyCards = [];

    public function isOutside(ExpensePayment $payment, Expense $expense): bool
    {
        if ($payment->method !== ExpensePayment::CARD || ! $payment->last_four) {
            return false;
        }

        $tenantId = (int) $expense->belongs_to_vendor_id;
        $override = $this->overrides($tenantId)[$payment->last_four] ?? null;

        if ($override !== null) {
            return $override;
        }

        $seen = collect([$expense->date, $payment->paid_at])->filter()->max();

        if ($seen === null || Carbon::parse($seen)->gt(now()->subDays(self::UNMATCHED_DAYS))) {
            return false;
        }

        return ! $this->isCompanyCard($tenantId, $payment->last_four);
    }

    /** One of the company's account numbers, or seen on one of its bank charges. */
    public function isCompanyCard(int $tenantId, string $lastFour): bool
    {
        return $this->companyCards[$tenantId.':'.$lastFour] ??= BankAccount::withoutGlobalScopes()
            ->where('vendor_id', $tenantId)
            ->where('account_number', $lastFour)
            ->whereNull('deleted_at')
            ->exists()
            || ExpensePayment::query()
                ->where('method', ExpensePayment::CARD)
                ->where('last_four', $lastFour)
                ->whereHas('expense', fn ($query) => $query->withoutGlobalScopes()
                    ->where('belongs_to_vendor_id', $tenantId)
                    ->whereNull('deleted_at'))
                ->whereExists(fn ($query) => $query->selectRaw('1')
                    ->from('transactions')
                    ->whereColumn('transactions.expense_id', 'expense_payments.expense_id')
                    ->whereNull('transactions.deleted_at'))
                ->exists();
    }

    /** "It's ours": the card is the company's own after all. */
    public function markCompanyCard(int $tenantId, string $lastFour, ?int $userId): OutsideCard
    {
        return $this->rule($tenantId, $lastFour, false, $userId);
    }

    /** The card is someone else's, whatever the evidence says. */
    public function mark(int $tenantId, string $lastFour, ?int $userId): OutsideCard
    {
        return $this->rule($tenantId, $lastFour, true, $userId);
    }

    protected function rule(int $tenantId, string $lastFour, bool $isOutside, ?int $userId): OutsideCard
    {
        $card = OutsideCard::query()->updateOrCreate(
            ['belongs_to_vendor_id' => $tenantId, 'last_four' => $lastFour],
            ['is_outside' => $isOutside, 'created_by_user_id' => $userId],
        );

        unset($this->overrides[$tenantId]);
        $this->reindex($tenantId, $lastFour);

        return $card;
    }

    /** @return array<string, bool> */
    protected function overrides(int $tenantId): array
    {
        return $this->overrides[$tenantId] ??= OutsideCard::query()
            ->where('belongs_to_vendor_id', $tenantId)
            ->pluck('is_outside', 'last_four')
            ->map(fn ($isOutside) => (bool) $isOutside)
            ->all();
    }

    /** Every expense paid with the card changes status, so the index follows. */
    protected function reindex(int $tenantId, string $lastFour): void
    {
        Expense::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('belongs_to_vendor_id', $tenantId)
            ->whereHas('payments', fn ($query) => $query->where('method', ExpensePayment::CARD)->where('last_four', $lastFour))
            ->searchable();
    }
}
