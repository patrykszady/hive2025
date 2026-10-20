<?php

use App\Livewire\Sms\SmsNewThread;
use App\Models\BlockedCaller;
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
        ->and($data[1]['sender_name'])->toBe('Patryk');
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
        ->and($response->json('data.sender_name'))->toBe('Patryk');

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

it('keeps a message scheduled for later in the thread, marked with its send time', function () {
    ['vendor' => $vendor, 'user' => $user] = appMessagingTeam();
    $thread = appMessagingThread($vendor);
    Sanctum::actingAs($user, ['app']);

    $scheduled = SmsMessage::query()->create([
        'thread_id' => $thread->id,
        'direction' => SmsMessage::DIRECTION_OUTBOUND,
        'from_number' => '+12247354200',
        'to_numbers' => ['+12245550001'],
        'text' => "See you tomorrow\n-GSC",
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->setTime(8, 0),
        'sent_by_user_id' => $user->id,
    ]);

    $response = $this->getJson("/api/app/v1/threads/{$thread->id}/messages")->assertOk();

    $row = collect($response->json('data'))->firstWhere('id', $scheduled->id);
    expect($row)->not->toBeNull()
        ->and($row['status'])->toBe('scheduled')
        ->and($row['scheduled_at'])->not->toBeNull();
});

// =========================================================================
// Visual fidelity pass (2026-10-20): the thread list preview's sender
// prefix, the conversation's quick-call row, the scheduled-message badge
// and the spam flag — all computed server-side so the app can render them
// exactly like thread-list.blade.php / conversation.blade.php.
// =========================================================================

it('names the last message\'s sender in the thread list, mirroring the web\'s preview prefix', function () {
    ['vendor' => $vendor, 'user' => $user] = appMessagingTeam();
    $teammate = User::factory()->create(['first_name' => 'Jordan', 'primary_vendor_id' => $vendor->id]);
    Sanctum::actingAs($user, ['app']);

    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);
    $katie = new User();
    $katie->forceFill([
        'first_name' => 'Katie',
        'last_name' => 'Love',
        'email' => 'katie-'.uniqid().'@example.test',
        'cell_phone' => '2245551234',
    ]);
    $katie->save();
    $katie->clients()->attach($client->id);

    $thread = appMessagingThread($vendor, ['participants' => ['+12245551234'], 'client_id' => $client->id]);

    $entry = fn () => collect($this->getJson('/api/app/v1/threads')->assertOk()->json('data'))->firstWhere('id', $thread->id);

    // Inbound: the resolved sender's first name (a thread client user whose
    // phone matches the inbound from_number), not blank.
    $inbound = SmsMessage::create(['thread_id' => $thread->id, 'direction' => 'inbound', 'from_number' => '+12245551234', 'text' => 'On my way', 'status' => 'received']);
    $inbound->forceFill(['created_at' => now()->subMinutes(3)])->saveQuietly();
    expect($entry()['last_message']['sender_name'])->toBe('Katie');

    // Outbound, sent by the viewer themself: null — the app renders "You".
    $own = SmsMessage::create(['thread_id' => $thread->id, 'direction' => 'outbound', 'from_number' => '+12247354200', 'text' => 'On it', 'status' => 'sent', 'sent_by_user_id' => $user->id]);
    $own->forceFill(['created_at' => now()->subMinutes(2)])->saveQuietly();
    expect($entry()['last_message']['sender_name'])->toBeNull();

    // Outbound, sent by a different teammate: that teammate's name, not "You".
    $teammateSent = SmsMessage::create(['thread_id' => $thread->id, 'direction' => 'outbound', 'from_number' => '+12247354200', 'text' => 'Confirmed', 'status' => 'sent', 'sent_by_user_id' => $teammate->id]);
    $teammateSent->forceFill(['created_at' => now()->subMinute()])->saveQuietly();
    expect($entry()['last_message']['sender_name'])->toBe('Jordan');
});

