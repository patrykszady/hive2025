<?php

use App\Http\Controllers\TransactionController;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Check;
use App\Models\Expense;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Services\Transactions\UnenteredCheckMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Replays Climate Solutions invoice 68405 (expense 28901, 2026-10-20):
 * $14,742.50, paid by check 2662 ($8,000 deposit, cleared 09/18) and check
 * 2683 ($6,742.50 balance at pickup, cleared 10/16) on Citibank 4903 —
 * neither check entered in Hive, so nothing linked them to the expense.
 *
 * @return array<string, mixed>
 */
function unentered_fixture(string $supplierType = 'Retail'): array
{
    test()->travelTo('2026-10-20 12:00:00');
    config(['scout.driver' => null]);

    $company = Vendor::factory()->create(['business_name' => 'GS Test Co', 'business_type' => 'Sub']);
    $company->forceFill(['registration' => ['registered' => true]])->save();
    $supplier = Vendor::factory()->create(['business_name' => 'Climate Solutions Window', 'business_type' => $supplierType]);
    $bank = Bank::create(['name' => 'Citibank', 'vendor_id' => $company->id, 'plaid_ins_id' => 'ins_unentered_'.uniqid()]);
    $account = BankAccount::create(['vendor_id' => $company->id, 'bank_id' => $bank->id, 'account_number' => '4903', 'plaid_account_id' => 'acc_unentered_'.uniqid(), 'type' => 'checking']);

    $expense = Expense::query()->create([
        'amount' => 14742.50,
        'date' => '2026-10-16',
        'invoice' => '68405',
        'vendor_id' => $supplier->id,
        'belongs_to_vendor_id' => $company->id,
        'created_by_user_id' => 0,
    ]);

    return compact('company', 'supplier', 'bank', 'account', 'expense');
}

function unentered_charge(array $fx, string $number, float $amount, string $date): Transaction
{
    return Transaction::withoutGlobalScopes()->create([
        'bank_account_id' => $fx['account']->id,
        'transaction_date' => $date,
        'amount' => $amount,
        'check_number' => $number,
        'plaid_merchant_description' => "CHECK {$number}",
    ]);
}

/** The other checks that cleared on Citibank 4903 around it. */
function unentered_neighbours(array $fx): void
{
    unentered_charge($fx, '2678', 3100.00, '2026-10-06');
    unentered_charge($fx, '2682', 13295.32, '2026-10-14');
    unentered_charge($fx, '2663', 1000.00, '2026-09-18');
    unentered_charge($fx, '2685', 900.00, '2026-10-19');
    unentered_charge($fx, '0000', 5475.00, '2026-10-13');
}

it('pays a supplier expense with the deposit and balance checks that add up to it', function () {
    $fx = unentered_fixture();
    $deposit = unentered_charge($fx, '2662', 8000.00, '2026-09-18');
    $balance = unentered_charge($fx, '2683', 6742.50, '2026-10-16');
    unentered_neighbours($fx);

    // The other $8,000 check that month was Vol Nat Heating's, already entered and linked.
    $volNat = Check::create(['check_type' => 'Check', 'check_number' => '2656', 'date' => '2026-09-03', 'amount' => 8000.00, 'bank_account_id' => $fx['account']->id, 'belongs_to_vendor_id' => $fx['company']->id, 'created_by_user_id' => 0]);
    unentered_charge($fx, '2656', 8000.00, '2026-09-04')->forceFill(['check_id' => $volNat->id])->save();

    app(TransactionController::class)->add_check_id_to_transactions();

    $checks = $fx['expense']->fresh()->checks()->orderBy('check_number')->get();

    expect($checks)->toHaveCount(2)
        ->and($checks->pluck('check_number')->map(fn ($n) => (string) $n)->all())->toBe(['2662', '2683'])
        ->and($checks->pluck('amount')->map(fn ($a) => (float) $a)->all())->toBe([8000.0, 6742.5])
        ->and($checks->pluck('vendor_id')->unique()->all())->toBe([$fx['supplier']->id])
        ->and($checks->pluck('bank_account_id')->unique()->all())->toBe([$fx['account']->id])
        ->and($deposit->fresh()->check_id)->toBe($checks[0]->id)
        ->and($balance->fresh()->check_id)->toBe($checks[1]->id)
        ->and($deposit->fresh()->expense_id)->toBeNull();
});

