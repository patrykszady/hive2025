<?php

use App\Livewire\Sms\SmsConversation;
use App\Models\Client;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Sms\ThreadAdminActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * ⋮ → Delete on a text (Patryk, 2026-10-26): the message leaves the
 * conversation in Hive (soft-deleted, with who did it). The people it went to
 * still have it — an SMS cannot be unsent.
 */

function deleteMessageWorld(): array
{
    config(['scout.driver' => null]);
    $company = Vendor::factory()->create(['business_name' => 'GS Construction']);
    $user = User::query()->create(['first_name' => 'Patryk', 'last_name' => 'Tester', 'email' => 'delete-msg-'.uniqid().'@example.com', 'cell_phone' => '2245551111', 'primary_vendor_id' => $company->id]);
    $company->users()->attach($user->id, ['role_id' => 1, 'is_employed' => 1]);

    $thread = SmsGroupThread::query()->create(['name' => 'Abbey', 'from_number' => '+12247354200', 'participants' => ['+18475550101'], 'vendor_id' => $company->id, 'last_activity_at' => now()]);
    $other = SmsGroupThread::query()->create(['name' => 'Other', 'from_number' => '+12247354200', 'participants' => ['+18475550102'], 'vendor_id' => $company->id, 'last_activity_at' => now()->subMinute()]);

    $first = SmsMessage::query()->create(['thread_id' => $thread->id, 'direction' => SmsMessage::DIRECTION_INBOUND, 'from_number' => '+18475550101', 'text' => 'Can you come Thursday?', 'status' => 'received', 'created_at' => now()->subMinutes(5)]);
    $last = SmsMessage::query()->create(['thread_id' => $thread->id, 'direction' => SmsMessage::DIRECTION_OUTBOUND, 'from_number' => '+12247354200', 'text' => 'Sent to the wrong thread, sorry', 'status' => 'delivered', 'sent_by_user_id' => $user->id, 'created_at' => now()]);
    $elsewhere = SmsMessage::query()->create(['thread_id' => $other->id, 'direction' => SmsMessage::DIRECTION_INBOUND, 'from_number' => '+18475550102', 'text' => 'Another conversation', 'status' => 'received']);

    return compact('company', 'user', 'thread', 'other', 'first', 'last', 'elsewhere');
}

it('deletes a text from the conversation in Hive and keeps the row with who deleted it', function () {
    $w = deleteMessageWorld();
    $this->actingAs($w['user']);

    $page = Livewire::test(SmsConversation::class, ['threadId' => $w['thread']->id])
        ->assertSeeHtml('data-delete-message')
        ->call('confirmDeleteMessage', $w['last']->id)
        ->assertSet('deletingMessageId', $w['last']->id)
        ->assertSet('showDeleteMessageConfirm', true)
        ->assertSee('The people it went to still have it')
        ->call('deleteMessage')
        ->assertSet('showDeleteMessageConfirm', false)
        ->assertSet('deletingMessageId', null)
        ->assertDispatched('sms-refresh-threads');

    $trashed = SmsMessage::withTrashed()->find($w['last']->id);
    expect($trashed->trashed())->toBeTrue()
        ->and($trashed->deleted_by_user_id)->toBe($w['user']->id)
        ->and($page->instance()->smsMessages->pluck('id')->all())->not->toContain($w['last']->id)
        ->and($page->instance()->smsMessages->pluck('id')->all())->toContain($w['first']->id)
        // The thread list's preview falls back to the message before it.
        ->and($w['thread']->fresh()->latestMessage->id)->toBe($w['first']->id);
});

it('only deletes a message of the open thread', function () {
    $w = deleteMessageWorld();
    $this->actingAs($w['user']);

    Livewire::test(SmsConversation::class, ['threadId' => $w['thread']->id])
        ->call('confirmDeleteMessage', $w['elsewhere']->id)
        ->assertSet('deletingMessageId', null)
        ->assertSet('showDeleteMessageConfirm', false)
        // Even with the id forced in, the delete looks it up in this thread only.
        ->set('deletingMessageId', $w['elsewhere']->id)
        ->call('deleteMessage');

    expect(SmsMessage::find($w['elsewhere']->id))->not->toBeNull();
});

