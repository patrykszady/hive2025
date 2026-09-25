<?php

use App\Livewire\Leads\LeadsIndex;
use App\Livewire\Projects\TimelapseStudio;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Project;
use App\Models\ProjectTimelapse;
use App\Models\ProjectTimelapseFrame;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Model::preventLazyLoading();
});

afterEach(function () {
    Model::preventLazyLoading(false);
});

function sec4_npFixtureVendor(): array
{
    $vendor = Vendor::factory()->create();
    $vendor->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();
    $admin = User::factory()->create();
    $admin->primary_vendor_id = $vendor->id;
    $admin->registration = ['registered' => true];
    $admin->save();
    $vendor->users()->attach($admin->id, ['role_id' => 1]);

    return compact('vendor', 'admin');
}

it('renders the leads list without lazy-loading user or last_status per row', function () {
    ['vendor' => $vendor, 'admin' => $admin] = sec4_npFixtureVendor();

    foreach (range(1, 3) as $i) {
        $lead = Lead::withoutEvents(fn () => Lead::create([
            'date' => now(),
            'origin' => 'Email',
            'lead_data' => ['name' => "Lead {$i}"],
            'belongs_to_vendor_id' => $vendor->id,
            'created_by_user_id' => $admin->id,
        ]));
        $lead->setStatus('New');
    }

    // A LazyLoadingViolationException surfaces here if either relation isn't
    // eager-loaded — the assertion is simply that rendering completes.
    Livewire::actingAs($admin)->test(LeadsIndex::class)->assertOk();
});

it('renders the timelapse lightbox without a per-frame timelapse/project lazy load', function () {
    Storage::fake('files');
    ['vendor' => $vendor, 'admin' => $admin] = sec4_npFixtureVendor();

    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);
    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Sec4 N+1 Project',
        'client_id' => $client->id,
        'address' => '1 Test St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
        'belongs_to_vendor_id' => $vendor->id,
    ]));
    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);

    $collection = ProjectTimelapse::create([
        'project_id' => $project->id,
        'title' => 'Project Images',
        'kind' => ProjectTimelapse::KIND_GALLERY,
    ]);

    foreach (range(1, 3) as $i) {
        $frame = ProjectTimelapseFrame::create([
            'project_timelapse_id' => $collection->id,
            'filename' => "frame{$i}.jpg",
            'path' => "timelapse/{$project->id}/frame{$i}.jpg",
            'disk' => 'files',
            'shot_at' => now(),
            'sort_order' => $i,
        ]);
        Storage::disk('files')->put($frame->path, 'BYTES');
    }

    $component = Livewire::actingAs($admin)
        ->test(TimelapseStudio::class, ['project' => $project])
        ->instance();

    $loaded = $component->collections()->firstWhere('id', $collection->id);

    // archiveVisibleTo() walks frame->timelapse->project for every frame —
    // this throws under preventLazyLoading() unless collections() has
    // already set both inverse relations in memory.
    $frames = $component->lightboxFrames($loaded);

    expect($frames)->toHaveCount(3);
});
