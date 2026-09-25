<?php

use App\Livewire\Tasks\TaskCreate;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * A vendor + Admin user + a project + client for that vendor.
 */
function sec1_task_company(string $label): array
{
    $vendor = Vendor::factory()->create(['business_name' => "Company {$label}"]);
    $vendor->forceFill(['registration' => ['registered' => true]])->save();

    $user = new User();
    $user->forceFill([
        'first_name' => $label,
        'last_name' => 'Admin',
        'email' => 'sec1-task-' . strtolower($label) . '-' . uniqid() . '@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
        'registration' => ['registered' => true],
    ]);
    $user->save();
    $vendor->users()->attach($user->id, ['role_id' => 1]);

    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);

    // withoutEvents(): ProjectObserver::creating() stamps belongs_to_vendor_id
    // from auth()->user() (there may be no signed-in user yet, or the wrong
    // one, while building a two-company fixture), and its created() hook
    // attaches the project_vendor pivot — done manually below instead, the
    // same relationship a real create() leaves in place.
    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => "{$label} Project",
        'client_id' => $client->id,
        'address' => '1 Main St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
        'belongs_to_vendor_id' => $vendor->id,
    ]));
    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);

    return compact('vendor', 'user', 'client', 'project');
}

function sec1_task_makeTask(array $company, array $overrides = []): Task
{
    return Task::withoutEvents(fn () => Task::create(array_merge([
        'title' => 'Existing task',
        'type' => 'Task',
        'order' => 1,
        'project_id' => $company['project']->id,
        'user_ids' => [],
        'belongs_to_vendor_id' => $company['vendor']->id,
        'created_by_user_id' => $company['user']->id,
        'options' => [],
    ], $overrides)));
}

// ── 1. editTask: cross-tenant read ──────────────────────────────────────

it('refuses to load another company\'s task into the edit form', function (): void {
    $mine = sec1_task_company('A');
    $theirs = sec1_task_company('B');
    $foreignTask = sec1_task_makeTask($theirs, ['title' => 'Their secret task']);

    Livewire::actingAs($mine['user'])
        ->test(TaskCreate::class)
        ->call('editTask', $foreignTask->id)
        ->assertNotFound();
});

it('loads a task on the same company\'s project into the edit form', function (): void {
    $mine = sec1_task_company('A');
    $task = sec1_task_makeTask($mine, ['title' => 'My own task']);

    Livewire::actingAs($mine['user'])
        ->test(TaskCreate::class)
        ->call('editTask', $task->id)
        ->assertSet('form.title', 'My own task');
});

// ── 2. task_id lock + removeTask/restoreTask authorization ─────────────

