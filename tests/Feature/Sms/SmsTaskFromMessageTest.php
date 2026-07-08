<?php

use App\Livewire\Sms\SmsConversation;
use App\Livewire\Tasks\TaskCreate;
use App\Models\Client;
use App\Models\Project;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\Task;
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

it('builds checklist items from multi-issue message text and carries sms image urls', function (): void {
    config(['services.openai.api_key' => 'test-key']);

    Http::fake([
        'api.openai.com/*' => Http::response(fakeOpenAiTaskResponse([
            'has_task' => true,
            'title' => 'Trim Repair',
            'type' => 'Task',
            'date' => null,
            'start_time' => null,
            'end_time' => null,
            'project_hint' => 'kitchen',
            'assignee_names' => [],
            'checklist' => [],
            'additional_tasks' => [],
        ])),
    ]);

    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $user = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.sms-checklist-images@example.com',
        'cell_phone' => '2245550399',
        'primary_vendor_id' => $vendor->id,
    ]);
    $vendor->users()->attach($user->id, ['is_employed' => true, 'role_id' => 1]);

    $this->actingAs($user);

    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);

    $project = Project::query()->create([
        'project_name' => 'Kitchen Service',
        'client_id' => $client->id,
        'belongs_to_vendor_id' => $vendor->id,
        'address' => '123 Main St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => 60601,
    ]);

    $thread = SmsGroupThread::query()->create([
        'name' => 'Client Thread',
        'from_number' => '+12245554444',
        'participants' => ['+12245550001'],
        'vendor_id' => $vendor->id,
        'client_id' => $client->id,
        'project_id' => $project->id,
        'last_activity_at' => now(),
    ]);

    $message = SmsMessage::query()->create([
        'thread_id' => $thread->id,
        'direction' => SmsMessage::DIRECTION_INBOUND,
        'from_number' => '+12245550001',
        'to_numbers' => ['+12245554444'],
        'text' => 'Trim from hidden pantry fell off. Also as I indicated the trim by microwave is rubbing against microwave when opening',
        'media_urls' => ['sms-media/example-one.jpg', 'sms-media/example-two.jpg'],
        'created_at' => Carbon::parse('2026-07-01 09:00:00'),
    ]);

    Livewire::test(SmsConversation::class, ['threadId' => $thread->id])
        ->call('createTaskFromMessage', $message->id)
        ->assertDispatched('prefillTaskFromSms', function (string $event, array $params) use ($project, $client): bool {
            $payload = $params['payload'];

            return $payload['title'] === 'Trim Repair'
                && (int) $payload['project_id'] === $project->id
                && (int) $payload['client_id'] === $client->id
                && is_array($payload['checklist'])
                && count($payload['checklist']) === 2
                && str_contains(strtolower((string) ($payload['checklist'][0]['text'] ?? '')), 'trim from hidden pantry fell off')
                && str_contains(strtolower((string) ($payload['checklist'][1]['text'] ?? '')), 'trim by microwave is rubbing')
                && is_array($payload['sms_media_urls'])
                && count($payload['sms_media_urls']) === 2
                && str_contains((string) $payload['sms_media_urls'][0], '/files/sms_media/')
                && str_contains((string) $payload['sms_media_urls'][1], '/files/sms_media/');
        });
});

