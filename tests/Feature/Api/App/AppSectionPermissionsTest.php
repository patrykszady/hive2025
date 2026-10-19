<?php

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
});

/**
 * A GS Construction team member (vendor id 1, pinned like AppAuthTest's
 * appTeamUser()) with the given vendor_user role: 1 = Admin, 2 = Member.
 */
function appSectionPermissionsUser(int $role): User
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => $role]);

    return $user->fresh();
}

test('me carries the sections the web sidebar would show an admin', function () {
    Sanctum::actingAs(appSectionPermissionsUser(1), ['app']);

    $permissions = $this->getJson('/api/app/v1/me')->assertOk()->json('user.permissions');

    expect($permissions)->toBeArray()
        ->and($permissions['payments'])->toBeTrue()
        ->and($permissions['payments_create'])->toBeTrue()
        ->and($permissions['expenses'])->toBeTrue()
        ->and($permissions['expenses_create'])->toBeTrue()
        ->and($permissions['clients'])->toBeTrue()
        ->and($permissions['checks'])->toBeTrue();
});

test('a member sees what the web shows a member, and never the money sections', function () {
    $member = appSectionPermissionsUser(2);
    Sanctum::actingAs($member, ['app']);

    $permissions = $this->getJson('/api/app/v1/me')->assertOk()->json('user.permissions');

    expect($permissions['payments'])->toBeFalse()
        ->and($permissions['payments_create'])->toBeFalse()
        ->and($permissions['expenses_create'])->toBeFalse()
        ->and($permissions['banks'])->toBeFalse();

    foreach ($permissions as $section => $allowed) {
        expect($allowed)->toBe(match ($section) {
            'expenses' => $member->can('viewAny', \App\Models\Expense::class) || $member->can('create', \App\Models\Expense::class),
            'hours' => $member->can('create', \App\Models\Hour::class),
            'checks' => $member->can('viewAny', \App\Models\Check::class),
            'vendors', 'team' => true,
            'own_balance' => $member->can('viewPayment', [\App\Models\Timesheet::class, $member]),
            default => $allowed,
        }, "section {$section}");
    }
});
