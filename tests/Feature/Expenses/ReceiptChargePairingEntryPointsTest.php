<?php

use App\Http\Controllers\CompanyEmailController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\TransactionController;
use App\Livewire\Transactions\MatchVendor;
use App\Models\CompanyEmail;
use App\Models\Distribution;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\ReceiptAccount;
use App\Models\Transaction;
use App\Models\TransactionBulkMatch;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorTransaction;
use App\Services\NylasService;
use App\Services\Transactions\UnmatchedTransactionCards;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

require_once __DIR__.'/../../Support/receipt-charge-pairing.php';

uses(RefreshDatabase::class);

/**
 * Where ReceiptChargePairer is called from: the hourly command, the scanned
 * receipt intake, the bank sweep that makes expenses from charges, and the
 * Match Vendor page and app screen. The rule itself is pinned in
 * ReceiptChargePairerTest.
 */
beforeEach(function (): void {
    $this->travelTo('2026-11-12 10:00:00');
    // The models run this check themselves the moment a receipt or a charge
    // lands on an expense (ReceiptSweepAndDuplicateCheckTest). Held back
    // here: these tests build the two halves first, then call the rule.
    pairingAutomaticCheck(false);
    pairingCompany();
});

/**
 * Run the scanned-receipt intake over one "Receipt Scans" email whose
 * attachments read as the given tickets.
 *
 * @param  list<array<string, mixed>>  $tickets  receipt reader fields, one per attachment
 */
function pairingScanIntake(string $messageId, array $tickets): void
{
    $nylas = Mockery::mock(NylasService::class);
    $nylas->shouldReceive('syncMessages')->andReturn(['messages' => [[
        'id' => $messageId,
        'from' => [['email' => 'noreply@print.epsonconnect.com']],
        'subject' => 'Receipt Scans',
        'date' => now()->timestamp,
        'attachments' => collect($tickets)->keys()->map(fn (int $index): array => ['id' => $messageId.'-att-'.$index, 'filename' => 'scan-'.$index.'.pdf'])->all(),
    ]]]);
    $nylas->shouldReceive('downloadAttachment')->andReturn('%PDF-scan');
    $nylas->shouldReceive('moveOriginalMessageToHiveFolder')->andReturnNull();
    app()->instance(NylasService::class, $nylas);

    $reader = Mockery::mock(ReceiptController::class)->makePartial();
    $reader->shouldReceive('extractReceipt')->andReturnUsing(function () use (&$tickets): array {
        $fields = array_shift($tickets);

        return ['fields' => $fields, 'content' => (string) $fields['raw_content']];
    });
    app()->instance(ReceiptController::class, $reader);

    (new CompanyEmailController($nylas))->fetchAutoReceipts();
}

function pairingMailbox(): void
{
    Storage::fake('files');

    // The printed name shares words with a vendor on file, as in production:
    // the intake then takes it for a real name and will not attach the scan
    // to an expense of some other vendor on amount and date alone.
    pairingVendor('Cook County Treasurer');

    CompanyEmail::create([
        'vendor_id' => test()->company->id,
        'email' => 'receipts@example.test',
        'grant_id' => 'grant-pairing',
        'api_json' => ['INBOX_FOLDER' => 'inbox-fld', 'HIVE_RECEIPTS_FOLDER' => 'hive-fld'],
    ]);
}

it('finds the charge\'s expense while the scanned ticket is being filed, and files the next one under the vendor', function () {
    pairingAutomaticCheck(true);
    pairingMailbox();
    $admin = pairingAdmin();
    $charge = pairingChargeExpense();
    $transaction = Transaction::withoutGlobalScopes()->where('expense_id', $charge->id)->sole();

    pairingScanIntake('msg-ticket-1', [pairingTicketFields(41.00, '2026-11-04')]);

    // The expense the intake was making is the one that stays, with the
    // receipt and the bank charge both on it; the charge's own is folded in.
    $scan = Expense::withoutGlobalScopes()->whereNull('deleted_at')->sole();
    $receipt = ExpenseReceipts::query()->sole();

    expect($scan->id)->not->toBe($charge->id)
        ->and(pairingTrashed($charge))->toBeTrue()
        ->and($scan)->vendor_id->toBe($this->groot->id)->invoice->toBe('4249778')
        ->and($receipt->expense_id)->toBe($scan->id)
        ->and($transaction->fresh()->expense_id)->toBe($scan->id)
        ->and(Storage::disk('files')->exists('receipts/'.$receipt->receipt_filename))->toBeTrue()
        ->and(VendorTransaction::query()->sole())->vendor_id->toBe($this->groot->id)->desc->toBe(PAIRING_PRINTED_NAME)
        ->and(app(UnmatchedTransactionCards::class)->expenseCards($admin))->toBeEmpty();

    // The next ticket from that station, before its charge has reached the bank feed.
    pairingScanIntake('msg-ticket-2', [pairingTicketFields(33.00, '2026-11-11', ticket: '4250001')]);

    $next = Expense::withoutGlobalScopes()->whereNull('deleted_at')->where('invoice', '4250001')->sole();

    expect($next->vendor_id)->toBe($this->groot->id)
        ->and(app(UnmatchedTransactionCards::class)->expenseCards($admin))->toBeEmpty()
        ->and(VendorTransaction::query()->count())->toBe(1);
});

