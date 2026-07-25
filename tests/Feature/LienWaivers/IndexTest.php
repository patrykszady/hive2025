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
it('lists project payments read-only in the create modal without linking them', function () {
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

    $client = Client::query()->create([
        'business_name' => 'Owner Client',
        'address' => '456 Oak Ave',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
    ]);
    // Link the client to the vendor (ClientScope) and to the project (client relation).
    $client->vendors()->attach($vendor->id, ['source' => 'test']);

    $project = Project::query()->create([
        'project_name' => 'Kitchen',
        'client_id' => $client->id,
        'address' => '456 Oak Ave',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
    ]);
    \Illuminate\Support\Facades\DB::table('project_vendor')->insert([
        'project_id' => $project->id,
        'vendor_id' => $vendor->id,
        'client_id' => $client->id,
        'created_at' => now(),
        'updated_at' => now(),
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
        ->assertSet('payerSource', 'client')
        ->assertSee('Payer')
        ->assertSee('456 Oak Ave')
        ->assertDontSee('Someone else')
        ->assertDontSee('Internal notes')
        ->assertDontSee('wire:click="selectPayment');

    $component->set('newAmount', '25,000')
        ->set('newPayerName', 'Owner Client')
        ->call('createWaiver')
        ->assertHasNoErrors();

    $waiver = LienWaiver::withoutGlobalScopes()
        ->where('project_id', $project->id)
        ->latest('id')
        ->first();

    expect($waiver)->not->toBeNull()
        ->and($waiver->payment_id)->toBeNull()
        ->and((float) $waiver->amount)->toBe(25000.0)
        ->and($payment->id)->toBeGreaterThan(0);
});

it('creates a sub-vendor waiver: claimant is the sub, payer is the GC', function () {
    $gc = Vendor::query()->create([
        'business_name' => 'GS Construction',
        'business_type' => 'Sub',
        'business_email' => 'gc@example.test',
        'address' => '123 Main St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
    ]);
    $sub = Vendor::query()->create([
        'business_name' => 'Arturo Cardona',
        'business_type' => 'Sub',
        'business_email' => 'sub@example.test',
        'address' => '941 W Windsor', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60640',
    ]);

    $user = makeLienWaiverIndexUser($gc);
    $user->vendors()->attach($gc->id, ['role_id' => 1, 'is_employed' => true]);
    $this->actingAs($user);

    $project = Project::query()->create([
        'project_name' => 'Sub Job',
        'belongs_to_vendor_id' => $gc->id,
        'client_id' => Client::query()->create([
            'business_name' => 'Owner', 'address' => '3154 Violet', 'city' => 'Northbrook', 'state' => 'IL', 'zip_code' => '60062',
        ])->id,
        'address' => '3154 Violet', 'city' => 'Northbrook', 'state' => 'IL', 'zip_code' => '60062',
    ]);
    // The sub is on the project via a bid.
    \App\Models\Bid::withoutGlobalScopes()->create([
        'project_id' => $project->id, 'vendor_id' => $sub->id, 'amount' => 9300, 'type' => 1,
    ]);
    // The sub must have at least one payment on the project to appear in the vendor filter.
    \App\Models\Payment::withoutGlobalScopes()->create([
        'project_id' => $project->id, 'belongs_to_vendor_id' => $sub->id, 'amount' => 1000, 'date' => now(), 'created_by_user_id' => $user->id,
    ]);

    $component = Livewire::actingAs($user)
        ->test(Index::class, ['project' => $project])
        ->call('openCreate')
        ->assertSet('newClaimantVendorId', $gc->id)
        ->assertSee('Arturo Cardona')
        ->set('newClaimantVendorId', $sub->id)
        ->assertSet('isSubClaimant', true)
        ->set('newAmount', '9300')
        ->call('createWaiver')
        ->assertHasNoErrors();

    $waiver = LienWaiver::withoutGlobalScopes()->where('project_id', $project->id)->latest('id')->first();

    expect($waiver->vendor_id)->toBe($sub->id)          // claimant = sub
        ->and($waiver->belongs_to_vendor_id)->toBe($gc->id) // payer = GC
        ->and($waiver->isSubWaiver())->toBeTrue()
        ->and((float) $waiver->amount)->toBe(9300.0);

    // Notes carry no payer override — the generator uses the GC vendor as payer.
    expect(json_decode($waiver->notes, true))->not->toHaveKey('payer');
});

it('does not double-create when createWaiver is called twice (guard closes the modal)', function () {
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
        'project_name' => 'Once',
        'client_id' => Client::query()->create([
            'business_name' => 'Once Client',
            'address' => '5 Once St',
            'city' => 'Chicago',
            'state' => 'IL',
            'zip_code' => '60601',
        ])->id,
        'address' => '5 Once St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
    ]);

    $component = Livewire::actingAs($user)
        ->test(Index::class, ['project' => $project])
        ->call('openCreate')
        ->set('newAmount', '60000')
        ->set('newPayerName', 'Once Client')
        ->call('createWaiver')
        ->assertHasNoErrors()
        ->assertSet('showCreate', false);

    // A second call (as from a queued double-click) must be a no-op.
    $component->call('createWaiver');

    expect(LienWaiver::withoutGlobalScopes()->where('project_id', $project->id)->count())->toBe(1);
});

