<?php

use App\Livewire\Agents\AgentsIndex;
use App\Models\Agent;
use App\Models\User;
use App\Models\VendorDoc;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('groups business name punctuation variants into one entry', function () {
    $vendor = Vendor::factory()->create();

    $user = User::query()->create([
        'first_name' => 'Test',
        'last_name' => 'Admin',
        'email' => 'agents-grouping-' . Str::random(8) . '@example.test',
        'cell_phone' => '7' . random_int(100000000, 999999999),
        'password' => bcrypt('password'),
        'remember_token' => Str::random(10),
        'primary_vendor_id' => $vendor->id,
    ]);

    $this->actingAs($user);

    Agent::query()->create([
        'business_name' => 'Biz Broker Inc',
        'name' => 'Biz Broker Inc',
        'email' => 'cert@biz1040.com',
        'address' => '3357 N Harlem Ave Chicago, IL 60634',
    ]);

    Agent::query()->create([
        'business_name' => 'Biz Broker, Inc.',
        'name' => 'Biz Broker, Inc.',
        'email' => 'certificates@biz1040.com',
        'address' => '3357 N Harlem Ave Chicago, IL 60634',
    ]);

    $grouped = (new AgentsIndex())->agents();

    expect($grouped)->toHaveCount(1)
        ->and(strtolower($grouped->first()->business_name))->toContain('biz broker')
        ->and(collect($grouped->first()->agents)->pluck('email')->sort()->values()->all())
        ->toBe([
            'cert@biz1040.com',
            'certificates@biz1040.com',
        ]);
});

it('groups legal suffix variants like llc and ltd into one entry', function () {
    $vendor = Vendor::factory()->create();

    $user = User::query()->create([
        'first_name' => 'Test',
        'last_name' => 'Admin',
        'email' => 'agents-grouping-' . Str::random(8) . '@example.test',
        'cell_phone' => '7' . random_int(100000000, 999999999),
        'password' => bcrypt('password'),
        'remember_token' => Str::random(10),
        'primary_vendor_id' => $vendor->id,
    ]);

    $this->actingAs($user);

    Agent::query()->create([
        'business_name' => 'Argus Financial Services, LLC',
        'name' => null,
        'email' => 'office@arguschicago.com',
        'address' => '8859 S. ROBERTS RD SUITE 101 HICKORY HILLS, IL 60457',
    ]);

    Agent::query()->create([
        'business_name' => 'Argus Financial Services, Ltd.',
        'name' => null,
        'email' => 'arguschicago@yahoo.com',
        'address' => '8859 S. ROBERTS RD SUITE 101 HICKORY HILLS, IL 60457',
    ]);

    $grouped = (new AgentsIndex())->agents();

    expect($grouped)->toHaveCount(1)
        ->and(strtolower($grouped->first()->business_name))->toContain('argus financial services')
        ->and(collect($grouped->first()->agents)->pluck('email')->sort()->values()->all())
        ->toBe([
            'arguschicago@yahoo.com',
            'office@arguschicago.com',
        ]);
});

