<?php

use App\Livewire\Planner\CardsIndex;
use App\Livewire\Tasks\TaskCreate;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectActionItem;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// =========================================================================
// Planner: "From calls & texts" (2026-10-12). The open action items a
// project's calls and texts asked for (ProjectActionItemExtractor) show as a
// chip on the project column in every view and a modal to act on them: make
// a task (the task dialog, prefilled), done, or dismiss.
// =========================================================================

/**
 * @return array{user: User, vendor: Vendor, project: Project}
 */
function actionPlannerFixture(): array
{
    $vendor = Vendor::factory()->create(['business_type' => 'GC']);
    $user = User::query()->create([
        'first_name' => 'Planner', 'last_name' => 'Admin',
        'email' => 'planner-items-'.uniqid().'@example.test',
        'cell_phone' => '224'.rand(1000000, 9999999),
        'primary_vendor_id' => $vendor->id,
    ]);
    $user->vendors()->attach($vendor->id, ['role_id' => 1]);
    test()->actingAs($user);

    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);
    $project = Project::factory()->create(['address' => '3154 Violet', 'project_name' => 'Home Renovation', 'client_id' => $client->id]);
    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);

    return ['user' => $user, 'vendor' => $vendor, 'project' => $project];
}

function actionItemRow(Project $project, string $title, array $extra = []): ProjectActionItem
{
    return ProjectActionItem::create(array_merge([
        'project_id' => $project->id,
        'title' => $title,
        'owner' => 'Patryk',
        'status' => ProjectActionItem::STATUS_OPEN,
        'mentions' => 1,
        'first_mentioned_at' => now()->subDay(),
        'last_mentioned_at' => now()->subDay(),
        'sources' => [['kind' => 'call', 'id' => 7, 'thread_id' => null, 'at' => now()->subDay()->toIso8601String(), 'excerpt' => 'Patryk: '.$title]],
        'fingerprint' => ProjectActionItem::fingerprintFor($title),
    ], $extra));
}

/** CardsIndex with activeProjects stubbed: the real query needs MySQL's JSON_OVERLAPS. */
function plannerWithProjects(array $projects): CardsIndex
{
    $component = new class extends CardsIndex
    {
        public $stubProjects;

        #[\Livewire\Attributes\Computed]
        public function activeProjects()
        {
            return $this->stubProjects;
        }
    };
    $component->stubProjects = collect($projects);

    return $component;
}

it('counts and lists the open items per active project, newest mention first', function () {
    ['project' => $project] = actionPlannerFixture();
    $other = Project::factory()->create(['address' => '9 Elsewhere', 'client_id' => $project->client_id]);
    $older = actionItemRow($project, 'Send Mark the latest window invoice', ['last_mentioned_at' => now()->subDays(3)]);
    $newer = actionItemRow($project, 'Call Denise for the final window invoice', ['last_mentioned_at' => now()->subHour()]);
    actionItemRow($project, 'Order the shed', ['status' => ProjectActionItem::STATUS_DONE]);
    actionItemRow($project, 'Pick a paint colour', ['status' => ProjectActionItem::STATUS_TASK]);
    actionItemRow($other, 'Not on this project');

    $planner = plannerWithProjects([$project]);

    expect($planner->actionItemCounts())->toBe([$project->id => 2]);

    $byProject = $planner->actionItemsByProject();
    expect(array_keys($byProject))->toBe([$project->id])
        ->and($byProject[$project->id]['title'])->toBe($project->short_address)
        ->and($byProject[$project->id]['recent'])->toBe(2)
        ->and($byProject[$project->id]['older'])->toBe(0)
        ->and(array_keys($byProject[$project->id]['groups']))->toBe(['Patryk'])
        ->and($byProject[$project->id]['groups']['Patryk']->pluck('id')->all())->toBe([$newer->id, $older->id]);
});

it('groups the modal by who is responsible, the signed-in person first, and parks the older ones', function () {
    ['user' => $user, 'project' => $project] = actionPlannerFixture();
    $mine = actionItemRow($project, 'Send the budget spreadsheet', ['owner' => $user->first_name]);
    $client = actionItemRow($project, 'Finalize tile selection for the basement', ['owner' => 'Mark']);
    $company = actionItemRow($project, 'Clean up and fill the basement crack', ['owner' => 'GS']);
    $nobody = actionItemRow($project, 'Proceed with the pocket door option', ['owner' => null]);
    $old = actionItemRow($project, 'Order the Bain de Ville tub', ['owner' => 'Mark', 'last_mentioned_at' => now()->subDays(20)]);

    $entry = plannerWithProjects([$project])->actionItemsByProject()[$project->id];

    expect(array_keys($entry['groups']))->toBe([$user->first_name, 'GS', 'Mark', 'Unassigned'])
        ->and($entry['groups']['Mark']->pluck('id')->all())->toBe([$client->id, $old->id])
        ->and($entry['recent'])->toBe(4)
        ->and($entry['older'])->toBe(1);
});