it('stays project-scoped after creating a waiver and never shows the project selector', function () {
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
        'project_name' => 'Basement',
        'client_id' => Client::query()->create([
            'business_name' => 'Scoped Owner',
            'address' => '9 Scoped St',
            'city' => 'Chicago',
            'state' => 'IL',
            'zip_code' => '60601',
        ])->id,
        'address' => '9 Scoped St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
    ]);

    Livewire::actingAs($user)
        ->test(Index::class, ['project' => $project])
        ->assertSet('scoped', true)
        ->assertDontSee('Select a project to get started')
        ->call('openCreate')
        ->set('newAmount', '5000')
        ->set('newPayerName', 'Scoped Owner')
        ->call('createWaiver')
        ->assertHasNoErrors()
        ->assertSet('scoped', true)
        ->assertSet('project.id', $project->id)
        ->assertSet('showProjectSelector', false)
        ->assertDontSee('Select a project to get started');
});

it('filters payments card by selected waiver vendor', function () {
    $gc = Vendor::query()->create([
        'business_name' => 'GS Construction',
        'business_type' => 'Sub',
        'business_email' => 'gc@example.test',
        'address' => '123 Main St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
    ]);
    $sub1 = Vendor::query()->create([
        'business_name' => 'PMG Carpentry Inc',
        'business_type' => 'Sub',
        'business_email' => 'pmg@example.test',
        'address' => '456 Oak Ave', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
    ]);
    $sub2 = Vendor::query()->create([
        'business_name' => 'Electrical Experts',
        'business_type' => 'Sub',
        'business_email' => 'elec@example.test',
        'address' => '789 Pine St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
    ]);

    $user = makeLienWaiverIndexUser($gc);
    $user->vendors()->attach($gc->id, ['role_id' => 1, 'is_employed' => true]);
    $this->actingAs($user);

    $project = Project::query()->create([
        'project_name' => 'Multi-Sub Job',
        'belongs_to_vendor_id' => $gc->id,
        'client_id' => Client::query()->create([
            'business_name' => 'Owner', 'address' => '3154 Violet', 'city' => 'Northbrook', 'state' => 'IL', 'zip_code' => '60062',
        ])->id,
        'address' => '3154 Violet', 'city' => 'Northbrook', 'state' => 'IL', 'zip_code' => '60062',
    ]);

    // Money from the homeowner: a plain payment to the GC, no check.
    \App\Models\Payment::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $gc->id,
        'amount' => 60000,
        'date' => '2026-06-01',
        'reference' => 'TRU060126ST',
        'created_by_user_id' => $user->id,
    ]);

    // Money to PMG: recorded as an expense paid by check (the real-world shape).
    $pmgCheck = \App\Models\Check::create([
        'check_type' => 'Check',
        'check_number' => 2632,
        'date' => '2026-07-09',
        'amount' => 30000,
        'vendor_id' => $sub1->id,
        'belongs_to_vendor_id' => $gc->id,
        'created_by_user_id' => $user->id,
    ]);
    \App\Models\Expense::withoutGlobalScopes()->create([
        'project_id' => $project->id,
        'vendor_id' => $sub1->id,
        'belongs_to_vendor_id' => $gc->id,
        'check_id' => $pmgCheck->id,
        'amount' => 30000,
        'date' => '2026-07-09',
        'created_by_user_id' => $user->id,
    ]);

    // Money to Electrical: a check-paid Payment row (the alternate shape).
    $elecCheck = \App\Models\Check::create([
        'check_type' => 'Check',
        'check_number' => 1002,
        'date' => '2026-06-15',
        'vendor_id' => $sub2->id,
        'belongs_to_vendor_id' => $gc->id,
        'created_by_user_id' => $user->id,
    ]);
    \App\Models\Payment::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $gc->id,
        'amount' => 40000,
        'date' => '2026-06-15',
        'reference' => 'ELEC-CHK-1002',
        'check_id' => $elecCheck->id,
        'created_by_user_id' => $user->id,
    ]);

    // Selecting PMG shows their expense/check — never the homeowner's transfer
    // or another sub's money.
    $component = Livewire::actingAs($user)
        ->test(Index::class, ['project' => $project])
        ->call('openCreate')
        ->set('newClaimantVendorId', $sub1->id)
        ->assertSee('Check #2632')
        ->assertSee('30,000.00')
        ->assertDontSee('TRU060126ST')
        ->assertDontSee('ELEC-CHK-1002');

    // Selecting Electrical shows their check payment instead.
    $component->set('newClaimantVendorId', $sub2->id)
        ->assertSee('ELEC-CHK-1002')
        ->assertSee('40,000.00')
        ->assertDontSee('Check #2632')
        ->assertDontSee('TRU060126ST');

    // And a waiver for PMG still saves correctly.
    $component->set('newClaimantVendorId', $sub1->id)
        ->set('newAmount', '9300')
        ->call('createWaiver')
        ->assertHasNoErrors();

    $waiver = LienWaiver::withoutGlobalScopes()->where('project_id', $project->id)->latest('id')->first();
    expect($waiver->vendor_id)->toBe($sub1->id);
});

