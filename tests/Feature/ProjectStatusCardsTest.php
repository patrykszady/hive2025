<?php

use App\Livewire\Projects\ProjectsIndex;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ProjectStatusTrends;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function statusCardsUser(): User
{
    $vendor = Vendor::factory()->create();
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => 1]);

    return $user;
}

/** @param  array<string, int>  $statuses  start date => status code */
function projectWithStatuses(Vendor $vendor, array $statuses): Project
{
    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Kitchen',
        'client_id' => Client::factory()->create()->id,
        'address' => '806 Parkside',
        'city' => 'Lake Zurich',
        'state' => 'IL',
        'zip_code' => '60047',
        'belongs_to_vendor_id' => $vendor->id,
    ]));

    foreach ($statuses as $startDate => $code) {
        ProjectStatus::withoutEvents(fn () => ProjectStatus::withoutGlobalScopes()->create([
            'project_id' => $project->id,
            'belongs_to_vendor_id' => $vendor->id,
            'status_code' => $code,
            'start_date' => $startDate,
        ]));
    }

    return $project;
}

/** @param  array<int, array{0: int, 1: int}>  $nowAndBefore  status code => [now, before] */
function fakeTrends(array $nowAndBefore): array
{
    return collect($nowAndBefore)
        ->map(fn (array $pair) => ['now' => $pair[0], 'before' => $pair[1], 'points' => array_fill(0, ProjectStatusTrends::WEEKS + 1, $pair[0])])
        ->all();
}

it('counts each status now, thirty days ago and week by week from status history', function () {
    $vendor = Vendor::factory()->create();
    $today = Carbon::parse('2026-10-15');

    // Estimate in August, Response since Oct 1.
    projectWithStatuses($vendor, ['2026-08-01' => 2, '2026-10-01' => 3]);
    // Active since July, a Service Call since Oct 10.
    projectWithStatuses($vendor, ['2026-07-01' => 6, '2026-10-10' => 8]);
    // Prep since September, Scheduled to start in November: Scheduled now,
    // as the list's latest-status filter has it.
    projectWithStatuses($vendor, ['2026-09-01' => 4, '2026-11-01' => 5]);
    // A deleted project and another company's statuses don't count.
    projectWithStatuses($vendor, ['2026-09-01' => 6])->delete();
    projectWithStatuses(Vendor::factory()->create(), ['2026-09-01' => 6]);

    $trends = app(ProjectStatusTrends::class)->forVendor($vendor->id, $today);

    expect(collect($trends)->map(fn ($trend) => [$trend['now'], $trend['before']])->all())->toBe([
        2 => [0, 1],
        3 => [1, 0],
        4 => [0, 1],
        5 => [1, 0],
        6 => [0, 1],
        8 => [1, 0],
    ])
        // Weekly points end on today's count; a week ago (Oct 8) the Service
        // Call project was still Active and the Scheduled one in Prep.
        ->and($trends[8]['points'])->toHaveCount(ProjectStatusTrends::WEEKS + 1)
        ->and(last($trends[8]['points']))->toBe(1)
        ->and($trends[6]['points'][ProjectStatusTrends::WEEKS - 1])->toBe(1)
        ->and($trends[4]['points'][ProjectStatusTrends::WEEKS - 1])->toBe(1);
});

it('shows a stat card per open status with its change since thirty days ago, better or worse', function () {
    $user = statusCardsUser();
    $this->mock(ProjectStatusTrends::class)
        ->shouldReceive('forVendor')
        ->andReturn(fakeTrends([2 => [22, 17], 3 => [15, 15], 4 => [7, 7], 6 => [5, 9], 7 => [269, 264], 8 => [2, 1], 9 => [0, 3], 10 => [120, 110]]));

    $component = Livewire::actingAs($user)
        ->withQueryParams(['project_status_title' => [4, 8]])
        ->test(ProjectsIndex::class);

    $cards = collect($component->instance()->statusCards)->keyBy('label');

    // Archive statuses (Complete, Cancelled) and statuses empty now and then
    // get no card; Consult emptied out this month, so it still shows.
    expect($cards->keys()->all())->toBe(['Active', 'Service Call', 'Prep', 'Estimate', 'Response', 'Consult'])
        ->and($cards['Estimate'])->toMatchArray(['count' => 22, 'delta' => 5, 'better' => true])
        ->and($cards['Response'])->toMatchArray(['delta' => 0, 'better' => null])
        ->and($cards['Active'])->toMatchArray(['delta' => -4, 'better' => false])
        // More service calls is worse news, not better.
        ->and($cards['Service Call'])->toMatchArray(['delta' => 1, 'better' => false])
        ->and($cards->where('selected', true)->keys()->all())->toBe(['Service Call', 'Prep'])
        ->and($cards['Prep']['style']['selected'])->toContain('aria-pressed:border-amber-400')
        ->and($cards['Prep']['style']['count'])->toContain('bg-amber-400/10');

    $component->assertSeeHtml('aria-label="Projects by status"')
        ->assertSeeHtml('aria-pressed="true"')
        ->assertSee('+5')
        ->assertSee('-4')
        ->assertSeeHtml('x-on:click="select(4)"')
        ->assertSeeHtml('x-bind:aria-pressed="has(4).toString()"');
});

it('keeps the cards under the filters, and the lists below them on fixed keys', function () {
    $this->mock(ProjectStatusTrends::class)
        ->shouldReceive('forVendor')
        ->andReturn(fakeTrends([4 => [7, 6]]));

    Livewire::actingAs(statusCardsUser())
        ->test(ProjectsIndex::class)
        ->assertSeeInOrder(['Filters', 'aria-label="Projects by status"'], false);

    $view = file_get_contents(resource_path('views/livewire/projects/index.blade.php'));

    expect($view)->toContain('wire:key="projects-index-table"')
        ->toContain('wire:key="projects-index-email-tracking"')
        ->toContain('wire:key="projects-index-deleted"');
});

it('shows no cards on a client\'s embedded project list', function () {
    $this->mock(ProjectStatusTrends::class)->shouldNotReceive('forVendor');

    $component = Livewire::actingAs(statusCardsUser())
        ->test(ProjectsIndex::class, ['view' => 'clients.index']);

    expect($component->instance()->statusCards)->toBe([]);
});

it('swaps the list in place on a filter change, without a page transition', function () {
    $this->mock(ProjectStatusTrends::class)
        ->shouldReceive('forVendor')
        ->andReturn(fakeTrends([4 => [7, 6]]));

    $component = Livewire::actingAs(statusCardsUser())
        ->test(ProjectsIndex::class)
        ->set('project_status_title', [4]);

    expect($component->effects['transition'] ?? null)->toBe(['skip' => true]);
});

it('reads the Status filter as a list, and an old single-status link as a list of one', function () {
    expect(ProjectsIndex::normalizeStatusCodes('6'))->toBe([6])
        ->and(ProjectsIndex::normalizeStatusCodes(['2', '3', '3', '99', '']))->toBe([2, 3])
        ->and(ProjectsIndex::normalizeStatusCodes(''))->toBe([])
        ->and(ProjectsIndex::normalizeStatusCodes(null))->toBe([]);

    $this->mock(ProjectStatusTrends::class)
        ->shouldReceive('forVendor')
        ->andReturn(fakeTrends([6 => [5, 9]]));

    Livewire::actingAs(statusCardsUser())
        ->withQueryParams(['project_status_title' => '6'])
        ->test(ProjectsIndex::class)
        ->assertSet('project_status_title', [6])
        ->set('project_status_title', ['2', '9'])
        ->assertSet('project_status_title', [2, 9]);
});
