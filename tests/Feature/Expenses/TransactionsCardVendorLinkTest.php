<?php

use App\Livewire\Expenses\ExpenseIndex;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * The vendor on a row of the Expenses screen's Transactions card opens the
 * vendor's page in a new tab, so the queue being worked through stays open
 * (Patryk, 2026-10-13).
 */
/** A search engine that finds this one unmatched row, the way Meilisearch would. */
function useOneRowSearch(int $transactionId): void
{
    app(\Laravel\Scout\EngineManager::class)->extend('one-row', fn () => new class($transactionId) extends \Laravel\Scout\Engines\NullEngine
    {
        public function __construct(private int $transactionId) {}

        public function search(\Laravel\Scout\Builder $builder)
        {
            return $builder->model instanceof Transaction
                ? ['hits' => [['id' => $this->transactionId]], 'totalHits' => 1]
                : ['hits' => [], 'totalHits' => 0];
        }

        public function paginate(\Laravel\Scout\Builder $builder, $perPage, $page)
        {
            return $this->search($builder);
        }

        public function mapIds($results)
        {
            return collect($results['hits'])->pluck('id');
        }

        public function map(\Laravel\Scout\Builder $builder, $results, $model)
        {
            $ids = collect($results['hits'])->pluck('id')->all();

            return $ids === [] ? $model->newCollection() : $model->queryScoutModelsByIds($builder, $ids)->get();
        }

        public function getTotalCount($results)
        {
            return $results['totalHits'];
        }
    });
    config(['scout.driver' => 'one-row']);
}

/** A company with a checking account and an Admin signed in. @return array{company: Vendor, account: BankAccount} */
function transactionsCardCompany(): array
{
    $company = Vendor::factory()->create(['business_name' => 'Owner LLC']);
    $bank = Bank::create(['name' => 'Citibank', 'vendor_id' => $company->id, 'plaid_ins_id' => 'ins_5']);
    $account = BankAccount::create([
        'vendor_id' => $company->id, 'bank_id' => $bank->id, 'account_number' => '4903',
        'plaid_account_id' => 'acc_'.uniqid(), 'type' => 'checking',
    ]);
    $user = User::query()->create([
        'first_name' => 'Patryk', 'last_name' => 'Szady', 'email' => 'tx-card-'.uniqid().'@example.test',
        'cell_phone' => '2245550003', 'password' => null, 'primary_vendor_id' => $company->id,
    ]);
    $user->vendors()->attach($company->id, ['role_id' => 1]);
    test()->actingAs($user);

    return ['company' => $company, 'account' => $account];
}

it('opens a table link in a new tab only when asked, without Livewire navigation', function (): void {
    $newTab = Blade::render('<x-table-link href="https://hive.test/vendors/5" label="Marcel Page Photography" new-tab />');
    $sameTab = Blade::render('<x-table-link href="https://hive.test/vendors/5" label="Marcel Page Photography" />');

    expect($newTab)->toContain('target="_blank"')
        ->and($newTab)->toContain('rel="noopener"')
        ->and($newTab)->not->toContain('wire:navigate')
        ->and($newTab)->toContain('Marcel Page Photography')
        ->and($sameTab)->toContain('wire:navigate.hover')
        ->and($sameTab)->not->toContain('target="_blank"');
});

it('links the vendor of an unmatched transaction to the vendor page in a new tab', function (): void {
    $company = Vendor::factory()->create(['business_name' => 'Owner LLC']);
    $payee = Vendor::factory()->create(['business_name' => 'Marcel Page Photography']);
    $company->vendors()->attach($payee->id);
    $bank = Bank::create(['name' => 'Citibank', 'vendor_id' => $company->id, 'plaid_ins_id' => 'ins_5']);
    $account = BankAccount::create([
        'vendor_id' => $company->id, 'bank_id' => $bank->id, 'account_number' => '4903',
        'plaid_account_id' => 'acc_'.uniqid(), 'type' => 'checking',
    ]);
    $user = User::query()->create([
        'first_name' => 'Patryk', 'last_name' => 'Szady', 'email' => 'tx-vendor-link-'.uniqid().'@example.test',
        'cell_phone' => '2245550003', 'password' => null, 'primary_vendor_id' => $company->id,
    ]);
    $user->vendors()->attach($company->id, ['role_id' => 1]);
    $transaction = Transaction::create([
        'transaction_date' => '2026-07-22', 'posted_date' => '2026-07-23', 'amount' => 570,
        'bank_account_id' => $account->id, 'plaid_transaction_id' => 'tx_'.uniqid(),
        'vendor_id' => $payee->id, 'plaid_merchant_description' => 'MARCEL PAGE PHOTOGRAPHY',
    ]);
    $this->actingAs($user);

    useOneRowSearch($transaction->id);

    $html = Livewire::test(ExpenseIndex::class)->html();

    expect($html)->toContain('Marcel Page Photography')
        ->and($html)->toMatch('#<a href="'.preg_quote(route('vendors.show', $payee->id), '#').'" target="_blank" rel="noopener"#');
});

it('marks a cash withdrawal "Cash" on its row', function (): void {
    ['account' => $account] = transactionsCardCompany();
    $atm = Transaction::create([
        'transaction_date' => '2026-08-17', 'posted_date' => '2026-08-17', 'amount' => 500,
        'bank_account_id' => $account->id, 'plaid_transaction_id' => 'tx_'.uniqid(),
        'check_number' => Transaction::CASH_CHECK_NUMBER, 'plaid_merchant_description' => 'Citibank ATM Withdrawal 4849 1828 N ARLNGTN HTS RD',
    ]);
    useOneRowSearch($atm->id);

    $html = Livewire::test(ExpenseIndex::class)->html();

    // The badge, then (past Livewire's marker comments) the bank's text.
    expect($html)->toMatch('#data-flux-badge[^>]*>\s*Cash\s*</div>(?:\s|<!--.*?-->)*Citibank ATM Withdrawal#s');
});
