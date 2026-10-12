<?php

namespace App\Models;

use App\Scopes\TransactionScope;
use App\Models\BankAccount;
use App\Traits\HasNumericSearch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class Transaction extends Model
{
    use HasFactory, Searchable, SoftDeletes, HasNumericSearch;

    // protected $dates = ['transaction_date', 'posted_date', 'date', 'deleted_at'];

    protected $guarded = [];

    protected $with = ['vendor', 'bank_account.bank'];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date:Y-m-d',
            'posted_date' => 'date:Y-m-d',
            'details' => 'array',
        ];
    }

    /**
     * Set by screens where a person links this charge to an expense on
     * purpose; automatic matching never sets it.
     */
    public bool $manualExpenseLink = false;

    /**
     * The first app class and method on the stack outside this model: the
     * job or screen that tried to save the link.
     */
    protected static function automationCaller(): ?string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
            $class = $frame['class'] ?? '';

            if (str_starts_with($class, 'App\\') && $class !== self::class) {
                return $class . '::' . ($frame['function'] ?? '?');
            }
        }

        return null;
    }

    protected static function booted()
    {
        static::addGlobalScope(new TransactionScope);

        // A paper check keeps its check number even when the bank left the
        // field empty ("CHECK 2658" with no check_number): the vendor matcher
        // skips check charges only when it can see that number.
        static::saving(function ($transaction) {
            if (trim((string) $transaction->check_number) === '') {
                $number = \App\Support\CheckCharge::numberFrom($transaction->plaid_merchant_description);

                if ($number !== null) {
                    $transaction->check_number = $number;
                }
            }
        });

        // A check charge belongs to its Check record, never straight to an
        // expense — unless a person links it by hand ($manualExpenseLink).
        // On 2026-09-08 a $3,075 check charge ended up on a $425.16 receipt.
        // Whatever automation tries it is refused here and named in the log.
        static::saving(function ($transaction) {
            if (
                $transaction->isDirty('expense_id')
                && $transaction->expense_id !== null
                && ! $transaction->manualExpenseLink
                && \App\Support\CheckCharge::isCheck($transaction->check_number, $transaction->plaid_merchant_description)
            ) {
                Log::warning('Blocked automatic check charge → expense link', [
                    'transaction_id' => $transaction->id,
                    'check_number' => $transaction->check_number,
                    'amount' => $transaction->amount,
                    'expense_id' => $transaction->expense_id,
                    'caller' => self::automationCaller(),
                ]);

                $transaction->expense_id = $transaction->getOriginal('expense_id');
            }
        });

        // Prevent linking a transaction to an expense with a different vendor
        static::saving(function ($transaction) {
            if ($transaction->isDirty('expense_id') && $transaction->expense_id !== null && $transaction->vendor_id !== null) {
                $expense = Expense::withoutGlobalScopes()->find($transaction->expense_id);
                if ($expense && $expense->vendor_id !== null && (int) $expense->vendor_id !== (int) $transaction->vendor_id) {
                    // Allow service fee transactions (small amounts with SERVICEFEE in description)
                    $isServiceFee = $transaction->amount <= 10.00 && str_contains($transaction->plaid_merchant_description ?? '', 'SERVICEFEE');
                    if (!$isServiceFee) {
                        Log::warning('Blocked cross-vendor transaction→expense link', [
                            'transaction_id' => $transaction->id,
                            'transaction_vendor_id' => $transaction->vendor_id,
                            'transaction_amount' => $transaction->amount,
                            'expense_id' => $transaction->expense_id,
                            'expense_vendor_id' => $expense->vendor_id,
                        ]);
                        $transaction->expense_id = null;
                    }
                }
            }
        });
        
        // When a transaction is saved or deleted, re-index related expenses
        // This ensures expense status updates when check transactions change
        static::saved(function ($transaction) {
            // Soft-delete $0 posted (non-pending) transactions from Plaid — they carry no value
            if (
                ! $transaction->trashed()
                && $transaction->posted_date !== null
                && (float) $transaction->amount === 0.00
                && $transaction->plaid_transaction_id !== null
            ) {
                $transaction->delete();

                return;
            }

            // Re-index direct expense if linked
            if ($transaction->expense_id) {
                $expense = Expense::withoutGlobalScopes()->find($transaction->expense_id);
                if ($expense) {
                    $expense->searchable();
                }
            }
            
            // Re-index expenses linked via check
            if ($transaction->check_id) {
                $check = Check::withoutGlobalScopes()->find($transaction->check_id);
                if ($check) {
                    foreach ($check->expenses as $expense) {
                        $expense->searchable();
                    }
                }
            }
        });
        
        static::deleted(function ($transaction) {
            // Re-index direct expense if linked
            if ($transaction->expense_id) {
                $expense = Expense::withoutGlobalScopes()->find($transaction->expense_id);
                if ($expense) {
                    $expense->searchable();
                }
            }
            
            // Re-index expenses linked via check
            if ($transaction->check_id) {
                $check = Check::withoutGlobalScopes()->find($transaction->check_id);
                if ($check) {
                    foreach ($check->expenses as $expense) {
                        $expense->searchable();
                    }
                }
            }
        });
    }

    /**
     * Get the name of the index associated with the model.
     */
    /**
     * The merchant name worth storing from a Plaid transaction payload. Plaid
     * guesses a merchant even for a payment-app transfer: 29182 ($200 Venmo
     * to a person, memo "VENMO *PATRYK SZADY") came back as "Anthropic
     * Headquarters" while Plaid's own counterparties said [Venmo:
     * payment_app] and no merchant (2026-07-12). Such a guess is dropped; a
     * payment that Plaid ties to a merchant counterparty (Netflix through
     * PayPal) keeps its merchant, and so does a payload without counterparty
     * data.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function merchantNameFromPlaid(array $payload): ?string
    {
        $merchant = trim((string) ($payload['merchant_name'] ?? ''));
        if ($merchant === '') {
            return null;
        }

        $memo = implode(' ', array_filter([(string) ($payload['name'] ?? ''), (string) ($payload['original_description'] ?? '')]));

        return self::isPaymentAppGuess($merchant, (array) ($payload['counterparties'] ?? []), $memo) ? null : $merchant;
    }

    /** Does this stored row carry such a guess (see merchantNameFromPlaid())? */
    public function merchantNameIsPaymentAppGuess(): bool
    {
        $details = (array) ($this->details ?? []);
        $memo = implode(' ', array_filter([(string) $this->plaid_merchant_description, (string) ($details['original_description'] ?? '')]));

        return self::isPaymentAppGuess((string) $this->plaid_merchant_name, (array) ($details['counterparties'] ?? []), $memo);
    }

    /**
     * A merchant name is a guess when Plaid's counterparties say the money
     * went through a payment app and to no merchant, and the bank memo names
     * that app.
     *
     * @param  list<array<string, mixed>>  $counterparties
     */
    public static function isPaymentAppGuess(string $merchant, array $counterparties, string $memo): bool
    {
        $merchant = trim($merchant);
        if ($merchant === '' || in_array(strtoupper($merchant), ['VENMO', 'ZELLE', 'PAYPAL', 'CASH APP'], true)) {
            return false;
        }

        $types = collect($counterparties)
            ->map(fn ($counterparty) => strtolower((string) (is_array($counterparty) ? ($counterparty['type'] ?? '') : '')))
            ->filter()
            ->values();

        return $types->contains('payment_app')
            && ! $types->contains('merchant')
            && preg_match('/\b(VENMO|ZELLE|PAYPAL|CASH\s?APP|APPLE\s?CASH)\b/i', $memo) === 1;
    }

    /** check_number of a transaction marked as a transfer (both legs of a card payment, money between accounts, a payment app). */
    public const TRANSFER_CHECK_NUMBER = '1010101';

    /**
     * How a transfer-marked transaction moved money. 'person' when it paid
     * someone through a payment app or a named transfer (Venmo, Zelle,
     * PayPal, Cash App, a credit union's "To NAME," share transfer): those
     * settle somebody's Transfer check and must stay visible until one
     * claims them. 'account' for everything else marked a transfer (a
     * credit-card payment, money between our own accounts), whose other leg
     * is the record. Null when the transaction is not a transfer at all.
     * Plaid's categories cannot do this: 177 of the last year's Zelle debits
     * came categorised as credit-card payments (2026-10-11).
     */
    public static function transferKindFor(?string $description, ?string $checkNumber): ?string
    {
        if ((string) $checkNumber !== self::TRANSFER_CHECK_NUMBER) {
            return null;
        }

        $description = (string) $description;

        if (preg_match('/\b(VENMO|ZELLE|PAYPAL|CASH\s?APP|APPLE\s?CASH)\b/i', $description)) {
            return 'person';
        }

        // "88123 To RACZKA,CEZARY T 4411 Share 1" — a named payee, upper case,
        // the way the credit union writes a share transfer to a person.
        if (preg_match('/\bTo [A-Z][A-Z\'\- ]+,/', $description)) {
            return 'person';
        }

        return 'account';
    }

    public function transferKind(): ?string
    {
        return self::transferKindFor($this->plaid_merchant_description, $this->check_number);
    }

    /** The badge a transfer-marked row wears in the Transactions card: the app that carried it, else "Transfer". */
    public function transferLabel(): ?string
    {
        if ($this->transferKind() === null) {
            return null;
        }

        $description = (string) $this->plaid_merchant_description;

        foreach (['Venmo' => '/\bVENMO\b/i', 'Zelle' => '/\bZELLE\b/i', 'PayPal' => '/\bPAYPAL\b/i', 'Cash App' => '/\bCASH\s?APP\b/i'] as $label => $pattern) {
            if (preg_match($pattern, $description)) {
                return $label;
            }
        }

        return 'Transfer';
    }

    public function searchableAs(): string
    {
        return env('APP_ENV') == 'local' ? 'transaction_index_dev' : 'transaction_index';
    }

    // Searchable
    public function toSearchableArray(): array
    {
        $array = $this->toArray();
        $array['transaction_date'] = $this->transaction_date->timestamp;
        $array['posted_date'] = $this->posted_date ? $this->posted_date->timestamp : null;
        // makeAllSearchableUsing() eager-loads payments for a bulk reindex;
        // outside that path (a single ->searchable() call) fall back to an
        // EXISTS check instead of lazy-loading every payment row.
        $hasPayments = $this->relationLoaded('payments')
            ? $this->payments->isNotEmpty()
            : $this->payments()->exists();
        $array['deposit'] = $hasPayments
            ? 'HAS_PAYMENTS'
            : ($this->deposit ? 'NO_PAYMENTS' : 'NOT_DEPOSIT');
        $array['expenses_count'] = $this->expenses()->count();
        
        // Ensure amount is consistently cast to float like Expense model
        $array['amount'] = (float) $this->amount;

        // Only index a real vendor name. The `vendor` relationship returns a
        // "No Vendor" default model when none is attached, which pollutes text
        // search (e.g. typo tolerance fuzzy-matches "Venmo" against "Vendor").
        $array['vendor_name'] = $this->vendor_id ? $this->vendor?->business_name : null;
        $array['transfer_kind'] = $this->transferKind();

        // The default vendor stub must never leak into the index via toArray().
        unset($array['vendor']);

        return $array;
    }

    /**
     * Eager-load payments for the whole reindex chunk so
     * toSearchableArray()'s payments check doesn't lazy-load per row (~936k
     * lazy loads logged from a full `scout:import` before this).
     */
    protected function makeAllSearchableUsing(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->with('payments:id,transaction_id');
    }

    /**
     * The Meilisearch filters behind the Expenses screen's Transactions card:
     * what still has to be accounted for. Nothing with an expense, a check
     * (and so a timesheet), or a deposit. A transfer-marked row stays out too
     * — a credit-card payment or money between our own accounts has its other
     * leg as the record — UNLESS it paid a person (transfer_kind 'person').
     * Those settle somebody's Transfer check, and while unclaimed they were
     * visible nowhere: 65 Venmo debits from Citibank since May 2026, $18,019,
     * 32 of them never linked to a check (2026-10-11). Real check numbers stay
     * visible until matched to a Check.
     *
     * @param  list<int>  $bankAccountIds
     * @return list<string>
     */
    public static function unmatchedSearchFilters(array $bankAccountIds): array
    {
        return [
            '__soft_deleted = 0',
            'bank_account_id IN ['.implode(',', $bankAccountIds).']',
            'expense_id IS NULL',
            'expenses_count = 0',
            'check_id IS NULL',
            'deposit IN ["NOT_DEPOSIT", "NO_PAYMENTS"]',
            '(check_number IS NULL OR check_number != "'.self::TRANSFER_CHECK_NUMBER.'" OR transfer_kind = "person")',
        ];
    }

    /**
     * Create a search builder that respects user access permissions
     * and allows chaining additional filters
     */
    public static function scopedSearch($query = '', $filterConditions = [], $sortBy = 'transaction_date', $sortDirection = 'desc', bool $textSearch = false)
    {
        return self::search($query, function ($meilisearch, $searchQuery, $options) use ($filterConditions, $sortBy, $sortDirection, $textSearch) {
            // Get the current user
            $user = auth()->user();
            
            // Get bank account IDs that belong to the current vendor
            $bankAccountIds = Cache::remember("vendor:{$user->vendor->id}:bank_account_ids", 600, function () use ($user) {
                return BankAccount::where('vendor_id', $user->vendor->id)
                    ->pluck('id')
                    ->toArray();
            });
            
            $baseFilters = self::unmatchedSearchFilters($bankAccountIds);
            
            $baseFilter = implode(' AND ', $baseFilters);
            
            // Process numeric search queries using shared trait logic
            [$actualQuery, $augmentedFilters] = self::processNumericSearch($searchQuery, $filterConditions);
            
            // Apply search options using shared trait logic
            $options = self::applySearchOptions($options, $baseFilter, $augmentedFilters, $actualQuery, $sortBy, $sortDirection);

            // Restrict which fields are searched based on search type
            if ($textSearch && trim($actualQuery) !== '') {
                $options['attributesToSearchOn'] = ['plaid_merchant_description', 'plaid_merchant_name', 'vendor_name'];
            } elseif (!$textSearch && trim($actualQuery) !== '') {
                $options['attributesToSearchOn'] = ['amount'];
            }
            
            return $meilisearch->search($actualQuery, $options);
        });
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withDefault([
            //if transaction->vendor_id == NULL?
            'business_name' => 'No Vendor',
        ]);
    }

    /**
     * The last four of the card that made this charge, wherever the bank put
     * it: Capital One's card member (owner, Plaid's account_owner), Citi's
     * description ("DEBIT PIN PURCHASE 4849 …", "DEBIT PURCHASE Sep 27 4849 …",
     * "MOBILE WALLET PURCHASE Sep28 10:35p 4849 …"), or a card account's own
     * number. Several can apply; none means the bank did not say.
     *
     * @return list<string>
     */
    public function cardNumbers(): array
    {
        $numbers = [];

        if (preg_match('/^\d{4}$/', (string) $this->owner)) {
            $numbers[] = (string) $this->owner;
        }

        if (preg_match('/\bPURCHASE\s+(?:[A-Z][a-z]{2}\s?\d{1,2}\s+)?(?:\d{1,2}:\d{2}[ap]?\s+)?(\d{4})\b/', (string) $this->plaid_merchant_description, $m)) {
            $numbers[] = $m[1];
        }

        // A shared card account's own number says nothing about which card was
        // used once Plaid names the card member (Capital One: account 4060,
        // members 0616, 0286, 4060…).
        $account = (string) $this->bank_account?->account_number;

        if ($numbers === [] && preg_match('/^\d{4}$/', $account) && in_array($this->bank_account?->type, ['credit', 'Credit', 'Credit Card', 'credit card'], true)) {
            $numbers[] = $account;
        }

        return array_values(array_unique($numbers));
    }

    public function expense(): BelongsTo
    {
        // return $this->belongsTo(Expense::class)->withDefault([
        //     //if transaction->expense_id == NULL?
        //     'id' => 'No Expense',
        // ]);
        return $this->belongsTo(Expense::class);
    }

    /**
     * Many-to-many relationship: one transaction can belong to multiple expenses.
     * This is used when a single bank transaction covers multiple expense records.
     */
    public function expenses(): BelongsToMany
    {
        return $this->belongsToMany(Expense::class, 'expense_transaction')
            ->withTimestamps();
    }

    public function bank_account(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    // public function accountOwner()
    // {
    //     return $this->hasOneThrough(Bank::class, BankAccount::class);
    // }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(Check::class);
    }

    //bank_accountBank
    // public function bank()
    // {
    //     return $this->hasOneThrough(BankAccount::class, Bank::class);
    // }

    /**
     * Plaid's merchant location for this transaction, when the bank provided
     * one. Null when every field is empty — some banks (e.g. Capital One)
     * strip location on many transactions.
     *
     * @return array{city: string, region: string, postal_code: string}|null
     */
    public function plaidLocation(): ?array
    {
        $location = data_get($this->details, 'location');

        if (! is_array($location)) {
            return null;
        }

        $normalized = [
            'city' => trim((string) ($location['city'] ?? '')),
            'region' => trim((string) ($location['region'] ?? '')),
            'postal_code' => substr(preg_replace('/\D/', '', (string) ($location['postal_code'] ?? '')), 0, 5),
        ];

        return array_filter($normalized) === [] ? null : $normalized;
    }

    /**
     * Compare this transaction's Plaid location against a vendor's stored
     * address. Generic same-named merchants ("SMOKE N VAPE", "Chinese Food")
     * can only be told apart by where the charge happened, so vendors with an
     * address act as location-specific and matching gates on agreement.
     *
     * true  => comparable and they agree (zip, or city and state)
     * false => comparable and they disagree — different physical location
     * null  => not comparable (transaction or vendor lacks location data)
     *
     * $strict requires exact city equality (after abbreviation
     * normalization) — use it when a positive answer PICKS a vendor rather
     * than merely avoiding a veto: a truncated fragment like "Park" must
     * never be decisive ("Park" would suffix-match "Highland Park" and
     * misassign a Park Ridge charge).
     */
    public function locationAgreementWithVendor(Vendor $vendor, bool $strict = false): ?bool
    {
        $location = $this->plaidLocation();

        if (! $location) {
            return null;
        }

        $vendorZip = $vendor->normalizedZip();
        if (strlen($location['postal_code']) === 5 && strlen($vendorZip) === 5) {
            return $location['postal_code'] === $vendorZip;
        }

        $txnCity = mb_strtolower($location['city']);
        $vendorCity = mb_strtolower(trim((string) $vendor->city));
        $txnRegion = mb_strtolower($location['region']);
        $vendorState = mb_strtolower(trim((string) $vendor->state));
        // Regions only compare when both are 2-letter state codes — vendors
        // sometimes store the full state name, which must not read as conflict.
        $regionsComparable = strlen($txnRegion) === 2 && strlen($vendorState) === 2;

        if ($txnCity === '' || $vendorCity === '') {
            if ($regionsComparable && $txnRegion !== $vendorState) {
                return false;
            }

            return null;
        }

        if (! self::citiesAgree($txnCity, $vendorCity, $strict)) {
            return false;
        }

        if ($regionsComparable && $txnRegion !== $vendorState) {
            return false;
        }

        return true;
    }

    /**
     * Some banks truncate city names in Plaid data — leading OR trailing
     * fragments ("Mount" and "Prospect" both appear for Mount Prospect) and
     * abbreviations ("Mt Prospect") — so cities agree when equal after
     * normalization or when one is a whole-word prefix/suffix of the other.
     * Lenient by design: a false agree only defers to other signals, while a
     * false conflict would hard-veto a correct match. Expects lowercase input.
     */
    protected static function citiesAgree(string $a, string $b, bool $exactOnly = false): bool
    {
        $a = self::normalizeCityName($a);
        $b = self::normalizeCityName($b);

        if ($a === $b) {
            return true;
        }

        if ($exactOnly) {
            return false;
        }

        [$short, $long] = strlen($a) <= strlen($b) ? [$a, $b] : [$b, $a];

        return str_starts_with($long, $short.' ') || str_ends_with($long, ' '.$short);
    }

    protected static function normalizeCityName(string $city): string
    {
        $expansions = ['mt' => 'mount', 'ft' => 'fort', 'st' => 'saint'];

        $words = array_map(
            fn (string $word) => $expansions[rtrim($word, '.')] ?? $word,
            preg_split('/\s+/', trim($city)) ?: [],
        );

        return implode(' ', $words);
    }

    //used in TransactionController::add_vendor_to_transactions
    //used in Livewire/Transactions/MatchVendor::mount
    public function scopeTransactionsSinVendor($query)
    {
        $query->withoutGlobalScopes()
            ->whereNull('vendor_id')
            ->whereNull('expense_id')
            ->whereNull('deposit')
            ->whereNull('check_number')
            ->whereNull('deleted_at')
            ->whereDoesntHave('payments', function ($query): void {
                $query->withoutGlobalScopes();
            });
    }
}