it('uses previous confirm-tasks message context for time-only replies', function (): void {
    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $user = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.sms-reply-context@example.com',
        'cell_phone' => '2245550199',
        'primary_vendor_id' => $vendor->id,
    ]);
    $vendor->users()->attach($user->id, ['is_employed' => true, 'role_id' => 1]);

    $this->actingAs($user);

    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);

    $project = Project::query()->create([
        'project_name' => 'Wallpaper Project',
        'client_id' => $client->id,
        'belongs_to_vendor_id' => $vendor->id,
        'address' => '2932 N 77th Ct',
        'city' => 'Elmwood Park',
        'state' => 'IL',
        'zip_code' => 60707,
    ]);

    $task = Task::query()->create([
        'title' => 'Wallpaper Repair',
        'project_id' => $project->id,
        'vendor_id' => $vendor->id,
        'type' => 'Task',
        'start_date' => Carbon::parse('2026-06-29')->toDateString(),
        'end_date' => Carbon::parse('2026-06-29')->toDateString(),
    ]);

    $thread = SmsGroupThread::query()->create([
        'name' => 'TBD Pro Painting',
        'from_number' => '+12245554444',
        'participants' => ['+12245550001'],
        'vendor_id' => $vendor->id,
        'client_id' => $client->id,
        'last_activity_at' => now(),
    ]);

    SmsMessage::query()->create([
        'thread_id' => $thread->id,
        'direction' => SmsMessage::DIRECTION_OUTBOUND,
        'from_number' => '+12245554444',
        'to_numbers' => ['+12245550001'],
        'text' => "Hi TBD Pro Painting,\nConfirm Tasks:\n\nTomorrow Mon 6/29:\n- Wallpaper Repair\n2932 N 77th Ct\nElmwood Park, IL 60707\n\nConfirm Schedule: https://hive.contractors/v/1b4d9d18b10d7037",
        'raw_payload' => [
            'source' => 'send_schedule_modal',
            'scheduled_task_ids' => [$task->id],
        ],
        'sent_by_user_id' => $user->id,
        'created_at' => Carbon::parse('2026-06-28 17:05:00'),
    ]);

    $reply = SmsMessage::query()->create([
        'thread_id' => $thread->id,
        'direction' => SmsMessage::DIRECTION_INBOUND,
        'from_number' => '+12245550001',
        'to_numbers' => ['+12245554444'],
        'text' => 'Hi, tak będzie między 1-2 pm',
        'created_at' => Carbon::parse('2026-06-28 17:18:00'),
    ]);

    $mock = Mockery::mock(SmsTaskExtractionService::class);
    $mock->shouldReceive('extract')
        ->once()
        ->andReturn(null);
    app()->instance(SmsTaskExtractionService::class, $mock);

    Livewire::test(SmsConversation::class, ['threadId' => $thread->id])
        ->call('createTaskFromMessage', $reply->id)
        ->assertDispatched('prefillTaskFromSms', function (string $event, array $params) use ($project, $client, $task): bool {
            $payload = $params['payload'];

            return $payload['title'] === 'Wallpaper Repair'
                && $payload['type'] === 'Task'
                && (int) $payload['task_id'] === $task->id
                && (int) $payload['project_id'] === $project->id
                && (int) $payload['client_id'] === $client->id
                && $payload['date'] === '2026-06-29'
                && $payload['start_time'] === '13:00'
                && $payload['end_time'] === '14:00';
        });
});

it('splits a multi-time message into separate tasks with their own times', function (): void {
    config(['services.openai.api_key' => 'test-key']);

    Http::fake([
        'api.openai.com/*' => Http::response(fakeOpenAiTaskResponse([
            'has_task' => true,
            'title' => 'Materials Delivery',
            'type' => 'Task',
            'date' => '2026-06-29',
            'start_time' => '09:00',
            'end_time' => null,
            'project_hint' => 'hall bath',
            'additional_tasks' => [[
                'title' => 'Drywall Install',
                'type' => 'Task',
                'date' => '2026-06-29',
                'start_time' => '10:00',
                'end_time' => null,
                'project_hint' => 'hall bath',
                'assignee_names' => [],
            ]],
        ])),
    ]);

    $result = app(SmsTaskExtractionService::class)->extract(
        'Hi Zora, we will be there around 9am with materials, drywall guys will be there around 10am. Thank you',
        Carbon::parse('2026-06-28 17:10:00'),
    );

    expect($result)->not->toBeNull()
        ->and($result['has_task'])->toBeTrue()
        ->and($result['title'])->toBe('Materials Delivery')
        ->and($result['start_time'])->toBe('09:00')
        ->and($result['additional_tasks'])->toHaveCount(1)
        ->and($result['additional_tasks'][0]['title'])->toBe('Drywall Install')
        ->and($result['additional_tasks'][0]['start_time'])->toBe('10:00');
});

