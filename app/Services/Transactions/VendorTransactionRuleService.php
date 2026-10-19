<?php

namespace App\Services\Transactions;

use App\Models\VendorTransaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The vendor-transaction match rules list and its CRUD — shared by
 * App\Livewire\Transactions\{VendorTransactionsPanel,VendorTransactionEditModal}
 * and the app API's VendorTransactionController, so the create/duplicate/
 * normalization rules only exist once.
 */
class VendorTransactionRuleService
{
    public const PER_PAGE = 25;

    /**
     * Named vendors first (alphabetical, case-blind), then the vendor-less
     * deposit/check/transfer rules — same order as
     * VendorTransactionsPanel::vendor_transactions().
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(string $search, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        $term = trim($search);

        return VendorTransaction::query()
            ->select([
                'vendor_transactions.id',
                'vendor_transactions.vendor_id',
                'vendor_transactions.deposit_check',
                'vendor_transactions.amount_sign',
                'vendor_transactions.plaid_inst_id',
                'vendor_transactions.desc',
                'vendor_transactions.options',
            ])
            ->leftJoin('vendors', 'vendors.id', '=', 'vendor_transactions.vendor_id')
            ->with(['vendor', 'bank'])
            ->when($term !== '', fn ($query) => $query->where(fn ($where) => $where
                ->where('vendor_transactions.desc', 'like', "%{$term}%")
                ->orWhere('vendors.business_name', 'like', "%{$term}%")))
            ->orderByRaw('vendors.business_name IS NULL')
            ->orderByRaw('LOWER(vendors.business_name)')
            ->orderBy('vendor_transactions.id')
            ->paginate($perPage)
            ->through(fn (VendorTransaction $transaction): array => [
                'id' => $transaction->id,
                'vendor' => $transaction->vendor ? [
                    'id' => $transaction->vendor->id,
                    'business_name' => $transaction->vendor->business_name,
                ] : null,
                'bank' => $transaction->bank ? [
                    'id' => $transaction->bank->id,
                    'name' => $transaction->bank->name,
                    'plaid_ins_id' => $transaction->bank->plaid_ins_id,
                ] : null,
                'desc' => $transaction->desc,
                'deposit_check' => $transaction->deposit_check,
                'deposit_check_label' => $this->depositCheckLabel($transaction->deposit_check),
                'amount_sign' => $transaction->amount_sign,
                'plaid_inst_id' => $transaction->plaid_inst_id,
                'options' => $transaction->options,
            ]);
    }

    /**
     * @param  array{vendor_id?: mixed, deposit_check?: mixed, amount_sign?: mixed, plaid_inst_id?: mixed, desc: mixed, options?: mixed}  $raw
     * @return array{vendor_id: ?int, deposit_check: ?int, amount_sign: ?int, plaid_inst_id: ?string, desc: string, options: string}
     */
    public function buildPayload(array $raw): array
    {
        return [
            'vendor_id' => $this->normalizeValue('vendor_id', $raw['vendor_id'] ?? ''),
            'deposit_check' => $this->normalizeValue('deposit_check', $raw['deposit_check'] ?? ''),
            'amount_sign' => $this->normalizeValue('amount_sign', $raw['amount_sign'] ?? ''),
            'plaid_inst_id' => (($raw['plaid_inst_id'] ?? '') !== '') ? trim((string) $raw['plaid_inst_id']) : null,
            'desc' => trim((string) ($raw['desc'] ?? '')),
            'options' => $this->formatOptions($raw['options'] ?? null),
        ];
    }

    /**
     * @param  array{vendor_id: ?int, deposit_check: ?int, amount_sign: ?int, plaid_inst_id: ?string, desc: string, options: string}  $payload
     */
    public function exists(array $payload, ?int $ignoreId = null): bool
    {
        return VendorTransaction::query()
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->where('desc', $payload['desc'])
            ->where('options', $payload['options'])
            ->where(fn ($query) => $payload['vendor_id'] === null
                ? $query->whereNull('vendor_id')
                : $query->where('vendor_id', $payload['vendor_id']))
            ->where(fn ($query) => $payload['deposit_check'] === null
                ? $query->whereNull('deposit_check')
                : $query->where('deposit_check', $payload['deposit_check']))
            ->where(fn ($query) => $payload['amount_sign'] === null
                ? $query->whereNull('amount_sign')
                : $query->where('amount_sign', $payload['amount_sign']))
            ->where(fn ($query) => $payload['plaid_inst_id'] === null
                ? $query->whereNull('plaid_inst_id')
                : $query->where('plaid_inst_id', $payload['plaid_inst_id']))
            ->exists();
    }

    /**
     * @param  array{vendor_id: ?int, deposit_check: ?int, amount_sign: ?int, plaid_inst_id: ?string, desc: string, options: string}  $payload
     */
    public function create(array $payload): VendorTransaction
    {
        return VendorTransaction::create($payload);
    }

    /**
     * @param  array{vendor_id: ?int, deposit_check: ?int, amount_sign: ?int, plaid_inst_id: ?string, desc: string, options: string}  $payload
     */
    public function update(VendorTransaction $rule, array $payload): void
    {
        $rule->vendor_id = $payload['vendor_id'];
        $rule->desc = $payload['desc'];
        $rule->deposit_check = $payload['deposit_check'];
        $rule->amount_sign = $payload['amount_sign'];
        $rule->plaid_inst_id = $payload['plaid_inst_id'];
        $rule->options = $payload['options'];
        $rule->save();
    }

    public function delete(VendorTransaction $rule): void
    {
        $rule->delete();
    }

    /**
     * @return array<string, string>
     */
    public function depositCheckOptions(): array
    {
        return [
            '' => 'None',
            '1' => 'Deposit',
            '2' => 'Check Paid',
            '3' => 'Transfer/Zelle Out',
            '4' => 'Cash Withdrawal',
        ];
    }

    public function depositCheckLabel(?int $value): string
    {
        return $this->depositCheckOptions()[(string) $value] ?? 'Unknown';
    }

    public function formatOptions(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return json_encode('/i');
        }

        return json_encode(trim($value).'/i');
    }

    private function normalizeValue(string $field, mixed $value): mixed
    {
        if ($value === '' || $value === null) {
            return null;
        }

        if (in_array($field, ['vendor_id', 'deposit_check', 'amount_sign'], true)) {
            return (int) $value;
        }

        return $value;
    }
}