it('does the same for the next ticket when its charge already has an expense', function () {
    pairingAutomaticCheck(true);
    pairingMailbox();
    VendorTransaction::create(['vendor_id' => $this->groot->id, 'desc' => PAIRING_PRINTED_NAME, 'options' => json_encode('/i')]);
    $charge = pairingChargeExpense(['amount' => 52.00, 'date' => '2026-11-06']);

    pairingScanIntake('msg-ticket-3', [pairingTicketFields(52.00, '2026-11-06', ticket: '4250111')]);

    $scan = Expense::withoutGlobalScopes()->whereNull('deleted_at')->sole();

    expect($scan->id)->not->toBe($charge->id)
        ->and(pairingTrashed($charge))->toBeTrue()
        ->and($scan)->vendor_id->toBe($this->groot->id)->invoice->toBe('4250111')
        ->and(pairingReceiptCount($scan))->toBe(1)
        ->and(Transaction::withoutGlobalScopes()->where('expense_id', $scan->id)->count())->toBe(1)
        ->and(VendorTransaction::query()->count())->toBe(1);
});

it('keeps a scan the rule cannot place as its own expense', function () {
    pairingAutomaticCheck(true);
    pairingMailbox();
    $first = pairingChargeExpense();
    $second = pairingChargeExpense();

    pairingScanIntake('msg-ticket-4', [pairingTicketFields(41.00, '2026-11-04')]);

    $scan = Expense::withoutGlobalScopes()->whereNull('deleted_at')->where('invoice', '4249778')->sole();

    expect($scan->vendor_id)->toBe(0)
        ->and(pairingReceiptCount($scan))->toBe(1)
        ->and(pairingReceiptCount($first) + pairingReceiptCount($second))->toBe(0)
        ->and(VendorTransaction::query()->count())->toBe(0);
});

it('folds a waiting receipt into the expense the bank sweep makes from its charge', function () {
    $dump = Distribution::query()->create(['name' => 'DUMP FEES', 'vendor_id' => $this->company->id]);
    ReceiptAccount::create(['vendor_id' => $this->groot->id, 'belongs_to_vendor_id' => $this->company->id]);
    TransactionBulkMatch::create([
        'vendor_id' => $this->groot->id, 'amount' => null, 'distribution_id' => $dump->id,
        'options' => ['amount_type' => 'ANY', 'desc' => null, 'splits' => []],
        'belongs_to_vendor_id' => $this->company->id,
    ]);

    pairingAutomaticCheck(true);
    $receipt = pairingReceiptExpense();
    $charge = pairingCharge();

    app(TransactionController::class)->transaction_vendor_bulk_match();

    $made = Expense::withoutGlobalScopes()->whereNull('deleted_at')->sole();

    expect($made->id)->not->toBe($receipt->id)
        ->and($charge->fresh()->expense_id)->toBe($made->id)
        ->and($made)->vendor_id->toBe($this->groot->id)->distribution_id->toBe($dump->id)->invoice->toBe('4249778')->created_by_user_id->toBe(0)
        ->and(pairingReceiptCount($made))->toBe(1)
        ->and(pairingTrashed($receipt))->toBeTrue()
        ->and(VendorTransaction::query()->sole()->desc)->toBe(PAIRING_PRINTED_NAME);
});

it('does not ask a person on Match Vendor who a receipt paid when its charge already says', function () {
    $admin = pairingAdmin();
    $charge = pairingChargeExpense();
    $receipt = pairingReceiptExpense();
    $unknown = pairingReceiptExpense(['amount' => 12.34, 'invoice' => '77', 'date' => '2026-11-05'], '4849', 'Unknown Corner Shop');

    expect(app(UnmatchedTransactionCards::class)->expenseCards($admin)->keys()->all())
        ->toEqualCanonicalizing([PAIRING_PRINTED_NAME, 'Unknown Corner Shop']);

    $page = Livewire::actingAs($admin)->test(MatchVendor::class);

    expect($page->instance()->expenseCards->keys()->all())->toBe(['Unknown Corner Shop'])
        ->and($page->html())->toContain('Unknown Corner Shop')
        ->and(pairingTrashed($receipt))->toBeTrue()
        ->and(pairingTrashed($unknown))->toBeFalse()
        ->and(pairingReceiptCount($charge))->toBe(1)
        ->and(VendorTransaction::query()->sole()->vendor_id)->toBe($this->groot->id);
});

