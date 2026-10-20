<?php

use App\Livewire\Sms\SendScheduleModal;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\SmsThreadParticipant;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ConsultScheduleLinkTexter;
use App\Services\GroupSmsService;
use App\Services\SmsTranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// =========================================================================
// The web conversation's composer tools in the app (2026-10-17): Send
// schedule, Schedule send, and Draft consult scheduling text.
// =========================================================================

beforeEach(function () {
    config(['scout.driver' => null, 'services.telnyx.api_key' => 'test-key', 'services.telnyx.from' => '+12247354200']);
    Http::fake(['api.telnyx.com/*' => Http::response(['data' => ['id' => 'msg-1', 'to' => [['status' => 'queued']]]], 200)]);

    $this->company = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $this->member = User::factory()->create(['first_name' => 'Patryk', 'last_name' => 'Szady', 'nickname' => null, 'cell_phone' => '2249993880', 'primary_vendor_id' => 1]);
    Sanctum::actingAs($this->member, ['app']);

    $this->thread = SmsGroupThread::query()->create(['from_number' => '+12247354200', 'participants' => ['+12245550001'], 'vendor_id' => 1, 'last_activity_at' => now()]);
    SmsThreadParticipant::query()->create(['thread_id' => $this->thread->id, 'phone_number' => '+12245550001', 'opted_in_at' => now()]);
});

it('schedules a message for later with the web composer\'s presets, and shows the sender by first name', function () {
    $this->travelTo(\Carbon\Carbon::parse('2026-10-17 10:00:00', 'America/Chicago'));

    $response = $this->postJson("/api/app/v1/threads/{$this->thread->id}/messages", ['body' => 'See you tomorrow', 'send_at' => 'tomorrow_8am'])->assertCreated();

    $message = SmsMessage::find($response->json('data.id'));
    expect($message->scheduled_at->timezone('America/Chicago')->format('Y-m-d H:i'))->toBe('2026-10-18 08:00')
        ->and($response->json('data.scheduled_at'))->not->toBeNull()
        ->and($response->json('data.sender_name'))->toBe('Patryk');

    $this->postJson("/api/app/v1/threads/{$this->thread->id}/messages", ['body' => 'x', 'send_at' => 'someday'])->assertJsonValidationErrors('send_at');
});

it('drafts and sends the schedule the web dialog would, through the dialog\'s own code', function () {
    app()->bind(SendScheduleModal::class, fn () => new class extends SendScheduleModal
    {
        public function open(int $threadId): void
        {
            $this->editableMessage = "Hi Erin,\nYour tile repair is Monday 9 AM.";
        }

        public function deliver(GroupSmsService $smsService, SmsTranslationService $translator): array
        {
            return $this->editableMessage === 'Edited schedule' && $this->scheduleWithoutDate === true
                ? ['ok' => true, 'variant' => 'success', 'heading' => 'Scheduled', 'text' => 'Message scheduled without date. Use send now to send it.']
                : ['ok' => false, 'variant' => 'warning', 'heading' => 'Unexpected', 'text' => 'Wrong text or mode'];
        }
    });

    $this->getJson("/api/app/v1/threads/{$this->thread->id}/schedule")->assertOk()->assertJson(['message' => "Hi Erin,\nYour tile repair is Monday 9 AM."]);
    $this->postJson("/api/app/v1/threads/{$this->thread->id}/schedule", ['message' => 'Edited schedule', 'without_date' => true])
        ->assertCreated()->assertJson(['heading' => 'Scheduled']);
});

it('drafts the consult scheduling text without sending it', function () {
    $texter = Mockery::mock(ConsultScheduleLinkTexter::class);
    $texter->shouldReceive('composeForThread')->once()->andReturn(['ok' => true, 'variant' => 'success', 'heading' => 'Drafted', 'text' => 'Review and send.', 'message' => 'Pick a consult time: https://hive.test/l/abc', 'lead_id' => null]);
    app()->instance(ConsultScheduleLinkTexter::class, $texter);

    $this->postJson("/api/app/v1/threads/{$this->thread->id}/consult-draft")
        ->assertOk()
        ->assertJson(['ok' => true, 'draft' => 'Pick a consult time: https://hive.test/l/abc']);

    expect(SmsMessage::query()->where('thread_id', $this->thread->id)->count())->toBe(0);
});

it('returns the "Confirm Tasks" task list from the schedule preview, shaped like the Home tab\'s own', function () {
    $this->travelTo(\Carbon\Carbon::parse('2026-10-17 10:00:00', 'America/Chicago'));

    $client = \App\Models\Client::factory()->create();
    $project = \App\Models\Project::query()->create([
        'project_name' => 'Tile Repair',
        'client_id' => $client->id,
        'address' => '100 Main St',
        'city' => 'Palatine',
        'state' => 'IL',
        'zip_code' => 60067,
        'belongs_to_vendor_id' => 1,
    ]);

    \App\Models\Task::query()->create([
        'title' => 'Demo tile',
        'project_id' => $project->id,
        'type' => 'Task',
        'start_date' => '2026-10-17',
        'end_date' => '2026-10-17',
    ]);
    \App\Models\Task::query()->create([
        'title' => 'Order grout',
        'project_id' => $project->id,
        'type' => 'Task',
        'start_date' => null,
        'end_date' => null,
    ]);

    $thread = SmsGroupThread::query()->create([
        'from_number' => '+12247354200',
        'participants' => ['+12245550002'],
        'vendor_id' => 1,
        'project_id' => $project->id,
        'last_activity_at' => now(),
    ]);

    $response = $this->getJson("/api/app/v1/threads/{$thread->id}/schedule")->assertOk();

    expect($response->json('projects.0.name'))->toBe('Tile Repair')
        ->and($response->json('tasks.days.0.groups.0.project.name'))->toBe('Tile Repair')
        ->and(collect($response->json('tasks.days'))->pluck('groups.0.tasks.*.title')->flatten())->toContain('Demo tile')
        ->and($response->json('tasks.pending.0.tasks.0.title'))->toBe('Order grout');
});

it('keeps another company\'s thread out of the tools', function () {
    $theirs = SmsGroupThread::query()->create(['from_number' => '+12247354200', 'participants' => ['+12245550009'], 'vendor_id' => Vendor::factory()->create()->id, 'last_activity_at' => now()]);

    $this->getJson("/api/app/v1/threads/{$theirs->id}/schedule")->assertForbidden();
    $this->postJson("/api/app/v1/threads/{$theirs->id}/consult-draft")->assertForbidden();
});
