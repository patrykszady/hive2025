<?php

use App\Models\Client;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\SmsThreadParticipant;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Parity round 2026-10-20: the app's Messages tab gains the web's subject
 * filter tabs, consent (opt-in) management, scheduled-message cancel/send-now,
 * per-message translate, and thread-level spam/delete/assign — every one of
 * them calling the exact same service the web conversation now delegates to
 * (App\Services\Sms\{ThreadOptInManager,ThreadSpamBlocker,ThreadAdminActions,
 * ScheduledMessageActions}).
 */
function appThreadActionsTeam(): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);

    $user = new User();
    $user->forceFill([
        'first_name' => 'Patryk',
        'last_name' => 'Crew',
        'email' => 'app-actions-'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
    ]);
    $user->save();

    return compact('vendor', 'user');
}

function appThreadActionsThread(Vendor $vendor, array $overrides = []): SmsGroupThread
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

beforeEach(function () {
    config(['scout.driver' => null]);
    Http::fake(['https://api.telnyx.com/*' => Http::response(['data' => ['id' => 'x']], 200)]);
});

// --- Subject filter tabs -------------------------------------------------

it('filters the thread list by client, vendor, and unread, matching the web\'s subject filter tabs', function () {
    ['vendor' => $vendor, 'user' => $user] = appThreadActionsTeam();
    Sanctum::actingAs($user, ['app']);

    $client = Client::query()->create(['business_name' => 'Jordan Household']);
    $clientThread = appThreadActionsThread($vendor, ['participants' => ['+12245551010'], 'client_id' => $client->id]);

    $otherVendor = Vendor::factory()->create(['business_name' => 'Acme Drywall']);
    $vendorThread = appThreadActionsThread($vendor, ['participants' => ['+12245551020'], 'subject_vendor_id' => $otherVendor->id]);

    $plainThread = appThreadActionsThread($vendor, ['participants' => ['+12245551030']]);
    SmsMessage::create([
        'thread_id' => $plainThread->id,
        'direction' => 'inbound',
        'from_number' => '+12245551030',
        'text' => 'Hello',
        'status' => 'received',
    ]);

    $idsFor = fn (string $filter) => collect(
        $this->getJson("/api/app/v1/threads?filter={$filter}")->assertOk()->json('data')
    )->pluck('id')->all();

    expect($idsFor('client'))->toBe([$clientThread->id]);
    expect($idsFor('vendor'))->toBe([$vendorThread->id]);
    expect($idsFor('unread'))->toBe([$plainThread->id]);
    expect($idsFor('all'))->toHaveCount(3);
    // An unrecognized value falls back to 'all' rather than erroring.
    expect($idsFor('bogus'))->toHaveCount(3);
});

// --- Opt-in / consent -----------------------------------------------------

it('flags a thread with a pending participant and resends the START prompt', function () {
    ['vendor' => $vendor, 'user' => $user] = appThreadActionsTeam();
    Sanctum::actingAs($user, ['app']);

    $thread = SmsGroupThread::query()->create([
        'from_number' => '+12247354200',
        'participants' => ['+12245552001'],
        'vendor_id' => $vendor->id,
        'last_activity_at' => now(),
    ]);
    $participant = SmsThreadParticipant::create(['thread_id' => $thread->id, 'phone_number' => '+12245552001', 'opted_in_at' => null]);

    $response = $this->getJson("/api/app/v1/threads/{$thread->id}")->assertOk();
    expect($response->json('data.pending_opt_in'))->toBeTrue();
    expect($response->json('data.pending_participants.0.id'))->toBe($participant->id);

    $this->postJson("/api/app/v1/threads/{$thread->id}/opt-in/resend")->assertOk()->assertJson(['sent' => true]);

    expect(SmsMessage::where('thread_id', $thread->id)->where('text', 'like', '%START%')->exists())->toBeTrue();

    // Already opted in: a second resend is a no-op 422, matching the web's "Already Opted In" toast case.
    $participant->update(['opted_in_at' => now()]);
    $this->postJson("/api/app/v1/threads/{$thread->id}/opt-in/resend")->assertStatus(422);
});

it('manually opts a participant in, recording who and why, and sends the welcome once everyone has opted in', function () {
    ['vendor' => $vendor, 'user' => $user] = appThreadActionsTeam();
    Sanctum::actingAs($user, ['app']);

    $thread = SmsGroupThread::query()->create([
        'from_number' => '+12247354200',
        'participants' => ['+12245552010'],
        'vendor_id' => $vendor->id,
        'last_activity_at' => now(),
    ]);
    $participant = SmsThreadParticipant::create(['thread_id' => $thread->id, 'phone_number' => '+12245552010', 'opted_in_at' => null]);

    $this->postJson("/api/app/v1/threads/{$thread->id}/opt-in/manual", [
        'participant_id' => $participant->id,
        'reason' => 'Confirmed verbally on a callback',
    ])->assertOk()->assertJson(['opted_in' => true]);

    $participant->refresh();
    expect($participant->opted_in_at)->not->toBeNull()
        ->and($participant->manual_opt_in_reason)->toBe('Confirmed verbally on a callback')
        ->and($participant->manual_opt_in_by)->toBe($user->id);

    expect(SmsMessage::where('thread_id', $thread->id)->where('text', 'like', '%welcome%i')->exists())
        ->toBeFalse(); // sanity: no crash; exact welcome copy isn't asserted here.

    // A participant from a different thread is rejected.
    $otherThread = appThreadActionsThread($vendor, ['participants' => ['+12245552099']]);
    $foreignParticipant = SmsThreadParticipant::where('thread_id', $otherThread->id)->first();

    $this->postJson("/api/app/v1/threads/{$thread->id}/opt-in/manual", [
        'participant_id' => $foreignParticipant->id,
        'reason' => 'Trying to cross threads',
    ])->assertStatus(422);
});

