<?php

use App\Livewire\Expenses\ExpenseShow;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Distribution;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\OutsideCard;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Card ••1854 paid two Amazon orders in March 2026 (expenses 26499 and 26525)
 * and is on none of GS Construction's bank accounts, so both sat at "No
 * Transaction" while the matcher hunted for a charge that could never come.
 *
 * @return array<string, mixed>
 */
function outsideCard_fixture(): array
{
    $company = Vendor::factory()->create(['business_name' => 'GS Construction & Remodeling, Inc', 'options' => ['short_name' => 'GS Construction']]);
    $amazon = Vendor::factory()->create(['business_name' => 'Amazon', 'business_type' => 'Retail']);

    $user = new User();
    $user->forceFill([
        'first_name' => 'Outside',
        'last_name' => 'Card',
        'email' => 'outside-card-'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224778####'),
        'primary_vendor_id' => $company->id,
    ]);
    $user->save();
    $company->users()->attach($user->id, ['role_id' => 1]);

    $bank = Bank::create(['name' => 'Capital One', 'vendor_id' => $company->id, 'plaid_ins_id' => 'ins_'.uniqid()]);
    $account = BankAccount::create(['vendor_id' => $company->id, 'bank_id' => $bank->id, 'account_number' => '4060', 'plaid_account_id' => 'acc_'.uniqid(), 'type' => 'Credit Card']);
    $distribution = Distribution::query()->create(['name' => 'Office', 'vendor_id' => $company->id]);

    $order = function (float $amount, string $date, string $lastFour) use ($company, $amazon, $distribution) {
        $expense = Expense::query()->create([
            'amount' => $amount, 'date' => $date, 'vendor_id' => $amazon->id, 'distribution_id' => $distribution->id,
            'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => 0,
        ]);
        ExpensePayment::create([
            'expense_id' => $expense->id, 'method' => ExpensePayment::CARD, 'amount' => $amount,
            'last_four' => $lastFour, 'paid_at' => $date, 'source' => ExpensePayment::SOURCE_MANUAL,
        ]);

        return $expense;
    };

    // A company card: an earlier order on it was paid by a charge on the company's account.
    $companyOrder = $order(41.10, '2026-03-01', '4849');
    Transaction::withoutGlobalScopes()->create([
        'bank_account_id' => $account->id, 'vendor_id' => $amazon->id, 'amount' => 41.10,
        'transaction_date' => '2026-03-02', 'expense_id' => $companyOrder->id, 'plaid_merchant_name' => 'Amazon',
    ]);

    return [
        'company' => $company,
        'user' => $user,
        'marion' => $order(60.48, '2026-03-13', '1854'),
        'greg' => $order(23.69, '2026-03-15', '1854'),
        'unmatchedCompanyCard' => $order(18.00, '2026-03-20', '4849'),
    ];
}

function outsideCard_status(Expense $expense): string
{
    return (fn () => $this->calculateStatus())->call($expense->fresh());
}

it('shows a card the company was never charged on as Not GS Construction, paid, once the matcher has had its time', function () {
    $fx = outsideCard_fixture();

    expect(outsideCard_status($fx['marion']))->toBe('Complete')
        ->and(outsideCard_status($fx['greg']))->toBe('Complete')
        ->and(outsideCard_status($fx['unmatchedCompanyCard']))->toBe('No Transaction')
        ->and(OutsideCard::query()->count())->toBe(0);

    Livewire::actingAs($fx['user'])
        ->test(ExpenseShow::class, ['expense' => $fx['greg']])
        ->assertSeeInOrder(['$23.69', '03/15/2026', 'Card', '••1854', 'Not GS Construction', 'entered by hand'])
        ->assertSee("It's ours", false)
        ->assertDontSee('Link bank transaction');
});

it('gives a recent card payment time to find its charge', function () {
    $fx = outsideCard_fixture();
    $fx['marion']->forceFill(['date' => now()->subDays(5)->toDateString()])->saveQuietly();
    $fx['marion']->payments()->update(['paid_at' => now()->subDays(5)->toDateString()]);

    expect(outsideCard_status($fx['marion']))->toBe('No Transaction');
});

it('treats one of the company\'s account numbers as its own card', function () {
    $fx = outsideCard_fixture();
    $fx['marion']->payments()->update(['last_four' => '4060']);

    expect(outsideCard_status($fx['marion']))->toBe('No Transaction');
});

it('puts the card back when someone says it\'s ours', function () {
    $fx = outsideCard_fixture();

    Livewire::actingAs($fx['user'])
        ->test(ExpenseShow::class, ['expense' => $fx['marion']])
        ->call('unmarkOutsideCard', '1854')
        ->assertRedirect(route('expenses.show', $fx['marion']));

    expect(OutsideCard::query()->where('last_four', '1854')->value('is_outside'))->toBeFalse()
        ->and(outsideCard_status($fx['marion']))->toBe('No Transaction')
        ->and(outsideCard_status($fx['greg']))->toBe('No Transaction');

    Livewire::actingAs($fx['user'])
        ->test(ExpenseShow::class, ['expense' => $fx['marion']->fresh()])
        ->assertSee('Link bank transaction')
        ->assertDontSee('Not GS Construction');
});

it('keeps one company\'s ruling on a card away from another company\'s expenses', function () {
    $fx = outsideCard_fixture();
    OutsideCard::create(['belongs_to_vendor_id' => Vendor::factory()->create()->id, 'last_four' => '1854', 'is_outside' => false]);

    expect(outsideCard_status($fx['marion']))->toBe('Complete');
});

it('counts an expense paid in full with store credit as complete', function () {
    // 83 expenses paid this way read "No Transaction" although their page
    // said "Paid without a bank charge" (2026-10-22).
    $fx = outsideCard_fixture();
    $fx['marion']->payments()->update(['method' => ExpensePayment::STORE_CREDIT, 'last_four' => null]);

    expect(outsideCard_status($fx['marion']))->toBe('Complete');

    $fx['marion']->payments()->update(['amount' => 20.00]);

    expect(outsideCard_status($fx['marion']))->toBe('No Transaction');
});
