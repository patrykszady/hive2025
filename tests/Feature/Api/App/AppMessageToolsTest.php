<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\SmsThreadParticipant;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Parity round 2026-10-20 (part 2): editing a scheduled message, forwarding
 * messages to another thread, adding a message's photos to a project,
 * creating a task from a message (AI prefill), a finer per-person recipient
 * picker for a new thread, and the new-call contact/multi-admin-ring picker.
 * Every write goes through the exact same service the web conversation now
 * delegates to (App\Services\Sms\{ScheduledMessageActions,ThreadForwarder,
 * MessagePhotoProjectLinker,MessageTaskSuggestionBuilder},
 * App\Services\Calls\CallContactDirectory, App\Services\Sms\AppThreadStarter).
 */
function appCommsTeam(): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);

    $user = new User();
    $user->forceFill([
        'first_name' => 'Patryk',
        'last_name' => 'Crew',
        'email' => 'app-comms-'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
    ]);
    $user->save();
    $vendor->users()->attach($user->id, ['is_employed' => true, 'role_id' => 1]);

    return compact('vendor', 'user');
}

function appCommsThread(Vendor $vendor, array $overrides = []): SmsGroupThread
{
    $participants = $overrides['participants'] ?? ['+12245551001'];

    $thread = SmsGroupThread::query()->create(array_merge([
        'from_number' => '+12247354200',
        'participants' => $participants,
        'vendor_id' => $vendor->id,
        'last_activity_at' => now(),
    ], $overrides));

    foreach ($participants as $phone) {
        SmsThreadParticipant::query()->create([
            'thread_id' => $thread->id,
            'phone_number' => $phone,
            'opted_in_at' => now(),
        ]);
    }

    return $thread;
}

/**
 * @param  array<string, mixed>  $payload
 */
function fakeOpenAiTaskResponseFor(array $payload): array
{
    return [
        'choices' => [
            ['message' => ['content' => json_encode($payload)]],
        ],
    ];
}

beforeEach(function () {
    config(['scout.driver' => null]);
    Http::fake(['https://api.telnyx.com/*' => Http::response(['data' => ['id' => 'x']], 200)]);
});

// --- Edit a scheduled message's text ---------------------------------------

it('edits a scheduled message\'s text, keeping its signature line', function () {
    ['vendor' => $vendor, 'user' => $user] = appCommsTeam();
    Sanctum::actingAs($user, ['app']);
    $thread = appCommsThread($vendor);

    $scheduled = SmsMessage::create([
        'thread_id' => $thread->id,
        'direction' => 'outbound',
        'from_number' => '+12247354200',
        'text' => "See you tomorrow\n-GSC",
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay(),
        'raw_payload' => ['original_text' => 'See you tomorrow', 'sender_language' => 'English', 'recipient_language' => 'English'],
    ]);

    $this->putJson("/api/app/v1/threads/{$thread->id}/messages/{$scheduled->id}", ['body' => 'See you Friday instead'])
        ->assertOk()
        // body is display_text (MessageResource), which — like the web
        // bubbles — never shows the raw "-GSC" signature line inline.
        ->assertJsonPath('data.body', 'See you Friday instead');

    $scheduled->refresh();
    expect($scheduled->text)->toBe("See you Friday instead\n-GSC")
        ->and($scheduled->raw_payload['original_text'])->toBe('See you Friday instead')
        ->and($scheduled->status)->toBe('scheduled');

    // Only a *scheduled* message can be edited this way.
    $sent = SmsMessage::create(['thread_id' => $thread->id, 'direction' => 'outbound', 'from_number' => '+12247354200', 'text' => 'Already sent', 'status' => 'sent']);
    $this->putJson("/api/app/v1/threads/{$thread->id}/messages/{$sent->id}", ['body' => 'Too late'])->assertStatus(404);

    $this->putJson("/api/app/v1/threads/{$thread->id}/messages/{$scheduled->id}", ['body' => ''])->assertStatus(422);
});

// --- Forward messages --------------------------------------------------------