it('never lets a client delete a message', function () {
    $w = deleteMessageWorld();
    $client = Client::query()->create(['business_name' => 'Abbey Household']);
    $clientUser = User::query()->create(['first_name' => 'Erin', 'last_name' => 'Abbey', 'email' => 'erin-'.uniqid().'@example.com', 'cell_phone' => '8475550101', 'primary_vendor_id' => null]);
    $clientUser->clients()->attach($client->id);
    $this->actingAs($clientUser->fresh());

    Livewire::test(SmsConversation::class, ['threadId' => $w['thread']->id])
        ->call('confirmDeleteMessage', $w['last']->id)
        ->assertStatus(403);

    expect(SmsMessage::find($w['last']->id))->not->toBeNull();
});

it('still deletes a whole thread and its messages for good', function () {
    $w = deleteMessageWorld();
    app(ThreadAdminActions::class)->deleteMessage($w['first'], $w['user']);

    app(ThreadAdminActions::class)->delete($w['thread']);

    expect(SmsMessage::withTrashed()->where('thread_id', $w['thread']->id)->count())->toBe(0)
        ->and(SmsMessage::withTrashed()->whereIn('id', [$w['first']->id, $w['last']->id])->count())->toBe(0)
        ->and(SmsMessage::find($w['elsewhere']->id))->not->toBeNull();
});

/*
 * Reactions and iMessage edit notices quote their text. Deleting the text
 * deletes them with it, and one that arrives after its text was deleted is
 * dropped as it lands (Patryk, 2026-10-26).
 */

const PINSTRIPES = 'I was going to offer you a comped dinner at pinstripes in Georgetown but the owner packed his bags a year after going public, sold it, and let everyone go 😢😭🥺';

function pinstripesThread(): array
{
    $w = deleteMessageWorld();
    $w['pinstripes'] = SmsMessage::query()->create(['thread_id' => $w['thread']->id, 'direction' => SmsMessage::DIRECTION_OUTBOUND, 'from_number' => '+12247354200', 'text' => PINSTRIPES, 'status' => 'delivered', 'sent_by_user_id' => $w['user']->id, 'created_at' => now()->addMinute()]);

    return $w;
}

it('deletes the reactions to a text with it, and keeps reactions to other texts', function () {
    $w = pinstripesThread();
    $emphasized = SmsMessage::query()->create(['thread_id' => $w['thread']->id, 'direction' => SmsMessage::DIRECTION_INBOUND, 'from_number' => '+18475550101',
        'text' => 'Emphasized “I was going to offer you a comped dinner at pinstripes in Georgetown but the owner packed his bags a year af…”', 'status' => 'received', 'created_at' => now()->addMinutes(2)]);
    $likedOther = SmsMessage::query()->create(['thread_id' => $w['thread']->id, 'direction' => SmsMessage::DIRECTION_OUTBOUND, 'from_number' => '+12247354200',
        'text' => 'Liked “Can you come Thursday?”', 'status' => 'delivered', 'created_at' => now()->addMinutes(3)]);

    app(ThreadAdminActions::class)->deleteMessage($w['pinstripes'], $w['user']);

    expect(SmsMessage::withTrashed()->find($emphasized->id)->trashed())->toBeTrue()
        ->and(SmsMessage::withTrashed()->find($emphasized->id)->deleted_by_user_id)->toBe($w['user']->id)
        ->and(SmsMessage::withTrashed()->find($emphasized->id)->deleted_with_message_id)->toBe($w['pinstripes']->id)
        ->and(SmsMessage::withTrashed()->find($w['pinstripes']->id)->deleted_with_message_id)->toBeNull()
        ->and(SmsMessage::find($likedOther->id))->not->toBeNull()
        ->and(SmsMessage::find($w['first']->id))->not->toBeNull();
});

