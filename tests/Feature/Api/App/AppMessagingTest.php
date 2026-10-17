<?php

use App\Livewire\Sms\SmsNewThread;
use App\Models\Client;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\SmsThreadParticipant;
use App\Models\SmsThreadRead;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * GS Construction is vendor id 1 — pinned explicitly, same reasoning as
 * AppAuthTest's appTeamUser().
 *
 * @return array{vendor: Vendor, user: User}
 */
function appMessagingTeam(): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);

    $user = new User();
    $user->forceFill([
        'first_name' => 'Patryk',
        'last_name' => 'Crew',
        'email' => 'app-msg-'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
    ]);
    $user->save();

    return compact('vendor', 'user');
}

/**
 * A thread owned by $vendor with an opted-in participant per phone in
 * $overrides['participants'] (default one phone).
 */
function appMessagingThread(Vendor $vendor, array $overrides = []): SmsGroupThread
{
    $participants = $overrides['participants'] ?? ['+12245550001'];

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

it('needs a token to list threads', function () {
    $this->getJson('/api/app/v1/threads')->assertUnauthorized();
});

it('lists only this team member\'s visible threads, newest activity first', function () {
    ['vendor' => $vendor, 'user' => $user] = appMessagingTeam();
    Sanctum::actingAs($user, ['app']);

    $older = appMessagingThread($vendor, ['participants' => ['+12245550001'], 'last_activity_at' => now()->subHour()]);
    $newer = appMessagingThread($vendor, ['participants' => ['+12245550002'], 'last_activity_at' => now()]);

    $otherVendor = Vendor::factory()->create();
    $otherThread = appMessagingThread($otherVendor, ['participants' => ['+12245550003']]);

    $response = $this->getJson('/api/app/v1/threads')->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids->all())->toBe([$newer->id, $older->id])
        ->and($ids)->not->toContain($otherThread->id);
});

it('shows a real unread count that clears once the thread is marked read', function () {
    ['vendor' => $vendor, 'user' => $user] = appMessagingTeam();
    Sanctum::actingAs($user, ['app']);

    $thread = appMessagingThread($vendor, ['participants' => ['+12245550050']]);
    $message = SmsMessage::create([
        'thread_id' => $thread->id,
        'direction' => 'inbound',
        'from_number' => '+12245550050',
        'text' => 'Hi there',
        'status' => 'received',
    ]);

    $entry = fn () => collect(
        $this->getJson('/api/app/v1/threads')->assertOk()->json('data')
    )->firstWhere('id', $thread->id);

    expect($entry()['unread_count'])->toBe(1);

    $this->postJson("/api/app/v1/threads/{$thread->id}/read")
        ->assertOk()
        ->assertExactJson(['read' => true]);

    expect(SmsThreadRead::where(['thread_id' => $thread->id, 'user_id' => $user->id])->first()->last_read_message_id)
        ->toBe($message->id);
    expect($entry()['unread_count'])->toBe(0);
});

it('reads a thread\'s messages oldest first, with stripped signatures and media', function () {
    ['vendor' => $vendor, 'user' => $user] = appMessagingTeam();
    Sanctum::actingAs($user, ['app']);
    $thread = appMessagingThread($vendor, ['participants' => ['+12245550060']]);

    $inbound = SmsMessage::create([
        'thread_id' => $thread->id,
        'direction' => 'inbound',
        'from_number' => '+12245550060',
        'text' => 'First message',
        'status' => 'received',
    ]);
    $outbound = SmsMessage::create([
        'thread_id' => $thread->id,
        'direction' => 'outbound',
        'from_number' => '+12247354200',
        'text' => "Second message\n-PS",
        'status' => 'sent',
        'sent_by_user_id' => $user->id,
    ]);

    $response = $this->getJson("/api/app/v1/threads/{$thread->id}/messages")->assertOk();
    $data = $response->json('data');

    expect($data)->toHaveCount(2);
    expect($data[0]['id'])->toBe($inbound->id)
        ->and($data[0]['body'])->toBe('First message')
        ->and($data[0]['direction'])->toBe('inbound')
        ->and($data[0]['sender_name'])->toBeNull();
    expect($data[1]['id'])->toBe($outbound->id)
        // Crew signature stripped from the body, same as the web conversation.
        ->and($data[1]['body'])->toBe('Second message')
        ->and($data[1]['sender_name'])->toBe('Patryk Crew');
});

