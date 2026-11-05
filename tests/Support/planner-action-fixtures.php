<?php

/**
 * The planner's action-item world, shared by the planner tests that read
 * ProjectActionItem rows: a company with one admin signed in and one
 * project, an item row on it, and a CardsIndex whose activeProjects is
 * stubbed (the real query needs MySQL's JSON_OVERLAPS). require_once this
 * file rather than declaring these names twice.
 */

use App\Livewire\Planner\CardsIndex;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectActionItem;
use App\Models\User;
use App\Models\Vendor;

if (! function_exists('actionPlannerFixture')) {
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
}

if (! function_exists('actionItemRow')) {
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
}

if (! function_exists('plannerWithProjects')) {
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
}
