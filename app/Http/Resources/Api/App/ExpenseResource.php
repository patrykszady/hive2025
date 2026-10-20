<?php

namespace App\Http\Resources\Api\App;

use App\Models\Check;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\ExpenseSplits;
use App\Models\User;
use App\Services\Expenses\ExpenseAssociationService;
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
        ];

        if (! $this->detail) {
            return $row;
        }

        return $row + [
            'paid_by' => $this->userPayload($expense->paid_by),
            'created_by' => $this->userPayload($expense->created_by_user_id),
            'splits' => $hasSplits ? $expense->splits->map(fn (ExpenseSplits $split) => [
                'id' => $split->id,
                'amount' => (float) $split->amount,
                'project' => $split->project ? ['id' => $split->project->id, 'name' => $split->project->project_name] : null,
                'distribution' => $split->distribution ? ['id' => $split->distribution->id, 'name' => $split->distribution->name] : null,
                'reimbursement' => $split->reimbursment,
                'note' => $split->note,
            ])->values()->all() : [],
            'receipts' => $expense->receipts->map(fn (ExpenseReceipts $receipt) => $this->receiptPayload($receipt))->values()->all(),
            'checks' => app(ExpenseAssociationService::class)->linkedChecks($expense)->map(fn (Check $check) => $this->checkPayload($check))->values()->all(),
            'check_associated_expenses' => app(ExpenseAssociationService::class)->checkAssociatedExpenses($expense)->map(fn (Expense $other) => $this->associatedPayload($other))->values()->all(),
            'associated_expenses' => $expense->associated_expenses->map(fn (Expense $other) => $this->associatedPayload($other))->values()->all(),
        ];
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