it('hides the project card until the contract is signed (base bid exists)', function () {
    $vendor = Vendor::query()->create([
        'business_name' => 'GS Construction',
        'business_type' => 'Sub',
        'business_email' => 'accounts@example.test',
        'address' => '123 Main St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
    ]);
    $user = makeLienWaiverIndexUser($vendor);
    $user->vendors()->attach($vendor->id, ['role_id' => 1, 'is_employed' => true]);
    $this->actingAs($user);

    $project = Project::query()->create([
        'project_name' => 'Unsigned Job',
        'client_id' => Client::query()->create([
            'business_name' => 'Owner', 'address' => '1 St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
        ])->id,
        'address' => '1 St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
    ]);

    // No signed contract yet — the card (and its buttons) stay hidden.
    Livewire::actingAs($user)
        ->test(Index::class, ['project' => $project])
        ->assertSet('hasSignedContract', false)
        ->assertDontSee('Lien Waivers')
        ->assertDontSee('Create Draw');

    // Contract accepted → base bid exists → card appears.
    \App\Models\Bid::withoutGlobalScopes()->create([
        'project_id' => $project->id, 'vendor_id' => $vendor->id, 'amount' => 100000, 'type' => 1,
    ]);

    Livewire::actingAs($user)
        ->test(Index::class, ['project' => $project])
        ->assertSet('hasSignedContract', true)
        ->assertSee('Lien Waivers')
        ->assertSee('Create Draw');
});

it('downloads waivers under the id-bearing filename', function () {
    $vendor = Vendor::query()->create([
        'business_name' => 'GS Construction',
        'business_type' => 'Sub',
        'business_email' => 'accounts@example.test',
        'address' => '123 Main St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
    ]);
    $user = makeLienWaiverIndexUser($vendor);
    $user->vendors()->attach($vendor->id, ['role_id' => 1, 'is_employed' => true]);
    // The auth + vendor.access middleware require completed registrations.
    $user->forceFill(['registration' => ['registered' => true]])->saveQuietly();
    $vendor->forceFill(['registration' => ['registered' => true]])->saveQuietly();
    $this->actingAs($user);

    $project = Project::query()->create([
        'project_name' => 'Filename Job',
        'client_id' => Client::query()->create([
            'business_name' => 'Mark & Gail Brodson', 'address' => '3154 Violet Ln', 'city' => 'Northbrook', 'state' => 'IL', 'zip_code' => '60062',
        ])->id,
        'address' => '3154 Violet Ln', 'city' => 'Northbrook', 'state' => 'IL', 'zip_code' => '60062',
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
        'notes' => json_encode(['payer' => ['name' => 'Mark & Gail Brodson']]),
        'created_by_user_id' => $user->id,
    ]);

    $response = $this->get(route('lien-waivers.download', $waiver));

    $response->assertSuccessful();
    expect($response->headers->get('content-disposition'))
        ->toContain('lien-waiver-' . $waiver->id . '-gs-construction')
        ->toContain('mark-gail-brodson')
        ->toContain('3154-violet-ln')
        ->toContain('-' . now()->format('Y-m-d') . '.pdf')
        ->not->toContain('thru-');
});
