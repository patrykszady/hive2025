<?php

use App\Livewire\Estimates\EstimateShow;
use App\Models\Bid;
use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateSection;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('treats sections as change orders only after the contract (base bid) exists', function () {
    $vendor = Vendor::query()->create([
        'business_name' => 'GS Construction',
        'business_type' => 'Sub',
        'business_email' => 'gc@example.test',
        'address' => '123 Main St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
    ]);

    $user = User::query()->create([
        'first_name' => 'Test', 'last_name' => 'User',
        'email' => 'co-bids-' . Str::random(8) . '@example.test',
        'cell_phone' => '7' . random_int(100000000, 999999999),
        'password' => bcrypt('password'),
    ]);
    $user->forceFill(['primary_vendor_id' => $vendor->id])->saveQuietly();
    $this->actingAs($user);

    $project = Project::query()->create([
        'project_name' => 'Started Before Signing',
        'client_id' => Client::query()->create([
            'business_name' => 'Owner', 'address' => '1 Oak St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
        ])->id,
        'address' => '1 Oak St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
    ]);

    // Work started (Active) but no contract signed yet — no type-1 bid exists.
    ProjectStatus::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'status_code' => 6, // Active
        'start_date' => now(),
    ]);

    $estimate = Estimate::withoutGlobalScopes()->create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
    ]);

    $section = EstimateSection::create([
        'estimate_id' => $estimate->id,
        'order' => 0,
        'name' => 'Family Room',
        'total' => 225.00,
    ]);

    $method = new ReflectionMethod(EstimateShow::class, 'maybeCreateChangeOrderBid');
    $method->invoke(new EstimateShow, $section);

    // Unsigned contract: the section stays part of the estimate — no CO bid.
    expect(Bid::withoutGlobalScopes()->where('project_id', $project->id)->count())->toBe(0)
        ->and($section->fresh()->bid_id)->toBeNull();

    // Sign the contract (EstimateAccept creates the base type-1 bid) …
    Bid::withoutGlobalScopes()->create([
        'project_id' => $project->id, 'vendor_id' => $vendor->id, 'amount' => 225.00, 'type' => 1,
    ]);

    // … then a new section IS a change order.
    $newSection = EstimateSection::create([
        'estimate_id' => $estimate->id,
        'order' => 1,
        'name' => 'Extra Closet',
        'total' => 500.00,
    ]);
    $method->invoke(new EstimateShow, $newSection);

    $coBid = Bid::withoutGlobalScopes()
        ->where('project_id', $project->id)
        ->where('type', '>', 1)
        ->first();

    expect($coBid)->not->toBeNull()
        ->and($coBid->type)->toBe(2)
        ->and($newSection->fresh()->bid_id)->toBe($coBid->id);
});