it('deletes an iMessage edit notice of the text with it', function () {
    $w = deleteMessageWorld();
    $edit = SmsMessage::query()->create(['thread_id' => $w['thread']->id, 'direction' => SmsMessage::DIRECTION_INBOUND, 'from_number' => '+18475550101',
        'text' => 'Edited to “Can you come Friday?”', 'status' => 'received', 'created_at' => now()->addMinutes(2)]);

    app(ThreadAdminActions::class)->deleteMessage($w['first'], $w['user']);

    expect(SmsMessage::withTrashed()->find($edit->id)->trashed())->toBeTrue();
});

it('drops a reaction that arrives after its text was deleted, and keeps one to a live text with the same words', function () {
    $w = pinstripesThread();
    app(ThreadAdminActions::class)->deleteMessage($w['pinstripes'], $w['user']);

    // Erin emphasizes the deleted text a minute later.
    $late = SmsMessage::query()->create(['thread_id' => $w['thread']->id, 'direction' => SmsMessage::DIRECTION_INBOUND, 'from_number' => '+18475550101',
        'text' => 'Emphasized “I was going to offer you a comped dinner at pinstripes in Georgetown but the owner packed his bags a year af…”', 'status' => 'received']);

    expect($late->trashed())->toBeTrue()
        ->and(SmsMessage::withTrashed()->find($late->id)->deleted_by_user_id)->toBe($w['user']->id)
        ->and(SmsMessage::withTrashed()->find($late->id)->deleted_with_message_id)->toBe($w['pinstripes']->id);

    // The same words are sent again and stay; a reaction to them is kept.
    $resent = SmsMessage::query()->create(['thread_id' => $w['thread']->id, 'direction' => SmsMessage::DIRECTION_OUTBOUND, 'from_number' => '+12247354200', 'text' => PINSTRIPES, 'status' => 'delivered', 'created_at' => now()->addMinutes(5)]);
    $loved = SmsMessage::query()->create(['thread_id' => $w['thread']->id, 'direction' => SmsMessage::DIRECTION_INBOUND, 'from_number' => '+18475550101',
        'text' => 'Loved “I was going to offer you a comped dinner at pinstripes in Georgetown but the owner packed his bags a year af…”', 'status' => 'received', 'created_at' => now()->addMinutes(6)]);
    expect($loved->trashed())->toBeFalse()
        ->and($resent->trashed())->toBeFalse();

    // Ordinary texts are never touched by the check.
    $plain = SmsMessage::query()->create(['thread_id' => $w['thread']->id, 'direction' => SmsMessage::DIRECTION_INBOUND, 'from_number' => '+18475550101', 'text' => 'Haha no worries', 'status' => 'received']);
    expect($plain->trashed())->toBeFalse();
});

it('keeps folding reactions into their text in the conversation', function () {
    $w = pinstripesThread();
    SmsMessage::query()->create(['thread_id' => $w['thread']->id, 'direction' => SmsMessage::DIRECTION_INBOUND, 'from_number' => '+18475550101',
        'text' => 'Emphasized “I was going to offer you a comped dinner at pinstripes in Georgetown but the owner packed his bags a year af…”', 'status' => 'received', 'created_at' => now()->addMinutes(2)]);
    $this->actingAs($w['user']);

    $processed = Livewire::test(SmsConversation::class, ['threadId' => $w['thread']->id])->instance()->processedMessages;

    expect($processed['visible']->pluck('id')->all())->toContain($w['pinstripes']->id)
        ->and($processed['reactions'][$w['pinstripes']->id] ?? [])->toHaveKey('‼️');
});

