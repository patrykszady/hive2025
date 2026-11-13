<?php

namespace App\Services\Expenses;

use App\Http\Controllers\CompanyEmailController;
use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\ExpenseReceipts;
use App\Models\ExpenseSplits;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Models\VendorTransaction;
use App\Support\ReceiptTenders;
use App\Support\Transactions\MerchantIdentity;
use App\Support\Transactions\PaymentProcessors;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pairs a receipt that became an expense of its own with the bank charge
 * that became another, when they are one purchase.
 *
 * On 2026-11-04 a $41.00 dump ticket was paid at the Glenview transfer
 * station. The bank charge ("… 4849 GROOT INDUSTRIES INCO …") became expense
 * 29031 under Groot the same day; the scanned ticket, printed "Solid Waste
 * Agency of Northern Cook County", became expense 29068 with no vendor six
 * days later, because that name was not known as Groot's. One purchase,
 * counted twice, and a card on Match Vendor asking who was paid.
 *
 * THE RULE — every part must hold, or the two are left for a person:
 *
 *  - Receipt side: an expense with a receipt and nothing paying it (no bank
 *    charge, shared charge or check, not paid by an employee or a sub, not
 *    a client's material order), whose receipt shows one card paying the
 *    whole amount. It has no vendor, or the charge's vendor; a receipt
 *    filed under any other vendor never pairs.
 *  - Charge side: an expense of the same company with exactly one bank
 *    charge, for the expense's whole amount, a vendor, and no receipt.
 *  - Same amount, to the cent.
 *  - The charge was made from DAYS_BEFORE day before to DAYS_AFTER days
 *    after the receipt's date. Of 1,297 receipts whose printed card is the
 *    card the bank states (local copy of production, 2026-11-12) 51% were
 *    charged the same day, 98% within three days after and 98.9% inside
 *    this window; the bank's posted date is later still and is not used.
 *  - The card is the evidence, and nothing else is (Patryk, 2026-11-12):
 *    the last four printed on the receipt is the card the bank says was
 *    charged (Transaction::cardNumbers(): the card the bank names on the
 *    charge, else a card account's own number). A receipt that prints no
 *    card number never pairs. A charge whose card the bank does not state
 *    never pairs. A receipt that prints a different card never pairs. Being
 *    filed under the charge's vendor proves nothing by itself.
 *  - One to one, both ways. Every charge of the company's of that amount in
 *    the window counts against the receipt unless the bank itself names a
 *    different card on it (Transaction::namedCardNumbers()) — whether or
 *    not it has an expense or a receipt already, whoever its vendor is, and
 *    also when the bank states no card for it, or only the number of a card
 *    account that several cards share: the bank stated none for 236 of the
 *    840 charges of the last twelve months whose receipts print one, so
 *    such a charge may well be the receipt's own. Likewise every
 *    receipt-only expense of that amount around the charge counts unless
 *    it prints a card other than the one the bank names on the charge, a
 *    receipt that prints none included. The charge must be the only one
 *    for the receipt and the receipt the only one for the charge. Two and
 *    two is as ambiguous as two and one: nobody is paired.
 *  - Not before the bank has had its say: unless the charge was made on the
 *    receipt's own date, nothing is paired until the window has closed, so
 *    a second charge of the same amount cannot turn up afterwards.
 *  - The printed name of a receipt with no vendor is not already a name of
 *    a different vendor.
 *
 * ON A PAIR the two become one expense (ExpenseFolder). In a sweep the
 * receipt expense is folded into the charge's expense: receipts move, the
 * charge's expense takes whatever only the receipt expense had (invoice
 * number, project, distribution or splits, note, category, reimbursement,
 * parent) and keeps everything of its own, and the receipt expense is
 * soft-deleted. When the pairing happens in the step that creates or edits
 * one of the two (DuplicateExpenseCheck), that one stays instead and the
 * other is folded into it the same way, so whatever is working on it still
 * holds a live expense. A charge with no expense yet simply becomes the
 * receipt expense's charge. The printed name is remembered
 * as a match rule of the charge's vendor (vendor_transactions, read by
 * CompanyEmailController::fuzzyMatchVendor() at intake), so the next
 * receipt printing it arrives under that vendor.
 */
class ReceiptChargePairer
{
    /** A charge may carry a date this many days before the receipt's. */
    public const DAYS_BEFORE = 1;

    /** A charge may carry a date this many days after the receipt's. */
    public const DAYS_AFTER = 4;

    public const PAIRED = 'paired';

    public const WOULD_PAIR = 'would_pair';

    public const LEFT = 'left';

    /** The bank itself names another card on the charge than the receipt prints. */
    private const OTHER_CARD = 'other_card';

    /** The receipt prints the card that was charged: the only tie there is. */
    private const BY_CARD = 'card';

