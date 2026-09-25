<?php

/**
 * EstimateCreate::mount() had its authorize() call commented out — and the
 * commented line itself was wrong (authorize('create', Estimate::class,
 * $this->project) silently drops $this->project, a 3rd positional arg the
 * 2-arg authorize() signature ignores), which is almost certainly why it was
 * disabled rather than fixed. Restored as authorize('create', [Estimate::
 * class, $this->project]) so EstimatePolicy::create(User, Project) actually
 * receives the project.
 */

use App\Livewire\Estimates\EstimateCreate;
use App\Models\Estimate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/t3-fixtures.php';

it('lets an Admin create an estimate for their own project', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $project = sec3_makeProject($vendor, $client);
    $admin = sec3_makeAdmin($vendor);
    test()->actingAs($admin);

    Livewire::test(EstimateCreate::class, ['project' => $project]);

    expect(Estimate::where('project_id', $project->id)->exists())->toBeTrue();
});

it('refuses a vendor Member creating an estimate', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $project = sec3_makeProject($vendor, $client);
    $member = sec3_makeMember($vendor);
    test()->actingAs($member);

    Livewire::test(EstimateCreate::class, ['project' => $project])
        ->assertForbidden();

    expect(Estimate::where('project_id', $project->id)->exists())->toBeFalse();
});
