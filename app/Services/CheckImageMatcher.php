<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\Check;
use App\Models\CheckImage;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Links a CheckImage (scanned check cropped from a bank statement) to the
 * Check record it depicts, or to the cleared bank Transaction when no Check
 * record exists yet.
 *
 * Read-only against the domain: writes ONLY to check_images. In particular it
 * never sets transactions.check_id — the scheduled
 * TransactionController::add_check_id_to_transactions pass owns that link.
 *
 * Matching ladder (house rule: link only when exactly ONE candidate survives):
 *   A. unique Check by (MICR-candidate accounts, check_type=Check, number, exact
 *      amount, written-date window [cleared-180d, cleared+7d])   → matched_check
 *   B. same without the amount filter, unique                    → matched_check_number_only
 *   C. unique cleared Transaction by (bank-sibling accounts, non-sentinel
 *      check_number, exact amount, ±7d of cleared date)          → matched_transaction
 *      (adopts the transaction's own check_id when it has one)
 *   D. multiple candidates anywhere                              → ambiguous
 *   E. nothing                                                   → unmatched
 */
class CheckImageMatcher
{
    /** transactions.check_number placeholders (Transfer / Cash / Plaid junk). */
    private const SENTINEL_CHECK_NUMBERS = ['1010101', '2020202', '0', '0000'];

    /** Checks are written up to months before they clear. */
    private const WRITTEN_DATE_LOOKBACK_DAYS = 180;

    private const WRITTEN_DATE_LOOKAHEAD_DAYS = 7;

    /** A cleared transaction posts within days of the statement's cleared date. */
    private const TRANSACTION_DATE_WINDOW_DAYS = 7;

    /**
     * @return array{status: string, details: array<string, mixed>} what was (or would be) recorded
     */
    public function match(CheckImage $image, bool $dryRun = false): array
    {
        if (! $image->isMatchable()) {
            return ['status' => $image->match_status, 'details' => ['reason' => 'already linked or manual']];
        }

        if (! $image->check_number) {
            return $this->record($image, CheckImage::STATUS_UNMATCHED, ['reason' => 'no check number extracted'], $dryRun);
        }

        $accountIds = $this->candidateBankAccountIds($image);

        // ── Tier A: unique check with exact amount ─────────────────────────
        $numberMatches = $this->checkCandidates($image, $accountIds)->get();
        $exactMatches  = $image->amount !== null
            ? $numberMatches->filter(fn (Check $c) => $c->amount !== null && abs((float) $c->amount - (float) $image->amount) < 0.005)->values()
            : collect();

        if ($exactMatches->count() > 1) {
            $exactMatches = $this->narrowSameBankDuplicates($exactMatches);
        }

        if ($exactMatches->count() === 1) {
            return $this->link($image, $exactMatches->first(), null, CheckImage::STATUS_MATCHED_CHECK, [
                'tier'   => 'check_exact',
                'reason' => 'unique check: number + exact amount' . ($accountIds->isNotEmpty() ? ' + account last-4' : ''),
            ], $dryRun);
        }

        if ($exactMatches->count() > 1) {
            return $this->record($image, CheckImage::STATUS_AMBIGUOUS, [
                'tier'          => 'check_exact',
                'reason'        => 'multiple checks share number + amount',
                'candidate_ids' => $exactMatches->pluck('id')->all(),
            ], $dryRun);
        }

        // ── Tier B: unique check by number only (OCR/entry amount drift, or
        //    checks whose amount is still null) — flagged for review. ────────
        if ($numberMatches->count() === 1) {
            $check = $numberMatches->first();

            return $this->link($image, $check, null, CheckImage::STATUS_MATCHED_CHECK_NUMBER_ONLY, [
                'tier'         => 'check_number_only',
                'reason'       => 'unique check by number; amount differs',
                'check_amount' => $check->amount,
                'image_amount' => $image->amount,
            ], $dryRun);
        }

        if ($numberMatches->count() > 1) {
            return $this->record($image, CheckImage::STATUS_AMBIGUOUS, [
                'tier'          => 'check_number_only',
                'reason'        => 'multiple checks share this number',
                'candidate_ids' => $numberMatches->pluck('id')->all(),
            ], $dryRun);
        }

        // ── Tier C: cleared bank transaction (check record doesn't exist) ──
        $transactions = $this->transactionCandidates($image, $accountIds)->get();

        if ($transactions->count() === 1) {
            $transaction = $transactions->first();
            $check       = $transaction->check_id
                ? Check::withoutGlobalScopes()->whereNull('deleted_at')->find($transaction->check_id)
                : null;

            return $this->link($image, $check, $transaction, CheckImage::STATUS_MATCHED_TRANSACTION, [
                'tier'   => 'transaction',
                'reason' => 'unique cleared transaction by check number + amount' . ($check ? ' (adopted its check link)' : ''),
            ], $dryRun);
        }

        if ($transactions->count() > 1) {
            return $this->record($image, CheckImage::STATUS_AMBIGUOUS, [
                'tier'          => 'transaction',
                'reason'        => 'multiple transactions share number + amount',
                'candidate_ids' => $transactions->pluck('id')->all(),
            ], $dryRun);
        }

        return $this->record($image, CheckImage::STATUS_UNMATCHED, [
            'reason'              => 'no check or transaction candidate',
            'account_candidates'  => $accountIds->all(),
        ], $dryRun);
    }

    /**
     * Bank accounts whose stored last-4 matches the image's account digits.
     * account_number is int(4) unsigned zerofill live but plain int in some
     * environments — compare as integers, never strings.
     *
     * @return Collection<int, int>
     */
    private function candidateBankAccountIds(CheckImage $image): Collection
    {
        $digits = preg_replace('/\D/', '', (string) $image->account_number);

        if ($digits === '') {
            return collect();
        }

        $last4 = (int) substr($digits, -4);

        return BankAccount::withoutGlobalScopes()
            ->withTrashed()
            ->get(['id', 'account_number'])
            ->filter(fn (BankAccount $account) => (int) $account->getRawOriginal('account_number') === $last4)
            ->pluck('id')
            ->values();
    }

    private function checkCandidates(CheckImage $image, Collection $accountIds)
    {
        // Raw check_number column + check_type filter: the checkNumber accessor
        // returns the row id for non-Check types (Transfer/Cash/Returned Check).
        return Check::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('check_type', 'Check')
            ->where('check_number', (int) $image->check_number)
            ->when($accountIds->isNotEmpty(), fn ($q) => $q->whereIn('bank_account_id', $accountIds))
            ->when($image->check_date, function ($q) use ($image) {
                // checks.date is the WRITTEN date; the image date is the CLEARED date.
                $cleared = Carbon::parse($image->check_date);
                $q->whereBetween('date', [
                    $cleared->copy()->subDays(self::WRITTEN_DATE_LOOKBACK_DAYS)->toDateString(),
                    $cleared->copy()->addDays(self::WRITTEN_DATE_LOOKAHEAD_DAYS)->toDateString(),
                ]);
            });
    }

    private function transactionCandidates(CheckImage $image, Collection $accountIds)
    {
        $siblingAccountIds = $this->bankSiblingAccountIds($accountIds);

        return Transaction::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereNull('deposit')
            ->whereNotNull('check_number')
            ->whereNotIn('check_number', self::SENTINEL_CHECK_NUMBERS)
            ->whereRaw('CAST(check_number AS UNSIGNED) = ?', [(int) $image->check_number])
            ->when($image->amount !== null, fn ($q) => $q->where('amount', $image->amount))
            ->when($siblingAccountIds->isNotEmpty(), fn ($q) => $q->whereIn('bank_account_id', $siblingAccountIds))
            // Returned-check reversals are not the cleared payment.
            ->where(fn ($q) => $q->whereNull('plaid_merchant_description')
                ->orWhere('plaid_merchant_description', 'NOT LIKE', '%RETURNED%'))
            ->when($image->check_date, function ($q) use ($image) {
                $cleared = Carbon::parse($image->check_date);
                $q->whereBetween('transaction_date', [
                    $cleared->copy()->subDays(self::TRANSACTION_DATE_WINDOW_DAYS)->toDateString(),
                    $cleared->copy()->addDays(self::TRANSACTION_DATE_WINDOW_DAYS)->toDateString(),
                ]);
            });
    }

    /**
     * Expand candidate accounts to every account of the same banks — check
     * numbers are unique per bank, not per account (re-linked Plaid items
     * produce duplicate accounts like Citibank #10/#17).
     *
     * @param  Collection<int, int>  $accountIds
     * @return Collection<int, int>
     */
    private function bankSiblingAccountIds(Collection $accountIds): Collection
    {
        if ($accountIds->isEmpty()) {
            return collect();
        }

        $bankIds = BankAccount::withoutGlobalScopes()->withTrashed()
            ->whereIn('id', $accountIds)->pluck('bank_id')->filter()->unique();

        if ($bankIds->isEmpty()) {
            return $accountIds;
        }

        return BankAccount::withoutGlobalScopes()->withTrashed()
            ->whereIn('bank_id', $bankIds)->pluck('id')->merge($accountIds)->unique()->values();
    }

    /**
     * Same-bank duplicate accounts (a Plaid re-link) can hold the same real
     * check twice; prefer the row that owns transactions, then the newest
     * account (scopeLatestCheckingAccounts MAX(id) convention).
     *
     * @param  Collection<int, Check>  $checks
     * @return Collection<int, Check>
     */
    private function narrowSameBankDuplicates(Collection $checks): Collection
    {
        $withTransactions = $checks->filter(fn (Check $c) => $c->transactions()->withoutGlobalScopes()->exists())->values();

        if ($withTransactions->count() === 1) {
            return $withTransactions;
        }

        $pool   = $withTransactions->isNotEmpty() ? $withTransactions : $checks;
        $newest = $pool->sortByDesc('bank_account_id')->values();

        // Only collapse when the duplicates genuinely differ by account.
        if ($newest->pluck('bank_account_id')->unique()->count() === $pool->count()) {
            return collect([$newest->first()]);
        }

        return $checks;
    }

    private function link(CheckImage $image, ?Check $check, ?Transaction $transaction, string $status, array $details, bool $dryRun): array
    {
        $details['check_id']       = $check?->id;
        $details['transaction_id'] = $transaction?->id;

        if (! $dryRun) {
            $bankAccountId = $check?->bank_account_id ?? $transaction?->bank_account_id;

            $image->update([
                'check_id'             => $check?->id,
                'transaction_id'       => $transaction?->id ?? $this->clearedTransactionIdForCheck($check, $image),
                'bank_account_id'      => $bankAccountId,
                'belongs_to_vendor_id' => $check?->belongs_to_vendor_id
                    ?? ($bankAccountId ? BankAccount::withoutGlobalScopes()->withTrashed()->find($bankAccountId)?->vendor_id : null),
                'match_status'         => $status,
                'match_details'        => $details,
                'matched_at'           => now(),
            ]);

            Log::channel('check_images')->info('Check image linked', [
                'check_image_id' => $image->id,
                'image'          => $image->image_filename,
                'status'         => $status,
            ] + $details);
        }

        return ['status' => $status, 'details' => $details];
    }

    /**
     * When we matched a check directly, also record which of its transactions
     * is this image's cleared payment (same amount, nearest to the cleared
     * date) — purely informational on check_images.
     */
    private function clearedTransactionIdForCheck(?Check $check, CheckImage $image): ?int
    {
        if (! $check || $image->amount === null) {
            return null;
        }

        return $check->transactions()->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('amount', $image->amount)
            ->get()
            ->sortBy(fn (Transaction $t) => $image->check_date
                ? abs(Carbon::parse($t->transaction_date)->diffInDays(Carbon::parse($image->check_date), false))
                : 0)
            ->first()?->id;
    }

    private function record(CheckImage $image, string $status, array $details, bool $dryRun): array
    {
        if (! $dryRun) {
            $image->update([
                'match_status'  => $status,
                'match_details' => $details,
                'matched_at'    => now(),
            ]);

            Log::channel('check_images')->info('Check image not linked', [
                'check_image_id' => $image->id,
                'image'          => $image->image_filename,
                'status'         => $status,
            ] + $details);
        }

        return ['status' => $status, 'details' => $details];
    }
}
