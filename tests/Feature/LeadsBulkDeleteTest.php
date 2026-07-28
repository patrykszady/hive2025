<?php

use App\Livewire\Leads\LeadsIndex;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeBulkDeleteAdmin(Vendor $vendor): User
{
    $admin = new User();
    $admin->forceFill([
        'first_name' => 'Leads',
        'last_name' => 'Admin',
        'email' => 'leads-admin.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
    ]);
    $admin->save();

    $vendor->users()->attach($admin->id, ['role_id' => 1]);

    return $admin->fresh();
}

function makeBulkDeleteLead(Vendor $vendor, ?Client $client = null, string $status = 'New'): Lead
{
    $contact = User::query()->create([
        'first_name' => 'Bulk',
        'last_name' => 'Contact',
        'email' => 'bulk.contact.'.uniqid().'@example.com',
        'cell_phone' => fake()->unique()->numerify('224888####'),
    ]);

    if ($client) {
        $client->users()->attach($contact->id);
    }

    $lead = Lead::create([
        'date' => now(),
        'origin' => 'gs.construction',
        'user_id' => $contact->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $contact->id,
        'lead_data' => [
            'name' => 'Bulk Homeowner',
            'address' => '123 Main St, Palatine, IL 60067',
        ],
    ]);

    $lead->statuses()->create([
        'title' => $status,
        'belongs_to_vendor_id' => $vendor->id,
    ]);

    return $lead;
}

it('bulk deletes the selected leads and cleans up orphaned clients and contacts', function () {
    $vendor = Vendor::factory()->create();
    $admin = makeBulkDeleteAdmin($vendor);

    $clientA = Client::factory()->create();
    $clientA->vendors()->attach($vendor->id);
    $leadA = makeBulkDeleteLead($vendor, $clientA);

    $clientB = Client::factory()->create();
    $clientB->vendors()->attach($vendor->id);
    $leadB = makeBulkDeleteLead($vendor, $clientB);

    $this->actingAs($admin);

    Livewire::test(LeadsIndex::class)
        ->set('selected', [$leadA->id, $leadB->id])
        ->call('confirmBulkDelete')
        ->assertSet('showBulkDelete', true)
        ->call('bulkDelete')
        ->assertSet('showBulkDelete', false)
        ->assertSet('selected', []);

    expect(Lead::withoutGlobalScopes()->withTrashed()->find($leadA->id)?->trashed() ?? true)->toBeTrue()
        ->and(Lead::withoutGlobalScopes()->withTrashed()->find($leadB->id)?->trashed() ?? true)->toBeTrue()
        // Both clients existed only for their lead — removed with it.
        ->and(Client::withoutGlobalScopes()->find($clientA->id))->toBeNull()
        ->and(Client::withoutGlobalScopes()->find($clientB->id))->toBeNull();
});

it('keeps clients that have projects and contacts linked to other records', function () {
    $vendor = Vendor::factory()->create();
    $admin = makeBulkDeleteAdmin($vendor);

    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);
    $lead = makeBulkDeleteLead($vendor, $client);

    // ProjectObserver reads auth()->user()->vendor on creating.
    $this->actingAs($admin);

    Project::factory()->create(['client_id' => $client->id]);

    Livewire::test(LeadsIndex::class)
        ->set('selected', [$lead->id])
        ->call('bulkDelete');

    expect(Lead::withoutGlobalScopes()->withTrashed()->find($lead->id)?->trashed() ?? true)->toBeTrue()
        // Client has a project — survives, and so does its contact.
        ->and(Client::withoutGlobalScopes()->find($client->id))->not->toBeNull()
        ->and(User::withoutGlobalScopes()->find($lead->user_id))->not->toBeNull();
});

it('reports the aggregate impact for the confirmation modal', function () {
    $vendor = Vendor::factory()->create();
    $admin = makeBulkDeleteAdmin($vendor);

    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);
    $lead = makeBulkDeleteLead($vendor, $client);

    $this->actingAs($admin);

    $component = Livewire::test(LeadsIndex::class)->set('selected', [$lead->id]);
    $impact = $component->instance()->bulkDeleteImpact;

    expect($impact['count'])->toBe(1)
        ->and($impact['clients'])->toContain($client->name);
});

it('does nothing when no leads are selected', function () {
    $vendor = Vendor::factory()->create();
    $admin = makeBulkDeleteAdmin($vendor);
    $lead = makeBulkDeleteLead($vendor);

    $this->actingAs($admin);

    Livewire::test(LeadsIndex::class)
        ->call('confirmBulkDelete')
        ->assertSet('showBulkDelete', false)
        ->call('bulkDelete');

    expect(Lead::withoutGlobalScopes()->find($lead->id))->not->toBeNull();
});
