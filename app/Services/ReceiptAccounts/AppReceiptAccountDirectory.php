<?php

namespace App\Services\ReceiptAccounts;

use App\Jobs\TransactionVendorBulkMatchJob;
use App\Models\Distribution;
use App\Models\ReceiptAccount;
use App\Models\TransactionBulkMatch;
use App\Models\Vendor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;

/**
 * The app's Vendor Match screen — the same vendor list, credentials and
 * bulk-match rules as the web's /vendor_match page
 * (App\Livewire\ReceiptAccounts\{ReceiptAccountsIndex,
 * ReceiptAccountVendorCreate}). The "API login" button (a receipt site's
 * own sign-in page, e.g. Menards) stays web-only — there is no browser to
 * drive it from here.
 */
class AppReceiptAccountDirectory
{
    /** ReceiptAccountsIndex::vendors(), scoped to the signed-in company via transactions_bulk_match's withCount filter. */
    public function vendors(Vendor $company): Collection
    {
        return Vendor::query()
            ->with(['receipt_account'])
            ->withCount([
                'transactions_bulk_match' => fn ($query) => $query->where('belongs_to_vendor_id', $company->id),
                'receipts',
            ])
            ->where(fn ($query) => $query->whereHas('receipt_account')->orWhereHas('receipts'))
            ->orderBy('business_name')
            ->get();
    }

    /** ReceiptAccountsIndex::mount()'s `new_vendors` — retail vendors not already in the Vendor Match list. */
    public function newVendors(Vendor $company): Collection
    {
        $existingIds = $this->vendors($company)->pluck('id');

        return Vendor::where('business_type', 'Retail')->whereNotIn('id', $existingIds)->get();
    }

    /** @return Collection<int, Distribution> */
    public function distributions(): Collection
    {
        return Distribution::all();
    }

    /**
     * ReceiptAccountVendorCreate::editReceiptVendor(), reshaped for a GET
     * response. Secret credential values (password/encrypted fields) are
     * never sent back — blank in the response means "unchanged if left
     * blank on save", same as the web's own save() comment intends (its
     * component property is in fact filled with the decrypted secret,
     * which this deliberately does not repeat over an API response).
     *
     * @return array{credential_fields: list<array<string, mixed>>, credential_values: array<string, string>, bulk_matches: list<array<string, mixed>>, vendor_transactions: list<array<string, mixed>>, distributions: Collection<int, Distribution>}
     */
    public function detail(Vendor $vendor, Vendor $company): array
    {
        $vendor->loadMissing(['transactions', 'receipts', 'receipt_account']);
        $bulkMatches = $vendor->transactions_bulk_match()->where('belongs_to_vendor_id', $company->id)->get();

        $credentialFields = (array) ($vendor->receipts->first()?->options['credential_fields'] ?? []);
        $options = $vendor->receipt_account->options ?? [];
        $credentialValues = [];
        foreach ($credentialFields as $field) {
            $key = $field['key'];
            $isSecret = ($field['encrypted'] ?? false) || ($field['type'] ?? '') === 'password';
            $credentialValues[$key] = $isSecret ? '' : (string) ($options[$key] ?? '');
        }

        return [
            'credential_fields' => $credentialFields,
            'credential_values' => $credentialValues,
            'bulk_matches' => $bulkMatches->map(fn (TransactionBulkMatch $match) => [
                'id' => $match->id,
                'amount' => $match->amount,
                'distribution_id' => $match->distribution_id,
                'options' => $match->options ?? ['amount_type' => 'ANY', 'desc' => null],
                'split' => isset($match->options['splits']) && ! empty($match->options['splits']),
                'splits' => $match->options['splits'] ?? null,
            ])->values()->all(),
            'vendor_transactions' => $this->vendorTransactionsSummary($vendor),
            'distributions' => $this->distributions(),
        ];
    }

    /** ReceiptAccountVendorCreate::editReceiptVendor()'s `vendor_transactions` summary. */
    private function vendorTransactionsSummary(Vendor $vendor): array
    {
        return $vendor->transactions()
            ->with(['expense.distribution'])
            ->get()
            ->groupBy('amount')
            ->filter(fn ($group) => $group->contains(fn ($t) => is_null($t->expense)) || $group->count() >= 2)
            ->map(function ($group, $amount) {
                $distributionsCount = $group
                    ->groupBy(fn ($t) => $t->expense && ! is_null($t->expense->distribution_id)
                        ? ($t->expense->distribution->name ?? 'Unknown Distribution')
                        : 'No Distribution')
                    ->map->count();

                return ['amount' => (float) $amount, 'count' => $group->count(), 'distributions_count' => $distributionsCount->all()];
            })
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * ReceiptAccountVendorCreate::store(), including finding #10's security
     * fix verbatim: a bulk-match id is only ever reused when it already
     * belongs to this company — a tampered/foreign id creates a new rule
     * instead of hijacking someone else's (see
     * tests/Feature/Security/T2/ReceiptAccountVendorSecurityTest.php, run
     * unchanged against the web component after this refactor).
     *
     * @param  array<string, string>  $credentialValues
     * @param  list<array{id: ?int, amount: mixed, distribution_id: ?int, options: array<string, mixed>, splits: ?list<array<string, mixed>>}>  $bulkMatches
     */
    public function save(Vendor $vendor, Vendor $company, array $credentialFields, array $credentialValues, array $bulkMatches): void
    {
        $receiptAccount = $vendor->receipt_account ?? new ReceiptAccount();
        $receiptAccount->belongs_to_vendor_id = $company->id;
        $receiptAccount->vendor_id = $vendor->id;

        $existingOptions = $receiptAccount->options ?? [];
        foreach ($credentialFields as $field) {
            $key = $field['key'];
            $value = $credentialValues[$key] ?? '';
            if ($value === '') {
                continue; // Skip empty — preserves existing saved value.
            }
            $existingOptions[$key] = (($field['encrypted'] ?? false) || ($field['type'] ?? '') === 'password')
                ? Crypt::encryptString($value)
                : $value;
        }
        $receiptAccount->options = $existingOptions;
        $receiptAccount->save();

        $keptIds = collect($bulkMatches)->pluck('id')->filter()->all();
        $vendor->transactions_bulk_match()
            ->where('belongs_to_vendor_id', $company->id)
            ->whereNotIn('id', $keptIds)
            ->get()
            ->each(fn (TransactionBulkMatch $match) => $match->delete());

        foreach ($bulkMatches as $data) {
            $match = ! empty($data['id'])
                ? TransactionBulkMatch::where('belongs_to_vendor_id', $company->id)->find($data['id'])
                : null;
            $match ??= new TransactionBulkMatch();

            $match->vendor_id = $vendor->id;
            $match->amount = $data['amount'] ?? null;
            $match->distribution_id = $data['distribution_id'] ?? null;
            $match->options = array_merge($data['options'] ?? [], ['splits' => $data['splits'] ?? []]);
            $match->belongs_to_vendor_id = $company->id;
            $match->save();
        }

        TransactionVendorBulkMatchJob::dispatch();
    }
}
