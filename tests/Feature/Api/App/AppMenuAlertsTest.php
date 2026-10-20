<?php

use App\Models\Bank;
use App\Models\EmailMessage;
use App\Models\User;
use App\Models\Vendor;
use App\Services\MenardsRemoteBrowserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null, 'services.menards.owner_vendor_id' => 1]);
});

/**
 * GS Construction is vendor id 1. $role is the vendor_user pivot's role_id
 * (1 = Admin, 2 = Member) — the Bank and CompanyEmail policies gate on it.
 *
 * @return array{vendor: Vendor, user: User}
 */
function appMenuAlertsTeamUser(int $role = 1): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1]);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => $role, 'is_employed' => 1]);

    return compact('vendor', 'user');
}

function appMenuAlertsErroredBank(Vendor $vendor): Bank
{
    return Bank::create([
        'name' => 'Citibank',
        'vendor_id' => $vendor->id,
        'plaid_access_token' => 'access-'.uniqid(),
        'plaid_ins_id' => 'ins_'.uniqid(),
        'plaid_options' => ['error' => ['error_code' => 'ITEM_LOGIN_REQUIRED', 'display_message' => 'Reconnect this bank.']],
    ]);
}

function appMenuAlertsUnplacedEmail(Vendor $vendor): EmailMessage
{
    return EmailMessage::factory()->create([
        'belongs_to_vendor_id' => $vendor->id, 'project_id' => null,
        'summarized_at' => now(), 'unplaced_dismissed_at' => null, 'sent_at' => now(),
    ]);
}

it('needs a token for the menu alerts', function () {
    $this->getJson('/api/app/v1/menu/alerts')->assertUnauthorized();
});

it('shows nothing when no bank errored, Menards is signed in and every email is placed', function () {
    ['user' => $admin] = appMenuAlertsTeamUser();
    Sanctum::actingAs($admin, ['app']);

    $this->getJson('/api/app/v1/menu/alerts')
        ->assertOk()
        ->assertExactJson(['data' => ['bank_errors' => false, 'menards_needs_login' => false, 'unplaced_email_count' => 0]]);
});

it('gives an Admin the same bank error, Menards sign-in and unplaced email alerts as the web sidebar', function () {
    ['vendor' => $vendor, 'user' => $admin] = appMenuAlertsTeamUser();
    Sanctum::actingAs($admin, ['app']);

    appMenuAlertsErroredBank($vendor);
    appMenuAlertsUnplacedEmail($vendor);
    appMenuAlertsUnplacedEmail($vendor);
    appMenuAlertsUnplacedEmail(Vendor::factory()->create());
    Cache::put(MenardsRemoteBrowserService::NEEDS_SIGNIN_CACHE_KEY, true, now()->addHour());

    $this->getJson('/api/app/v1/menu/alerts')
        ->assertOk()
        ->assertExactJson(['data' => ['bank_errors' => true, 'menards_needs_login' => true, 'unplaced_email_count' => 2]]);
});

it('keeps every alert from a Member, like the web sidebar', function () {
    ['vendor' => $vendor, 'user' => $member] = appMenuAlertsTeamUser(role: 2);
    Sanctum::actingAs($member, ['app']);

    appMenuAlertsErroredBank($vendor);
    appMenuAlertsUnplacedEmail($vendor);
    Cache::put(MenardsRemoteBrowserService::NEEDS_SIGNIN_CACHE_KEY, true, now()->addHour());

    $this->getJson('/api/app/v1/menu/alerts')
        ->assertOk()
        ->assertExactJson(['data' => ['bank_errors' => false, 'menards_needs_login' => false, 'unplaced_email_count' => 0]]);
});