it('pages older messages with ?before=', function () {
    ['vendor' => $vendor, 'user' => $user] = appMessagingTeam();
    Sanctum::actingAs($user, ['app']);
    $thread = appMessagingThread($vendor, ['participants' => ['+12245550070']]);

    $messages = collect(range(1, 3))->map(fn (int $i) => SmsMessage::create([
        'thread_id' => $thread->id,
        'direction' => 'inbound',
        'from_number' => '+12245550070',
        'text' => "Message {$i}",
        'status' => 'received',
    ]));
    [$first, $second, $third] = $messages->all();

    // Everything, newest last.
    $all = $this->getJson("/api/app/v1/threads/{$thread->id}/messages")->assertOk();
    expect(collect($all->json('data'))->pluck('id')->all())->toBe([$first->id, $second->id, $third->id])
        ->and($all->json('next_before'))->toBe($first->id)
        ->and($all->json('has_more'))->toBeFalse();

    // Only what came before the third message.
    $olderPage = $this->getJson("/api/app/v1/threads/{$thread->id}/messages?before={$third->id}")->assertOk();
    expect(collect($olderPage->json('data'))->pluck('id')->all())->toBe([$first->id, $second->id])
        ->and($olderPage->json('next_before'))->toBe($first->id);
});

it('sends a message the same way the web composer does', function () {
    ['vendor' => $vendor, 'user' => $user] = appMessagingTeam();
    Sanctum::actingAs($user, ['app']);
    $thread = appMessagingThread($vendor, ['participants' => ['+12245550009']]);

    Http::fake([
        'https://api.telnyx.com/*' => Http::response(['data' => ['id' => 'telnyx-test-id']], 200),
    ]);

    $response = $this->postJson("/api/app/v1/threads/{$thread->id}/messages", [
        'body' => 'On our way',
    ])->assertStatus(201);

    $expectedText = 'On our way' . "\n" . SmsNewThread::getSignature($user->id);
    $message = SmsMessage::findOrFail($response->json('data.id'));

    expect($message->direction)->toBe('outbound')
        ->and($message->text)->toBe($expectedText)
        ->and($message->sent_by_user_id)->toBe($user->id)
        ->and($message->status)->toBe('sent'); // SendGroupMms ran synchronously (QUEUE_CONNECTION=sync)

    expect($response->json('data.body'))->toBe('On our way')
        ->and($response->json('data.sender_name'))->toBe('Patryk Crew');

    // Exactly the request SendGroupMms::sendSms() sends for a single
    // recipient — same from-number, same endpoint, same body.
    Http::assertSent(function (HttpRequest $request) use ($expectedText) {
        return $request->url() === 'https://api.telnyx.com/v2/messages'
            && $request['from'] === config('services.telnyx.from')
            && $request['to'] === '+12245550009'
            && $request['text'] === $expectedText;
    });
});

it('uploads image attachments through the same public-disk convention as the web composer', function () {
    Storage::fake('public');
    ['vendor' => $vendor, 'user' => $user] = appMessagingTeam();
    Sanctum::actingAs($user, ['app']);
    $thread = appMessagingThread($vendor, ['participants' => ['+12245550010']]);

    Http::fake(['https://api.telnyx.com/*' => Http::response(['data' => ['id' => 'telnyx-mms']], 200)]);

    $file = UploadedFile::fake()->image('photo.jpg', 200, 200)->size(100);

    $response = $this->postJson("/api/app/v1/threads/{$thread->id}/messages", [
        'body' => 'Here is a photo',
        'media' => [$file],
    ])->assertStatus(201);

    $message = SmsMessage::findOrFail($response->json('data.id'));
    expect($message->media_urls)->toHaveCount(1)
        ->and($message->media_urls[0])->toStartWith('/storage/sms-attachments/');

    Storage::disk('public')->assertExists(str_replace('/storage/', '', $message->media_urls[0]));

    // Absolute, session-less (signed) URL — see ConversationPresenter::publicMediaUrl().
    $mediaUrls = $response->json('data.media_urls');
    expect($mediaUrls)->toHaveCount(1)
        ->and($mediaUrls[0])->toContain('/m/sms/sms-attachments/');
});

it('refuses to send project messages while opt-in is pending', function () {
    ['vendor' => $vendor, 'user' => $user] = appMessagingTeam();
    Sanctum::actingAs($user, ['app']);

    $thread = SmsGroupThread::create([
        'from_number' => '+12247354200',
        'participants' => ['+12245550011'],
        'vendor_id' => $vendor->id,
        'last_activity_at' => now(),
    ]);
    SmsThreadParticipant::create(['thread_id' => $thread->id, 'phone_number' => '+12245550011', 'opted_in_at' => null]);

    $this->postJson("/api/app/v1/threads/{$thread->id}/messages", ['body' => 'hello'])
        ->assertStatus(422)
        ->assertJson(['message' => 'Each recipient must reply START before sending project messages.']);
});

