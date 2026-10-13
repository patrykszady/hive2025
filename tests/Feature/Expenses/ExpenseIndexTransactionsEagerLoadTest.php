<?php

use App\Livewire\Expenses\ExpenseIndex;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('defers the unmatched-transactions table to a lazy island', function (): void {
    $vendor = Vendor::factory()->create();
    $user = User::query()->create([
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'expense-index-lazy@example.test',
        'cell_phone' => '2245550001',
        'password' => null,
        'primary_vendor_id' => $vendor->id,
    ]);
    $user->vendors()->attach($vendor->id, ['role_id' => 1]);

    $this->actingAs($user);

    $html = Livewire::test(ExpenseIndex::class)->html();

    // In the browser both tables are lazy islands (island_lazy()) and the
    // Transactions load is gated behind the Expenses load; under the test
    // runner islands render eagerly so assertions can see real content.
    expect($html)->toContain('Transactions')
        // the sequencing gate that holds Transactions until Expenses loads
        ->and($html)->toContain('transactionsGo')
        ->and($html)->toContain('expenses-table-loaded')
        // ...and the search box is present from the first paint.
        ->and($html)->toContain('Search vendor');
});

it('exposes the transactions search as a URL-bound query string property', function (): void {
    $reflection = new ReflectionClass(ExpenseIndex::class);

    $searchAttrs = $reflection->getProperty('transaction_search')
        ->getAttributes(\Livewire\Attributes\Url::class);

    expect($searchAttrs)->not->toBeEmpty()
        ->and($searchAttrs[0]->getArguments())->toMatchArray(['except' => '']);
});

it('shows an empty Transactions card instead of an error while the search index is being rebuilt', function (): void {
    $vendor = Vendor::factory()->create();
    $user = User::query()->create([
        'first_name' => 'Test', 'last_name' => 'User', 'email' => 'expense-index-rebuild@example.test',
        'cell_phone' => '2245550002', 'password' => null, 'primary_vendor_id' => $vendor->id,
    ]);
    $user->vendors()->attach($vendor->id, ['role_id' => 1]);
    $this->actingAs($user);

    // An engine that answers like Meilisearch does on an index that was just
    // deleted and recreated: no field is searchable yet.
    app(\Laravel\Scout\EngineManager::class)->extend('rebuilding', fn () => new class extends \Laravel\Scout\Engines\NullEngine
    {
        public function search(\Laravel\Scout\Builder $builder)
        {
            // Only a search on a field fails (the amount search); filter-only
            // searches on the empty, rebuilding index just find nothing.
            if (! $builder->model instanceof \App\Models\Transaction) {
                return ['hits' => [], 'totalHits' => 0, 'estimatedTotalHits' => 0];
            }

            throw new \Meilisearch\Exceptions\ApiException(new \GuzzleHttp\Psr7\Response(400), ['message' => 'Index `transaction_index_dev`: Attribute `amount` is not searchable. Available searchable attributes are: ``.', 'code' => 'invalid_search_attributes_to_search_on']);
        }

        public function paginate(\Laravel\Scout\Builder $builder, $perPage, $page)
        {
            return $this->search($builder);
        }

        public function getTotalCount($results)
        {
            return 0;
        }
    });
    config(['scout.driver' => 'rebuilding']);

    $component = Livewire::test(ExpenseIndex::class)->set('amount', '400');

    expect($component->instance()->transactions()->total())->toBe(0);
    $component->assertOk();
});