it('ticks an item off or dismisses it from the planner, on this tenant\'s projects only', function () {
    ['project' => $project] = actionPlannerFixture();
    $done = actionItemRow($project, 'Send Mark the latest window invoice');
    $dismissed = actionItemRow($project, 'Research Provia door pricing');

    $strangerVendor = Vendor::factory()->create();
    $strangerProject = Project::factory()->create(['address' => '1 Far Away', 'client_id' => $project->client_id]);
    $strangerProject->vendors()->attach($strangerVendor->id, ['client_id' => $project->client_id]);
    \DB::table('project_vendor')->where('project_id', $strangerProject->id)->where('vendor_id', '!=', $strangerVendor->id)->delete();
    $strangerItem = actionItemRow($strangerProject, 'Someone else\'s item');

    $planner = plannerWithProjects([$project]);
    $planner->actionItemDone($done->id);
    $planner->actionItemDismiss($dismissed->id);
    $planner->actionItemDone($strangerItem->id);

    expect($done->fresh()->status)->toBe(ProjectActionItem::STATUS_DONE)
        ->and($done->fresh()->done_at)->not->toBeNull()
        ->and($done->fresh()->done_evidence['kind'])->toBe('user')
        ->and($dismissed->fresh()->status)->toBe(ProjectActionItem::STATUS_DISMISSED)
        ->and($strangerItem->fresh()->status)->toBe(ProjectActionItem::STATUS_OPEN)
        ->and($planner->actionItemCounts())->toBe([]);
});

it('the task dialog, opened from an item, saves a task that the item points at', function () {
    ['user' => $user, 'project' => $project] = actionPlannerFixture();
    $item = actionItemRow($project, 'Call Denise on Monday for the final window invoice', ['due_on' => now()->addDays(2)->toDateString()]);

    $dialog = Livewire::actingAs($user)
        ->test(TaskCreate::class)
        ->call('prefillTaskFromSms', [
            'title' => $item->title,
            'project_id' => $project->id,
            'client_id' => $project->client_id,
            'date' => $item->due_on->toDateString(),
            'notes' => 'From a call Oct 10: Patryk: Call Denise on Monday for the final window invoice',
            'action_item_id' => $item->id,
        ]);

    expect($dialog->instance()->actionItemId)->toBe($item->id)
        ->and($dialog->instance()->form->title)->toBe($item->title)
        ->and($dialog->instance()->form->project_id)->toBe($project->id)
        ->and($dialog->instance()->form->dates)->toBe([$item->due_on->toDateString()]);

    $dialog->call('save')->assertDispatchedTo(CardsIndex::class, 'action-items-changed');

    $task = Task::query()->where('project_id', $project->id)->where('title', $item->title)->first();
    expect($task)->not->toBeNull()
        ->and($item->fresh()->status)->toBe(ProjectActionItem::STATUS_TASK)
        ->and($item->fresh()->task_id)->toBe($task->id)
        ->and($dialog->instance()->actionItemId)->toBeNull();

    // The item's id is not trusted across projects: a task saved elsewhere leaves it alone.
    $elsewhere = Project::factory()->create(['address' => '2 Other St', 'client_id' => $project->client_id]);
    $elsewhere->vendors()->attach($user->vendor->id, ['client_id' => $project->client_id]);
    $second = actionItemRow($project, 'Order the Gyorgy door');
    Livewire::actingAs($user)
        ->test(TaskCreate::class)
        ->call('prefillTaskFromSms', ['title' => 'Order the Gyorgy door', 'project_id' => $elsewhere->id, 'action_item_id' => $second->id])
        ->call('save');
    expect($second->fresh()->status)->toBe(ProjectActionItem::STATUS_OPEN);
});

it('links each source to where it can be read back', function () {
    expect(ProjectActionItem::sourceUrl(['kind' => 'sms', 'id' => 46542, 'thread_id' => 201]))->toEndWith('/messages?threadId=201')
        ->and(ProjectActionItem::sourceUrl(['kind' => 'call', 'id' => 2648]))->toContain('activeTab=calls')
        ->and(ProjectActionItem::sourceUrl(['kind' => 'call', 'id' => 2648]))->toContain('callId=2648')
        ->and(ProjectActionItem::sourceLabel(['kind' => 'call', 'at' => '2026-10-10T19:37:00+00:00']))->toBe('call Oct 10')
        ->and(ProjectActionItem::sourceLabel(['kind' => 'sms', 'at' => '2026-10-12T12:42:00+00:00']))->toBe('text Oct 12');
});

it('links an email source to the project page and labels it with its subject', function () {
    $projectId = 123; // only used to build the URL — no row needs to exist for that
    $source = ['kind' => 'email', 'id' => 91, 'thread_id' => null, 'at' => '2026-10-15T09:00:00+00:00', 'subject' => 'Cabinet hardware'];

    expect(ProjectActionItem::sourceUrl($source, $projectId))->toBe(route('projects.show', $projectId))
        ->and(ProjectActionItem::sourceUrl($source))->toBeNull()
        ->and(ProjectActionItem::sourceLabel($source))->toBe('Cabinet hardware Oct 15')
        ->and(ProjectActionItem::sourceLabel(['kind' => 'email', 'at' => '2026-10-15T09:00:00+00:00']))->toBe('email Oct 15')
        ->and(ProjectActionItem::sourceArticle($source))->toBe('an')
        ->and(ProjectActionItem::sourceArticle(['kind' => 'call']))->toBe('a')
        ->and(ProjectActionItem::sourcePhrase($source))->toBe('From an email (Cabinet hardware Oct 15)')
        ->and(ProjectActionItem::sourcePhrase(['kind' => 'email', 'at' => '2026-10-15T09:00:00+00:00']))->toBe('From an email Oct 15')
        ->and(ProjectActionItem::sourcePhrase(['kind' => 'call', 'at' => '2026-10-10T19:37:00+00:00']))->toBe('From a call Oct 10');
});
