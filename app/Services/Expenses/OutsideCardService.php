<?php

namespace App\Services\Expenses;

use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\OutsideCard;

/**
 * Marking a card on a receipt as not the company's own (App\Models\
 * OutsideCard). Card ••1854 paid two Amazon orders in March 2026 (expenses
 * 26499 and 26525) and is on none of GS Construction's bank accounts, so
 * both sat at "No Transaction" while the matcher hunted for a charge that
 * could never come. A marked card applies to every expense paid with it.
 */
class OutsideCardService
{
    /**
     * Cards on $expense's payment lines that may be marked: not marked yet,
     * and never seen on one of the company's bank charges — a card whose
     * expenses have been linked to a charge is the company's own.
     *
     * @return list<string>
     */
    public function candidates(Expense $expense): array
    {
        $tenantId = (int) $expense->belongs_to_vendor_id;

        return $expense->payments()
            ->where('method', ExpensePayment::CARD)
            ->whereNotNull('last_four')
            ->pluck('last_four')
            ->unique()
            ->reject(fn (string $lastFour) => OutsideCard::isOutside($tenantId, $lastFour) || $this->isCompanyCard($tenantId, $lastFour))
            ->values()
            ->all();
    }

    /** Some expense paid with this card is linked to one of the company's bank charges. */
    public function isCompanyCard(int $tenantId, string $lastFour): bool
    {
        return ExpensePayment::query()
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

    public function mark(int $tenantId, string $lastFour, ?int $userId): OutsideCard
    {
        $card = OutsideCard::query()->firstOrCreate(
            ['belongs_to_vendor_id' => $tenantId, 'last_four' => $lastFour],
            ['created_by_user_id' => $userId],
        );

        $this->reindex($tenantId, $lastFour);

        return $card;
    }

    public function unmark(int $tenantId, string $lastFour): void
    {
        OutsideCard::query()
            ->where('belongs_to_vendor_id', $tenantId)
            ->where('last_four', $lastFour)
            ->delete();

        $this->reindex($tenantId, $lastFour);
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