it('does nothing when two different sets of checks add up to the expense', function () {
    $fx = unentered_fixture();
    unentered_charge($fx, '2662', 8000.00, '2026-09-18');
    unentered_charge($fx, '2683', 6742.50, '2026-10-16');
    unentered_charge($fx, '2690', 7000.00, '2026-09-20');
    unentered_charge($fx, '2691', 7742.50, '2026-10-17');

    expect(app(UnenteredCheckMatcher::class)->run())->toBe(0)
        ->and(Check::withoutGlobalScopes()->count())->toBe(0);
});

it('leaves subcontractor expenses to the checks written in Hive', function () {
    $fx = unentered_fixture('Sub');
    unentered_charge($fx, '2662', 8000.00, '2026-09-18');
    unentered_charge($fx, '2683', 6742.50, '2026-10-16');

    expect(app(UnenteredCheckMatcher::class)->run())->toBe(0)
        ->and(Check::withoutGlobalScopes()->count())->toBe(0);
});

it('never writes a second check for one already entered in Hive', function () {
    $fx = unentered_fixture();
    unentered_charge($fx, '2662', 8000.00, '2026-09-18');
    unentered_charge($fx, '2683', 6742.50, '2026-10-16');
    Check::create(['check_type' => 'Check', 'check_number' => '2683', 'date' => '2026-10-15', 'amount' => 6742.50, 'bank_account_id' => $fx['account']->id, 'belongs_to_vendor_id' => $fx['company']->id, 'created_by_user_id' => 0]);

    expect(app(UnenteredCheckMatcher::class)->run())->toBe(0)
        ->and(Check::withoutGlobalScopes()->where('check_number', '2683')->count())->toBe(1);
});

it('wants the last check to clear around the invoice date', function () {
    $fx = unentered_fixture();
    unentered_charge($fx, '2662', 8000.00, '2026-08-01');
    unentered_charge($fx, '2683', 6742.50, '2026-09-01');

    expect(app(UnenteredCheckMatcher::class)->run())->toBe(0);
});

it('matches a round amount only to a single check', function () {
    $fx = unentered_fixture();
    $fx['expense']->forceFill(['amount' => 12000.00])->save();
    unentered_charge($fx, '2662', 6000.00, '2026-09-18');
    unentered_charge($fx, '2683', 6000.00, '2026-10-16');

    expect(app(UnenteredCheckMatcher::class)->run())->toBe(0);

    $single = unentered_charge($fx, '2684', 12000.00, '2026-10-17');

    expect(app(UnenteredCheckMatcher::class)->run())->toBe(1)
        ->and($single->fresh()->check_id)->not->toBeNull();
});

it('pays an expense only once however often the schedule runs', function () {
    $fx = unentered_fixture();
    unentered_charge($fx, '2662', 8000.00, '2026-09-18');
    unentered_charge($fx, '2683', 6742.50, '2026-10-16');

    expect(app(UnenteredCheckMatcher::class)->run())->toBe(1)
        ->and(app(UnenteredCheckMatcher::class)->run())->toBe(0)
        ->and(Check::withoutGlobalScopes()->count())->toBe(2);
});

