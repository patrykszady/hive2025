<?php

use App\Models\User;
use App\Models\Vendor;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

uses(Tests\TestCase::class)->in('Feature');
uses(PHPUnit\Framework\TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

// expect()->extend('toBeOne', function () {
//     return $this->toBe(1);
// });

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

// function something()
// {
//     // ..
// }

/**
 * Shared by the certificates-mailbox tests (InsuranceReplyContext,
 * VendorDocReconcile, MoveVendorDocs).
 */
/*
 * Mariusz Kot's shape: one owner, two vendor rows ("Kot Construction" from
 * 2017, "Mariusz Kot Construction" from 2021), and an agent's COI arriving
 * as a reply to the request we sent for the newer one.
 */
function kotVendors(): array
{
    // Creating a workers-comp doc dispatches the state lookup job, which
    // runs a real scraper when the queue is sync — never in a test.
    \Illuminate\Support\Facades\Queue::fake();

    config([
        'nylas.certificates_email' => 'certificates@hive.contractors',
        'nylas.crew_leads.internal_domains' => ['gs.construction', 'hive.contractors'],
    ]);

    $old = Vendor::factory()->create(['business_name' => 'Kot Construction', 'business_type' => 'Sub']);
    $new = Vendor::factory()->create(['business_name' => 'Mariusz Kot Construction', 'business_type' => 'Sub']);
    $gs = Vendor::factory()->create(['business_name' => 'GS Construction & Remodeling', 'business_type' => 'GC']);

    $mariusz = User::query()->create([
        'first_name' => 'Mariusz', 'last_name' => 'Kot',
        'email' => 'mariuszkot40@att.net', 'cell_phone' => '8475550100',
    ]);
    $old->users()->attach($mariusz->id, ['role_id' => 1]);
    $new->users()->attach($mariusz->id, ['role_id' => 1]);

    return compact('old', 'new', 'gs', 'mariusz');
}

require_once __DIR__.'/Support/consult-fixtures.php';

/**
 * Shared by the /api/admin/v1 tests (AdminPingTest, AdminDashboardStatsTest,
 * AdminSeoSnapshotTest, AdminPlatformsStatusTest): the bearer header every
 * request needs, once services.admin_api.token is configured per-test.
 * Ported from gsc's/dawnsellshomes' WithAdminApiAuth trait.
 */
function adminApiHeaders(): array
{
    return ['Authorization' => 'Bearer test-admin-api-token'];
}
