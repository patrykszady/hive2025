<?php

namespace App\Http\Resources\Api\App;

use App\Models\Check;
use App\Models\Client;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\ExpenseReceipts;
use App\Models\ExpenseSplits;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Expenses\ExpenseAssociationService;
use App\Services\Expenses\TransactionLinkService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * An expense as the app's Expenses tab shows it, mirroring the web's
 * ExpenseIndex row and ExpenseShow page — see
 * App\Services\Expenses\AppExpenseDirectory. withDetail() adds everything
 * the detail screen needs beyond the list row.
 *
 * `status`/`status_color` are read straight off Expense::status/statusColor
 * (the model's own accessors, built from the same calculateStatus() the web
 * uses) rather than re-derived here.
 *
 * @mixin \App\Models\Expense
 */
class ExpenseResource extends JsonResource
{
    protected bool $detail = false;

    public function withDetail(): static
    {
        $this->detail = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Expense $expense */
        $expense = $this->resource;
        $hasSplits = $expense->relationLoaded('splits') ? $expense->splits->isNotEmpty() : $expense->splits()->exists();

        $row = [
            'id' => $expense->id,
            'amount' => (float) $expense->amount,
            'date' => $expense->date?->format('Y-m-d'),
            'vendor' => $this->vendorPayload($expense),
            'project' => $this->projectPayload($expense),
            'is_split' => $hasSplits,
            'status' => $expense->status,
            'status_color' => $expense->statusColor,
            'invoice' => $expense->invoice,
            'note' => $expense->note,
            'reimbursement' => $expense->reimbursment,
            'has_receipt' => $expense->relationLoaded('receipts') ? $expense->receipts->isNotEmpty() : $expense->receipts()->exists(),
            'web_path' => '/expenses/'.$expense->id,
            // "Show deleted" + restore (ExpenseIndex::isShowingTrashed()):
            // true only inside a trashed listing/show, false otherwise.
            'trashed' => $expense->trashed(),
            // Split rows under their parent on the list, exactly like the
            // web's table (index.blade.php's "Show split rows if expense
            // has splits" block) — lightweight enough to always include,
            // same fields the detail payload already exposed below.
            'splits' => $this->splitsPayload($expense, $hasSplits),
        ];

        if (! $this->detail) {
            return $row;
        }

        $hubVendorId = (int) ($request->user()->vendor?->id ?? 0);
        $latestReceipt = $expense->relationLoaded('receipts')
            ? $expense->receipts->sortByDesc('created_at')->first()
            : $expense->receipts()->latest()->first();

        return $row + [
            'paid_by' => $this->userPayload($expense->paid_by),
            'created_by' => $this->userPayload($expense->created_by_user_id),
            // Raw select value ('Client', a user id, or 'V:{vendor_id}') so
            // the app's edit form can preselect the right option — `reimbursement`
            // above is the human-readable display string instead (Expense::reimbursment()'s accessor).
            'reimbursement_raw' => $expense->getRawOriginal('reimbursment'),
            // Mirrors ExpenseForm::setExpense(): is_material_order is read
            // off the latest receipt, not the expense row itself.
            'is_material_order' => (bool) ($latestReceipt?->is_material_order ?? false),
            // Only surfaced when it differs from the hub vendor — same `@elseif` the web's show page uses.
            'belongs_to_vendor' => ($expense->belongs_to_vendor_id && (int) $expense->belongs_to_vendor_id !== $hubVendorId)
                ? $this->vendorSummary($expense->belongs_to_vendor_id)
                : null,
            'belongs_to_client' => $expense->belongs_to_client_id
                ? $this->clientSummary($expense->belongs_to_client_id)
                : null,
            'receipts' => $expense->receipts->map(fn (ExpenseReceipts $receipt) => $this->receiptPayload($receipt))->values()->all(),
            // The matched bank charge(s) — mirrors the web's x-transactions.list_card,
            // fed by Expense::allTransactions() (own -> shared -> check).
            'transactions' => $expense->allTransactions()->map(fn (Transaction $transaction) => $this->transactionPayload($transaction))->values()->all(),
            // Payments that never reached the bank (rebate checks, store
            // credit, gift cards, points, cash) — mirrors the web's
            // x-transactions.list_card $payments rows (ExpensePayment::isOffBank()).
            'payments' => $expense->payments->filter(fn (ExpensePayment $payment) => $payment->isOffBank())
                ->sortBy('id')
                ->map(fn (ExpensePayment $payment) => $this->paymentPayload($payment, $expense))
                ->values()->all(),
            'checks' => app(ExpenseAssociationService::class)->linkedChecks($expense)->map(fn (Check $check) => $this->checkPayload($check))->values()->all(),
            // The expense's own singular check (not the merged list above) —
            // pre-fills the edit form's "Record a New Check" fields exactly
            // the way ExpenseForm::setExpense() does, so re-saving the
            // expense without touching them reuses this same check instead
            // of AppExpenseDirectory::resolveCheckOnUpdate() detaching it.
            'linked_check' => $expense->check ? [
                'bank_account_id' => $expense->check->bank_account_id,
                'bank_account_label' => $expense->check->bank_account?->getNameAndType(),
                'check_type' => $expense->check->check_type,
                'check_number' => $expense->check->check_type === 'Check' && $expense->check->check_number !== null
                    ? (string) $expense->check->check_number
                    : null,
            ] : null,
            'check_associated_expenses' => app(ExpenseAssociationService::class)->checkAssociatedExpenses($expense)->map(fn (Expense $other) => $this->associatedPayload($other))->values()->all(),
            'associated_expenses' => $expense->associated_expenses->map(fn (Expense $other) => $this->associatedPayload($other))->values()->all(),
            // Mirrors the @if wrapping <livewire:expenses.link-transaction>
            // on the web's expense page: offered only when nothing has
            // settled this expense yet (see TransactionLinkService::canOffer()).
            'can_link_transaction' => $request->user()->can('update', $expense)
                && app(TransactionLinkService::class)->canOffer($expense, app(ExpenseAssociationService::class)),
        ];
    }

