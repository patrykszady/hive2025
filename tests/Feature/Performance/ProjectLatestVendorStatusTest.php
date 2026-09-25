<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * @return array{0: Vendor, 1: Vendor, 2: array<int, Project>}
 */
function perfStatus_fixture(): array
{
    $mine = Vendor::factory()->create();
    $partner = Vendor::factory()->create();

    $user = new User();
    $user->forceFill([
        'first_name' => 'Status',
        'last_name' => 'Reader',
        'email' => 'status-reader-'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224666####'),
        'primary_vendor_id' => $mine->id,
    ]);
    $user->save();
    $mine->users()->attach($user->id, ['role_id' => 1]);
    test()->actingAs($user);

    $client = Client::factory()->create();
    $projects = [];
    foreach (range(1, 6) as $i) {
        $project = Project::withoutEvents(fn () => Project::factory()->create(['belongs_to_vendor_id' => $mine->id, 'client_id' => $client->id]));
        ProjectStatus::withoutEvents(fn () => ProjectStatus::withoutGlobalScopes()->create([
            'project_id' => $project->id, 'belongs_to_vendor_id' => $partner->id,
            'start_date' => now()->subDays(2)->toDateString(), 'status_code' => 'Active',
        ]));
        if ($i % 2 === 0) {
            ProjectStatus::withoutEvents(fn () => ProjectStatus::withoutGlobalScopes()->create([
                'project_id' => $project->id, 'belongs_to_vendor_id' => $mine->id,
                'start_date' => now()->subDays(5)->toDateString(), 'status_code' => 'Estimate',
            ]));
        }
        $projects[] = $project;
    }

    return [$mine, $partner, $projects];
}

it('answers from one query per page and matches the per-row lookup', function () {
    [$mine, , $projects] = perfStatus_fixture();

    $expected = collect($projects)->map(fn (Project $p) => $p->latestVendorStatus($mine->id)?->id)->all();

    $fresh = Project::withoutGlobalScopes()->whereIn('id', collect($projects)->pluck('id'))->orderBy('id')->get();

    DB::enableQueryLog();
    Project::primeLatestVendorStatuses($fresh);
    $primed = $fresh->map(fn (Project $p) => $p->latestVendorStatus($mine->id)?->id)->all();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(1)
        ->and($primed)->toBe($expected);
});

it('prefers the signed-in company\'s own status and falls back to the latest of anyone\'s', function () {
    [$mine, $partner, $projects] = perfStatus_fixture();

    $fresh = Project::withoutGlobalScopes()->whereIn('id', collect($projects)->pluck('id'))->orderBy('id')->get();
    Project::primeLatestVendorStatuses($fresh);

    expect($fresh[0]->latestVendorStatus($mine->id)->belongs_to_vendor_id)->toBe($partner->id)
        ->and($fresh[1]->latestVendorStatus($mine->id)->belongs_to_vendor_id)->toBe($mine->id);
});
