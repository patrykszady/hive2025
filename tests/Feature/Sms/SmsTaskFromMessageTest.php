<?php

use App\Livewire\Sms\SmsConversation;
use App\Livewire\Tasks\TaskCreate;
use App\Models\Client;
use App\Models\Project;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\User;
use App\Models\Vendor;
use App\Services\SmsTaskExtractionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Build a fake OpenAI structured-output response body.
 *
 * @param  array<string, mixed>  $payload
 */
function fakeOpenAiTaskResponse(array $payload): array
{
    return [
        'choices' => [
            ['message' => ['content' => json_encode($payload)]],
        ],
    ];
}

it('extracts and normalizes a task from a message via the AI service', function (): void {
    config(['services.openai.api_key' => 'test-key']);

    Http::fake([
        'api.openai.com/*' => Http::response(fakeOpenAiTaskResponse([
            'has_task' => true,
            'title' => 'Tile/Grout Repair',
            'type' => 'Task',
            'date' => '2026-06-30',
            'start_time' => '07:00',
            'end_time' => '08:00',
            'project_hint' => 'hall bath',
        ])),
    ]);

    $result = app(SmsTaskExtractionService::class)->extract(
        'Hi, would Tuesday work for the tile/grout repair in hall bath? You would be the 1st stop between 7-8am',
        Carbon::parse('2026-06-27 09:00:00'),
    );

    expect($result)->not->toBeNull()
        ->and($result['has_task'])->toBeTrue()
        ->and($result['title'])->toBe('Tile/Grout Repair')
        ->and($result['type'])->toBe('Task')
        ->and($result['date'])->toBe('2026-06-30')
        ->and($result['start_time'])->toBe('07:00')
        ->and($result['end_time'])->toBe('08:00')
        ->and($result['project_hint'])->toBe('hall bath');
});

it('returns null from the AI service when no api key is configured', function (): void {
    config(['services.openai.api_key' => null]);

    $result = app(SmsTaskExtractionService::class)->extract('Some message', Carbon::parse('2026-06-27'));

    expect($result)->toBeNull();
});

it('extracts a hive task from a message via the 3-dot action and prefills the task modal', function (): void {
    config(['services.openai.api_key' => 'test-key']);

    Http::fake([
        'api.openai.com/*' => Http::response(fakeOpenAiTaskResponse([
            'has_task' => true,
            'title' => 'Tile/Grout Repair',
            'type' => 'Task',
            'date' => '2026-06-30',
            'start_time' => '07:00',
            'end_time' => '08:00',
            'project_hint' => 'hall bath',
        ])),
    ]);

    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $user = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.sms-task@example.com',
        'cell_phone' => '2245550099',
        'primary_vendor_id' => $vendor->id,
    ]);
    $vendor->users()->attach($user->id, ['is_employed' => true, 'role_id' => 1]);

    $this->actingAs($user);

    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);

    $project = Project::query()->create([
        'project_name' => 'Bathrooms',
        'client_id' => $client->id,
        'belongs_to_vendor_id' => $vendor->id,
        'address' => '3154 Violet Ln',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => 60062,
    ]);

    $thread = SmsGroupThread::query()->create([
        'name' => 'Client Thread',
        'from_number' => '+12245554444',
        'participants' => ['+12245550001'],
        'vendor_id' => $vendor->id,
        'client_id' => $client->id,
        'last_activity_at' => now(),
    ]);

    $message = SmsMessage::query()->create([
        'thread_id' => $thread->id,
        'direction' => SmsMessage::DIRECTION_OUTBOUND,
        'from_number' => '+12245554444',
        'to_numbers' => ['+12245550001'],
        'text' => 'Hi, would Tuesday work for the tile/grout repair in hall bath? You would be the 1st stop between 7-8am',
        'sent_by_user_id' => $user->id,
        'created_at' => Carbon::parse('2026-06-27 09:00:00'),
    ]);

    Livewire::test(SmsConversation::class, ['threadId' => $thread->id])
        ->call('createTaskFromMessage', $message->id)
        ->assertDispatched('prefillTaskFromSms', function (string $event, array $params) use ($project, $client): bool {
            $payload = $params['payload'];

            return $payload['title'] === 'Tile/Grout Repair'
                && $payload['type'] === 'Task'
                && (int) $payload['project_id'] === $project->id
                && (int) $payload['client_id'] === $client->id
                && $payload['date'] === '2026-06-30'
                && $payload['start_time'] === '07:00'
                && $payload['end_time'] === '08:00';
        });
});