it('locks form.task_id against direct client writes', function (): void {
    $mine = sec1_task_company('A');

    $component = Livewire::actingAs($mine['user'])->test(TaskCreate::class);

    expect(fn () => $component->set('form.task_id', 999999))
        ->toThrow(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
});

it('refuses to delete a task belonging to another company even when visible on a shared project', function (): void {
    $gc = sec1_task_company('GC');
    $sub = sec1_task_company('Sub');
    // Sub is attached to the GC's project (a shared project), so the task is
    // VISIBLE to the sub, but it belongs to the GC — the sub must not be
    // able to delete it.
    $gc['project']->vendors()->attach($sub['vendor']->id, ['client_id' => $gc['client']->id]);
    $task = sec1_task_makeTask($gc, ['title' => 'GC task on shared project']);

    Livewire::actingAs($sub['user'])
        ->test(TaskCreate::class)
        ->call('editTask', $task->id)
        ->call('removeTask')
        ->assertForbidden();

    expect(Task::withTrashed()->find($task->id)->trashed())->toBeFalse();
});

it('lets a company delete its own task', function (): void {
    $mine = sec1_task_company('A');
    $task = sec1_task_makeTask($mine);

    Livewire::actingAs($mine['user'])
        ->test(TaskCreate::class)
        ->call('editTask', $task->id)
        ->call('removeTask');

    expect(Task::withTrashed()->find($task->id)->trashed())->toBeTrue();
});

// ── 3. saveNotes / saveChecklistOnly authorization ──────────────────────

it('refuses to write notes on another company\'s task visible on a shared project', function (): void {
    $gc = sec1_task_company('GC2');
    $sub = sec1_task_company('Sub2');
    $gc['project']->vendors()->attach($sub['vendor']->id, ['client_id' => $gc['client']->id]);
    $task = sec1_task_makeTask($gc, ['notes' => 'original']);

    Livewire::actingAs($sub['user'])
        ->test(TaskCreate::class)
        ->call('editTask', $task->id)
        ->set('form.notes', 'tampered by sub')
        ->call('saveNotes')
        ->assertForbidden();

    expect($task->fresh()->notes)->toBe('original');
});

it('lets a company edit notes on its own task', function (): void {
    $mine = sec1_task_company('A2');
    $task = sec1_task_makeTask($mine, ['notes' => 'original']);

    Livewire::actingAs($mine['user'])
        ->test(TaskCreate::class)
        ->call('editTask', $task->id)
        ->set('form.notes', 'updated by owner')
        ->call('saveNotes');

    expect($task->fresh()->notes)->toBe('updated by owner');
});

it('refuses to toggle a checklist item on another company\'s task', function (): void {
    $gc = sec1_task_company('GC3');
    $sub = sec1_task_company('Sub3');
    $gc['project']->vendors()->attach($sub['vendor']->id, ['client_id' => $gc['client']->id]);
    $task = sec1_task_makeTask($gc, ['options' => ['checklist' => [
        ['uid' => 'c1', 'text' => 'Item', 'completed' => false],
    ]]]);

    Livewire::actingAs($sub['user'])
        ->test(TaskCreate::class)
        ->call('editTask', $task->id)
        ->call('toggleChecklistItem', 0)
        ->assertForbidden();

    expect(data_get($task->fresh()->options, 'checklist.0.completed'))->toBeFalse();
});

// ── 4. removeDependency: must belong to form->task ──────────────────────

it('refuses to remove a dependency that does not belong to the open task', function (): void {
    $mine = sec1_task_company('A3');
    $task = sec1_task_makeTask($mine, ['title' => 'Task with no deps of its own']);

    $otherTaskA = sec1_task_makeTask($mine, ['title' => 'Unrelated predecessor']);
    $otherTaskB = sec1_task_makeTask($mine, ['title' => 'Unrelated successor']);
    $unrelatedDependency = TaskDependency::create([
        'predecessor_task_id' => $otherTaskA->id,
        'successor_task_id' => $otherTaskB->id,
        'type' => 'finish_to_start',
        'lag_days' => 0,
    ]);

    Livewire::actingAs($mine['user'])
        ->test(TaskCreate::class)
        ->call('editTask', $task->id)
        ->call('removeDependency', $unrelatedDependency->id);

    expect(TaskDependency::find($unrelatedDependency->id))->not->toBeNull();
});

it('removes a dependency that does belong to the open task', function (): void {
    $mine = sec1_task_company('A4');
    $predecessor = sec1_task_makeTask($mine, ['title' => 'Predecessor']);
    $task = sec1_task_makeTask($mine, ['title' => 'Successor']);
    $dependency = TaskDependency::create([
        'predecessor_task_id' => $predecessor->id,
        'successor_task_id' => $task->id,
        'type' => 'finish_to_start',
        'lag_days' => 0,
    ]);

    Livewire::actingAs($mine['user'])
        ->test(TaskCreate::class)
        ->call('editTask', $task->id)
        ->call('removeDependency', $dependency->id);

    expect(TaskDependency::find($dependency->id))->toBeNull();
});

// ── 5. addDependency: predecessor must be visible ───────────────────────

it('refuses to add another company\'s task as a predecessor', function (): void {
    $mine = sec1_task_company('A5');
    $theirs = sec1_task_company('B5');
    $foreignTask = sec1_task_makeTask($theirs, ['title' => 'Foreign predecessor']);
    $task = sec1_task_makeTask($mine, ['title' => 'My task']);

    Livewire::actingAs($mine['user'])
        ->test(TaskCreate::class)
        ->call('editTask', $task->id)
        ->set('selectedPredecessorId', $foreignTask->id)
        ->call('addDependency')
        ->assertHasErrors('selectedPredecessorId');

    expect(TaskDependency::where('successor_task_id', $task->id)->count())->toBe(0);
});

it('adds a visible same-company task as a predecessor', function (): void {
    $mine = sec1_task_company('A6');
    $predecessor = sec1_task_makeTask($mine, ['title' => 'Predecessor']);
    $task = sec1_task_makeTask($mine, ['title' => 'Successor']);

    Livewire::actingAs($mine['user'])
        ->test(TaskCreate::class)
        ->call('editTask', $task->id)
        ->set('selectedPredecessorId', $predecessor->id)
        ->call('addDependency')
        ->assertHasNoErrors('selectedPredecessorId');

    expect(TaskDependency::where('successor_task_id', $task->id)
        ->where('predecessor_task_id', $predecessor->id)
        ->exists())->toBeTrue();
});

// ── 6. prefillTaskFromSms: foreign task_id / project_id ignored ─────────

it('ignores a foreign task_id sent to prefillTaskFromSms', function (): void {
    $mine = sec1_task_company('A7');
    $theirs = sec1_task_company('B7');
    $foreignTask = sec1_task_makeTask($theirs, ['title' => 'Foreign task']);

    Livewire::actingAs($mine['user'])
        ->test(TaskCreate::class)
        ->call('prefillTaskFromSms', [
            'task_id' => $foreignTask->id,
            'title' => 'Injected title',
            'type' => 'Task',
        ])
        ->assertSet('form.task_id', null);
});

// ── 7. availableTasks / homeownerAvailability: foreign project_id ───────

it('does not list another company\'s tasks as predecessor candidates via a tampered project_id', function (): void {
    $mine = sec1_task_company('A8');
    $theirs = sec1_task_company('B8');
    sec1_task_makeTask($theirs, ['title' => 'Their secret task title']);

    $component = Livewire::actingAs($mine['user'])
        ->test(TaskCreate::class)
        ->set('form.project_id', $theirs['project']->id);

    expect($component->instance()->availableTasks()->pluck('title')->all())->toBe([]);
});

it('lists same-company tasks as predecessor candidates', function (): void {
    $mine = sec1_task_company('A9');
    $candidate = sec1_task_makeTask($mine, [
        'title' => 'Candidate',
        'start_date' => now()->addDay()->format('Y-m-d'),
        'end_date' => now()->addDay()->format('Y-m-d'),
    ]);

    $component = Livewire::actingAs($mine['user'])
        ->test(TaskCreate::class)
        ->set('form.project_id', $mine['project']->id);

    expect($component->instance()->availableTasks()->pluck('id')->all())->toContain($candidate->id);
});

// ── 8. TaskForm store/update: scoped project_id / parent_task_id / user_ids ──

it('refuses to save a task onto another company\'s project', function (): void {
    $mine = sec1_task_company('C1');
    $theirs = sec1_task_company('C2');

    Livewire::actingAs($mine['user'])
        ->test(TaskCreate::class)
        ->call('addTask', $theirs['project']->id)
        ->set('form.project_id', $theirs['project']->id)
        ->set('form.title', 'Sneaky task')
        ->set('form.dates', [now()->addDay()->format('Y-m-d')])
        ->call('save')
        ->assertHasErrors('form.project_id');

    expect(Task::where('title', 'Sneaky task')->exists())->toBeFalse();
});

it('saves a task normally onto the company\'s own project', function (): void {
    $mine = sec1_task_company('C3');

    Livewire::actingAs($mine['user'])
        ->test(TaskCreate::class)
        ->call('addTask', $mine['project']->id)
        ->set('form.project_id', $mine['project']->id)
        ->set('form.title', 'Legitimate task')
        ->set('form.dates', [now()->addDay()->format('Y-m-d')])
        ->call('save');

    expect(Task::where('title', 'Legitimate task')->where('project_id', $mine['project']->id)->exists())->toBeTrue();
});

it('refuses to assign a team member who is not employed by this company', function (): void {
    $mine = sec1_task_company('C4');
    $other = sec1_task_company('C5');

    Livewire::actingAs($mine['user'])
        ->test(TaskCreate::class)
        ->call('addTask', $mine['project']->id)
        ->set('form.project_id', $mine['project']->id)
        ->set('form.title', 'Task with a stranger assigned')
        ->set('form.dates', [now()->addDay()->format('Y-m-d')])
        ->set('form.user_ids', [$other['user']->id])
        ->call('save')
        ->assertHasErrors('form.user_ids');

    expect(Task::where('title', 'Task with a stranger assigned')->exists())->toBeFalse();
});