    /** The cards cannot be compared: the charge can neither pair nor be ruled out. */
    private const NO_CARD = 'no_card';

    /** The same card, but the two are filed under different vendors. */
    private const VENDORS_DIFFER = 'vendors_differ';

    /** @var array<int, list<int>> company => its bank account ids */
    private array $bankAccountIds = [];

    /** @var Collection<int, Vendor>|null */
    private ?Collection $vendors = null;

    public function __construct(private ExpenseFolder $folder) {}

    /**
     * Every receipt-only expense, oldest first: paired when the rule holds,
     * listed with the reason when it does not and the receipt sits beside a
     * charge on its printed card, or beside a charge-only expense of the
     * same amount that is its own vendor's (or it has no vendor).
     *
     * @return list<array{status: string, receipt_expense_id: int, charge_expense_id: ?int, transaction_id: ?int, amount: string, receipt_date: string, charge_date: ?string, printed_name: string, vendor: ?string, evidence: ?string, reason: string, name: ?string}>
     */
    public function run(bool $dryRun = false, ?CarbonInterface $since = null, ?int $companyId = null, bool $vendorlessOnly = false): array
    {
        $ids = $this->receiptOnlyExpenses()
            ->when($since, fn (Builder $query) => $query->where('date', '>=', $since->toDateString()))
            ->when($companyId, fn (Builder $query) => $query->where('belongs_to_vendor_id', $companyId))
            ->when($vendorlessOnly, fn (Builder $query) => $query->where(fn (Builder $vendor) => $vendor->where('vendor_id', 0)->orWhereNull('vendor_id')))
            ->orderBy('date')
            ->orderBy('id')
            ->pluck('id');

        $outcomes = [];

        foreach ($ids as $id) {
            $expense = $this->receiptOnlyExpenses()->whereKey($id)->first();

            try {
                $outcome = $expense ? $this->evaluate($expense, $dryRun) : null;
            } catch (\Throwable $e) {
                report($e);
                $outcome = $this->outcome($expense, null, null, 'could not be paired: '.$e->getMessage());
            }

            if ($outcome !== null) {
                $outcomes[] = $outcome;
            }
        }

        return $outcomes;
    }

    /**
     * The receipt cards of Match Vendor: a company's receipt expenses with
     * no vendor, paired where the rule holds so the page does not ask a
     * person who was paid.
     *
     * @return list<array<string, mixed>>
     */
    public function pairVendorlessReceipts(int $companyId): array
    {
        return $this->run(companyId: $companyId, vendorlessOnly: true);
    }

    /**
     * One expense, whichever half it is: a receipt-only expense is tried
     * against the charges around it, a charge-only expense against the
     * receipts around its charge. Null when it is neither, or nothing is
     * near it.
     *
     * With $keep the expense handed in is the one that stays, whichever
     * half it is — for a caller in the middle of creating or editing it
     * (DuplicateExpenseCheck): a receipt expense then takes the charge and
     * the filing of the charge's expense, which is the one soft-deleted.
     * Without it the charge's expense stays, as in a sweep.
     *
     * @return array{status: string, receipt_expense_id: int, charge_expense_id: ?int, transaction_id: ?int, amount: string, receipt_date: string, charge_date: ?string, printed_name: string, vendor: ?string, evidence: ?string, reason: string, name: ?string}|null
     */
    public function pairExpense(Expense|int $expense, bool $dryRun = false, bool $keep = false): ?array
    {
        $id = $expense instanceof Expense ? (int) $expense->id : $expense;
        $receiptExpense = $this->receiptOnlyExpenses()->whereKey($id)->first();

        if ($receiptExpense) {
            return $this->evaluate($receiptExpense, $dryRun, keepReceiptExpense: $keep);
        }

        $chargeExpense = Expense::withoutGlobalScopes()->whereNull('deleted_at')->find($id);
        $charges = $chargeExpense ? $this->linkedCharges($chargeExpense) : collect();

        if ($charges->count() !== 1) {
            return null;
        }

        foreach ($this->receiptExpensesNear((int) $chargeExpense->belongs_to_vendor_id, $charges->first()) as $candidate) {
            $outcome = $this->evaluate($candidate, $dryRun);

            if ($outcome !== null && $outcome['charge_expense_id'] === (int) $chargeExpense->id && $outcome['status'] !== self::LEFT) {
                return $outcome;
            }
        }

        return null;
    }