it('extracts a punch list of undated requests into multiple tasks', function (): void {
    config(['services.openai.api_key' => 'test-key']);

    Http::fake([
        'api.openai.com/*' => Http::response(fakeOpenAiTaskResponse([
            'has_task' => true,
            'title' => 'Grout Repair',
            'type' => 'Task',
            'date' => null,
            'start_time' => null,
            'end_time' => null,
            'project_hint' => 'counter/backsplash',
            'notes' => 'grout repair at counter/backsplash interface',
            'additional_tasks' => [
                [
                    'title' => 'Repair Pantry Outlet (Open Ground)',
                    'type' => 'Task',
                    'date' => null,
                    'start_time' => null,
                    'end_time' => null,
                    'project_hint' => 'pantry',
                    'notes' => 'outlet in pantry still shows open ground (initially flagged by the inspector)',
                    'assignee_names' => [],
                ],
                [
                    'title' => 'Discuss Cooktop Issue',
                    'type' => 'Meet',
                    'date' => null,
                    'start_time' => null,
                    'end_time' => null,
                    'project_hint' => 'kitchen',
                    'notes' => 'to discuss your thoughts on the cooktop',
                    'assignee_names' => [],
                ],
            ],
        ])),
    ]);

    $message = "Hi Patryk and Greg. Hope all is well. Please add me to your schedule for the following:\n"
        . "1) grout repair at counter/backsplash interface\n"
        . "2) outlet in pantry still shows open ground (initially flagged by the inspector)\n"
        . "3) to discuss your thoughts on the cooktop which goes off and relights when drawers are opened.\n"
        . 'Thanks. Tom';

    $result = app(SmsTaskExtractionService::class)->extract(
        $message,
        Carbon::parse('2026-06-28 09:00:00'),
    );

    expect($result)->not->toBeNull()
        ->and($result['has_task'])->toBeTrue()
        ->and($result['title'])->toBe('Grout Repair')
        ->and($result['date'])->toBeNull()
        ->and($result['notes'])->toBe('grout repair at counter/backsplash interface')
        ->and($result['additional_tasks'])->toHaveCount(2)
        ->and($result['additional_tasks'][0]['title'])->toBe('Repair Pantry Outlet (Open Ground)')
        ->and($result['additional_tasks'][0]['notes'])->toBe('outlet in pantry still shows open ground (initially flagged by the inspector)')
        ->and($result['additional_tasks'][1]['title'])->toBe('Discuss Cooktop Issue')
        ->and($result['additional_tasks'][1]['type'])->toBe('Meet');
});

it('queues undated punch-list items as separate tasks in the modal', function (): void {
    config(['services.openai.api_key' => 'test-key']);

    Http::fake([
        'api.openai.com/*' => Http::response(fakeOpenAiTaskResponse([
            'has_task' => true,
            'title' => 'Grout Repair',
            'type' => 'Task',
            'date' => null,
            'start_time' => null,
            'end_time' => null,
            'project_hint' => 'counter/backsplash',
            'notes' => 'grout repair at counter/backsplash interface',
            'additional_tasks' => [
                [
                    'title' => 'Repair Pantry Outlet (Open Ground)',
                    'type' => 'Task',
                    'date' => null,
                    'start_time' => null,
                    'end_time' => null,
                    'project_hint' => 'pantry',
                    'notes' => 'outlet in pantry still shows open ground',
                    'assignee_names' => [],
                ],
                [
                    'title' => 'Discuss Cooktop Issue',
                    'type' => 'Meet',
                    'date' => null,
                    'start_time' => null,
                    'end_time' => null,
                    'project_hint' => 'kitchen',
                    'notes' => 'to discuss your thoughts on the cooktop',
                    'assignee_names' => [],
                ],
            ],
        ])),
    ]);

    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $user = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.sms-punchlist@example.test',
        'cell_phone' => '2245550190',
        'primary_vendor_id' => $vendor->id,
    ]);
    $vendor->users()->attach($user->id, ['is_employed' => true, 'role_id' => 1]);
    $this->actingAs($user);

    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);

    $project = Project::query()->create([
        'project_name' => 'Kitchen Remodel',
        'client_id' => $client->id,
        'belongs_to_vendor_id' => $vendor->id,
        'address' => '100 Main St',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => '60013',
    ]);
    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);

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
        'text' => "Please add me to your schedule for the following:\n1) grout repair\n2) pantry outlet\n3) to discuss cooktop",
        'created_at' => Carbon::parse('2026-06-28 09:00:00'),
    ]);

    Livewire::test(SmsConversation::class, ['threadId' => $thread->id])
        ->call('createTaskFromMessage', $message->id)
        ->assertDispatched('prefillTaskFromSms', function (string $event, array $params) use ($project): bool {
            $payload = $params['payload'] ?? $params[0] ?? $params;

            return $payload['title'] === 'Grout Repair'
                && (int) $payload['project_id'] === $project->id
                && $payload['notes'] === 'grout repair at counter/backsplash interface'
                && count($payload['additional_tasks']) === 2
                && $payload['additional_tasks'][0]['title'] === 'Repair Pantry Outlet (Open Ground)'
                && $payload['additional_tasks'][0]['notes'] === 'outlet in pantry still shows open ground'
                && $payload['additional_tasks'][1]['title'] === 'Discuss Cooktop Issue';
        });
});