it('brings a restored text back with its reactions — the ones deleted with it and the ones that came after', function () {
    $w = pinstripesThread();
    $emphasized = SmsMessage::query()->create(['thread_id' => $w['thread']->id, 'direction' => SmsMessage::DIRECTION_INBOUND, 'from_number' => '+18475550101',
        'text' => 'Emphasized “I was going to offer you a comped dinner at pinstripes in Georgetown but the owner packed his bags a year af…”', 'status' => 'received', 'created_at' => now()->addMinutes(2)]);
    $unrelatedGone = SmsMessage::query()->create(['thread_id' => $w['thread']->id, 'direction' => SmsMessage::DIRECTION_INBOUND, 'from_number' => '+18475550101', 'text' => 'Wrong thread, ignore', 'status' => 'received', 'created_at' => now()->addMinutes(3)]);

    app(ThreadAdminActions::class)->deleteMessage($w['pinstripes'], $w['user']);
    app(ThreadAdminActions::class)->deleteMessage($unrelatedGone, $w['user']);

    // A second person reacts after the text was deleted: dropped, but linked to it.
    $laughed = SmsMessage::query()->create(['thread_id' => $w['thread']->id, 'direction' => SmsMessage::DIRECTION_INBOUND, 'from_number' => '+18475550109',
        'text' => 'Laughed at “I was going to offer you a comped dinner at pinstripes in Georgetown but the owner packed his bags a year af…”', 'status' => 'received', 'created_at' => now()->addMinutes(4)]);
    expect($laughed->trashed())->toBeTrue();

    $back = app(ThreadAdminActions::class)->restoreMessage($w['pinstripes']->fresh());

    expect($back)->toBe(3, 'the text and its two reactions')
        ->and(SmsMessage::find($w['pinstripes']->id))->not->toBeNull()
        ->and(SmsMessage::find($emphasized->id))->not->toBeNull()
        ->and(SmsMessage::find($laughed->id))->not->toBeNull()
        ->and(SmsMessage::find($laughed->id)->deleted_with_message_id)->toBeNull()
        ->and(SmsMessage::find($laughed->id)->deleted_by_user_id)->toBeNull()
        // A text deleted on its own is not part of this one.
        ->and(SmsMessage::find($unrelatedGone->id))->toBeNull();

    // And the thread folds both reactions into it again.
    $this->actingAs($w['user']);
    $processed = Livewire::test(SmsConversation::class, ['threadId' => $w['thread']->id])->instance()->processedMessages;
    expect($processed['visible']->pluck('id')->all())->toContain($w['pinstripes']->id)
        ->not->toContain($emphasized->id)
        ->not->toContain($laughed->id)
        ->and(array_keys($processed['reactions'][$w['pinstripes']->id] ?? []))->toEqualCanonicalizing(['‼️', '😂']);
});

it('puts two people\'s reactions to one long text on that text, not on each other', function () {
    $w = pinstripesThread();
    SmsMessage::query()->create(['thread_id' => $w['thread']->id, 'direction' => SmsMessage::DIRECTION_INBOUND, 'from_number' => '+18475550101',
        'text' => 'Emphasized “I was going to offer you a comped dinner at pinstripes in Georgetown but the owner packed his bags a year af…”', 'status' => 'received', 'created_at' => now()->addMinutes(2)]);
    SmsMessage::query()->create(['thread_id' => $w['thread']->id, 'direction' => SmsMessage::DIRECTION_INBOUND, 'from_number' => '+18475550109',
        'text' => 'Laughed at “I was going to offer you a comped dinner at pinstripes in Georgetown but the owner packed his bags a year af…”', 'status' => 'received', 'created_at' => now()->addMinutes(3)]);
    $this->actingAs($w['user']);

    $processed = Livewire::test(SmsConversation::class, ['threadId' => $w['thread']->id])->instance()->processedMessages;

    expect(array_keys($processed['reactions']))->toBe([$w['pinstripes']->id])
        ->and(array_keys($processed['reactions'][$w['pinstripes']->id]))->toEqualCanonicalizing(['‼️', '😂']);
});