it('lists forward targets excluding the source thread, and forwards selected messages', function () {
    ['vendor' => $vendor, 'user' => $user] = appCommsTeam();
    Sanctum::actingAs($user, ['app']);

    $source = appCommsThread($vendor, ['participants' => ['+12245552101']]);
    $target = appCommsThread($vendor, ['participants' => ['+12245552102'], 'name' => 'Target Thread']);

    $msg1 = SmsMessage::create(['thread_id' => $source->id, 'direction' => 'inbound', 'from_number' => '+12245552101', 'text' => 'Here is a problem', 'status' => 'received']);
    $msg2 = SmsMessage::create(['thread_id' => $source->id, 'direction' => 'inbound', 'from_number' => '+12245552101', 'text' => 'And another one', 'status' => 'received']);

    $targetIds = collect($this->getJson("/api/app/v1/threads/{$source->id}/forward-targets")->assertOk()->json('data'))->pluck('id')->all();
    expect($targetIds)->toContain($target->id)->not->toContain($source->id);

    $this->postJson("/api/app/v1/threads/{$source->id}/forward", [
        'target_thread_id' => $target->id,
        'message_ids' => [$msg1->id, $msg2->id],
    ])->assertCreated()->assertJson(['forwarded' => 2]);

    $forwarded = SmsMessage::where('thread_id', $target->id)->latest('id')->first();
    expect($forwarded->text)->toContain('Forwarded')
        ->and($forwarded->text)->toContain('Here is a problem')
        ->and($forwarded->text)->toContain('And another one');

    // Can't forward into the same thread, or into one it can't access.
    $this->postJson("/api/app/v1/threads/{$source->id}/forward", ['target_thread_id' => $source->id, 'message_ids' => [$msg1->id]])->assertStatus(422);

    $otherVendor = Vendor::factory()->create();
    $foreignThread = appCommsThread($otherVendor, ['participants' => ['+12245559999']]);
    $this->postJson("/api/app/v1/threads/{$source->id}/forward", ['target_thread_id' => $foreignThread->id, 'message_ids' => [$msg1->id]])->assertForbidden();
});

it('refuses to forward into a thread with pending START opt-ins', function () {
    ['vendor' => $vendor, 'user' => $user] = appCommsTeam();
    Sanctum::actingAs($user, ['app']);

    $source = appCommsThread($vendor, ['participants' => ['+12245552201']]);
    $target = appCommsThread($vendor, ['participants' => ['+12245552202']]);
    SmsThreadParticipant::where('thread_id', $target->id)->update(['opted_in_at' => null]);

    $msg = SmsMessage::create(['thread_id' => $source->id, 'direction' => 'inbound', 'from_number' => '+12245552201', 'text' => 'Forward me', 'status' => 'received']);

    $this->postJson("/api/app/v1/threads/{$source->id}/forward", ['target_thread_id' => $target->id, 'message_ids' => [$msg->id]])
        ->assertStatus(422);
});

// --- Add a message's photos to a project ------------------------------------

it('lists candidate projects and pins a message\'s photos to one', function () {
    ['vendor' => $vendor, 'user' => $user] = appCommsTeam();
    Sanctum::actingAs($user, ['app']);

    $client = Client::factory()->create();
    $thread = appCommsThread($vendor, ['participants' => ['+12245553001'], 'client_id' => $client->id]);

    $project = Project::query()->create([
        'project_name' => 'Kitchen Remodel',
        'client_id' => $client->id,
        'belongs_to_vendor_id' => $vendor->id,
        'address' => '100 Main St',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => '60013',
    ]);

    $message = SmsMessage::create([
        'thread_id' => $thread->id,
        'direction' => 'inbound',
        'from_number' => '+12245553001',
        'text' => null,
        'media_urls' => ['sms-media/one.jpg', 'sms-media/two.jpg'],
        'status' => 'received',
    ]);

    $this->getJson("/api/app/v1/threads/{$thread->id}/messages/{$message->id}/project-candidates")
        ->assertOk()
        ->assertJsonFragment(['id' => $project->id]);

    $this->postJson("/api/app/v1/threads/{$thread->id}/messages/{$message->id}/add-to-project", ['project_id' => $project->id])
        ->assertOk()
        ->assertJson(['added' => 2]);

    expect(\Illuminate\Support\Facades\DB::table('project_pinned_messages')
        ->where('project_id', $project->id)
        ->where('sms_message_id', $message->id)
        ->exists())->toBeTrue();

    // A message with no photos is rejected.
    $textOnly = SmsMessage::create(['thread_id' => $thread->id, 'direction' => 'inbound', 'from_number' => '+12245553001', 'text' => 'just text', 'status' => 'received']);
    $this->postJson("/api/app/v1/threads/{$thread->id}/messages/{$textOnly->id}/add-to-project", ['project_id' => $project->id])->assertStatus(422);
});

// --- Create a task from a message (AI prefill) ------------------------------

