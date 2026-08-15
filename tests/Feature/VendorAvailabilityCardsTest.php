<?php

use App\Livewire\Vendor\AvailabilityIndex;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders checklist and notes on the public vendor schedule but hides auto-generated booking notes', function (): void {
    $owner = Vendor::factory()->create(['business_name' => 'GS Construction']);
    $sub = Vendor::factory()->create([
        'business_name' => 'Smartech Electric',
        'availability_token' => 'tok-cards-test-123',
    ]);

    $client = Client::factory()->create();
    $project = Project::withoutEvents(fn () => Project::query()->create([
        'project_name' => 'Bathroom',
        'client_id' => $client->id,
        'address' => '104 North Plum Grove Road',
        'city' => 'Palatine',
        'state' => 'IL',
        'zip_code' => 60067,
        'belongs_to_vendor_id' => $owner->id,
    ]));

    Task::withoutEvents(fn () => Task::create([
        'title' => 'Electrical Punch List',
        'type' => 'Task',
        'order' => 1,
        'project_id' => $project->id,
        'vendor_id' => $sub->id,
        'belongs_to_vendor_id' => $owner->id,
        'created_by_user_id' => 1,
        'start_date' => now()->addDay(),
        'end_date' => now()->addDay(),
        'notes' => 'Booked from lead email reply — Fri, Aug 14 · 9:30 AM.',
        'options' => ['checklist' => [
            ['text' => 'Staircase 3-way switch', 'completed' => false],
            ['text' => 'Primary bedroom closet light', 'completed' => true],
        ]],
    ]));

    Livewire::test(AvailabilityIndex::class, ['token' => 'tok-cards-test-123'])
        ->assertSee('Electrical Punch List')
        ->assertSee('Staircase 3-way switch')
        ->assertSee('Primary bedroom closet light')
        ->assertSee('104 North Plum Grove Road')
        ->assertDontSee('Booked from lead email reply');
});
