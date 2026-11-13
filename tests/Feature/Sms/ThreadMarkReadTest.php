<?php

use App\Jobs\SendExpoBadgeUpdate;
use App\Livewire\Sms\SmsConversation;
use App\Livewire\Sms\SmsThreadList;
use App\Livewire\Sms\TeamList;
use App\Models\AppNotification;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\SmsThreadParticipant;
use App\Models\SmsThreadRead;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Sms\ThreadReadMarker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Mark a conversation read without opening it (Patryk, 2026-11-12: "swipe a
 * message on the ios app (and hive.contractors) to mark it as read (remove
 * from notifications)"). One rule — ThreadReadMarker — behind every way of
 * doing it: the swipe / hover button on the Messages and Team lists, the
 * app's endpoint, opening the conversation, and leaving the page.
 */

/** @return array{vendor: Vendor, user: User} */
function markReadTeam(): array
{
    config(['scout.driver' => null]);
    // Vendor 1 = GS: the company the app and the Team tab are open to.
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $vendor->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();
    $vendor->users()->detach();

    return ['vendor' => $vendor, 'user' => markReadUser($vendor, 'Patryk')];
}

function markReadUser(Vendor $vendor, string $firstName): User
{
    $user = new User;
    $user->forceFill([
        'first_name' => $firstName,
        'last_name' => 'Crew',
        'email' => 'mark-read-'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
        'registration' => ['registered' => true],
    ]);
    $user->save();
    $vendor->users()->attach($user->id, ['role_id' => 1, 'is_employed' => 1]);

    return $user;
}

/** A text conversation of $vendor's with one inbound text waiting in it. */
function markReadThread(Vendor $vendor, string $phone, string $text = 'Are you coming today?'): SmsGroupThread
{
    $thread = SmsGroupThread::query()->create([
        'from_number' => '+12247354200',
        'participants' => [$phone],
        'vendor_id' => $vendor->id,
        'last_activity_at' => now(),
    ]);
    SmsThreadParticipant::query()->create(['thread_id' => $thread->id, 'phone_number' => $phone, 'opted_in_at' => now()]);
    SmsMessage::query()->create(['thread_id' => $thread->id, 'direction' => 'inbound', 'from_number' => $phone, 'text' => $text, 'status' => 'received']);

    return $thread;
}

function markReadNotification(User $user, ?SmsGroupThread $thread, string $type = 'sms_received'): AppNotification
{
    return AppNotification::query()->create([
        'user_id' => $user->id,
        'type' => $type,
        'title' => 'New text',
        'body' => 'Are you coming today?',
        'action_url' => $thread ? "/messages?threadId={$thread->id}" : '/leads',
        'data' => $thread ? ['sms_message_id' => 1, 'thread_id' => $thread->id] : [],
    ]);
}

it('marks the conversation read and takes only its own texts out of the person\'s notifications', function () {
    ['vendor' => $vendor, 'user' => $user] = markReadTeam();
    $greg = markReadUser($vendor, 'Greg');
    Queue::fake();

    $thread = markReadThread($vendor, '+12245550101');
    $otherThread = markReadThread($vendor, '+12245550102');
    $first = markReadNotification($user, $thread);
    $second = markReadNotification($user, $thread);
    $otherThreadText = markReadNotification($user, $otherThread);
    $lead = markReadNotification($user, null, 'lead_created');
    $gregsCopy = markReadNotification($greg, $thread);

    $result = app(ThreadReadMarker::class)->markRead($user, $thread->id);

    expect($result)->toBe(['thread_id' => $thread->id, 'was_unread' => true, 'notifications_cleared' => 2, 'unread_notifications' => 2])
        ->and(SmsThreadRead::query()->where(['thread_id' => $thread->id, 'user_id' => $user->id])->value('last_read_message_id'))->toBe(SmsMessage::query()->where('thread_id', $thread->id)->max('id'))
        ->and(SmsThreadRead::query()->where('thread_id', $otherThread->id)->exists())->toBeFalse()
        ->and($first->fresh()->read_at)->not->toBeNull()
        ->and($second->fresh()->read_at)->not->toBeNull()
        ->and($otherThreadText->fresh()->read_at)->toBeNull('a text from another conversation stays')
        ->and($lead->fresh()->read_at)->toBeNull('a notification about something else stays')
        ->and($gregsCopy->fresh()->read_at)->toBeNull('Greg has not read it')
        ->and(SmsThreadRead::query()->where('user_id', $greg->id)->exists())->toBeFalse();

    // The phones' app icon is told the number that is left, not zero.
    Queue::assertPushed(SendExpoBadgeUpdate::class, fn (SendExpoBadgeUpdate $job) => $job->userId === $user->id && $job->badge === 2);
    Queue::assertPushed(SendExpoBadgeUpdate::class, 1);

    // A second swipe has nothing left to do, and sends the phones nothing.
    $again = app(ThreadReadMarker::class)->markRead($user, $thread->id);
    expect($again)->toMatchArray(['was_unread' => false, 'notifications_cleared' => 0, 'unread_notifications' => 2]);
    Queue::assertPushed(SendExpoBadgeUpdate::class, 1);
});

it('leaves a conversation that is not the person\'s alone', function () {
    ['user' => $user] = markReadTeam();
    Queue::fake();
    $theirs = markReadThread(Vendor::factory()->create(), '+12245550103');
    // A row that should never exist, to prove even that is not touched.
    $notification = markReadNotification($user, $theirs);

    expect(app(ThreadReadMarker::class)->markRead($user, $theirs->id))->toBeNull()
        ->and(app(ThreadReadMarker::class)->markRead($user, 999999))->toBeNull()
        ->and(SmsThreadRead::query()->count())->toBe(0)
        ->and($notification->fresh()->read_at)->toBeNull();
    Queue::assertNotPushed(SendExpoBadgeUpdate::class);
});

it('offers Mark as read on unread rows of the Messages list, and marks one without opening it', function () {
    ['vendor' => $vendor, 'user' => $user] = markReadTeam();
    Queue::fake();
    $unread = markReadThread($vendor, '+12245550104');
    $read = markReadThread($vendor, '+12245550105');
    SmsThreadRead::query()->create(['thread_id' => $read->id, 'user_id' => $user->id, 'last_read_message_id' => SmsMessage::query()->where('thread_id', $read->id)->max('id')]);
    $notification = markReadNotification($user, $unread);
    $this->actingAs($user);

    $list = Livewire::test(SmsThreadList::class, ['isClientUser' => false]);
    expect($list->instance()->unreadThreadIds)->toBe([$unread->id]);
    $list->assertSeeHtml('wire:key="thread-'.$unread->id.'"')
        ->assertSeeHtml('wire:key="thread-'.$read->id.'"')
        ->assertSeeHtml('wire:click="markRead('.$unread->id.')"')
        ->assertDontSeeHtml('wire:click="markRead('.$read->id.')"');
    expect(substr_count($list->html(), 'data-unread="1"'))->toBe(1)
        ->and(substr_count($list->html(), 'data-unread="0"'))->toBe(1);

    $list->call('markRead', $unread->id)
        ->assertDispatched('sms-thread-read')
        ->assertDispatched('notification-read')
        ->assertDontSeeHtml('wire:click="markRead(')
        ->assertDontSeeHtml('data-unread="1"')
        ->assertSeeHtml('wire:key="thread-'.$unread->id.'"');

    expect($list->instance()->unreadThreadIds)->toBe([])
        ->and($notification->fresh()->read_at)->not->toBeNull()
        ->and(SmsThreadRead::query()->where(['thread_id' => $unread->id, 'user_id' => $user->id])->exists())->toBeTrue();
});

it('ignores a Mark as read for a conversation of another company', function () {
    ['user' => $user] = markReadTeam();
    $theirs = markReadThread(Vendor::factory()->create(), '+12245550106');
    $this->actingAs($user);

    Livewire::test(SmsThreadList::class, ['isClientUser' => false])
        ->call('markRead', $theirs->id)
        ->assertNotDispatched('sms-thread-read')
        ->assertNotDispatched('notification-read');

    expect(SmsThreadRead::query()->count())->toBe(0);
});

it('marks a teammate\'s message read from the Team list', function () {
    ['vendor' => $vendor, 'user' => $user] = markReadTeam();
    $greg = markReadUser($vendor, 'Greg');
    // A third person, so the crew row is a different conversation from Greg's.
    markReadUser($vendor, 'Cezary');
    Queue::fake();
    $thread = SmsGroupThread::teamThreadFor([$user->id, $greg->id], $vendor->id);
    SmsMessage::query()->create(['thread_id' => $thread->id, 'direction' => 'outbound', 'from_number' => '+1'.$greg->cell_phone, 'text' => 'Running late', 'status' => 'sent', 'provider' => 'hive', 'sent_by_user_id' => $greg->id]);
    $notification = markReadNotification($user, $thread, 'team_message');
    $this->actingAs($user);

    $list = Livewire::test(TeamList::class);
    expect(collect($list->instance()->rows)->firstWhere('user_id', $greg->id))->toMatchArray(['thread_id' => $thread->id, 'unread' => true]);
    $list->assertSeeHtml('wire:key="team-row-user-'.$greg->id.'"')
        ->assertSeeHtml('wire:click="markRead('.$thread->id.')"');
    // The crew row has no thread yet: nothing to mark, and nothing to swipe.
    expect(substr_count($list->html(), 'data-unread="1"'))->toBe(1);

    $list->call('markRead', $thread->id)
        ->assertDispatched('sms-thread-read')
        ->assertDispatched('notification-read')
        ->assertDontSeeHtml('wire:click="markRead(')
        ->assertDontSeeHtml('data-team-unread');

    expect(collect($list->instance()->rows)->firstWhere('user_id', $greg->id)['unread'])->toBeFalse()
        ->and($notification->fresh()->read_at)->not->toBeNull()
        ->and(SmsGroupThread::unreadCountForUser($user->id, $vendor->id))->toBe(0);
});

it('tells the app how many notifications are left after its swipe', function () {
    ['vendor' => $vendor, 'user' => $user] = markReadTeam();
    Queue::fake();
    $thread = markReadThread($vendor, '+12245550107');
    $swiped = markReadNotification($user, $thread);
    markReadNotification($user, markReadThread($vendor, '+12245550108'));
    Sanctum::actingAs($user, ['app']);

    $this->postJson("/api/app/v1/threads/{$thread->id}/read")
        ->assertOk()
        ->assertExactJson(['read' => true, 'unread_notifications' => 1]);

    expect($swiped->fresh()->read_at)->not->toBeNull();
    expect(collect($this->getJson('/api/app/v1/threads')->assertOk()->json('data'))->firstWhere('id', $thread->id)['unread_count'])->toBe(0);
});

it('clears a conversation\'s notifications when it is read by opening it, or by leaving the page', function () {
    ['vendor' => $vendor, 'user' => $user] = markReadTeam();
    Queue::fake();
    $opened = markReadThread($vendor, '+12245550109');
    $next = markReadThread($vendor, '+12245550110');
    $openedText = markReadNotification($user, $opened);
    $nextText = markReadNotification($user, $next);
    $this->actingAs($user);

    // A conversation is read when the person moves on from it (SmsConversation::loadThread).
    Livewire::test(SmsConversation::class, ['threadId' => $opened->id])
        ->call('loadThread', $next->id)
        ->assertDispatched('sms-thread-read')
        ->assertDispatched('notification-read');
    expect($openedText->fresh()->read_at)->not->toBeNull()
        ->and($nextText->fresh()->read_at)->toBeNull('still open, still unread');

    // Closing the tab on it sends the exit beacon.
    $this->post(route('sms.threads.read', $next))->assertNoContent();
    expect($nextText->fresh()->read_at)->not->toBeNull()
        ->and(SmsThreadRead::query()->where(['thread_id' => $next->id, 'user_id' => $user->id])->exists())->toBeTrue();

    $this->post(route('sms.threads.read', markReadThread(Vendor::factory()->create(), '+12245550111')))->assertForbidden();
});