it('keeps separate per-agent addresses and vendor docs counts in grouped row', function () {
    $vendor = Vendor::factory()->create();

    $user = User::query()->create([
        'first_name' => 'Test',
        'last_name' => 'Admin',
        'email' => 'agents-per-agent-' . Str::random(8) . '@example.test',
        'cell_phone' => '7' . random_int(100000000, 999999999),
        'password' => bcrypt('password'),
        'remember_token' => Str::random(10),
        'primary_vendor_id' => $vendor->id,
    ]);

    $this->actingAs($user);

    $agentOne = Agent::query()->create([
        'business_name' => 'Handzel & Associates LTD',
        'name' => 'Aracely Pineda',
        'email' => 'apineda@handzel.com',
        'address' => '5361 N Harlem Ave Chicago, IL 60656',
    ]);

    $agentTwo = Agent::query()->create([
        'business_name' => 'Handzel & Associates LTD',
        'name' => 'David Babovich',
        'email' => 'dbabovich@handzel.com',
        'address' => '1590 Wilkening Road Schaumburg, IL 60173',
    ]);

    VendorDoc::withoutGlobalScopes()->forceCreate([
        'type' => 'workers',
        'vendor_id' => $vendor->id,
        'belongs_to_vendor_id' => $vendor->id,
        'agent_id' => $agentOne->id,
        'number' => 'W-A',
        'effective_date' => now()->subMonth()->toDateString(),
        'expiration_date' => now()->addMonth()->toDateString(),
        'doc_filename' => 'a.pdf',
    ]);

    VendorDoc::withoutGlobalScopes()->forceCreate([
        'type' => 'workers',
        'vendor_id' => $vendor->id,
        'belongs_to_vendor_id' => $vendor->id,
        'agent_id' => $agentOne->id,
        'number' => 'W-B',
        'effective_date' => now()->subMonth()->toDateString(),
        'expiration_date' => now()->addMonth()->toDateString(),
        'doc_filename' => 'b.pdf',
    ]);

    VendorDoc::withoutGlobalScopes()->forceCreate([
        'type' => 'workers',
        'vendor_id' => $vendor->id,
        'belongs_to_vendor_id' => $vendor->id,
        'agent_id' => $agentTwo->id,
        'number' => 'W-C',
        'effective_date' => now()->subMonth()->toDateString(),
        'expiration_date' => now()->addMonth()->toDateString(),
        'doc_filename' => 'c.pdf',
    ]);

    $group = (new AgentsIndex())->agents()->first();

    $byEmail = collect($group->agents)->keyBy('email');

    expect($byEmail->get('apineda@handzel.com')->address)->toBe('5361 N Harlem Ave Chicago, IL 60656')
        ->and($byEmail->get('apineda@handzel.com')->vendor_docs_count)->toBe(2)
        ->and($byEmail->get('dbabovich@handzel.com')->address)->toBe('1590 Wilkening Road Schaumburg, IL 60173')
        ->and($byEmail->get('dbabovich@handzel.com')->vendor_docs_count)->toBe(1);
});

it('loads vendor docs for all agent ids in selected group modal', function () {
    $vendor = Vendor::factory()->create();

    $user = User::query()->create([
        'first_name' => 'Test',
        'last_name' => 'Admin',
        'email' => 'agents-modal-' . Str::random(8) . '@example.test',
        'cell_phone' => '7' . random_int(100000000, 999999999),
        'password' => bcrypt('password'),
        'remember_token' => Str::random(10),
        'primary_vendor_id' => $vendor->id,
    ]);

    $this->actingAs($user);

    $agentOne = Agent::query()->create([
        'business_name' => 'Biz Broker Inc',
        'name' => 'One',
        'email' => 'one@example.test',
        'address' => '3357 N Harlem Ave Chicago, IL 60634',
    ]);

    $agentTwo = Agent::query()->create([
        'business_name' => 'Biz Broker, Inc.',
        'name' => 'Two',
        'email' => 'two@example.test',
        'address' => '3357 N Harlem Ave Chicago, IL 60634',
    ]);

    VendorDoc::withoutGlobalScopes()->forceCreate([
        'type' => 'workers',
        'vendor_id' => $vendor->id,
        'belongs_to_vendor_id' => $vendor->id,
        'agent_id' => $agentOne->id,
        'number' => 'W-1',
        'effective_date' => now()->subMonth()->toDateString(),
        'expiration_date' => now()->addMonth()->toDateString(),
        'doc_filename' => 'a.pdf',
    ]);

    VendorDoc::withoutGlobalScopes()->forceCreate([
        'type' => 'workers',
        'vendor_id' => $vendor->id,
        'belongs_to_vendor_id' => $vendor->id,
        'agent_id' => $agentTwo->id,
        'number' => 'W-2',
        'effective_date' => now()->subMonth()->toDateString(),
        'expiration_date' => now()->addMonth()->toDateString(),
        'doc_filename' => 'b.pdf',
    ]);

    $component = new AgentsIndex();
    $component->openVendorDocsModal('Biz Broker Inc', [$agentOne->id], 'One');

    $numbers = $component->selectedVendorDocs()->pluck('number')->sort()->values()->all();

    expect($component->showVendorDocsModal)->toBeTrue()
        ->and($component->selectedAgentName)->toBe('One')
        ->and($numbers)->toBe(['W-1']);
});
