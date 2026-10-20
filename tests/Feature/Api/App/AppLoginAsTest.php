<?php

/**
 * The web's "Login As User" (Patryk only) in the app: a short-lived token
 * for a chosen team member, never for someone outside the team or Patryk.
 */

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** @return array{vendor: Vendor, patryk: User, member: User} */
function loginAsWorld(): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $patryk = User::query()->withoutGlobalScopes()->find(1);
    $patryk->forceFill(['primary_vendor_id' => $vendor->id])->save();
    $member = User::factory()->create(['first_name' => 'Cezary', 'last_name' => 'Crew', 'primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($member->id, ['role_id' => 2, 'is_employed' => 1]);

    return compact('vendor', 'patryk', 'member');
}

it('is Patryk\'s alone, like the web', function () {
    ['member' => $member, 'patryk' => $patryk] = loginAsWorld();
    Sanctum::actingAs($member, ['app']);

    $this->getJson('/api/app/v1/admin/login-as/users')->assertForbidden();
    $this->postJson('/api/app/v1/admin/login-as', ['user_id' => $patryk->id])->assertForbidden();
});

it('offers only the team, without Patryk', function () {
    ['patryk' => $patryk, 'member' => $member] = loginAsWorld();
    $outsider = User::factory()->create(['first_name' => 'Outside', 'primary_vendor_id' => Vendor::factory()->create()->id]);
    Sanctum::actingAs($patryk, ['app']);

    $ids = collect($this->getJson('/api/app/v1/admin/login-as/users')->assertOk()->json('data'))->pluck('id');

    expect($ids)->toContain($member->id)
        ->not->toContain($patryk->id)
        ->not->toContain($outsider->id);

    $this->postJson('/api/app/v1/admin/login-as', ['user_id' => $outsider->id])->assertUnprocessable();
    $this->postJson('/api/app/v1/admin/login-as', ['user_id' => $patryk->id])->assertUnprocessable();
});

it('hands back a working-day token that signs in as the team member', function () {
    ['patryk' => $patryk, 'member' => $member] = loginAsWorld();
    Sanctum::actingAs($patryk, ['app']);

    $response = $this->postJson('/api/app/v1/admin/login-as', ['user_id' => $member->id])
        ->assertCreated()
        ->assertJsonPath('user.id', $member->id);

    $stored = PersonalAccessToken::query()->where('tokenable_id', $member->id)->latest('id')->first();
    expect($stored->name)->toBe('app-login-as-by-'.$patryk->id)
        ->and($stored->abilities)->toBe(['app'])
        ->and($stored->expires_at->between(now()->addHours(7)->addMinutes(59), now()->addHours(8)->addMinute()))->toBeTrue();

    app('auth')->forgetGuards();
    $this->withToken($response->json('token'))->getJson('/api/app/v1/me')->assertOk()->assertJsonPath('user.id', $member->id);
});
