<?php

use App\Enums\LienWaiverStatus;
use App\Enums\LienWaiverType;
use App\Livewire\LienWaivers\Index;
use App\Models\Client;
use App\Models\LienWaiver;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeLienWaiverIndexUser(Vendor $vendor): User
{
    $user = User::query()->create([
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'lien-waivers-' . Str::random(8) . '@example.test',
        'cell_phone' => '7' . random_int(100000000, 999999999),
        'password' => bcrypt('password'),
        'remember_token' => Str::random(10),
    ]);

    $user->forceFill(['primary_vendor_id' => $vendor->id])->saveQuietly();

    return $user;
}

it('soft deletes a lien waiver from the index', function () {
    $vendor = Vendor::query()->create([
        'business_name' => 'GS Construction',
        'business_type' => 'Sub',
        'business_email' => 'accounts@example.test',
        'address' => '123 Main St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
    ]);

    $user = makeLienWaiverIndexUser($vendor);
    $this->actingAs($user);

    $project = Project::query()->create([
        'project_name' => 'Bathroom',
        'client_id' => Client::query()->create([
            'business_name' => 'Owner Client',
            'address' => '456 Oak Ave',
            'city' => 'Chicago',
            'state' => 'IL',
            'zip_code' => '60601',
        ])->id,
        'address' => '456 Oak Ave',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
    ]);

    $waiver = LienWaiver::withoutGlobalScopes()->create([
        'belongs_to_vendor_id' => $vendor->id,
        'vendor_id' => $vendor->id,
        'project_id' => $project->id,
        'type' => LienWaiverType::ConditionalProgress,
        'status' => LienWaiverStatus::Draft,
        'amount' => 1000,
        'exceptions_amount' => 0,
        'through_date' => now()->toDateString(),
        'jurisdiction' => 'US-IL',
        'created_by_user_id' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test(Index::class, ['project' => $project])
        ->call('delete', $waiver->id)
        ->assertSuccessful();

    expect(LienWaiver::find($waiver->id))->toBeNull()
        ->and(LienWaiver::withTrashed()->find($waiver->id)?->trashed())->toBeTrue();
});