it('keeps a short title, stores the message as notes, and shortens the checklist', function (): void {
    config(['services.openai.api_key' => 'test-key']);

    Http::fake([
        'api.openai.com/*' => Http::response(fakeOpenAiTaskResponse([
            'has_task' => true,
            'title' => 'Cabinet Trim',
            'type' => 'Task',
            'date' => null,
            'start_time' => null,
            'end_time' => null,
            'project_hint' => 'kitchen',
            'assignee_names' => [],
            'checklist' => ['Adjust cabinet trim'],
            'additional_tasks' => [],
        ])),
    ]);

    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $user = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.sms-cabinet-trim@example.test',
        'cell_phone' => '2245550191',
        'primary_vendor_id' => $vendor->id,
    ]);
    $vendor->users()->attach($user->id, ['is_employed' => true, 'role_id' => 1]);
    $this->actingAs($user);

    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);

    $project = Project::query()->create([
        'project_name' => 'Kitchen Remodel',
        'client_id' => $client->id,
        'belongs_to_vendor_id' => $vendor->id,
        'address' => '100 Main St',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => '60013',
    ]);
    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);

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
        'text' => 'The new trim put on our cabinet is impacting the microwave drawer',
        'created_at' => Carbon::parse('2026-06-28 09:00:00'),
    ]);

    Livewire::test(SmsConversation::class, ['threadId' => $thread->id])
        ->call('createTaskFromMessage', $message->id)
        ->assertDispatched('prefillTaskFromSms', function (string $event, array $params): bool {
            $payload = $params['payload'] ?? $params[0] ?? $params;

            return $payload['title'] === 'Cabinet Trim'
                && str_word_count($payload['title']) <= 4
                && $payload['notes'] === 'The new trim put on our cabinet is impacting the microwave drawer'
                && count($payload['checklist']) === 1
                && $payload['checklist'][0]['text'] === 'Adjust cabinet trim';
        });
});

it('does not synthesize a checklist from the message when it is split into multiple tasks', function (): void {
    config(['services.openai.api_key' => 'test-key']);

    Http::fake([
        'api.openai.com/*' => Http::response(fakeOpenAiTaskResponse([
            'has_task' => true,
            'title' => 'Grout Repair',
            'type' => 'Task',
            'date' => null,
            'start_time' => null,
            'end_time' => null,
            'project_hint' => 'counter/backsplash',
            'assignee_names' => [],
            'checklist' => [],
            'notes' => 'grout repair at counter/backsplash interface',
            'additional_tasks' => [
                [
                    'title' => 'Repair Pantry Outlet (Open Ground)',
                    'type' => 'Task',
                    'date' => null,
                    'start_time' => null,
                    'end_time' => null,
                    'project_hint' => 'pantry',
                    'notes' => 'outlet in pantry still shows open ground',
                    'assignee_names' => [],
                ],
            ],
        ])),
    ]);

    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $user = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.sms-punchlist-checklist@example.test',
        'cell_phone' => '2245550193',
        'primary_vendor_id' => $vendor->id,
    ]);
    $vendor->users()->attach($user->id, ['is_employed' => true, 'role_id' => 1]);
    $this->actingAs($user);

    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);

    $project = Project::query()->create([
        'project_name' => 'Kitchen Remodel',
        'client_id' => $client->id,
        'belongs_to_vendor_id' => $vendor->id,
        'address' => '100 Main St',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => '60013',
    ]);
    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);

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
        'text' => "Hi Patryk and Greg. Hope all is well. Please add me to your schedule for the following:\n1) grout repair\n2) outlet in pantry still shows open ground",
        'created_at' => Carbon::parse('2026-06-28 09:00:00'),
    ]);

    Livewire::test(SmsConversation::class, ['threadId' => $thread->id])
        ->call('createTaskFromMessage', $message->id)
        ->assertDispatched('prefillTaskFromSms', function (string $event, array $params): bool {
            $payload = $params['payload'] ?? $params[0] ?? $params;

            return $payload['title'] === 'Grout Repair'
                && $payload['checklist'] === []
                && $payload['notes'] === 'grout repair at counter/backsplash interface';
        });
});

