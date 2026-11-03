<?php

namespace App\Services\Receipts;

use App\Models\Receipt;
use App\Models\ReceiptAccount;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Email receipt-PARSING patterns — matched against forwarded vendor emails
 * by App\Http\Controllers\CompanyEmailController to auto-create an expense
 * (NOT an expense's own uploaded receipt image/PDF, a different model).
 * Shared by the web's /receipts page (App\Livewire\Receipts\ReceiptsIndex)
 * and the app's Receipt Patterns screen (App\Http\Controllers\Api\App\V1\
 * ReceiptPatternController), so both list/create/edit/delete these rows
 * identically. Patryk (user id 1) only, same convention as Agents/
 * AgentController — see ReceiptPatternController's own docblock for why
 * the app locks reads to id 1 too, even though the web route itself
 * doesn't.
 */
class ReceiptPatternDirectory
{
    private const PER_PAGE = 25;

    /**
     * ReceiptsIndex::receipts() — $page left null lets Livewire's own
     * WithPagination resolver decide the page (as it always has); the app
     * controller passes its own `?page=` query value instead.
     */
    public function list(string $search, ?int $page = null): LengthAwarePaginator
    {
        $query = Receipt::query()
            ->with('vendor')
            ->whereNotNull('receipt_type')
            ->where('receipt_type', '!=', 0)
            ->orderBy('id', 'desc');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('from_address', 'like', "%{$search}%")
                    ->orWhere('from_subject', 'like', "%{$search}%")
                    ->orWhereHas('vendor', fn ($vq) => $vq->where('business_name', 'like', "%{$search}%"));
            });
        }

        return $query->paginate(self::PER_PAGE, ['*'], 'page', $page);
    }

    /**
     * ReceiptsIndex::vendors() — every vendor is a valid "From" match, not
     * just this company's own tree (withoutGlobalScopes, same as the web).
     *
     * @return Collection<int, Vendor>
     */
    public function vendors(): Collection
    {
        return Vendor::withoutGlobalScopes()->orderBy('business_name')->get(['id', 'business_name']);
    }

    /**
     * ReceiptsIndex::store() when creating — the same from_type inference,
     * subject/options cleanup.
     *
     * @param  array{vendor_id: int, from_address: string, from_subjects?: array<int, string|null>, receipt_type: int|string, options?: array<string, mixed>}  $data
     */
    public function create(array $data): Receipt
    {
        $receipt = Receipt::create($this->attributesFromForm($data));
        $this->linkVendorToCompany((int) $receipt->vendor_id);

        return $receipt;
    }

    /**
     * @param  array{vendor_id: int, from_address: string, from_subjects?: array<int, string|null>, receipt_type: int|string, options?: array<string, mixed>}  $data
     */
    public function update(Receipt $receipt, array $data): Receipt
    {
        // Through the model so the array casts encode from_subject and
        // options; a query-builder update() skips casts and fails on them
        // (ReceiptsIndex::store() hit this before — see its own history).
        $receipt->update($this->attributesFromForm($data));
        $this->linkVendorToCompany((int) $receipt->vendor_id);

        return $receipt;
    }

    /**
     * A rule only reaches a mailbox through the company's receipt account for
     * its vendor (CompanyEmail::receipts() goes through receipt_accounts), and
     * nothing created that account: a rule saved here for a new vendor was
     * silently never forwarded (2026-11-02, City of Rolling Meadows). Saving
     * a rule now links its vendor to the signed-in company, once.
     */
    private function linkVendorToCompany(int $vendorId): void
    {
        $companyVendorId = auth()->user()?->vendor?->id;
        if (! $companyVendorId || ! $vendorId) {
            return;
        }

        ReceiptAccount::withoutGlobalScopes()->firstOrCreate([
            'vendor_id' => $vendorId,
            'belongs_to_vendor_id' => $companyVendorId,
        ]);
    }

    public function delete(Receipt $receipt): void
    {
        $receipt->delete();
    }

    /**
     * @param  array{vendor_id: int, from_address: string, from_subjects?: array<int, string|null>, receipt_type: int|string, options?: array<string, mixed>}  $data
     * @return array<string, mixed>
     */
    private function attributesFromForm(array $data): array
    {
        $fromAddress = (string) $data['from_address'];
        // Determine from_type based on from_address pattern.
        $fromType = str_starts_with($fromAddress, '@') ? 2 : 3;

        // Filter out empty option values.
        $cleanOptions = array_filter($data['options'] ?? [], fn ($value) => $value !== null && $value !== '');

        $cleanSubjects = array_values(array_filter(
            array_map('trim', array_map('strval', $data['from_subjects'] ?? [])),
            fn (string $value) => $value !== ''
        ));

        return [
            'vendor_id' => $data['vendor_id'],
            'from_type' => $fromType,
            'from_address' => $fromAddress,
            'from_subject' => empty($cleanSubjects) ? null : $cleanSubjects,
            'receipt_type' => $data['receipt_type'],
            'options' => $cleanOptions ?: (object) [],
        ];
    }
}