it('lists who a thread can click-to-call, excluding the business number', function () {
    config(['services.telnyx.numbers' => ['+12247354200']]);
    ['vendor' => $vendor, 'user' => $user] = appMessagingTeam();
    Sanctum::actingAs($user, ['app']);

    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);
    $katie = new User();
    $katie->forceFill([
        'first_name' => 'Katie',
        'last_name' => 'Love',
        'email' => 'katie-'.uniqid().'@example.test',
        'cell_phone' => '2245550400',
    ]);
    $katie->save();
    $katie->clients()->attach($client->id);

    $thread = appMessagingThread($vendor, [
        'participants' => ['+12245550400', '+12247354200'],
        'client_id' => $client->id,
    ]);

    $contacts = $this->getJson("/api/app/v1/threads/{$thread->id}")->assertOk()->json('data.callable_contacts');

    expect($contacts)->toHaveCount(1)
        ->and($contacts[0]['phone'])->toBe('+12245550400')
        ->and($contacts[0]['name'])->toBe('Katie');
});

it('counts a thread\'s not-yet-sent scheduled messages, for the list\'s clock badge', function () {
    ['vendor' => $vendor, 'user' => $user] = appMessagingTeam();
    Sanctum::actingAs($user, ['app']);
    $thread = appMessagingThread($vendor, ['participants' => ['+12245550200']]);

    SmsMessage::create([
        'thread_id' => $thread->id, 'direction' => 'outbound', 'from_number' => '+12247354200',
        'text' => 'See you then', 'status' => 'scheduled', 'scheduled_at' => now()->addDay(), 'sent_by_user_id' => $user->id,
    ]);
    SmsMessage::create([
        'thread_id' => $thread->id, 'direction' => 'inbound', 'from_number' => '+12245550200', 'text' => 'Sounds good', 'status' => 'received',
    ]);

    $entry = collect($this->getJson('/api/app/v1/threads')->assertOk()->json('data'))->firstWhere('id', $thread->id);
    expect($entry['scheduled_messages_count'])->toBe(1);

    expect($this->getJson("/api/app/v1/threads/{$thread->id}")->assertOk()->json('data.scheduled_messages_count'))->toBe(1);
});

it('flags a thread as spam in the list and detail once its participant number is blocked', function () {
    ['vendor' => $vendor, 'user' => $user] = appMessagingTeam();
    Sanctum::actingAs($user, ['app']);
    $thread = appMessagingThread($vendor, ['participants' => ['+12245550300']]);

    $entry = fn () => collect($this->getJson('/api/app/v1/threads')->assertOk()->json('data'))->firstWhere('id', $thread->id);
    expect($entry()['is_spam'])->toBeFalse();
    expect($this->getJson("/api/app/v1/threads/{$thread->id}")->assertOk()->json('data.is_spam'))->toBeFalse();

    BlockedCaller::create(['phone_number' => '+12245550300']);

    expect($entry()['is_spam'])->toBeTrue();
    expect($this->getJson("/api/app/v1/threads/{$thread->id}")->assertOk()->json('data.is_spam'))->toBeTrue();
});

/**
 * ThreadController::index()'s search now goes through the same
 * App\Services\Sms\ThreadSearch Meilisearch path as SmsThreadList (see
 * tests/Feature/SmsThreadListTest.php's own "orders search results newest
 * first" test, which this mirrors through the app endpoint instead of
 * Livewire), rather than its own separate LIKE-only query.
 *
 * Every app user has a vendor (EnsureAppUserAllowed requires primary_vendor_id
 * 1), so — unlike SmsThreadListTest's own version of this test — this can't
 * dodge the thread index's `vendor_visibility_ids` filter by using a
 * vendorless user; the collection driver can't evaluate that Meilisearch-only
 * field against a real table column, so the thread index itself matches
 * nothing here, same as SmsThreadListTest's comment explains. What this
 * still proves end to end: a match coming ONLY from the dedicated message
 * index (ThreadSearch::matchingThreadIds()'s inner merge) reaches the list,
 * and the caller re-sorts the merged ids by thread activity rather than
 * leaving them in message-search order.
 */