    /**
     * Expenses that are a receipt and nothing else: nothing pays them.
     *
     * @return Builder<Expense>
     */
    private function receiptOnlyExpenses(): Builder
    {
        return Expense::withoutGlobalScopes()
            ->whereNull('expenses.deleted_at')
            ->where('amount', '!=', 0)
            ->whereNull('check_id')
            ->whereNull('paid_by')
            ->whereNull('paid_by_vendor_id')
            ->whereNull('belongs_to_client_id')
            ->whereHas('receipts', fn (Builder $receipts) => $receipts->withoutGlobalScopes()->whereNull('deleted_at'))
            ->whereDoesntHave('receipts', fn (Builder $receipts) => $receipts->withoutGlobalScopes()->whereNull('deleted_at')->where('is_material_order', true))
            ->whereDoesntHave('transactions', fn (Builder $charges) => $charges->withoutGlobalScopes()->whereNull('deleted_at'))
            ->whereDoesntHave('sharedTransactions', fn (Builder $charges) => $charges->withoutGlobalScopes())
            ->whereDoesntHave('checks', fn (Builder $checks) => $checks->withoutGlobalScopes());
    }

    /**
     * Decide, and unless it is a dry run act — under a lock, on a fresh
     * read, so two runs meeting on the same pair fold it once.
     *
     * @return array<string, mixed>|null
     */
    private function evaluate(Expense $receiptExpense, bool $dryRun, bool $keepReceiptExpense = false): ?array
    {
        [$outcome, $pair] = $this->decide($receiptExpense);

        if ($dryRun || $pair === null) {
            return $outcome;
        }

        try {
            return Cache::lock('receipt-charge-pairer:'.$receiptExpense->belongs_to_vendor_id, 120)->block(30, function () use ($receiptExpense, $keepReceiptExpense): ?array {
                $fresh = $this->receiptOnlyExpenses()->whereKey($receiptExpense->id)->first();
                [$outcome, $pair] = $fresh ? $this->decide($fresh) : [null, null];

                if ($pair === null) {
                    return $outcome;
                }

                $outcome['name'] = match (true) {
                    $pair['expense'] === null => $this->attach($fresh, $pair['charge'], $pair['vendor_id'], $pair['name']),
                    $keepReceiptExpense => $this->absorb($fresh, $pair['expense'], $pair['charge'], $pair['name']),
                    default => $this->pair($fresh, $pair['expense'], $pair['charge'], $pair['name']),
                };
                $outcome['status'] = self::PAIRED;
                $outcome['expense_id'] = $pair['expense'] === null || $keepReceiptExpense ? (int) $fresh->id : (int) $pair['expense']->id;

                Log::info('Paired a receipt expense with its bank charge', $outcome);

                return $outcome;
            });
        } catch (LockTimeoutException) {
            return array_merge($outcome, ['status' => self::LEFT, 'reason' => 'another pairing run was busy with this company; the next run takes it']);
        }
    }