it('rolls a single-time late-day schedule message to tomorrow', function (): void {
    config(['services.openai.api_key' => 'test-key']);

    Http::fake([
        'api.openai.com/*' => Http::response(fakeOpenAiTaskResponse([
            'has_task' => true,
            'title' => 'Materials Delivery',
            'type' => 'Task',
            'date' => '2026-06-28',
            'start_time' => '09:00',
            'end_time' => null,
            'project_hint' => 'hall bath',
        ])),
    ]);

    $result = app(SmsTaskExtractionService::class)->extract(
        'Hi Zora, we will be there around 9am with materials. Thank you',
        Carbon::parse('2026-06-28 17:10:00'),
    );

    expect($result)->not->toBeNull()
        ->and($result['date'])->toBe('2026-06-29')
        ->and($result['start_time'])->toBe('09:00');
});

it('matches existing task for time-only replies when prior confirm message has no metadata', function (): void {
    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $user = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.sms-reply-no-metadata@example.com',
        'cell_phone' => '2245550299',
        'primary_vendor_id' => $vendor->id,
    ]);
    $vendor->users()->attach($user->id, ['is_employed' => true, 'role_id' => 1]);

    $this->actingAs($user);

    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);

    $project = Project::query()->create([
        'project_name' => 'Wallpaper Project',
        'client_id' => $client->id,
        'belongs_to_vendor_id' => $vendor->id,
        'address' => '2932 N 77th Ct',
        'city' => 'Elmwood Park',
        'state' => 'IL',
        'zip_code' => 60707,
    ]);

    $task = Task::query()->create([
        'title' => 'Wallpaper Repair',
        'project_id' => $project->id,
        'vendor_id' => $vendor->id,
        'type' => 'Task',
        'start_date' => Carbon::parse('2026-06-29')->toDateString(),
        'end_date' => Carbon::parse('2026-06-29')->toDateString(),
    ]);

    $thread = SmsGroupThread::query()->create([
        'name' => 'TBD Pro Painting',
        'from_number' => '+12245554444',
        'participants' => ['+12245550001'],
        'vendor_id' => $vendor->id,
        'client_id' => $client->id,
        'last_activity_at' => now(),
    ]);

    SmsMessage::query()->create([
        'thread_id' => $thread->id,
        'direction' => SmsMessage::DIRECTION_OUTBOUND,
        'from_number' => '+12245554444',
        'to_numbers' => ['+12245550001'],
        'text' => "Hi TBD Pro Painting,\nConfirm Tasks:\n\nTomorrow Mon 6/29:\n- Wallpaper Repair\n2932 N 77th Ct\nElmwood Park, IL 60707\n\nConfirm Schedule: https://hive.contractors/v/1b4d9d18b10d7037",
        'sent_by_user_id' => $user->id,
        'created_at' => Carbon::parse('2026-06-28 17:05:00'),
    ]);

    $reply = SmsMessage::query()->create([
        'thread_id' => $thread->id,
        'direction' => SmsMessage::DIRECTION_INBOUND,
        'from_number' => '+12245550001',
        'to_numbers' => ['+12245554444'],
        'text' => 'Hi, it will be between 1-2 pm.',
        'created_at' => Carbon::parse('2026-06-28 17:18:00'),
    ]);

    $mock = Mockery::mock(SmsTaskExtractionService::class);
    $mock->shouldReceive('extract')
        ->once()
        ->andReturn(null);
    app()->instance(SmsTaskExtractionService::class, $mock);

    Livewire::test(SmsConversation::class, ['threadId' => $thread->id])
        ->call('createTaskFromMessage', $reply->id)
        ->assertDispatched('prefillTaskFromSms', function (string $event, array $params) use ($project, $client, $task): bool {
            $payload = $params['payload'];

            return $payload['title'] === 'Wallpaper Repair'
                && $payload['type'] === 'Task'
                && (int) $payload['task_id'] === $task->id
                && (int) $payload['project_id'] === $project->id
                && (int) $payload['client_id'] === $client->id
                && $payload['date'] === '2026-06-29'
                && $payload['start_time'] === '13:00'
                && $payload['end_time'] === '14:00';
        });
});

