<?php

use App\Livewire\Projects\TimelapseStudio;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectTimelapse;
use App\Models\ProjectTimelapseFrame;
use App\Models\SmsGroupThread;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function sec4_timelapseFixture(): array
{
    Storage::fake('files');

    $vendorA = Vendor::factory()->create();
    $vendorA->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();
    $vendorB = Vendor::factory()->create();
    $vendorB->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();

    $admin = User::factory()->create();
    $admin->primary_vendor_id = $vendorA->id;
    $admin->registration = ['registered' => true];
    $admin->save();
    $vendorA->users()->attach($admin->id, ['role_id' => 1]);

    // A client shared with vendor B (a homeowner working with two companies).
    $client = Client::factory()->create();
    $client->vendors()->attach($vendorA->id);
    $client->vendors()->attach($vendorB->id);

    $projectA = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Sec4 Timelapse A',
        'client_id' => $client->id,
        'address' => '1 Test St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
        'belongs_to_vendor_id' => $vendorA->id,
    ]));
    $projectA->vendors()->attach($vendorA->id, ['client_id' => $client->id]);

    $projectB = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Sec4 Timelapse B (foreign)',
        'client_id' => $client->id,
        'address' => '2 Test St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
        'belongs_to_vendor_id' => $vendorB->id,
    ]));
    $projectB->vendors()->attach($vendorB->id, ['client_id' => $client->id]);

    $foreignCollection = ProjectTimelapse::create([
        'project_id' => $projectB->id,
        'title' => 'Foreign Collection',
        'kind' => ProjectTimelapse::KIND_GALLERY,
    ]);
    $foreignFrame = ProjectTimelapseFrame::create([
        'project_timelapse_id' => $foreignCollection->id,
        'filename' => 'foreign.jpg',
        'path' => "timelapse/{$projectB->id}/foreign.jpg",
        'disk' => 'files',
        'shot_at' => now(),
        'sort_order' => 1,
    ]);
    Storage::disk('files')->put($foreignFrame->path, 'FOREIGN-TENANT-BYTES');

    // Vendor B's own SMS thread about their own project, tagged with the
    // SAME shared client_id — must not surface on vendor A's images page.
    $foreignThread = SmsGroupThread::create([
        'from_number' => '+13125550199',
        'participants' => ['+13125550100'],
        'vendor_id' => $vendorB->id,
        'client_id' => $client->id,
        'last_activity_at' => now(),
    ]);

    return compact('vendorA', 'vendorB', 'admin', 'client', 'projectA', 'projectB', 'foreignCollection', 'foreignFrame', 'foreignThread');
}

it('frameMicro, frameTakers and textThreadLabel are not callable as Livewire actions', function () {
    expect((new ReflectionMethod(TimelapseStudio::class, 'frameMicro'))->isPublic())->toBeFalse()
        ->and((new ReflectionMethod(TimelapseStudio::class, 'frameTakers'))->isPublic())->toBeFalse()
        ->and((new ReflectionMethod(TimelapseStudio::class, 'textThreadLabel'))->isPublic())->toBeFalse();
});

it('refuses lightboxFrames for a collection outside the current project', function () {
    $fx = sec4_timelapseFixture();

    $component = Livewire::actingAs($fx['admin'])
        ->test(TimelapseStudio::class, ['project' => $fx['projectA']])
        ->instance();

    $frames = $component->lightboxFrames($fx['foreignCollection']);

    expect($frames)->toBe([]);
});

it('does not surface another tenant thread sharing the project client on messageImages', function () {
    $fx = sec4_timelapseFixture();

    $component = Livewire::actingAs($fx['admin'])
        ->test(TimelapseStudio::class, ['project' => $fx['projectA']])
        ->instance();

    $images = $component->messageImages();

    // messageImages resolves threads by (this project's id OR client_id) —
    // the foreign thread shares the client_id but belongs to vendor B, so it
    // must be excluded now that accessibleTo() gates the query.
    expect($images)->toBeEmpty();
});

it('lightboxFrames still returns frames for the studio own project', function () {
    $fx = sec4_timelapseFixture();

    $ownCollection = ProjectTimelapse::create([
        'project_id' => $fx['projectA']->id,
        'title' => 'Own Collection',
        'kind' => ProjectTimelapse::KIND_GALLERY,
    ]);
    $ownFrame = ProjectTimelapseFrame::create([
        'project_timelapse_id' => $ownCollection->id,
        'filename' => 'own.jpg',
        'path' => "timelapse/{$fx['projectA']->id}/own.jpg",
        'disk' => 'files',
        'shot_at' => now(),
        'sort_order' => 1,
    ]);
    Storage::disk('files')->put($ownFrame->path, 'OWN-BYTES');

    $component = Livewire::actingAs($fx['admin'])
        ->test(TimelapseStudio::class, ['project' => $fx['projectA']])
        ->instance();

    $ownCollection->load('frames');
    $frames = $component->lightboxFrames($ownCollection);

    expect($frames)->toHaveCount(1)
        ->and($frames[0]['id'])->toBe($ownFrame->id);
});