it('only pairs the signed-in company\'s receipts when Match Vendor loads', function () {
    $charge = pairingChargeExpense();
    $receipt = pairingReceiptExpense();

    $otherCompany = Vendor::factory()->create(['business_name' => 'Another Builder', 'business_type' => 'Sub']);
    $otherCompany->forceFill(['registration' => ['registered' => true]])->save();
    $outsider = User::factory()->create(['primary_vendor_id' => $otherCompany->id]);
    $outsider->vendors()->attach($otherCompany->id, ['role_id' => 1]);

    Livewire::actingAs($outsider)->test(MatchVendor::class);

    expect(pairingTrashed($receipt))->toBeFalse()
        ->and(pairingReceiptCount($charge))->toBe(0);
});

it('leaves the receipt out of the app\'s unmatched list the same way', function () {
    $admin = pairingAdmin();
    $charge = pairingChargeExpense();
    $receipt = pairingReceiptExpense();
    pairingReceiptExpense(['amount' => 12.34, 'invoice' => '77', 'date' => '2026-11-05'], '4849', 'Unknown Corner Shop');

    Sanctum::actingAs($admin, ['app']);

    $cards = $this->getJson('/api/app/v1/transactions/unmatched')->assertSuccessful()->json('data.expense_cards');

    expect(collect($cards)->pluck('merchant_name')->all())->toBe(['Unknown Corner Shop'])
        ->and(pairingTrashed($receipt))->toBeTrue()
        ->and(pairingReceiptCount($charge))->toBe(1);
});

it('lists pairs and what it left alone on a dry run, and changes nothing', function () {
    $charge = pairingChargeExpense();
    $receipt = pairingReceiptExpense();
    $otherCard = pairingChargeExpense(['amount' => 25.00, 'date' => '2026-11-06']);
    $otherReceipt = pairingReceiptExpense(['amount' => 25.00, 'date' => '2026-11-06', 'invoice' => '4250500'], card: '1854');

    Artisan::call('expenses:pair-receipts-with-charges', ['--dry-run' => true]);
    $output = Artisan::output();

    expect($output)
        ->toContain('Would pair 1 receipt expense(s) with their bank charge.')
        ->toContain('the receipt prints card ••4849, the card that was charged')
        ->toContain('"'.PAIRING_PRINTED_NAME.'" learned as Groot')
        ->toContain('Left for a person:')
        ->toContain('the receipt prints card ••1854; the charge of that amount (expense #'.$otherCard->id.') was made with ••4849')
        ->and(pairingTrashed($receipt))->toBeFalse()
        ->and(pairingTrashed($otherReceipt))->toBeFalse()
        ->and(pairingReceiptCount($charge))->toBe(0)
        ->and(VendorTransaction::query()->count())->toBe(0);
});

it('pairs for real without the flag, and a second run finds nothing left to do', function () {
    $charge = pairingChargeExpense();
    $receipt = pairingReceiptExpense();

    Artisan::call('expenses:pair-receipts-with-charges');
    $first = Artisan::output();

    expect($first)->toContain('Paired 1 receipt expense(s) with their bank charge.')
        ->and(pairingTrashed($receipt))->toBeTrue()
        ->and(pairingReceiptCount($charge))->toBe(1)
        ->and(VendorTransaction::query()->count())->toBe(1);

    Artisan::call('expenses:pair-receipts-with-charges');

    expect(Artisan::output())->toContain('No receipt expenses to pair with a bank charge.')
        ->and(VendorTransaction::query()->count())->toBe(1)
        ->and(Expense::query()->count())->toBe(1);
});

it('looks at the last twelve months unless told otherwise', function (array $options, bool $pairs) {
    $charge = pairingChargeExpense(['date' => '2024-03-05']);
    $receipt = pairingReceiptExpense(['date' => '2024-03-05']);

    Artisan::call('expenses:pair-receipts-with-charges', $options);

    expect(pairingTrashed($receipt))->toBe($pairs)
        ->and(pairingReceiptCount($charge))->toBe($pairs ? 1 : 0);
})->with([
    'by default' => [[], false],
    '--since after it' => [['--since' => '2024-03-06'], false],
    '--since on its day' => [['--since' => '2024-03-05'], true],
    '--all' => [['--all' => true], true],
]);

it('runs every hour next to the paid-invoice merge, in production only', function () {
    $events = collect(app(Schedule::class)->events());
    $event = $events->first(fn ($event) => str_contains((string) $event->command, 'expenses:pair-receipts-with-charges'));
    $sibling = $events->first(fn ($event) => str_contains((string) $event->command, 'expenses:merge-paid-invoices'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe($sibling->expression)->toBe('0 * * * *')
        ->and($event->environments)->toBe(['production'])
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});
