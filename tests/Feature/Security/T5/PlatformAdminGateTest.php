<?php

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * id 1 is the app's only platform-superadmin check (see AppServiceProvider's
 * `platform-admin` gate, matching the pre-existing auth()->id() === 1 in
 * AgentsIndex) — and it already exists as seed data baked into the
 * migrations, so it is reused rather than inserted a second time (SQLite
 * would raise a unique-constraint violation on a second row with id 1). The
 * fresh test database's copy of that row has no vendor/registration set, so
 * both are filled in here for the routes that require them.
 */
function sec5_platformAdmin(): User
{
    $vendor = Vendor::factory()->create();
    $vendor->forceFill(['registration' => ['registered' => true]])->save();

    $user = User::query()->findOrFail(1);
    $user->forceFill([
        'primary_vendor_id' => $vendor->id,
        'registration' => ['registered' => true],
    ])->save();
    $vendor->users()->attach($user->id, ['role_id' => 1]);

    return $user->fresh();
}

/** A tenant Admin who is explicitly NOT the platform admin. */
function sec5_tenantAdmin(): User
{
    $vendor = Vendor::factory()->create();
    $vendor->forceFill(['registration' => ['registered' => true]])->save();

    $user = new User();
    $user->forceFill([
        'first_name' => 'Tenant',
        'last_name' => 'Admin',
        'email' => 'tenant-admin-'.uniqid().'@example.test',
        'cell_phone' => (string) random_int(2000000000, 9999999999),
        'password' => null,
        'primary_vendor_id' => $vendor->id,
        'registration' => ['registered' => true],
    ]);
    $user->save();
    $vendor->users()->attach($user->id, ['role_id' => 1]);

    return $user;
}

/**
 * Cross-tenant job-trigger URLs (finding T5#3): these run scheduled work
 * across every tenant using withoutGlobalScopes, and used to have no auth at
 * all. The scheduler already runs the same jobs directly (routes/console.php
 * RunScheduledTask calls), never over HTTP — no UI links to any of these
 * URLs — so gating them to the platform admin costs nothing.
 */
it('gates every cross-tenant job-trigger URL behind auth + platform-admin', function () {
    $uris = [
        'vendor_docs/verifyWorkersComp',
        'receipts/home-depot-messages',
        'receipts/goutte_crawl',
        'plaid_transactions_sync',
        'plaid_statements_list',
        'plaid_transactions_refresh',
        'plaid_item_status',
        'plaid_transactions_enrich',
        'add_vendor_to_transactions',
        'add_expense_to_transactions',
        'add_transaction_to_multi_expenses',
        'add_check_id_to_transactions',
        'add_check_deposit_to_transactions',
        'add_payments_to_transaction',
        'add_transaction_to_expenses_sin_vendor',
        'find_credit_payments_on_debit',
        'transactions_sum_not_expense_amount',
        'add_category_to_expense',
        'receipts/amazon_login',
        'receipts/amazon_auth_response',
        'receipts/amazon_orders_api',
    ];

    foreach ($uris as $uri) {
        $route = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === $uri && in_array('GET', $r->methods(), true)
        );

        expect($route)->not->toBeNull("No GET route registered for {$uri}");

        $middleware = $route->gatherMiddleware();

        expect($middleware)->toContain('auth')
            ->and($middleware)->toContain('can:platform-admin');
    }
});

it('lets the platform admin reach a gated job trigger while a tenant Admin and a guest are refused', function () {
    // Guest: redirected to login, not run.
    $this->get('/plaid_transactions_sync')->assertRedirect(route('login'));

    // A regular tenant Admin: refused.
    $this->actingAs(sec5_tenantAdmin())
        ->get('/plaid_transactions_sync')
        ->assertForbidden();

    // Platform admin: reaches the controller (no banks exist, so it is a safe no-op).
    $this->actingAs(sec5_platformAdmin())
        ->get('/plaid_transactions_sync')
        ->assertOk();
});

it('gates activate-scheduled-projects and forward-receipt-emails to the platform admin', function () {
    $this->actingAs(sec5_tenantAdmin())
        ->get('/activate-scheduled-projects')
        ->assertForbidden();

    $this->actingAs(sec5_platformAdmin())
        ->get('/activate-scheduled-projects')
        ->assertRedirect();

    $this->actingAs(sec5_tenantAdmin())
        ->get('/forward-receipt-emails')
        ->assertForbidden();

    $this->actingAs(sec5_platformAdmin())
        ->get('/forward-receipt-emails')
        ->assertOk();
});