it('prefills an existing task in edit mode when sms extraction includes task_id', function (): void {
    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $user = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.sms-prefill-existing@example.com',
        'cell_phone' => '2245550096',
        'primary_vendor_id' => $vendor->id,
    ]);
    $vendor->users()->attach($user->id, ['is_employed' => true, 'role_id' => 1]);

    $this->actingAs($user);

    $client = Client::factory()->create();

    $project = Project::query()->create([
        'project_name' => 'Wallpaper Project',
        'client_id' => $client->id,
        'belongs_to_vendor_id' => $vendor->id,
        'address' => '2932 N 77th Ct',
        'city' => 'Elmwood Park',
        'state' => 'IL',
        'zip_code' => 60707,
    ]);

    $existingTask = Task::query()->create([
        'title' => 'Wallpaper Repair',
        'project_id' => $project->id,
        'vendor_id' => $vendor->id,
        'type' => 'Task',
        'start_date' => Carbon::parse('2026-06-29')->toDateString(),
        'end_date' => Carbon::parse('2026-06-29')->toDateString(),
    ]);

    Livewire::test(TaskCreate::class)
        ->call('prefillTaskFromSms', [
            'task_id' => $existingTask->id,
            'title' => 'Wallpaper Repair',
            'type' => 'Task',
            'project_id' => $project->id,
            'client_id' => $client->id,
            'date' => '2026-06-29',
            'start_time' => '13:00',
            'end_time' => '14:00',
        ])
        ->assertSet('form.task_id', $existingTask->id)
        ->assertSet('form.time_settings.2026-06-29.use_time', true)
        ->assertSet('form.time_settings.2026-06-29.start_time', '13:00')
        ->assertSet('form.time_settings.2026-06-29.end_time', '14:00');
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

it('creates secondary tasks from a multi-task SMS prefill', function (): void {
    $vendor = Vendor::factory()->create();

    $user = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.sms-multitask@example.com',
        'cell_phone' => '2245550098',
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

    $component = Livewire::test(TaskCreate::class)
        ->call('prefillTaskFromSms', [
            'title' => 'Materials Delivery',
            'type' => 'Task',
            'project_id' => $project->id,
            'client_id' => $client->id,
            'vendor_id' => $vendor->id,
            'date' => '2026-06-29',
            'start_time' => '09:00',
            'end_time' => null,
            'user_ids' => [],
            'checklist' => [],
            'additional_tasks' => [[
                'title' => 'Drywall Install',
                'type' => 'Task',
                'date' => '2026-06-29',
                'start_time' => '10:00',
                'end_time' => null,
                'user_ids' => [],
            ]],
        ]);

    // The primary task is created and the second is queued, not yet persisted.
    expect(\App\Models\Task::query()->where('project_id', $project->id)->count())->toBe(1)
        ->and($component->get('pendingSmsTasks'))->toHaveCount(1)
        ->and($component->get('form.title'))->toBe('Materials Delivery');

    // Clicking "Update" on the first task opens the second one for review.
    $component->call('edit')
        ->assertSet('form.title', 'Drywall Install')
        ->assertSet('pendingSmsTasks', []);

    $tasks = \App\Models\Task::query()->where('project_id', $project->id)->get();

    expect($tasks)->toHaveCount(2);

    $drywall = $tasks->firstWhere('title', 'Drywall Install');

    expect($drywall)->not->toBeNull()
        ->and($drywall->start_date->toDateString())->toBe('2026-06-29')
        ->and(json_decode(json_encode($drywall->options->time_settings), true)['2026-06-29']['start_time'])->toBe('10:00');

    // Re-running the same message edits the existing tasks instead of duplicating.
    Livewire::test(TaskCreate::class)
        ->call('prefillTaskFromSms', [
            'title' => 'Materials Delivery',
            'type' => 'Task',
            'project_id' => $project->id,
            'client_id' => $client->id,
            'vendor_id' => $vendor->id,
            'date' => '2026-06-29',
            'start_time' => '09:00',
            'end_time' => null,
            'user_ids' => [],
            'checklist' => [],
            'additional_tasks' => [],
        ])
        ->call('edit');

    expect(\App\Models\Task::query()->where('project_id', $project->id)->count())->toBe(2);
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


