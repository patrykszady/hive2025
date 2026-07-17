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
it('lists project payments in the create modal and links the selected one to the waiver', function () {
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
    $user->vendors()->attach($vendor->id, ['role_id' => 1, 'is_employed' => true]);
    $this->actingAs($user);

    $project = Project::query()->create([
        'project_name' => 'Kitchen',
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

    $payment = \App\Models\Payment::query()->create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'amount' => 60000,
        'date' => '2026-07-09',
        'reference' => '070926',
        'created_by_user_id' => $user->id,
    ]);

    $component = Livewire::actingAs($user)
        ->test(Index::class, ['project' => $project])
        ->call('openCreate')
        ->assertSee('Payments received on this project')
        ->assertSee('070926')
        ->call('selectPayment', $payment->id)
        ->assertSet('newPaymentId', $payment->id)
        ->assertSet('newAmount', '60000.00')
        ->assertSet('newThroughDate', '2026-07-09');

    $component->set('newPayerName', 'Owner Client')->call('createWaiver')->assertHasNoErrors();

    $waiver = LienWaiver::withoutGlobalScopes()
        ->where('project_id', $project->id)
        ->latest('id')
        ->first();

    expect($waiver)->not->toBeNull()
        ->and($waiver->payment_id)->toBe($payment->id)
        ->and((float) $waiver->amount)->toBe(60000.0);
});

it('deselects a payment when clicked again', function () {
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
    $user->vendors()->attach($vendor->id, ['role_id' => 1, 'is_employed' => true]);
    $this->actingAs($user);

    $project = Project::query()->create([
        'project_name' => 'Deck',
        'client_id' => Client::query()->create([
            'business_name' => 'Owner Client 2',
            'address' => '789 Elm St',
            'city' => 'Chicago',
            'state' => 'IL',
            'zip_code' => '60601',
        ])->id,
        'address' => '789 Elm St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
    ]);

    $payment = \App\Models\Payment::query()->create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'amount' => 1500,
        'date' => '2026-07-01',
        'reference' => 'ref-1',
        'created_by_user_id' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test(Index::class, ['project' => $project])
        ->call('openCreate')
        ->call('selectPayment', $payment->id)
        ->assertSet('newPaymentId', $payment->id)
        ->call('selectPayment', $payment->id)
        ->assertSet('newPaymentId', null);
});