it('returns the same AI task suggestion the web computes for a message', function () {
    config(['services.openai.api_key' => 'test-key']);
    Http::fake([
        'api.openai.com/*' => Http::response(fakeOpenAiTaskResponseFor([
            'has_task' => true,
            'title' => 'Tile/Grout Repair',
            'type' => 'Task',
            'date' => '2026-06-30',
            'start_time' => '07:00',
            'end_time' => '08:00',
            'project_hint' => 'hall bath',
        ])),
        'https://api.telnyx.com/*' => Http::response(['data' => ['id' => 'x']], 200),
    ]);

    ['vendor' => $vendor, 'user' => $user] = appCommsTeam();
    Sanctum::actingAs($user, ['app']);

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

    $thread = appCommsThread($vendor, ['participants' => ['+12245550001'], 'client_id' => $client->id]);

    $message = SmsMessage::create([
        'thread_id' => $thread->id,
        'direction' => 'outbound',
        'from_number' => '+12247354200',
        'text' => 'Hi, would Tuesday work for the tile/grout repair in hall bath? You would be the 1st stop between 7-8am',
        'sent_by_user_id' => $user->id,
        'status' => 'sent',
        'created_at' => \Carbon\Carbon::parse('2026-06-27 09:00:00'),
    ]);

    $response = $this->postJson("/api/app/v1/threads/{$thread->id}/messages/{$message->id}/task-suggestion")
        ->assertOk();

    expect($response->json('data.title'))->toBe('Tile/Grout Repair')
        ->and((int) $response->json('data.project_id'))->toBe($project->id)
        ->and((int) $response->json('data.client_id'))->toBe($client->id)
        ->and($response->json('data.date'))->toBe('2026-06-30')
        ->and($response->json('data.start_time'))->toBe('07:00');
});

it('tells the app why no task could be suggested, matching the web\'s toasts', function () {
    ['vendor' => $vendor, 'user' => $user] = appCommsTeam();
    Sanctum::actingAs($user, ['app']);
    $thread = appCommsThread($vendor);

    $blank = SmsMessage::create(['thread_id' => $thread->id, 'direction' => 'inbound', 'from_number' => '+12245551001', 'text' => '', 'status' => 'received']);
    $this->postJson("/api/app/v1/threads/{$thread->id}/messages/{$blank->id}/task-suggestion")
        ->assertStatus(422)
        ->assertJson(['reason' => 'no_text']);

    config(['services.openai.api_key' => 'test-key']);
    Http::fake([
        'api.openai.com/*' => Http::response(fakeOpenAiTaskResponseFor(['has_task' => false, 'title' => '', 'type' => 'Task', 'date' => null, 'start_time' => null, 'end_time' => null, 'project_hint' => null])),
        'https://api.telnyx.com/*' => Http::response(['data' => ['id' => 'x']], 200),
    ]);
    $chitchat = SmsMessage::create(['thread_id' => $thread->id, 'direction' => 'inbound', 'from_number' => '+12245551001', 'text' => 'Thanks, talk soon!', 'status' => 'received']);
    $this->postJson("/api/app/v1/threads/{$thread->id}/messages/{$chitchat->id}/task-suggestion")
        ->assertStatus(422)
        ->assertJson(['reason' => 'no_task']);
});

// --- Finer per-person recipient picker for a new thread ---------------------

it('offers the web\'s finer per-person recipient presets for a client, and lets the app pick a subset', function () {
    ['vendor' => $vendor, 'user' => $user] = appCommsTeam();
    Sanctum::actingAs($user, ['app']);

    $client = Client::factory()->create(['home_phone' => '2245559001']);
    $memberOne = User::factory()->create(['first_name' => 'Jordan', 'last_name' => 'Smith', 'cell_phone' => '2245559002']);
    $memberTwo = User::factory()->create(['first_name' => 'Casey', 'last_name' => 'Lee', 'cell_phone' => '2245559003']);
    $client->users()->attach([$memberOne->id, $memberTwo->id]);

    $response = $this->getJson("/api/app/v1/recipient-presets?client_id={$client->id}")->assertOk();
    $options = $response->json('data');

    expect($options)->not->toBeEmpty();
    $labels = collect($options)->pluck('label')->all();
    expect(collect($labels)->filter(fn ($label) => str_contains($label, 'Jordan')))->not->toBeEmpty();
    expect(collect($labels)->filter(fn ($label) => str_contains($label, 'Casey')))->not->toBeEmpty();
    // A combined "everyone" preset exists too, once there's more than one number.
    expect(collect($labels)->filter(fn ($label) => str_contains($label, 'Jordan') && str_contains($label, 'Casey')))->not->toBeEmpty();

    $jordanOnly = collect($options)->first(fn ($option) => count($option['recipients']) === 1 && str_contains($option['recipients'][0]['label'], 'Jordan'));
    expect($jordanOnly)->not->toBeNull();

    // Picking just Jordan's preset actually texts only Jordan, not the
    // household's other numbers (home phone + Casey's cell).
    $this->postJson('/api/app/v1/threads', [
        'client_id' => $client->id,
        'phones' => collect($jordanOnly['recipients'])->pluck('number')->all(),
        'body' => 'Hi Jordan',
    ])->assertCreated();

    $thread = SmsGroupThread::where('client_id', $client->id)->first();
    expect(count($thread->participants))->toBe(1)
        ->and($thread->participants)->toBe([$jordanOnly['recipients'][0]['number']]);
});