    /**
     * The verdict for one receipt-only expense, and what to fold when the
     * rule holds. Reads only. No outcome at all when no charge of the same
     * amount is near, or none of them is the kind this rule is about.
     *
     * @return array{0: array<string, mixed>|null, 1: array{expense: ?Expense, charge: Transaction, name: array{action: string, text: string, note: ?string}, vendor_id: int}|null}
     */
    private function decide(Expense $receiptExpense): array
    {
        $charges = $this->chargesNear($receiptExpense);

        if ($charges->isEmpty()) {
            return [null, null];
        }

        $payment = $this->receiptPayment($receiptExpense);
        $rows = $charges->map(fn (Transaction $charge): array => [
            'charge' => $charge,
            'expense' => $this->liveExpense($charge),
            'relation' => $this->relation($receiptExpense, $payment, $charge),
        ]);
        $receiptVendor = (int) $receiptExpense->vendor_id;
        $worthListing = $rows->filter(fn (array $row): bool => in_array($row['relation'], [self::BY_CARD, self::VENDORS_DIFFER], true)
            || ($row['expense'] !== null
                && ($receiptVendor <= 0 || $receiptVendor === $this->chargeVendorId($row['charge'], $row['expense']))
                && $this->chargeOnlyProblem($row['expense'], $row['charge']) === null));

        if ($worthListing->isEmpty()) {
            return [null, null];
        }

        $left = fn (string $reason, ?array $row = null): array => [$this->outcome($receiptExpense, $row, $payment['card'], $reason), null];

        if ($payment['problem'] !== null) {
            return $left($payment['problem']);
        }

        if ($payment['card'] === null) {
            return $left('the receipt prints no card number', $worthListing->count() === 1 ? $worthListing->first() : null);
        }

        $candidates = $rows->reject(fn (array $row): bool => $row['relation'] === self::OTHER_CARD)->values();

        if ($candidates->isEmpty()) {
            $other = $worthListing->first();

            return $left('the receipt prints card ••'.$payment['card'].'; the charge of that amount (expense #'.$other['expense']->id.') was made with ••'.implode('/••', $other['charge']->namedCardNumbers()));
        }

        if ($candidates->count() > 1) {
            return $left($candidates->count().' charges of '.$this->money($receiptExpense->amount).' in the window could be this receipt\'s ('.$candidates->map(fn (array $row): string => $this->chargeLabel($row))->implode(', ').')');
        }

        $row = $candidates->first();
        $charge = $row['charge'];
        $chargeExpense = $row['expense'];

        if ($row['relation'] === self::NO_CARD) {
            return $left($charge->cardNumbers() === []
                ? 'the bank does not say which card made the charge'
                : 'the bank names only the card account ••'.implode('/••', $charge->cardNumbers()).', not which of its cards made the charge', $row);
        }

        if ($row['relation'] === self::VENDORS_DIFFER) {
            return $left('same card ••'.$payment['card'].', but the receipt is filed under '.$this->vendorName($receiptVendor).' and the charge under '.$this->vendorName($this->chargeVendorId($charge, $chargeExpense)), $row);
        }

        $vendorId = $this->chargeVendorId($charge, $chargeExpense) ?: $receiptVendor;

        if ($chargeExpense === null && $vendorId <= 0) {
            return $left('its charge (transaction #'.$charge->id.') has no vendor yet: who was paid is named on Match Vendor first', $row);
        }

        if ($chargeExpense !== null && ($problem = $this->chargeOnlyProblem($chargeExpense, $charge)) !== null) {
            return $left($problem, $row);
        }

        $rivals = $this->receiptExpensesNear((int) $receiptExpense->belongs_to_vendor_id, $charge)
            ->filter(function (Expense $other) use ($charge): bool {
                $otherPayment = $this->receiptPayment($other);

                return $otherPayment['problem'] === null && $this->relation($other, $otherPayment, $charge) !== self::OTHER_CARD;
            });

        if ($rivals->count() !== 1 || (int) $rivals->first()->id !== (int) $receiptExpense->id) {
            return $left($rivals->count().' receipts of '.$this->money($receiptExpense->amount).' could belong to this charge (expenses #'.$rivals->pluck('id')->implode(', #').')', $row);
        }

        $closes = $receiptExpense->date->copy()->addDays(self::DAYS_AFTER);

        if (! $charge->transaction_date->isSameDay($receiptExpense->date) && ! $closes->lt(now()->startOfDay())) {
            return $left('waits until '.$closes->copy()->addDay()->toDateString().': the charge is dated '.$charge->transaction_date->toDateString().', not the receipt\'s day, and another charge of this amount may still arrive', $row);
        }

        $name = $this->nameDecision($receiptExpense, $vendorId, $charge);

        if ($name['action'] === 'conflict' && $receiptVendor <= 0) {
            return $left($name['note'], $row);
        }

        $outcome = $this->outcome($receiptExpense, $row, $payment['card'], 'the receipt prints card ••'.$payment['card'].', the card that was charged'
            .($chargeExpense === null ? '; the charge had no expense of its own, so it now pays this one' : ''));
        $outcome['status'] = self::WOULD_PAIR;
        $outcome['name'] = $name['note'];

        return [$outcome, ['expense' => $chargeExpense, 'charge' => $charge, 'name' => $name, 'vendor_id' => $vendorId]];
    }

    /**
     * The company's charges of exactly the receipt's amount inside its
     * window. A charge with no expense counts only when it is a plain
     * purchase (not a check, a transfer, cash or a deposit).
     *
     * @return Collection<int, Transaction>
     */
    private function chargesNear(Expense $receiptExpense): Collection
    {
        $companyId = (int) $receiptExpense->belongs_to_vendor_id;
        $accounts = $this->bankAccountIds[$companyId] ??= BankAccount::withoutGlobalScopes()
            ->where('vendor_id', $companyId)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return Transaction::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('amount', $receiptExpense->amount)
            ->whereBetween('transaction_date', [
                $receiptExpense->date->copy()->subDays(self::DAYS_BEFORE)->toDateString(),
                $receiptExpense->date->copy()->addDays(self::DAYS_AFTER)->toDateString().' 23:59:59',
            ])
            ->with([
                'bank_account' => fn ($account) => $account->withoutGlobalScopes(),
                'expense' => fn ($expense) => $expense->withoutGlobalScopes(),
            ])
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get()
            ->filter(function (Transaction $charge) use ($companyId, $accounts): bool {
                $expense = $this->liveExpense($charge);

                if ($expense !== null) {
                    return (int) $expense->belongs_to_vendor_id === $companyId;
                }

                return in_array((int) $charge->bank_account_id, $accounts, true)
                    && $charge->check_id === null
                    && trim((string) $charge->check_number) === ''
                    && $charge->deposit === null
                    && ! $charge->payments()->withoutGlobalScopes()->exists();
            })
            ->values();
    }

