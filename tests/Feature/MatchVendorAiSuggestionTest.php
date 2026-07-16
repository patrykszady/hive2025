<?php

use App\Livewire\Transactions\MatchVendor;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorTransaction;
use App\Services\VendorSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function aiSuggestionActor(): array
{
    $vendor = Vendor::factory()->create([
        'business_name' => 'Fixture Holding Co',
        'business_type' => 'Sub',
    ]);
    $vendor->forceFill(['registration' => ['registered' => true]])->save();

    $user = User::query()->create([
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'ai-test-user@example.com',
        'cell_phone' => '5551234567',
        'password' => bcrypt('password'),
        'primary_vendor_id' => $vendor->id,
    ]);
    $user->vendors()->attach($vendor->id, ['role_id' => 1]);

    $bank = Bank::create([
        'name' => 'Test Bank',
        'vendor_id' => $vendor->id,
        'plaid_ins_id' => 'ins_test',
    ]);
    $account = BankAccount::create([
        'vendor_id' => $vendor->id,
        'bank_id' => $bank->id,
        'account_number' => '1234',
        'plaid_account_id' => 'acc_test_'.uniqid(),
        'type' => 'credit',
    ]);

    return [$user, $account];
}

function rocketTransaction(BankAccount $account): Transaction
{
    return Transaction::create([
        'transaction_date' => now()->subDay(),
        'amount' => 20.50,
        'bank_account_id' => $account->id,
        'plaid_merchant_name' => 'Rocket',
        'plaid_merchant_description' => 'ROCKET 6546',
        'details' => ['category' => ['Food and Drink', 'Restaurants'], 'payment_channel' => 'in store'],
    ]);
}

it('stores an AI suggestion for the descriptor card', function () {
    [$user, $account] = aiSuggestionActor();
    rocketTransaction($account);

    $this->mock(VendorSuggestionService::class, function ($mock) {
        $mock->shouldReceive('suggest')
            ->once()
            ->withArgs(fn (string $descriptor) => $descriptor === 'ROCKET 6546')
            ->andReturn([
                'vendor_name' => 'Rocket Fizz',
                'existing_vendor_id' => null,
                'website' => 'https://rocketfizz.com',
                'city' => 'Arlington Heights',
                'state' => 'IL',
                'match_desc' => 'ROCKET ',
                'confidence' => 'medium',
                'reasoning' => 'Candy and soda shop chain; matches an in-store restaurant-category charge.',
            ]);
    });

    $this->withSession(['is_admin_login_as' => true])->actingAs($user);

    Livewire::test(MatchVendor::class)
        ->call('suggestVendor', 0)
        ->assertSet('ai_suggestions.0.vendor_name', 'Rocket Fizz')
        ->assertSee('Rocket Fizz');
});

it('fills the form when accepting an existing-vendor suggestion', function () {
    [$user, $account] = aiSuggestionActor();
    rocketTransaction($account);
    $existing = Vendor::factory()->create([
        'business_name' => 'Rocket Fizz',
        'business_type' => 'Retail',
    ]);

    $this->withSession(['is_admin_login_as' => true])->actingAs($user);

    Livewire::test(MatchVendor::class)
        ->set('ai_suggestions', [0 => [
            'vendor_name' => 'Rocket Fizz',
            'existing_vendor_id' => $existing->id,
            'website' => null,
            'city' => null,
            'state' => null,
            'match_desc' => 'ROCKET ',
            'confidence' => 'high',
            'reasoning' => 'Existing vendor.',
        ]])
        ->call('applySuggestion', 0)
        ->assertSet('match_merchant_names.0.vendor_id', (string) $existing->id)
        ->assertSet('match_merchant_names.0.match_desc', 'ROCKET ');

    expect(Vendor::withoutGlobalScopes()->where('business_name', 'Rocket Fizz')->count())->toBe(1);
});

it('creates the vendor, alias, and links transactions when accepting a new-vendor suggestion', function () {
    [$user, $account] = aiSuggestionActor();
    $transaction = rocketTransaction($account);

    $this->withSession(['is_admin_login_as' => true])->actingAs($user);

    Livewire::test(MatchVendor::class)
        ->set('ai_suggestions', [0 => [
            'vendor_name' => 'Rocket Fizz',
            'existing_vendor_id' => null,
            'website' => 'https://rocketfizz.com',
            'city' => 'Arlington Heights',
            'state' => 'IL',
            'match_desc' => 'ROCKET ',
            'confidence' => 'medium',
            'reasoning' => 'Candy shop.',
        ]])
        ->call('applySuggestion', 0)
        ->assertRedirect(route('transactions.match_vendor'));

    $vendor = Vendor::withoutGlobalScopes()->where('business_name', 'like', 'Rocket Fizz')->first();

    expect($vendor)->not->toBeNull()
        ->and($vendor->city)->toBe('Arlington Heights')
        ->and($vendor->business_website)->toBe('https://rocketfizz.com')
        // the desc mutator trims, so 'ROCKET ' stores as 'ROCKET'
        ->and(VendorTransaction::where('vendor_id', $vendor->id)->where('desc', 'ROCKET')->exists())->toBeTrue()
        ->and($transaction->refresh()->vendor_id)->toBe($vendor->id)
        ->and($user->vendor->vendors()->where('vendors.id', $vendor->id)->exists())->toBeTrue();
});

it('falls back to chat completions when the web-search call fails and parses fenced JSON', function () {
    Http::fake([
        'api.openai.com/v1/responses' => Http::response(['error' => ['message' => 'no such tool']], 400),
        'api.openai.com/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => "```json\n{\"vendor_name\": \"Rocket Fizz\", \"existing_vendor_id\": null, \"website\": null, \"city\": null, \"state\": null, \"match_desc\": \"ROCKET \", \"confidence\": \"medium\", \"reasoning\": \"test\"}\n```"]]],
        ]),
    ]);

    [$user, $account] = aiSuggestionActor();
    $transaction = rocketTransaction($account)->load('bank_account.bank');

    $suggestion = app(VendorSuggestionService::class)->suggest(
        'ROCKET 6546',
        collect([$transaction]),
        Vendor::withoutGlobalScopes()->select(['id', 'business_name', 'city', 'state'])->get(),
    );

    expect($suggestion)->not->toBeNull()
        ->and($suggestion['vendor_name'])->toBe('Rocket Fizz')
        ->and($suggestion['match_desc'])->toBe('ROCKET ')
        ->and($suggestion['confidence'])->toBe('medium');
});

it('drops an existing_vendor_id the model hallucinated', function () {
    Http::fake([
        'api.openai.com/v1/responses' => Http::response([
            'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => '{"vendor_name": "Rocket Fizz", "existing_vendor_id": 999999, "website": null, "city": null, "state": null, "match_desc": "ROCKET ", "confidence": "low", "reasoning": "test"}']],
            ]],
        ]),
    ]);

    [$user, $account] = aiSuggestionActor();
    $transaction = rocketTransaction($account)->load('bank_account.bank');

    $suggestion = app(VendorSuggestionService::class)->suggest(
        'ROCKET 6546',
        collect([$transaction]),
        Vendor::withoutGlobalScopes()->select(['id', 'business_name', 'city', 'state'])->get(),
    );

    expect($suggestion['existing_vendor_id'])->toBeNull();
});
