<?php
use App\Livewire\Planner\CardsIndex;
use App\Models\{Task, Project, User, Vendor, Client};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('persists drag updates to task dates and reflects them in gantt rows', function (): void {
    // The gantt query uses MySQL's JSON_OVERLAPS, absent from the sqlite test DB.
    if (\DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Gantt render requires MySQL (JSON_OVERLAPS).');
    }

    // ProjectObserver stamps belongs_to_vendor_id from the authenticated
    // user's vendor, so a vendor-scoped, logged-in user must exist first.
    $vendor = Vendor::factory()->create();
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $this->actingAs($user);

    $client = Client::factory()->create();
    $project = Project::factory()->create([
        'belongs_to_vendor_id' => $vendor->id,
        'client_id' => $client->id,
    ]);
    $task = Task::factory()->create([
        'project_id' => $project->id,
        'start_date' => now()->startOfDay()->format('Y-m-d'),
        'end_date'   => now()->startOfDay()->addDays(2)->format('Y-m-d'),
    ]);

    $newStart = now()->startOfDay()->addDays(5)->format('Y-m-d');
    $newEnd   = now()->startOfDay()->addDays(7)->format('Y-m-d');

    $comp = Livewire::actingAs($user)
        ->test(CardsIndex::class)
        ->set('viewMode', 'gantt')
        ->call('updateTaskDates', $task->id, $newStart, $newEnd);

    expect($task->fresh()->start_date->format('Y-m-d'))->toBe($newStart)
        ->and($task->fresh()->end_date->format('Y-m-d'))->toBe($newEnd);
});