it('starts a new thread from phone numbers, sending only the consent prompt', function () {
    ['vendor' => $vendor, 'user' => $user] = appMessagingTeam();
    Sanctum::actingAs($user, ['app']);
    Http::fake(['https://api.telnyx.com/*' => Http::response(['data' => ['id' => 'x']], 200)]);

    $response = $this->postJson('/api/app/v1/threads', [
        'phones' => ['2245550020'],
        'body' => 'Ignored manual text',
    ])->assertStatus(201);

    $thread = SmsGroupThread::findOrFail($response->json('data.id'));
    expect($thread->participants)->toBe(['+12245550020'])
        ->and($thread->vendor_id)->toBe($vendor->id);

    // GroupSmsService::sendNewGroup() always sends its own consent prompt —
    // the submitted body is never the first SMS, exactly like the web's
    // new-thread modal (see AppThreadStarter's docblock).
    $consent = SmsMessage::where('thread_id', $thread->id)->first();
    expect($consent->text)->toContain('Reply START')
        ->and($consent->text)->not->toContain('Ignored manual text');
});

it('starts a new thread from a client\'s phone numbers', function () {
    ['vendor' => $vendor, 'user' => $user] = appMessagingTeam();
    Sanctum::actingAs($user, ['app']);
    Http::fake(['https://api.telnyx.com/*' => Http::response(['data' => ['id' => 'x']], 200)]);

    $client = Client::query()->create(['business_name' => 'Jordan Household', 'home_phone' => '2245550030']);

    $response = $this->postJson('/api/app/v1/threads', [
        'client_id' => $client->id,
        'body' => 'Hi there',
    ])->assertStatus(201);

    expect($response->json('data.client_id'))->toBe($client->id);

    $thread = SmsGroupThread::findOrFail($response->json('data.id'));
    expect($thread->participants)->toBe(['+12245550030']);
});

it('returns the existing thread for an identical participant set instead of duplicating it', function () {
    ['vendor' => $vendor, 'user' => $user] = appMessagingTeam();
    Sanctum::actingAs($user, ['app']);
    Http::fake(['https://api.telnyx.com/*' => Http::response(['data' => ['id' => 'x']], 200)]);

    $existing = appMessagingThread($vendor, ['participants' => ['+12245550040']]);

    $response = $this->postJson('/api/app/v1/threads', [
        'phones' => ['2245550040'],
        'body' => 'Hi',
    ])->assertStatus(201);

    expect($response->json('data.id'))->toBe($existing->id)
        // No second consent prompt went out for the same participant set.
        ->and(SmsMessage::where('thread_id', $existing->id)->count())->toBe(0);
});

it('rejects a request with neither phones nor a client', function () {
    ['user' => $user] = appMessagingTeam();
    Sanctum::actingAs($user, ['app']);

    $this->postJson('/api/app/v1/threads', ['body' => 'Hi'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['phones']);
});

it('refuses another company\'s user access to a thread', function () {
    ['user' => $user] = appMessagingTeam();
    Sanctum::actingAs($user, ['app']);

    $otherVendor = Vendor::factory()->create();
    $otherThread = appMessagingThread($otherVendor, ['participants' => ['+12245550080']]);

    $this->getJson("/api/app/v1/threads/{$otherThread->id}/messages")->assertStatus(403);
    $this->postJson("/api/app/v1/threads/{$otherThread->id}/messages", ['body' => 'hi'])->assertStatus(403);
    $this->postJson("/api/app/v1/threads/{$otherThread->id}/read")->assertStatus(403);
});

it('404s for a thread that does not exist at all', function () {
    ['user' => $user] = appMessagingTeam();
    Sanctum::actingAs($user, ['app']);

    $this->getJson('/api/app/v1/threads/999999/messages')->assertStatus(404);
});

it('gets 403 for a disallowed user, even for a thread owned by their own vendor', function () {
    $outsiderVendor = Vendor::factory()->create();

    $outsider = new User();
    $outsider->forceFill([
        'first_name' => 'Out',
        'last_name' => 'Sider',
        'email' => 'app-out-'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $outsiderVendor->id,
    ]);
    $outsider->save();
    Sanctum::actingAs($outsider, ['app']);

    $thread = appMessagingThread($outsiderVendor, ['participants' => ['+12245550090']]);

    $this->getJson('/api/app/v1/threads')->assertStatus(403);
    $this->getJson("/api/app/v1/threads/{$thread->id}/messages")->assertStatus(403);
});

it('opens one thread by id, for links and notification taps, and only a visible one', function () {
    ['vendor' => $vendor, 'user' => $user] = appMessagingTeam();
    $thread = appMessagingThread($vendor, ['participants' => ['+12245550123']]);
    $other = appMessagingThread(Vendor::factory()->create());
    Sanctum::actingAs($user, ['app']);

    $this->getJson("/api/app/v1/threads/{$thread->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $thread->id)
        ->assertJsonPath('data.participants.0.phone', '+12245550123');

    $this->getJson("/api/app/v1/threads/{$other->id}")->assertForbidden();
    $this->getJson('/api/app/v1/threads/999999')->assertNotFound();
});