it('warns and does not dispatch when the message has no schedulable task', function (): void {
    config(['services.openai.api_key' => 'test-key']);

    Http::fake([
        'api.openai.com/*' => Http::response(fakeOpenAiTaskResponse([
            'has_task' => false,
            'title' => '',
            'type' => 'Task',
            'date' => null,
            'start_time' => null,
            'end_time' => null,
            'project_hint' => null,
        ])),
    ]);

    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $user = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.sms-notask@example.com',
        'cell_phone' => '2245550098',
        'primary_vendor_id' => $vendor->id,
    ]);
    $vendor->users()->attach($user->id, ['is_employed' => true, 'role_id' => 1]);

    $this->actingAs($user);

    $client = Client::factory()->create();

    Project::query()->create([
        'project_name' => 'Bathrooms',
        'client_id' => $client->id,
        'belongs_to_vendor_id' => $vendor->id,
        'address' => '3154 Violet Ln',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => 60062,
    ]);

    $thread = SmsGroupThread::query()->create([
        'name' => 'Client Thread',
        'from_number' => '+12245554444',
        'participants' => ['+12245550001'],
        'vendor_id' => $vendor->id,
        'client_id' => $client->id,
        'last_activity_at' => now(),
    ]);

    $message = SmsMessage::query()->create([
        'thread_id' => $thread->id,
        'direction' => SmsMessage::DIRECTION_INBOUND,
        'from_number' => '+12245550001',
        'to_numbers' => ['+12245554444'],
        'text' => 'Thanks, talk soon!',
        'created_at' => Carbon::parse('2026-06-27 09:00:00'),
    ]);

    Livewire::test(SmsConversation::class, ['threadId' => $thread->id])
        ->call('createTaskFromMessage', $message->id)
        ->assertNotDispatched('prefillTaskFromSms');
});

it('creates the task and opens the full editor in edit mode from an sms extraction payload', function (): void {
    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $user = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.sms-prefill@example.com',
        'cell_phone' => '2245550097',
        'primary_vendor_id' => $vendor->id,
    ]);
    $vendor->users()->attach($user->id, ['is_employed' => true, 'role_id' => 1]);

    $this->actingAs($user);

    $client = Client::factory()->create();

    $project = Project::query()->create([
        'project_name' => 'Bathrooms',
        'client_id' => $client->id,
        'belongs_to_vendor_id' => $vendor->id,
        'address' => '3154 Violet Ln',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => 60062,
    ]);

    Livewire::test(TaskCreate::class)
        ->call('prefillTaskFromSms', [
            'title' => 'Tile/Grout Repair',
            'type' => 'Task',
            'project_id' => $project->id,
            'client_id' => $client->id,
            'vendor_id' => null,
            'date' => '2026-06-30',
            'start_time' => '07:00',
            'end_time' => '08:00',
            'user_ids' => [$user->id],
            'checklist' => [['text' => 'Adjust Ring cameras', 'completed' => false]],
        ])
        ->assertSet('form.title', 'Tile/Grout Repair')
        ->assertSet('form.type', 'Task')
        ->assertSet('form.project_id', $project->id)
        ->assertSet('view_text.form_submit', 'edit')
        ->assertSet('form.checklist', fn ($value) => json_decode(json_encode($value), true) === [['text' => 'Adjust Ring cameras', 'completed' => false]])
        ->assertSet('form.dates', ['2026-06-30'])
        ->assertSet('form.time_settings', [
            '2026-06-30' => [
                'use_time' => true,
                'start_time' => '07:00',
                'end_time' => '08:00',
            ],
        ]);

    $task = \App\Models\Task::query()->where('project_id', $project->id)->first();

    expect($task)->not->toBeNull()
        ->and($task->title)->toBe('Tile/Grout Repair')
        ->and($task->type)->toBe('Task')
        ->and($task->user_ids)->toBe([(string) $user->id])
        ->and($task->start_date->toDateString())->toBe('2026-06-30')
        ->and(json_decode(json_encode($task->options->checklist), true))
        ->toBe([['text' => 'Adjust Ring cameras', 'completed' => false]]);
});

it('extracts assignee names and checklist items via the AI service', function (): void {
    config(['services.openai.api_key' => 'test-key']);

    Http::fake([
        'api.openai.com/*' => Http::response(fakeOpenAiTaskResponse([
            'has_task' => true,
            'title' => 'Onsite Visit',
            'type' => 'Task',
            'date' => '2026-06-28',
            'start_time' => null,
            'end_time' => null,
            'project_hint' => null,
            'assignee_names' => ['Greg'],
            'checklist' => ['Adjust Ring cameras'],
        ])),
    ]);

    $result = app(SmsTaskExtractionService::class)->extract(
        "Greg is stopping by onsite tomorrow. (Let's adjust your ring cameras on Monday)",
        Carbon::parse('2026-06-27 09:00:00'),
    );

    expect($result)->not->toBeNull()
        ->and($result['assignee_names'])->toBe(['Greg'])
        ->and($result['checklist'])->toBe(['Adjust Ring cameras']);
});

