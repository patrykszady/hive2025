<?php

namespace App\Services\Expenses;

use App\Models\Expense;
use App\Models\ExpenseReceipts;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Folds an invoice that was entered as its own expense into the expense that
 * paid it. National Construction Rentals sends an invoice and, later, a
 * payment confirmation listing the invoices it paid; both became expenses, so
 * the invoice was counted twice and sat at "No Transaction" forever (28411,
 * 27334, 22107, 27503 — removed by hand on 2026-10-21). Either may arrive
 * first, by email or uploaded by hand.
 *
 * A paid expense (one with a bank charge or a check) whose receipt carries a
 * "Paid Invoice List" takes every unpaid expense of the same vendor and
 * company filed under one of those invoice numbers. An unpaid invoice whose
 * "Ticket #" is a paid expense's invoice number, for the same amount, folds in
 * too (invoice 8238410 for ticket 1904464). The invoice's PDF moves onto the
 * paid expense, which also takes its project and invoice number when it has
 * none, and the invoice expense is soft-deleted the way ExpenseForm::delete()
 * does it.
 */
class PaidInvoiceMerger
{
    /**
     * @return list<array{invoice_expense_id: int, payment_expense_id: int, invoice: string, amount: string, rule: string}>
     */
    public function run(bool $dryRun = false): array
    {
        $merges = [];

        foreach ($this->paidInvoiceLists() as $paymentId => $invoiceNumbers) {
            $payment = $this->paidExpense($paymentId);

            if (! $payment) {
                continue;
            }

            $invoices = $this->unpaidExpenses($payment)->whereIn('invoice', $invoiceNumbers)->get();

            foreach ($invoices as $invoice) {
                $merges[] = $this->merge($invoice, $payment, 'paid_invoice_list', $dryRun);
            }
        }

        foreach ($this->ticketInvoices() as $invoiceId => $ticket) {
            $invoice = Expense::withoutGlobalScopes()->find($invoiceId);

            if (! $invoice || $this->isPaid($invoice)) {
                continue;
            }

            $payment = Expense::withoutGlobalScopes()
                ->where('vendor_id', $invoice->vendor_id)
                ->where('belongs_to_vendor_id', $invoice->belongs_to_vendor_id)
                ->where('invoice', $ticket)
                ->where('amount', $invoice->amount)
                ->whereKeyNot($invoice->id)
                ->get()
                ->first(fn (Expense $candidate) => $this->isPaid($candidate));

            if ($payment) {
                $merges[] = $this->merge($invoice, $payment, 'ticket', $dryRun);
            }
        }

        return $merges;
    }

    /**
     * Invoice numbers a payment confirmation says it paid, by expense id.
     *
     * @return array<int, list<string>>
     */
    private function paidInvoiceLists(): array
    {
        $lists = [];

        $this->receiptsMentioning('Paid Invoice List')->each(function (ExpenseReceipts $receipt) use (&$lists): void {
            $numbers = self::paidInvoiceNumbers($this->receiptText($receipt));

            if ($numbers !== []) {
                $lists[$receipt->expense_id] = array_values(array_unique(array_merge($lists[$receipt->expense_id] ?? [], $numbers)));
            }
        });

        return $lists;
    }

    /**
     * Invoices that name the rental ticket they bill, by expense id.
     *
     * @return array<int, string>
     */
    private function ticketInvoices(): array
    {
        $tickets = [];

        $this->receiptsMentioning('Ticket #')->each(function (ExpenseReceipts $receipt) use (&$tickets): void {
            if (preg_match('/Ticket\s*#\s*(\d{5,})/i', $this->receiptText($receipt), $match)) {
                $tickets[$receipt->expense_id] = $match[1];
            }
        });

        return $tickets;
    }

    /**
     * The invoice numbers under a "Paid Invoice List" heading. The job site
     * ("JobSite: 30200012-0004") and street numbers are not invoices.
     *
     * @return list<string>
     */
    public static function paidInvoiceNumbers(string $text): array
    {
        $position = stripos($text, 'Paid Invoice List');

        if ($position === false) {
            return [];
        }

        $list = substr($text, $position + strlen('Paid Invoice List'));
        $list = preg_replace('/JobSite:\s*\S+/i', ' ', $list);

        preg_match_all('/(?<![\d-])(\d{6,10})(?![\d-])/', (string) $list, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @return Collection<int, ExpenseReceipts>
     */
    private function receiptsMentioning(string $phrase): Collection
    {
        return ExpenseReceipts::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereNotNull('expense_id')
            ->where(fn ($query) => $query->where('receipt_items', 'like', '%'.$phrase.'%')->orWhere('receipt_html', 'like', '%'.$phrase.'%'))
            ->get(['id', 'expense_id', 'receipt_items', 'receipt_html']);
    }

    private function receiptText(ExpenseReceipts $receipt): string
    {
        $raw = (string) (($receipt->receipt_items ?? [])['raw_content'] ?? '');

        if ($raw === '') {
            $raw = strip_tags(html_entity_decode((string) $receipt->receipt_html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return (string) preg_replace('/\s+/', ' ', $raw);
    }

    private function paidExpense(int $id): ?Expense
    {
        $expense = Expense::withoutGlobalScopes()->find($id);

        return $expense && $this->isPaid($expense) ? $expense : null;
    }

    /** Paid: a bank charge, a shared charge or a check stands behind it. */
    private function isPaid(Expense $expense): bool
    {
        return $expense->transactions()->exists()
            || $expense->sharedTransactions()->exists()
            || $expense->checks()->exists()
            || $expense->check_id !== null;
    }

    /**
     * Same vendor and company, nothing paying it, not a reimbursement.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Expense>
     */
    private function unpaidExpenses(Expense $payment)
    {
        return Expense::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('vendor_id', $payment->vendor_id)
            ->where('belongs_to_vendor_id', $payment->belongs_to_vendor_id)
            ->whereKeyNot($payment->id)
            ->whereNull('paid_by')
            ->whereNull('check_id')
            ->whereDoesntHave('transactions')
            ->whereDoesntHave('sharedTransactions')
            ->whereDoesntHave('checks');
    }

    /**
     * @return array{invoice_expense_id: int, payment_expense_id: int, invoice: string, amount: string, rule: string}
     */
    private function merge(Expense $invoice, Expense $payment, string $rule, bool $dryRun): array
    {
        $result = [
            'invoice_expense_id' => $invoice->id,
            'payment_expense_id' => $payment->id,
            'invoice' => (string) $invoice->invoice,
            'amount' => (string) $invoice->amount,
            'rule' => $rule,
        ];

        if ($dryRun) {
            return $result;
        }

        DB::transaction(function () use ($invoice, $payment): void {
            ExpenseReceipts::withoutGlobalScopes()
                ->where('expense_id', $invoice->id)
                ->get()
                ->each(function (ExpenseReceipts $receipt) use ($payment): void {
                    $receipt->expense_id = $payment->id;
                    $receipt->save();
                });

            $hasAllocation = $payment->project_id || $payment->distribution_id || $payment->splits()->exists();

            if (! $hasAllocation && $invoice->project_id) {
                $payment->project_id = $invoice->project_id;
            }

            if (blank($payment->invoice) && filled($invoice->invoice)) {
                $payment->invoice = $invoice->invoice;
            }

            $payment->save();

            foreach ($invoice->associated as $associated) {
                $associated->parent_expense_id = null;
                $associated->save();
            }

            foreach ($invoice->splits as $split) {
                $split->delete();
            }

            $invoice->delete();
        });

        $payment->searchable();

        Log::info('Folded an invoice expense into the expense that paid it', $result);

        return $result;
    }
}
