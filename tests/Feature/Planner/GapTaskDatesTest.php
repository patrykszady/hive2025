<?php

use App\Livewire\Planner\CardsIndex;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The planner table/kanban views asked for a project's gap-calculation task
 * dates one project at a time — 18x `SELECT ... WHERE project_id = ?` for
 * 18 visible projects. allTaskDatesForProject() now batches every active
 * project into one `whereIn` query the first time any project's dates are
 * requested. activeProjects() itself needs MySQL (JSON_OVERLAPS), so this
 * tests the pure helper and the fallback/memoization paths that DON'T
 * require it, plus the batching query shape via a stub subclass — same
 * constraint noted in the livewire-islands-rules memory for Gantt/table
 * render tests.
 */
function perf_plannerUser(): User
{
    $vendor = Vendor::factory()->create(['business_type' => 'GC']);

    $user = User::query()->create([
        'first_name' => 'Planner', 'last_name' => 'Admin',
        'email' => 'planner-admin-'.uniqid().'@example.test',
        'cell_phone' => '224'.rand(1000000, 9999999),
        'primary_vendor_id' => $vendor->id,
    ]);
    $user->vendors()->attach($vendor->id, ['role_id' => 1]);

    return $user;
}

function perf_plannerProject(User $user, string $address): Project
{
    $client = Client::factory()->create();

    return Project::factory()->create([
        'address' => $address,
        'project_name' => $address,
        'belongs_to_vendor_id' => $user->vendor->id,
        'client_id' => $client->id,
    ]);
}

it('summarizeTaskDates extracts selected dates, falls back to start_date, dedupes and sorts', function () {
    $withSelectedDates = new Task();
    $withSelectedDates->options = ['dates' => ['2026-02-03', '2026-02-01']];

    $withStartDateOnly = new Task();
    $withStartDateOnly->options = [];
    $withStartDateOnly->start_date = '2026-02-02';

    $duplicateOfAbove = new Task();
    $duplicateOfAbove->options = ['dates' => ['2026-02-01']];

    $method = new ReflectionMethod(CardsIndex::class, 'summarizeTaskDates');
    $method->setAccessible(true);

    $result = $method->invoke(new CardsIndex(), collect([$withSelectedDates, $withStartDateOnly, $duplicateOfAbove]));

    expect($result->values()->all())->toBe(['2026-02-01', '2026-02-02', '2026-02-03']);
});

it('memoizes a project\'s dates once computed, without re-querying', function () {
    $user = perf_plannerUser();
    $this->actingAs($user);

    $project = perf_plannerProject($user, '123 Fixture St');
    Task::factory()->create(['project_id' => $project->id, 'title' => 'Task 1', 'start_date' => '2026-03-01']);

    // activeProjects() needs MySQL's JSON_OVERLAPS — stub it (see the next
    // test) rather than depend on the real query for this project set.
    $component = new class extends CardsIndex
    {
        public $stubProjects;

        #[\Livewire\Attributes\Computed]
        public function activeProjects()
        {
            return $this->stubProjects;
        }
    };
    $component->stubProjects = collect([$project]);

    $method = new ReflectionMethod(CardsIndex::class, 'allTaskDatesForProject');
    $method->setAccessible(true);

    // First call primes every active project's dates in one query.
    DB::enableQueryLog();
    $first = $method->invoke($component, $project->id);
    $queriesAfterFirst = collect(DB::getQueryLog())->count();

    // Second call for the SAME project must not query again.
    $second = $method->invoke($component, $project->id);
    $queriesAfterSecond = collect(DB::getQueryLog())->count();
    DB::disableQueryLog();

    expect($first->all())->toBe(['2026-03-01'])
        ->and($second->all())->toBe(['2026-03-01'])
        ->and($queriesAfterSecond)->toBe($queriesAfterFirst);
});

it('batches every active project\'s task dates into one query instead of one per project', function () {
    $user = perf_plannerUser();
    $this->actingAs($user);

    $projects = collect();
    for ($i = 0; $i < 5; $i++) {
        $project = perf_plannerProject($user, "Project {$i}");
        Task::factory()->create(['project_id' => $project->id, 'title' => "Task {$i}", 'start_date' => '2026-04-0'.($i + 1)]);
        $projects->push($project);
    }

    // activeProjects() needs MySQL's JSON_OVERLAPS — stub it here with the
    // fixture projects so primeGapTaskDatesForActiveProjects() (the batching
    // logic under test) runs against a real, known project set on sqlite.
    $component = new class extends CardsIndex
    {
        public $stubProjects;

        #[\Livewire\Attributes\Computed]
        public function activeProjects()
        {
            return $this->stubProjects;
        }
    };
    $component->stubProjects = $projects;

    $method = new ReflectionMethod(CardsIndex::class, 'allTaskDatesForProject');
    $method->setAccessible(true);

    DB::enableQueryLog();
    foreach ($projects as $project) {
        $method->invoke($component, $project->id);
    }
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    $taskQueries = $queries->filter(fn ($sql) => str_contains($sql, 'from "tasks"'));

    // One batched query for all 5 projects, not 5 individual ones.
    expect($taskQueries)->toHaveCount(1)
        ->and($taskQueries->first())->toContain('in (');
});