    /**
     * @return list<array{id: int, amount: float, project: ?array{id: int, name: string}, distribution: ?array{id: int, name: string}, reimbursement: ?string, note: ?string}>
     */
    private function splitsPayload(Expense $expense, bool $hasSplits): array
    {
        if (! $hasSplits) {
            return [];
        }

        return $expense->splits->map(fn (ExpenseSplits $split) => [
            'id' => $split->id,
            'amount' => (float) $split->amount,
            'project' => $split->project ? ['id' => $split->project->id, 'name' => $split->project->project_name] : null,
            'distribution' => $split->distribution ? ['id' => $split->distribution->id, 'name' => $split->distribution->name] : null,
            'reimbursement' => $split->reimbursment,
            'note' => $split->note,
        ])->values()->all();
    }

    /**
     * @return array{id: int, check_number: ?string, date: ?string, amount: float, bank: ?string}
     */
    private function checkPayload(Check $check): array
    {
        return [
            'id' => $check->id,
            'check_number' => $check->check_number,
            'date' => $check->date?->format('Y-m-d'),
            'amount' => (float) $check->amount,
            'bank' => $check->bank_account?->bank?->name,
        ];
    }

    /**
     * @return array{id: int, amount: float, date: ?string, bank: ?string, account: ?string, description: ?string}
     */
    private function transactionPayload(Transaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'amount' => (float) $transaction->amount,
            'date' => $transaction->transaction_date?->format('Y-m-d'),
            'bank' => $transaction->bank_account?->bank?->name,
            'account' => $transaction->bank_account?->account_number,
            'description' => $transaction->plaid_merchant_name ?: $transaction->plaid_merchant_description,
        ];
    }

    /**
     * An off-bank payment row — mirrors ExpensePayment::methodName()/
     * notation() exactly, the same text the web's transaction card shows
     * under each one ("Paid without a bank charge · Store credit · from the
     * receipt").
     *
     * @return array{id: int, amount: float, method: string, label: string, last_four: ?string, paid_at: ?string, notation: string}
     */
    private function paymentPayload(ExpensePayment $payment, Expense $expense): array
    {
        return [
            'id' => $payment->id,
            'amount' => (float) $payment->amount,
            'method' => $payment->methodName(),
            'label' => $payment->label(),
            'last_four' => $payment->last_four,
            'paid_at' => ($payment->paid_at ?? $expense->date)?->format('Y-m-d'),
            'notation' => $payment->notation(),
        ];
    }

    /**
     * A sibling expense in "Linked Expenses" or "Associated Expenses" (other
     * expenses paid by the same check) — just enough to list and open it.
     *
     * @return array{id: int, amount: float, date: ?string, vendor: ?string, project: ?string, web_path: string}
     */
    private function associatedPayload(Expense $expense): array
    {
        return [
            'id' => $expense->id,
            'amount' => (float) $expense->amount,
            'date' => $expense->date?->format('Y-m-d'),
            'vendor' => $expense->vendor?->business_name,
            'project' => $expense->project?->project_name,
            'web_path' => '/expenses/'.$expense->id,
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function vendorPayload(Expense $expense): ?array
    {
        $vendor = $expense->vendor;

        return $vendor && $vendor->id ? ['id' => $vendor->id, 'name' => $vendor->business_name] : null;
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function projectPayload(Expense $expense): ?array
    {
        if ($expense->distribution_id && $expense->distribution) {
            return ['id' => null, 'name' => $expense->distribution->name, 'is_distribution' => true];
        }

        if (! $expense->project_id) {
            return null;
        }

        $project = $expense->project;

        return $project && $project->exists ? ['id' => $project->id, 'name' => $project->project_name] : null;
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function vendorSummary(int $vendorId): ?array
    {
        $vendor = Vendor::withoutGlobalScopes()->select(['id', 'business_name'])->find($vendorId);

        return $vendor ? ['id' => $vendor->id, 'name' => $vendor->business_name] : null;
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function clientSummary(int $clientId): ?array
    {
        // Client::name is a computed accessor (business_name, or built from
        // the users relation) — load it whole rather than a column list.
        $client = Client::query()->find($clientId);

        return $client ? ['id' => $client->id, 'name' => $client->name] : null;
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function userPayload(?int $userId): ?array
    {
        if (! $userId) {
            return null;
        }

        $user = User::query()->select(['id', 'first_name', 'last_name'])->find($userId);

        return $user ? ['id' => $user->id, 'name' => trim($user->first_name.' '.$user->last_name)] : null;
    }

    /**
     * A receipt's scan (a time-limited signed link — see
     * ReceiptController::receiptSigned(), so the app can show it without a
     * web session) plus its OCR line items, exactly as stored.
     *
     * @return array{id: int, url: string, is_material_order: bool, merchant_name: ?string, total: ?float, notes: ?string, items: list<mixed>, created_at: string}
     */
    private function receiptPayload(ExpenseReceipts $receipt): array
    {
        $items = $receipt->receipt_items ?? [];

        return [
            'id' => $receipt->id,
            'url' => URL::temporarySignedRoute('expenses.receipts.signed', now()->addHours(24), ['receipt' => $receipt->id]),
            'is_material_order' => (bool) $receipt->is_material_order,
            'merchant_name' => $items['merchant_name'] ?? null,
            'total' => isset($items['total']) ? (float) $items['total'] : null,
            'notes' => $receipt->notes,
            'items' => $items['items'] ?? [],
            'created_at' => $receipt->created_at?->toIso8601String(),
        ];
    }
}