it('assigns the named team member and adds a checklist item from the 3-dot action', function (): void {
    config(['services.openai.api_key' => 'test-key']);

    Http::fake([
        'api.openai.com/*' => Http::response(fakeOpenAiTaskResponse([
            'has_task' => true,
            'title' => 'Onsite Visit',
            'type' => 'Task',
            'date' => '2026-06-28',
            'start_time' => null,
            'end_time' => null,
            'project_hint' => null,
            'assignee_names' => ['Greg'],
            'checklist' => ['Adjust Ring cameras'],
        ])),
    ]);

    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $user = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.sms-greg@example.com',
        'cell_phone' => '2245550096',
        'primary_vendor_id' => $vendor->id,
    ]);
    $vendor->users()->attach($user->id, ['is_employed' => true, 'role_id' => 1]);

    $greg = User::query()->create([
        'first_name' => 'Grzegorz',
        'last_name' => 'Szady',
        'nickname' => 'Greg',
        'email' => 'greg.sms-greg@example.com',
        'cell_phone' => '2245550095',
        'primary_vendor_id' => $vendor->id,
    ]);
    $vendor->users()->attach($greg->id, ['is_employed' => true, 'role_id' => 1]);

    $this->actingAs($user);

    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);

    $project = Project::query()->create([
        'project_name' => 'Smart Home',
        'client_id' => $client->id,
        'belongs_to_vendor_id' => $vendor->id,
        'address' => '3154 Violet Ln',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => 60062,
    ]);

    $thread = SmsGroupThread::query()->create([
        'name' => 'Client Thread',
        'from_number' => '+12245554444',
        'participants' => ['+12245550001'],
        'vendor_id' => $vendor->id,
        'client_id' => $client->id,
        'last_activity_at' => now(),
    ]);

    $message = SmsMessage::query()->create([
        'thread_id' => $thread->id,
        'direction' => SmsMessage::DIRECTION_OUTBOUND,
        'from_number' => '+12245554444',
        'to_numbers' => ['+12245550001'],
        'text' => "Greg is stopping by onsite tomorrow. (Let's adjust your ring cameras on Monday)",
        'sent_by_user_id' => $user->id,
        'created_at' => Carbon::parse('2026-06-27 09:00:00'),
    ]);

    Livewire::test(SmsConversation::class, ['threadId' => $thread->id])
        ->call('createTaskFromMessage', $message->id)
        ->assertDispatched('prefillTaskFromSms', function (string $event, array $params) use ($greg): bool {
            $payload = $params['payload'];

            return $payload['user_ids'] === [$greg->id]
                && $payload['checklist'] === [['text' => 'Adjust Ring cameras', 'completed' => false]];
        });
});

it('anchors the AI sent date on the vendor timezone, not UTC', function (): void {
    config(['services.openai.api_key' => 'test-key']);

    $vendor = Vendor::factory()->create([
        'business_name' => 'GS Construction',
        'timezone' => 'America/Chicago',
    ]);

    $user = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.sms-tz@example.com',
        'cell_phone' => '2245550094',
        'primary_vendor_id' => $vendor->id,
    ]);
    $vendor->users()->attach($user->id, ['is_employed' => true, 'role_id' => 1]);

    $this->actingAs($user);

    $thread = SmsGroupThread::query()->create([
        'name' => 'Client Thread',
        'from_number' => '+12245554444',
        'participants' => ['+12245550001'],
        'vendor_id' => $vendor->id,
        'last_activity_at' => now(),
    ]);

    // Stored UTC time of 2026-06-27 01:10 is 2026-06-26 8:10 PM Central — so the
    // "sent date" the AI anchors on must be 2026-06-26, not 2026-06-27.
    $message = SmsMessage::query()->create([
        'thread_id' => $thread->id,
        'direction' => SmsMessage::DIRECTION_OUTBOUND,
        'from_number' => '+12245554444',
        'to_numbers' => ['+12245550001'],
        'text' => 'Greg is stopping by onsite tomorrow.',
        'sent_by_user_id' => $user->id,
    ]);
    $message->forceFill(['created_at' => Carbon::parse('2026-06-27 01:10:00', 'UTC')])->saveQuietly();

    $capturedSentAt = null;

    $mock = Mockery::mock(SmsTaskExtractionService::class);
    $mock->shouldReceive('extract')
        ->once()
        ->andReturnUsing(function (string $text, \Carbon\CarbonInterface $sentAt) use (&$capturedSentAt) {
            $capturedSentAt = $sentAt;

            return null;
        });
    app()->instance(SmsTaskExtractionService::class, $mock);

    Livewire::test(SmsConversation::class, ['threadId' => $thread->id])
        ->call('createTaskFromMessage', $message->id);

    expect($capturedSentAt)->not->toBeNull()
        ->and($capturedSentAt->toDateString())->toBe('2026-06-26');
});

it('hides the create-task action for schedule blasts and short messages', function (string $text, bool $allowed): void {
    $message = new SmsMessage(['text' => $text]);

    expect((new SmsConversation)->messageAllowsTaskCreation($message))->toBe($allowed);
})->with([
    'schedule blast' => ["Hi Carri, Debra & Alan,\nUpcoming tasks:\n\nNext up Tue 6/30:\n- Demo @ 8AM\n\nView Schedule: https://hive.contractors/s/20b1bbbb8c56cba4", false],
    'schedule link only' => ['Check it out https://hive.contractors/s/20b1bbbb8c56cba4', false],
    'one word' => ['Thanks', false],
    'two words' => ['Sounds good', false],
    'three words' => ['See you soon', false],
    'empty' => ['', false],
    'four words' => ['Can we meet tomorrow', true],
    'real task message' => ['Would Tuesday work for the tile repair in hall bath at 7am?', true],
]);