it('counts the bank lines of every check paying the expense in its status', function () {
    $fx = unentered_fixture();
    $project = \App\Models\Project::withoutEvents(fn () => \App\Models\Project::create([
        'project_name' => 'Bates', 'client_id' => 1, 'address' => '1400 Kenilwood Lane',
        'city' => 'Kenilworth', 'state' => 'IL', 'zip_code' => 60043, 'belongs_to_vendor_id' => $fx['company']->id,
    ]));
    $fx['expense']->forceFill(['project_id' => $project->id])->save();

    expect($fx['expense']->fresh()->status)->toBe('No Transaction');

    unentered_charge($fx, '2662', 8000.00, '2026-09-18');
    unentered_charge($fx, '2683', 6742.50, '2026-10-16');
    app(UnenteredCheckMatcher::class)->run();

    // https://hive.contractors/checks/3994 showed "No Transaction" (2026-10-20).
    expect($fx['expense']->fresh()->status)->toBe('Complete');
});

/**
 * Climate Solutions invoice 68297 (expense 28924): $27,618.69, paid with a
 * $14,420.00 card charge the bank tagged with the vendor (a $14,000 deposit +
 * the 3% fee billed as "Misc. Charges") and check 2682 for $13,295.32 two
 * days after the invoice — $96.63 more than the $13,198.69 left.
 */
function deposit_fixture(): array
{
    $fx = unentered_fixture();
    $fx['expense']->forceFill(['amount' => 27618.69, 'date' => '2026-10-12', 'invoice' => '68297'])->save();
    $card = BankAccount::create(['vendor_id' => $fx['company']->id, 'bank_id' => $fx['bank']->id, 'account_number' => '7712', 'plaid_account_id' => 'acc_card_'.uniqid(), 'type' => 'credit']);
    $fx['deposit'] = Transaction::withoutGlobalScopes()->create([
        'bank_account_id' => $card->id,
        'transaction_date' => '2026-08-29',
        'amount' => 14420.00,
        'vendor_id' => $fx['supplier']->id,
        'plaid_merchant_name' => 'Climate Solutions Window',
        'plaid_merchant_description' => 'CLIMATE SOLUTIONS WINDOW',
    ]);

    return $fx;
}

it('pays an invoice with the vendor\'s card deposit and the one check that covers the balance', function () {
    $fx = deposit_fixture();
    unentered_charge($fx, '2642', 13000.00, '2026-08-03');
    unentered_charge($fx, '2678', 3100.00, '2026-10-06');
    $balance = unentered_charge($fx, '2682', 13295.32, '2026-10-14');

    app(TransactionController::class)->add_check_id_to_transactions();

    $expense = $fx['expense']->fresh();
    $checks = $expense->checks()->get();

    expect($fx['deposit']->fresh()->expense_id)->toBe($expense->id)
        ->and($checks)->toHaveCount(1)
        ->and((string) $checks[0]->check_number)->toBe('2682')
        ->and((float) $checks[0]->amount)->toBe(13295.32)
        ->and($checks[0]->vendor_id)->toBe($fx['supplier']->id)
        ->and($balance->fresh()->check_id)->toBe($checks[0]->id);
});

it('does not pay it when two checks could be the balance', function () {
    $fx = deposit_fixture();
    unentered_charge($fx, '2682', 13295.32, '2026-10-14');
    unentered_charge($fx, '2684', 13150.00, '2026-10-18');

    expect(app(UnenteredCheckMatcher::class)->run())->toBe(0)
        ->and($fx['deposit']->fresh()->expense_id)->toBeNull()
        ->and(Check::withoutGlobalScopes()->count())->toBe(0);
});

it('does not pay it when the check is more than a percent off the balance', function () {
    $fx = deposit_fixture();
    unentered_charge($fx, '2682', 13500.00, '2026-10-14');

    expect(app(UnenteredCheckMatcher::class)->run())->toBe(0)
        ->and($fx['deposit']->fresh()->expense_id)->toBeNull();
});

it('does not take a charge the bank did not tag with the vendor as the deposit', function () {
    $fx = deposit_fixture();
    $fx['deposit']->forceFill(['vendor_id' => null])->save();
    unentered_charge($fx, '2682', 13295.32, '2026-10-14');

    expect(app(UnenteredCheckMatcher::class)->run())->toBe(0)
        ->and(Check::withoutGlobalScopes()->count())->toBe(0);
});