it('finds threads via the message-index merge, newest activity first, not relevance order', function () {
    config(['scout.driver' => 'collection']);

    ['vendor' => $vendor, 'user' => $user] = appMessagingTeam();
    Sanctum::actingAs($user, ['app']);

    // Activity order is deliberately unrelated to message age, so a list
    // sorted by search relevance (or insertion order) can't pass by luck.
    $threads = [
        ['Scott Weyman', 2, 1200],
        ['Scott Swenson', 20, 7],
        ['Scott Lunardini', 200, 300],
    ];

    $created = [];
    foreach ($threads as [$name, $messageAge, $activityAge]) {
        $thread = appMessagingThread($vendor, [
            'name' => $name,
            'participants' => ['+1224555'.random_int(1000, 9999)],
            'last_activity_at' => now()->subDays($activityAge),
        ]);
        SmsMessage::create([
            'thread_id' => $thread->id, 'direction' => 'outbound', 'from_number' => '+12247354200',
            'text' => "Hi {$name}, checking in.", 'status' => 'sent', 'created_at' => now()->subDays($messageAge),
        ]);
        $created[$name] = $thread;
    }

    $response = $this->getJson('/api/app/v1/threads?search=Scott')->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->all();

    expect($ids)->toBe([
        $created['Scott Swenson']->id,
        $created['Scott Lunardini']->id,
        $created['Scott Weyman']->id,
    ]);
});

/**
 * Pest's ambient scout.driver is null (see tests/TestCase.php), which Scout
 * resolves to its own built-in NullEngine — it returns an empty result
 * without throwing, so (same as SmsThreadList already does — this isn't a
 * behavior change) a search under the plain default driver finds nothing
 * at all rather than reaching the LIKE fallback. Reaching that fallback
 * for real needs Meilisearch to actually fail, so this switches to an
 * unsupported driver name (which does throw) only AFTER the fixture rows
 * are created — creating them under NullEngine first avoids also breaking
 * the Searchable observers that index a model on save.
 */
it('falls back to a LIKE search when Meilisearch itself fails, same as the web', function () {
    ['vendor' => $vendor, 'user' => $user] = appMessagingTeam();
    Sanctum::actingAs($user, ['app']);

    $client = \App\Models\Client::factory()->create();
    $project = \App\Models\Project::factory()->create(['address' => '742 Evergreen Terrace', 'belongs_to_vendor_id' => $vendor->id, 'client_id' => $client->id]);
    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);
    $byAddress = appMessagingThread($vendor, ['participants' => ['+12245559001'], 'project_id' => $project->id]);

    $byMessage = appMessagingThread($vendor, ['participants' => ['+12245559002']]);
    SmsMessage::create([
        'thread_id' => $byMessage->id, 'direction' => 'outbound', 'from_number' => '+12247354200',
        'text' => 'The quartz countertop ships Monday.', 'status' => 'sent',
    ]);

    $byPhone = appMessagingThread($vendor, ['participants' => ['+13125556349']]);

    $unrelated = appMessagingThread($vendor, ['participants' => ['+12245559999']]);

    config(['scout.driver' => 'totally-unsupported-driver']);

    $addressIds = collect($this->getJson('/api/app/v1/threads?search=Evergreen')->assertOk()->json('data'))->pluck('id');
    expect($addressIds)->toContain($byAddress->id)->not->toContain($unrelated->id);

    $messageIds = collect($this->getJson('/api/app/v1/threads?search=quartz')->assertOk()->json('data'))->pluck('id');
    expect($messageIds)->toContain($byMessage->id)->not->toContain($unrelated->id);

    $phoneIds = collect($this->getJson('/api/app/v1/threads?search=6349')->assertOk()->json('data'))->pluck('id');
    expect($phoneIds)->toContain($byPhone->id)->not->toContain($unrelated->id);
});