    /** The expense a charge is linked to, unless that expense is deleted. */
    private function liveExpense(Transaction $charge): ?Expense
    {
        $expense = $charge->expense_id ? $charge->expense : null;

        return $expense !== null && $expense->deleted_at === null ? $expense : null;
    }

    /**
     * Receipt-only expenses of the charge's company and amount whose window
     * holds the charge's date.
     *
     * @return Collection<int, Expense>
     */
    private function receiptExpensesNear(int $companyId, Transaction $charge): Collection
    {
        return $this->receiptOnlyExpenses()
            ->where('belongs_to_vendor_id', $companyId)
            ->where('amount', $charge->amount)
            ->whereBetween('date', [
                $charge->transaction_date->copy()->subDays(self::DAYS_AFTER)->toDateString(),
                $charge->transaction_date->copy()->addDays(self::DAYS_BEFORE)->toDateString().' 23:59:59',
            ])
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, Transaction>
     */
    private function linkedCharges(Expense $expense): Collection
    {
        return Transaction::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('expense_id', $expense->id)
            ->with(['bank_account' => fn ($account) => $account->withoutGlobalScopes()])
            ->get();
    }

    /**
     * Why an expense is not a charge and nothing else, or null when it is:
     * one bank charge for its whole amount, a vendor, no receipt, nothing
     * else paying it.
     */
    private function chargeOnlyProblem(Expense $expense, Transaction $charge): ?string
    {
        $label = 'the charge\'s expense #'.$expense->id;

        return match (true) {
            ExpenseReceipts::withoutGlobalScopes()->whereNull('deleted_at')->where('expense_id', $expense->id)->exists() => $label.' already has a receipt',
            $this->linkedCharges($expense)->count() !== 1 => $label.' has more than one bank charge',
            $expense->check_id !== null || $expense->checks()->withoutGlobalScopes()->exists() => $label.' is also paid by a check',
            $expense->sharedTransactions()->withoutGlobalScopes()->exists() => $label.' shares a bank charge with other expenses',
            $expense->paid_by !== null || $expense->paid_by_vendor_id !== null => $label.' was paid by a person, not the company',
            abs((float) $expense->amount - (float) $charge->amount) >= 0.005 => $label.' is for a different amount than its charge',
            (int) $expense->vendor_id <= 0 => $label.' has no vendor',
            default => null,
        };
    }

    /**
     * How the receipt says it was paid: the card last four when one card
     * paid the whole amount, and a problem when it was paid some other way
     * (cash, store credit, several tenders) — such a receipt cannot be the
     * receipt of one bank charge for the full amount. Read from the expense's
     * payment lines (ExpensePaymentSync keeps them), else from the receipts.
     *
     * @return array{card: ?string, problem: ?string}
     */
    private function receiptPayment(Expense $expense): array
    {
        $lines = ExpensePayment::query()->where('expense_id', $expense->id)->get();

        if ($lines->isEmpty()) {
            $read = ReceiptTenders::forExpense($expense->loadMissing('receipts'));
            $lines = collect($read['status'] === 'matched' ? $read['lines'] : [])
                ->map(fn (array $line): ExpensePayment => new ExpensePayment([
                    'expense_id' => $expense->id,
                    'method' => $line['method'],
                    'amount' => $line['amount'],
                    'last_four' => $line['last_four'] ?? null,
                ]));
        }

        if ($lines->isEmpty()) {
            return ['card' => null, 'problem' => null];
        }

        $line = $lines->first();

        if ($lines->count() > 1 || $line->isOffBank() || abs((float) $line->amount - (float) $expense->amount) >= 0.005) {
            return ['card' => null, 'problem' => 'the receipt shows it was not paid by one card charge for the whole amount ('.$lines->map(fn (ExpensePayment $tender): string => $tender->label())->implode(' + ').')'];
        }

        $isCard = $line->method === ExpensePayment::CARD && preg_match('/^\d{4}$/', (string) $line->last_four) === 1;

        return ['card' => $isCard ? (string) $line->last_four : null, 'problem' => null];
    }

    /**
     * What the cards say about a receipt and a charge. The receipt's card
     * being the charge's (named by the bank, or the card account's own
     * number) ties them. Only a different card named by the bank itself
     * rules the charge out; a card account's number that differs does not,
     * since the account may have several cards. The vendor either is filed
     * under never ties them and never rules a charge out; it only forbids
     * the pair when the cards agree and the two are filed under different
     * vendors.
     *
     * @param  array{card: ?string, problem: ?string}  $payment
     */
    private function relation(Expense $receiptExpense, array $payment, Transaction $charge): string
    {
        if ($payment['card'] === null) {
            return self::NO_CARD;
        }

        if (! in_array($payment['card'], $charge->cardNumbers(), true)) {
            return $charge->namedCardNumbers() !== [] ? self::OTHER_CARD : self::NO_CARD;
        }

        $receiptVendor = (int) $receiptExpense->vendor_id;
        $chargeVendor = $this->chargeVendorId($charge, $this->liveExpense($charge));

        return $receiptVendor > 0 && $chargeVendor > 0 && $receiptVendor !== $chargeVendor ? self::VENDORS_DIFFER : self::BY_CARD;
    }

    private function chargeVendorId(Transaction $charge, ?Expense $chargeExpense): int
    {
        return (int) ($chargeExpense?->vendor_id ?: $charge->vendor_id);
    }

    /**
     * What to do with the name printed on the receipt, decided before
     * anything is written:
     *
     *  - "learn": write it as a match rule of the charge's vendor. Only a
     *    name of two words and eight letters or more (a rule is also
     *    matched against bank descriptors, where a short word would claim
     *    strangers), never a payment processor's name.
     *  - "conflict": a rule with the same text, or the intake's own lookup,
     *    already gives the name to a different vendor. That rule is left as
     *    it is, and a receipt with no vendor is not paired against it.
     *  - "none": nothing to write — already known as this vendor's, no name
     *    printed, a processor, or too short.
     *
     * @return array{action: string, text: string, note: ?string}
     */
    private function nameDecision(Expense $receiptExpense, int $vendorId, Transaction $charge): array
    {
        $text = $this->printedName($receiptExpense);
        $none = fn (?string $note): array => ['action' => 'none', 'text' => $text, 'note' => $note];

        if ($text === '') {
            return $none(null);
        }

        if (PaymentProcessors::isProcessorOnly($text) || MerchantIdentity::of($charge)->isProcessorName($text)) {
            return $none('"'.$text.'" not learned: a payment processor, not who was paid');
        }

        $sameText = VendorTransaction::query()->get(['id', 'vendor_id', 'desc'])
            ->filter(fn (VendorTransaction $rule): bool => $this->comparable($this->unescaped((string) $rule->desc)) === $this->comparable($text));

        if (($other = $sameText->first(fn (VendorTransaction $rule): bool => (int) $rule->vendor_id !== $vendorId)) !== null) {
            return ['action' => 'conflict', 'text' => $text, 'note' => 'the printed name "'.$text.'" is already match rule #'.$other->id.' of '.($other->vendor_id ? $this->vendorName((int) $other->vendor_id) : 'a non-vendor code').'; left as it is'];
        }

        $known = $sameText->isNotEmpty() ? $vendorId : (int) app(CompanyEmailController::class)->fuzzyMatchVendor($text, $this->vendors(), 70.0)?->id;

        if ($known === $vendorId) {
            return $none('"'.$text.'" is already known as '.$this->vendorName($vendorId));
        }

        if ($known > 0) {
            return ['action' => 'conflict', 'text' => $text, 'note' => 'the printed name "'.$text.'" is on file as '.$this->vendorName($known).', not '.$this->vendorName($vendorId)];
        }

        if (count(preg_split('/\s+/', $text) ?: []) < 2 || strlen((string) preg_replace('/[^\p{L}\p{N}]/u', '', $text)) < 8) {
            return $none('"'.$text.'" not learned: too short to be safe as a match rule');
        }

        return ['action' => 'learn', 'text' => $text, 'note' => '"'.$text.'" learned as '.$this->vendorName($vendorId)];
    }

    /** The merchant name on the receipt, as Match Vendor reads it, on one line. */
    private function printedName(Expense $receiptExpense): string
    {
        $receipt = ExpenseReceipts::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('expense_id', $receiptExpense->id)
            ->latest('id')
            ->first();
        $name = is_array($receipt?->receipt_items) ? ($receipt->receipt_items['merchant_name'] ?? '') : '';

        return is_string($name) ? trim((string) preg_replace('/\s+/u', ' ', $name)) : '';
    }

    /**
     * Fold the receipt expense into the charge's expense and, when decided,
     * remember the printed name. Returns what became of the name.
     *
     * @param  array{action: string, text: string, note: ?string}  $name
     */
    private function pair(Expense $receiptExpense, Expense $chargeExpense, Transaction $charge, array $name): ?string
    {
        DB::transaction(function () use ($receiptExpense, $chargeExpense, $charge, $name): void {
            $this->carryOver($receiptExpense, $chargeExpense);
            $this->folder->fold($receiptExpense, $chargeExpense);

            ExpensePayment::query()
                ->where('expense_id', $receiptExpense->id)
                ->where('source', '!=', ExpensePayment::SOURCE_MANUAL)
                ->delete();

            ExpensePayment::query()
                ->where('expense_id', $chargeExpense->id)
                ->where('method', ExpensePayment::CARD)
                ->whereNull('transaction_id')
                ->get()
                ->first(fn (ExpensePayment $line): bool => abs((float) $line->amount - (float) $charge->amount) < 0.005)
                ?->update(['transaction_id' => $charge->id]);

            $this->learn($name, (int) $chargeExpense->vendor_id);
        });

        $chargeExpense->searchable();
        $receiptExpense->searchable();

        return $name['note'];
    }

    /**
     * The other way round from pair(): the receipt expense is the one in
     * hand (it is being created or edited right now) and stays. It takes the
     * vendor and whatever else only the charge's expense had, then the
     * charge itself; the charge's expense, now empty, is soft-deleted.
     * Returns what became of the name.
     *
     * @param  array{action: string, text: string, note: ?string}  $name
     */
    private function absorb(Expense $receiptExpense, Expense $chargeExpense, Transaction $charge, array $name): ?string
    {
        DB::transaction(function () use ($receiptExpense, $chargeExpense, $charge, $name): void {
            if ((int) $receiptExpense->vendor_id <= 0) {
                $receiptExpense->vendor_id = $chargeExpense->vendor_id;
            }

            $this->carryOver($chargeExpense, $receiptExpense);

            $charge->expense_id = $receiptExpense->id;
            $charge->save();

            if ((int) Transaction::withoutGlobalScopes()->whereKey($charge->id)->value('expense_id') !== (int) $receiptExpense->id) {
                throw new \RuntimeException('transaction #'.$charge->id.' refused the link to expense #'.$receiptExpense->id);
            }

            ExpensePayment::query()
                ->where('expense_id', $receiptExpense->id)
                ->where('method', ExpensePayment::CARD)
                ->whereNull('transaction_id')
                ->get()
                ->first(fn (ExpensePayment $line): bool => abs((float) $line->amount - (float) $charge->amount) < 0.005)
                ?->update(['transaction_id' => $charge->id]);

            $this->folder->fold($chargeExpense->unsetRelation('transactions'), $receiptExpense);
            $this->learn($name, (int) $receiptExpense->vendor_id);
        });

        $receiptExpense->searchable();
        $chargeExpense->searchable();

        return $name['note'];
    }

    /**
     * The charge has no expense of its own: it becomes the receipt
     * expense's charge. The receipt expense takes the charge's vendor when it
     * has none — first, since a charge is refused by an expense filed under
     * another vendor (Transaction's own guard) — and its card payment line is
     * tied to the charge. Returns what became of the name.
     *
     * @param  array{action: string, text: string, note: ?string}  $name
     */
    private function attach(Expense $receiptExpense, Transaction $charge, int $vendorId, array $name): ?string
    {
        DB::transaction(function () use ($receiptExpense, $charge, $vendorId, $name): void {
            if ((int) $receiptExpense->vendor_id <= 0 && $vendorId > 0) {
                $receiptExpense->vendor_id = $vendorId;
                $receiptExpense->save();
            }

            $charge->expense_id = $receiptExpense->id;
            $charge->save();

            if ((int) Transaction::withoutGlobalScopes()->whereKey($charge->id)->value('expense_id') !== (int) $receiptExpense->id) {
                throw new \RuntimeException('transaction #'.$charge->id.' refused the link to expense #'.$receiptExpense->id);
            }

            ExpensePayment::query()
                ->where('expense_id', $receiptExpense->id)
                ->where('method', ExpensePayment::CARD)
                ->whereNull('transaction_id')
                ->get()
                ->first(fn (ExpensePayment $line): bool => abs((float) $line->amount - (float) $charge->amount) < 0.005)
                ?->update(['transaction_id' => $charge->id]);

            $this->learn($name, (int) $receiptExpense->vendor_id);
        });

        $receiptExpense->searchable();

        return $name['note'];
    }

    /**
     * Writes the printed name as a match rule of the vendor, when decided.
     *
     * @param  array{action: string, text: string, note: ?string}  $name
     */
    private function learn(array $name, int $vendorId): void
    {
        if ($name['action'] !== 'learn' || $vendorId <= 0) {
            return;
        }

        VendorTransaction::create([
            'vendor_id' => $vendorId,
            'deposit_check' => null,
            'desc' => (string) preg_replace('/[\\\\*+?^${}()|\[\]]/', '\\\\$0', $name['text']),
            'plaid_inst_id' => null,
            'options' => json_encode('/i'),
        ]);
    }

    /**
     * Give the expense that stays (the second argument) what only the one
     * being folded had, before the fold (which itself carries the invoice
     * number and the project): its distribution or splits when the one that
     * stays is filed nowhere (the amounts are equal, so the splits still add
     * up), and its note, category, reimbursement and parent expense where
     * the one that stays has none. Expenses associated under the folded one
     * follow it. Nothing the expense that stays holds is overwritten. Named
     * for pair()'s direction; absorb() calls it the other way round.
     */
    private function carryOver(Expense $receiptExpense, Expense $chargeExpense): void
    {
        $splits = fn (Expense $expense) => ExpenseSplits::withoutGlobalScopes()->whereNull('deleted_at')->where('expense_id', $expense->id);
        $hasOwnSplits = $splits($chargeExpense)->exists();

        if (! $chargeExpense->project_id && ! $chargeExpense->distribution_id && ! $hasOwnSplits) {
            $chargeExpense->project_id = $receiptExpense->project_id ?: $chargeExpense->project_id;
            $chargeExpense->distribution_id = $receiptExpense->distribution_id;

            $splits($receiptExpense)->get()->each(function (ExpenseSplits $split) use ($chargeExpense): void {
                $split->expense_id = $chargeExpense->id;
                $split->save();
            });
        }

        foreach (['note', 'category_id', 'parent_expense_id'] as $field) {
            if (blank($chargeExpense->{$field}) && filled($receiptExpense->{$field})) {
                $chargeExpense->{$field} = $receiptExpense->{$field};
            }
        }

        if (blank($chargeExpense->getRawOriginal('reimbursment')) && filled($receiptExpense->getRawOriginal('reimbursment')) && ! $hasOwnSplits) {
            $chargeExpense->reimbursment = $receiptExpense->getRawOriginal('reimbursment');
        }

        $chargeExpense->save();
        $receiptExpense->unsetRelation('splits');

        Expense::withoutGlobalScopes()
            ->where('parent_expense_id', $receiptExpense->id)
            ->whereKeyNot($chargeExpense->id)
            ->get()
            ->each(function (Expense $child) use ($chargeExpense): void {
                $child->parent_expense_id = $chargeExpense->id;
                $child->save();
            });
    }

    /**
     * @param  array{charge: Transaction, expense: ?Expense, relation: string}|null  $row
     * @return array{status: string, receipt_expense_id: int, charge_expense_id: ?int, transaction_id: ?int, amount: string, receipt_date: string, charge_date: ?string, printed_name: string, vendor: ?string, evidence: ?string, reason: string, name: ?string}
     */
    private function outcome(Expense $receiptExpense, ?array $row, ?string $card, string $reason): array
    {
        $vendorId = $row ? $this->chargeVendorId($row['charge'], $row['expense']) : 0;

        return [
            'status' => self::LEFT,
            'receipt_expense_id' => (int) $receiptExpense->id,
            'charge_expense_id' => $row && $row['expense'] ? (int) $row['expense']->id : null,
            'transaction_id' => $row ? (int) $row['charge']->id : null,
            'amount' => number_format((float) $receiptExpense->amount, 2, '.', ''),
            'receipt_date' => $receiptExpense->date->toDateString(),
            'charge_date' => $row ? $row['charge']->transaction_date->toDateString() : null,
            'printed_name' => $this->printedName($receiptExpense),
            'vendor' => $vendorId > 0 ? $this->vendorName($vendorId) : null,
            'evidence' => in_array($row['relation'] ?? null, [self::BY_CARD, self::VENDORS_DIFFER], true) ? 'card ••'.$card : null,
            'reason' => $reason,
            'name' => null,
        ];
    }

    /**
     * @param  array{charge: Transaction, expense: ?Expense, relation: string}  $row
     */
    private function chargeLabel(array $row): string
    {
        $named = $row['charge']->namedCardNumbers();
        $cards = $row['charge']->cardNumbers();

        return ($row['expense'] ? 'expense #'.$row['expense']->id : 'transaction #'.$row['charge']->id.' with no expense')
            .' on '.$row['charge']->transaction_date->toDateString()
            .match (true) {
                $named !== [] => ', card ••'.implode('/••', $named),
                $cards !== [] => ', card account ••'.implode('/••', $cards),
                default => ', no card stated',
            };
    }

    /**
     * Every vendor, the way the receipt intake hands them to
     * fuzzyMatchVendor().
     *
     * @return Collection<int, Vendor>
     */
    private function vendors(): Collection
    {
        return $this->vendors ??= Vendor::withoutGlobalScopes()->get(['id', 'business_name'])->keyBy('id');
    }

    private function vendorName(int $vendorId): string
    {
        return (string) ($this->vendors()->get($vendorId)?->business_name ?? 'vendor #'.$vendorId);
    }

    /** A stored rule back to the text a person would read ("\*" → "*"). */
    private function unescaped(string $ruleDesc): string
    {
        return (string) preg_replace('/\\\\(.)/', '$1', $ruleDesc);
    }

    private function comparable(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    private function money(mixed $amount): string
    {
        return '$'.number_format((float) $amount, 2);
    }
}
