<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// =========================================================================
// RG Tile's grout task listed the owner's text as its checklist, twice
// over (2026-10-15). tasks:strip-message-checklists takes copied sentences
// and repeats off, and leaves real to-dos and ticked items alone.
// =========================================================================

it('removes copied message sentences and repeats, and keeps real to-dos and ticked items', function () {
    $message = "Hey Patryk, hope things have been going well. The new shower grout that was water resistant seems to be holding up well and there's more discoloration appearing elsewhere. Is it possible we pursue the idea of replacing the entire shower floor grout with a new batch so it all acts the same and has the same color?";
    $sentences = [
        'Hey Patryk, hope things have been going well',
        "The new shower grout that was water resistant seems to be holding up well and there's more discoloration appearing elsewhere",
        'Is it possible we pursue the idea of replacing the entire shower floor grout with a new batch so it all acts the same and has the same color',
    ];
    $item = fn (string $text, bool $done = false) => ['text' => $text, 'completed' => $done];
    $project = Project::withoutEvents(fn () => Project::factory()->create(['client_id' => Client::factory()->create()->id]));
    $task = fn (array $attributes) => Task::withoutEvents(fn () => Task::create($attributes + ['type' => 'Task', 'belongs_to_vendor_id' => 1, 'project_id' => $project->id, 'created_by_user_id' => 1, 'order' => 0, 'start_date' => '2026-10-20', 'end_date' => '2026-10-20', 'user_ids' => []]));

    // The owner's text arrived by SMS; the task's notes kept only its last
    // sentence, as RG Tile's did, so the first two are found in the text.
    \App\Models\SmsMessage::query()->create(['direction' => 'inbound', 'from_number' => '+12245550101', 'to_numbers' => ['+12245554444'], 'text' => $message, 'created_at' => now()->subDays(2)]);

    $grout = $task(['title' => 'Shower Floor Grout Replacement', 'notes' => $sentences[2].'?', 'options' => ['checklist' => [
        ...array_map($item, $sentences),
        ...array_map($item, $sentences),
        $item('Regrout entire shower floor'),
    ]]]);
    $ticked = $task(['title' => 'Trim Repair', 'notes' => 'Trim from hidden pantry fell off and needs to go back up.', 'options' => ['checklist' => [
        $item('Trim from hidden pantry fell off and needs to go back up', true),
        $item('Reattach pantry trim'),
    ]]]);

    $this->artisan('tasks:strip-message-checklists', ['--dry-run' => true])
        ->expectsOutputToContain('[dry run] 6 item(s) removed from 1 task(s).')
        ->assertSuccessful();
    expect($grout->fresh()->options->checklist)->toHaveCount(7);

    $this->artisan('tasks:strip-message-checklists')->assertSuccessful();

    expect(array_column(json_decode(json_encode($grout->fresh()->options->checklist), true), 'text'))->toBe(['Regrout entire shower floor'])
        ->and(json_decode(json_encode($ticked->fresh()->options->checklist), true))->toHaveCount(2);

    $this->artisan('tasks:strip-message-checklists')->expectsOutputToContain('0 item(s) removed from 0 task(s).')->assertSuccessful();
});