it('offers recipient presets for a vendor', function () {
    ['vendor' => $vendor, 'user' => $user] = appCommsTeam();
    Sanctum::actingAs($user, ['app']);

    $subVendor = Vendor::factory()->create(['business_name' => 'Acme Drywall', 'business_phone' => '2245558001']);
    $subUser = User::factory()->create(['first_name' => 'Terry', 'last_name' => 'Owens', 'cell_phone' => '2245558002']);
    $subVendor->users()->attach($subUser->id, ['is_employed' => true, 'role_id' => 2]);

    $options = $this->getJson("/api/app/v1/recipient-presets?vendor_id={$subVendor->id}")
        ->assertOk()
        ->json('data');

    expect(collect($options)->pluck('label')->filter(fn ($label) => str_contains($label, 'Terry')))->not->toBeEmpty();

    $this->getJson('/api/app/v1/recipient-presets')->assertStatus(422);
});

// --- New-call contact picker + multi-admin ring recipients ------------------

it('lists known contacts and this vendor\'s admin ring-along recipients', function () {
    ['vendor' => $vendor, 'user' => $user] = appCommsTeam();
    Sanctum::actingAs($user, ['app']);

    $teammate = User::factory()->create(['first_name' => 'Greg', 'cell_phone' => '2245557001']);
    $vendor->users()->attach($teammate->id, ['is_employed' => true, 'role_id' => 1]);

    $response = $this->getJson('/api/app/v1/calls/contacts')->assertOk();

    $contact = collect($response->json('contacts'))->firstWhere('id', $teammate->id);
    $recipient = collect($response->json('recipients'))->firstWhere('id', $teammate->id);

    expect($contact)->not->toBeNull()
        ->and($contact['name'])->toContain('Greg')
        ->and($contact['phone'])->not->toBeNull()
        // role_id 1 = Admin — Greg also shows up as a multi-admin ring recipient.
        ->and($recipient)->not->toBeNull();
});

it('places a call that also rings other admin team members', function () {
    ['vendor' => $vendor, 'user' => $user] = appCommsTeam();
    Sanctum::actingAs($user, ['app']);

    $admin = User::factory()->create(['first_name' => 'Greg', 'cell_phone' => '2245556001']);
    $vendor->users()->attach($admin->id, ['is_employed' => true, 'role_id' => 1]);

    $response = $this->postJson('/api/app/v1/calls', [
        'to' => '8475550100',
        'ring' => 'cell',
        'recipient_ids' => [$admin->id],
    ])->assertCreated();

    $callLog = \App\Models\CallLog::find($response->json('call_log_id'));
    expect($callLog->metadata['click_to_call_recipient_ids'] ?? [])->toContain($admin->id);
});

// --- "Schedule Only (manual send)" composer option (gap found re-checking app/Livewire/Sms/*) ---

it('saves a message as Schedule Only — a draft with no send time, sent later by hand', function () {
    ['vendor' => $vendor, 'user' => $user] = appCommsTeam();
    Sanctum::actingAs($user, ['app']);
    $thread = appCommsThread($vendor);

    $response = $this->postJson("/api/app/v1/threads/{$thread->id}/messages", [
        'body' => 'Draft for later',
        'send_at' => 'schedule_only',
    ])->assertCreated();

    expect($response->json('data.status'))->toBe('scheduled')
        ->and($response->json('data.scheduled_at'))->toBeNull();

    $message = SmsMessage::where('thread_id', $thread->id)->latest('id')->first();
    expect($message->status)->toBe('scheduled')
        ->and($message->scheduled_at)->toBeNull()
        ->and($message->raw_payload['schedule_only'] ?? null)->toBeTrue();

    // It sits there until sent by hand — sendNow() dispatches it like any
    // other scheduled message.
    $this->postJson("/api/app/v1/threads/{$thread->id}/messages/{$message->id}/send-now")
        ->assertOk()
        ->assertJsonPath('data.status', 'sent');
});