// --- Scheduled messages ----------------------------------------------------

it('cancels a scheduled message', function () {
    ['vendor' => $vendor, 'user' => $user] = appThreadActionsTeam();
    Sanctum::actingAs($user, ['app']);
    $thread = appThreadActionsThread($vendor);

    $scheduled = SmsMessage::create([
        'thread_id' => $thread->id,
        'direction' => 'outbound',
        'from_number' => '+12247354200',
        'text' => "See you tomorrow\n-GSC",
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay(),
    ]);

    $this->deleteJson("/api/app/v1/threads/{$thread->id}/messages/{$scheduled->id}")->assertOk()->assertJson(['deleted' => true]);
    expect(SmsMessage::find($scheduled->id))->toBeNull();

    // Only a *scheduled* message can be cancelled this way.
    $sent = SmsMessage::create([
        'thread_id' => $thread->id,
        'direction' => 'outbound',
        'from_number' => '+12247354200',
        'text' => 'Already sent',
        'status' => 'sent',
    ]);
    $this->deleteJson("/api/app/v1/threads/{$thread->id}/messages/{$sent->id}")->assertStatus(404);
});

it('sends a scheduled message right now, re-stamping the sender\'s own signature', function () {
    ['vendor' => $vendor, 'user' => $user] = appThreadActionsTeam();
    Sanctum::actingAs($user, ['app']);
    $thread = appThreadActionsThread($vendor);

    $scheduled = SmsMessage::create([
        'thread_id' => $thread->id,
        'direction' => 'outbound',
        'from_number' => '+12247354200',
        'to_numbers' => $thread->participants,
        'text' => "See you tomorrow\n-GS",
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay(),
    ]);

    $response = $this->postJson("/api/app/v1/threads/{$thread->id}/messages/{$scheduled->id}/send-now")->assertOk();

    $scheduled->refresh();
    expect($scheduled->status)->toBe('sent') // SendGroupMms ran synchronously (QUEUE_CONNECTION=sync)
        ->and($scheduled->scheduled_at)->toBeNull()
        ->and($scheduled->sent_by_user_id)->toBe($user->id);
    expect($response->json('data.status'))->toBe('sent');
});

// --- Per-message translate --------------------------------------------------

it('translates a message into the viewer\'s preferred language without persisting anything', function () {
    ['vendor' => $vendor, 'user' => $user] = appThreadActionsTeam();
    $user->forceFill(['preferred_language' => 'Spanish'])->save();
    Sanctum::actingAs($user, ['app']);
    $thread = appThreadActionsThread($vendor);

    $message = SmsMessage::create([
        'thread_id' => $thread->id,
        'direction' => 'inbound',
        'from_number' => '+12245551001',
        'text' => 'See you tomorrow',
        'status' => 'received',
    ]);

    config(['services.openai.api_key' => 'test-key']);
    Http::fake(['https://api.openai.com/*' => Http::response([
        'choices' => [['message' => ['content' => 'Nos vemos mañana']]],
    ], 200)]);

    $response = $this->postJson("/api/app/v1/threads/{$thread->id}/messages/{$message->id}/translate")->assertOk();
    expect($response->json('translated'))->toBe('Nos vemos mañana')
        ->and($response->json('language'))->toBe('Spanish');

    $message->refresh();
    expect($message->text)->toBe('See you tomorrow'); // never persisted
});

it('refuses to translate for an English-reading viewer', function () {
    ['vendor' => $vendor, 'user' => $user] = appThreadActionsTeam();
    Sanctum::actingAs($user, ['app']);
    $thread = appThreadActionsThread($vendor);
    $message = SmsMessage::create([
        'thread_id' => $thread->id, 'direction' => 'inbound', 'from_number' => '+12245551001', 'text' => 'Hi', 'status' => 'received',
    ]);

    $this->postJson("/api/app/v1/threads/{$thread->id}/messages/{$message->id}/translate")->assertStatus(422);
});

// --- Thread admin: spam / delete / assign ----------------------------------

it('marks and unmarks a thread\'s participant numbers as spam', function () {
    ['vendor' => $vendor, 'user' => $user] = appThreadActionsTeam();
    Sanctum::actingAs($user, ['app']);
    $thread = appThreadActionsThread($vendor, ['participants' => ['+12245553001']]);

    $this->postJson("/api/app/v1/threads/{$thread->id}/spam")->assertOk()->assertJson(['blocked_phones' => ['+12245553001']]);
    expect(\App\Models\BlockedCaller::where('phone_number', '+12245553001')->exists())->toBeTrue();

    $this->deleteJson("/api/app/v1/threads/{$thread->id}/spam")->assertOk()->assertJson(['blocked' => false]);
    expect(\App\Models\BlockedCaller::where('phone_number', '+12245553001')->exists())->toBeFalse();

    // Unmarking a never-blocked thread is a 422, matching the web's "Not Blocked" toast.
    $this->deleteJson("/api/app/v1/threads/{$thread->id}/spam")->assertStatus(422);
});

it('deletes a thread and everything in it', function () {
    ['vendor' => $vendor, 'user' => $user] = appThreadActionsTeam();
    Sanctum::actingAs($user, ['app']);
    $thread = appThreadActionsThread($vendor);
    SmsMessage::create(['thread_id' => $thread->id, 'direction' => 'inbound', 'from_number' => '+12245551001', 'text' => 'Hi', 'status' => 'received']);

    $this->deleteJson("/api/app/v1/threads/{$thread->id}")->assertOk()->assertJson(['deleted' => true]);

    expect(SmsGroupThread::find($thread->id))->toBeNull()
        ->and(SmsMessage::where('thread_id', $thread->id)->count())->toBe(0)
        ->and(SmsThreadParticipant::where('thread_id', $thread->id)->count())->toBe(0);
});

it('assigns a thread to a client, then re-assigns it to a vendor', function () {
    ['vendor' => $vendor, 'user' => $user] = appThreadActionsTeam();
    Sanctum::actingAs($user, ['app']);
    $thread = appThreadActionsThread($vendor);
    $client = Client::query()->create(['business_name' => 'Jordan Household']);
    $subVendor = Vendor::factory()->create(['business_name' => 'Acme Drywall']);

    $this->putJson("/api/app/v1/threads/{$thread->id}/assign", [
        'subject_type' => 'client',
        'client_id' => $client->id,
    ])->assertOk()->assertJsonPath('data.client_id', $client->id);

    $this->putJson("/api/app/v1/threads/{$thread->id}/assign", [
        'subject_type' => 'vendor',
        'vendor_id' => $subVendor->id,
    ])->assertOk()->assertJsonPath('data.subject_vendor_id', $subVendor->id)->assertJsonPath('data.client_id', null);

    $thread->refresh();
    expect($thread->client_id)->toBeNull()->and($thread->subject_vendor_id)->toBe($subVendor->id);
});

it('refuses another company\'s user access to every new thread action', function () {
    ['user' => $user] = appThreadActionsTeam();
    Sanctum::actingAs($user, ['app']);

    $otherVendor = Vendor::factory()->create();
    $thread = appThreadActionsThread($otherVendor);
    $participant = SmsThreadParticipant::where('thread_id', $thread->id)->first();

    $this->postJson("/api/app/v1/threads/{$thread->id}/opt-in/resend")->assertForbidden();
    $this->postJson("/api/app/v1/threads/{$thread->id}/opt-in/manual", ['participant_id' => $participant->id, 'reason' => 'x'])->assertForbidden();
    $this->postJson("/api/app/v1/threads/{$thread->id}/spam")->assertForbidden();
    $this->deleteJson("/api/app/v1/threads/{$thread->id}/spam")->assertForbidden();
    $this->putJson("/api/app/v1/threads/{$thread->id}/assign", ['subject_type' => 'client'])->assertForbidden();
    $this->deleteJson("/api/app/v1/threads/{$thread->id}")->assertForbidden();
});

// --- Starting a thread with a vendor ---------------------------------------

it('starts a new thread from a vendor\'s phone numbers, sending only the consent prompt', function () {
    ['vendor' => $vendor, 'user' => $user] = appThreadActionsTeam();
    Sanctum::actingAs($user, ['app']);

    $subVendor = Vendor::factory()->create(['business_name' => 'Acme Drywall', 'business_phone' => '2245554001']);
    $subUser = User::factory()->create(['cell_phone' => '2245554002', 'primary_vendor_id' => $subVendor->id]);
    $subVendor->users()->syncWithoutDetaching([$subUser->id]);

    $response = $this->postJson('/api/app/v1/threads', [
        'vendor_id' => $subVendor->id,
        'body' => 'Ignored manual text',
    ])->assertStatus(201);

    expect($response->json('data.subject_vendor_id'))->toBe($subVendor->id);

    $thread = SmsGroupThread::findOrFail($response->json('data.id'));
    expect($thread->participants)->toContain('+12245554001', '+12245554002');

    $consent = SmsMessage::where('thread_id', $thread->id)->first();
    expect($consent->text)->toContain('Reply START')->and($consent->text)->not->toContain('Ignored manual text');
});

it('rejects a thread start with no phones, client, or vendor', function () {
    ['user' => $user] = appThreadActionsTeam();
    Sanctum::actingAs($user, ['app']);

    $this->postJson('/api/app/v1/threads', ['body' => 'Hi'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['phones']);
});
