<?php

use App\Console\Commands\SendScheduledSmsMessages;
use App\Http\Resources\Api\App\MessageResource;
use App\Jobs\SendGroupMms;
use App\Livewire\Sms\SmsConversation;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\SmsThreadParticipant;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Sms\ScheduledMessageActions;
use App\Services\SmsTranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Two things Patryk asked for on 2026-10-27, off thread 305 (Arturo, who
 * reads Spanish): a text typed in English goes out in English — "we got rid
 * of translating messages for now, same should be true for scheduled
 * messages" — and a text scheduled at night for 8 am shows in the thread at
 * 8 am, where it went out, not at 10 pm where it was typed ("it shouldn't be
 * created_at, that should remain, but show 8 AM": SmsMessage::shown_at).
 */

/** @return array{user: User, thread: SmsGroupThread} */
function outboundWorld(): array
{
    // GS is vendor 1, as in every other app-API test: thread visibility (SmsGroupThread::visibleToVendor) is keyed on it.
    $gs = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $sub = Vendor::factory()->create(['business_name' => 'Arturo Cardona Demolition']);

    $patryk = User::query()->create([
        'first_name' => 'Patryk', 'last_name' => 'Tester', 'email' => 'outbound-'.uniqid().'@example.com',
        'cell_phone' => '2245559000', 'primary_vendor_id' => $gs->id, 'preferred_language' => 'English',
    ]);
    $arturo = User::query()->create([
        'first_name' => 'Arturo', 'last_name' => 'Cardona', 'email' => 'arturo-'.uniqid().'@example.com',
        'cell_phone' => '7735550001', 'primary_vendor_id' => $sub->id, 'preferred_language' => 'Spanish',
    ]);
    $gs->users()->attach($patryk->id, ['role_id' => 1, 'is_employed' => 1]);
    $sub->users()->attach($arturo->id, ['role_id' => 1, 'is_employed' => 1]);

    $thread = SmsGroupThread::query()->create([
        'name' => 'Arturo Cardona', 'from_number' => '+12247354200', 'participants' => ['+17735550001'],
        'vendor_id' => $gs->id, 'subject_vendor_id' => $sub->id, 'last_activity_at' => now(),
    ]);
    SmsThreadParticipant::query()->create(['thread_id' => $thread->id, 'phone_number' => '+17735550001', 'opted_in_at' => now()]);

    test()->actingAs($patryk);
    Bus::fake([SendGroupMms::class]);
    // One Telnyx fake for the whole test — a later Http::fake() never overrides an earlier stub.
    Http::fake(['api.telnyx.com/*' => Http::response(['data' => ['id' => 'tx-1', 'record_type' => 'message', 'to' => [['phone_number' => '+17735550001', 'status' => 'queued']]]])]);

    return ['user' => $patryk, 'thread' => $thread];
}

it('sends, schedules and edits a text as typed — in English — even to a sub who reads Spanish', function () {
    ['thread' => $thread] = outboundWorld();
    $this->partialMock(SmsTranslationService::class, fn ($mock) => $mock->shouldNotReceive('translate'));

    // Send now.
    Livewire::test(SmsConversation::class, ['threadId' => $thread->id])
        ->set('newMessage', 'Only carpet and ceiling tiles. 500 square feet')
        ->call('sendMessage')
        ->assertHasNoErrors();
    $sent = SmsMessage::query()->where('thread_id', $thread->id)->latest('id')->first();
    expect($sent->text)->toBe("Only carpet and ceiling tiles. 500 square feet\n-GSC")
        ->and(data_get($sent->raw_payload, 'recipient_language'))->toBe('Spanish')
        ->and(data_get($sent->raw_payload, 'original_text'))->toBe('Only carpet and ceiling tiles. 500 square feet');

    // Schedule for tomorrow 8 am.
    Livewire::test(SmsConversation::class, ['threadId' => $thread->id])
        ->set('newMessage', 'Start with the basement stairs')
        ->call('scheduleMessage', 'tomorrow_8am')
        ->assertHasNoErrors();
    $scheduled = SmsMessage::query()->where('thread_id', $thread->id)->where('status', 'scheduled')->sole();
    expect($scheduled->text)->toBe("Start with the basement stairs\n-GSC")
        ->and($scheduled->scheduled_at->timezone('America/Chicago')->format('g:i A'))->toBe('8:00 AM');

    // Edit the scheduled text.
    app(ScheduledMessageActions::class)->edit($scheduled, 'Start with the basement stairs, then the hall', app(SmsTranslationService::class));
    expect($scheduled->fresh()->text)->toBe("Start with the basement stairs, then the hall\n-GSC");
});

it('translates again only when sms.translate_outbound is switched back on', function () {
    ['thread' => $thread] = outboundWorld();
    config(['sms.translate_outbound' => true]);
    $this->partialMock(SmsTranslationService::class, function ($mock) {
        $mock->shouldReceive('translate')->once()->with('Only carpet and ceiling tiles', 'Spanish', 'English')->andReturn('Solo alfombra y losetas de techo');
    });

    Livewire::test(SmsConversation::class, ['threadId' => $thread->id])
        ->set('newMessage', 'Only carpet and ceiling tiles')
        ->call('scheduleMessage', 'tomorrow_8am')
        ->assertHasNoErrors();

    expect(SmsMessage::query()->where('thread_id', $thread->id)->where('status', 'scheduled')->sole()->text)->toBe("Solo alfombra y losetas de techo\n-GSC");
});

it('shows a scheduled text at the time it went out — created_at stays when it was typed', function () {
    ['user' => $user, 'thread' => $thread] = outboundWorld();
    $this->freezeSecond();

    // created_at is not mass-assignable on SmsMessage: stamp the "typed at" times with forceFill.
    $typedAt = now()->subHours(10);
    SmsMessage::query()->create([
        'thread_id' => $thread->id, 'direction' => SmsMessage::DIRECTION_OUTBOUND, 'from_number' => '+12247354200', 'to_numbers' => ['+17735550001'],
        'text' => "Only carpet and ceiling tiles\n-PS", 'status' => 'scheduled', 'scheduled_at' => now()->subMinute(), 'sent_by_user_id' => $user->id,
    ])->forceFill(['created_at' => $typedAt])->save();
    SmsMessage::query()->create([
        'thread_id' => $thread->id, 'direction' => SmsMessage::DIRECTION_OUTBOUND, 'from_number' => '+12247354200', 'to_numbers' => ['+17735550001'],
        'text' => "500 square feet\n-PS", 'status' => 'scheduled', 'scheduled_at' => now()->subMinute(), 'sent_by_user_id' => $user->id,
    ])->forceFill(['created_at' => $typedAt->copy()->addMinute()])->save();
    SmsMessage::query()->create([
        'thread_id' => $thread->id, 'direction' => SmsMessage::DIRECTION_INBOUND, 'from_number' => '+17735550001', 'to_numbers' => ['+12247354200'],
        'text' => 'Good morning, the demolition is confirmed.', 'status' => 'received',
    ])->forceFill(['created_at' => now()->subHours(2)])->save();
    [$first, $second, $reply] = SmsMessage::query()->where('thread_id', $thread->id)->orderBy('id')->get()->all();

    $this->artisan(SendScheduledSmsMessages::class)->assertSuccessful();
    Bus::assertDispatchedTimes(SendGroupMms::class, 2);

    $first->refresh();
    $second->refresh();
    expect($first->status)->toBe('sending')
        ->and($first->created_at->equalTo($typedAt))->toBeTrue('created_at stays the moment it was typed')
        ->and($first->scheduled_at->equalTo(now()->subMinute()))->toBeTrue()
        ->and($first->shown_at->equalTo(now()->subMinute()))->toBeTrue()
        ->and($reply->shown_at->equalTo($reply->created_at))->toBeTrue('a plain text is shown at its own time');

    // The thread (oldest first) reads: the reply at 6 am, then the two texts at 8 am in the order they were typed — not the texts at 10 pm the night before.
    $conversation = Livewire::test(SmsConversation::class, ['threadId' => $thread->id]);
    expect($conversation->instance()->processedMessages['visible']->pluck('id')->take(3)->all())->toBe([$reply->id, $first->id, $second->id]);

    // And the bubble is labelled with the time it went out, not the time it was typed.
    $tz = browser_timezone();
    $conversation->assertSee($first->scheduled_at->copy()->setTimezone($tz)->format('g:i A'))
        ->assertDontSee($typedAt->copy()->setTimezone($tz)->format('g:i A'));

    // The iPhone app gets the same time and the same order.
    expect((new MessageResource($first))->toArray(request())['sent_at'])->toBe($first->scheduled_at->toIso8601String());
    Sanctum::actingAs($user, ['app']);
    $page = $this->getJson("/api/app/v1/threads/{$thread->id}/messages")->assertOk()->json('data');
    expect(collect($page)->pluck('id')->all())->toBe([$reply->id, $first->id, $second->id]);
});

it('gives a scheduled draft sent by hand the moment it went out', function () {
    ['user' => $user, 'thread' => $thread] = outboundWorld();
    $this->freezeSecond();

    $draft = SmsMessage::query()->create([
        'thread_id' => $thread->id, 'direction' => SmsMessage::DIRECTION_OUTBOUND, 'from_number' => '+12247354200', 'to_numbers' => ['+17735550001'],
        'text' => "Draft\n-GSC", 'status' => 'scheduled', 'scheduled_at' => null, 'sent_by_user_id' => $user->id,
    ]);
    $draft->forceFill(['created_at' => now()->subDay()])->save();

    app(ScheduledMessageActions::class)->sendNow($draft, $user->id);

    $draft->refresh();
    expect($draft->status)->toBe('sending')
        ->and($draft->created_at->equalTo(now()->subDay()))->toBeTrue()
        ->and($draft->scheduled_at->equalTo(now()))->toBeTrue()
        ->and($draft->shown_at->equalTo(now()))->toBeTrue();
});

it('keeps what the composer recorded — the English typed, the languages — when Telnyx answers', function () {
    ['user' => $user, 'thread' => $thread] = outboundWorld();
    config(['services.telnyx.api_key' => 'test-key', 'services.telnyx.messaging_profile_id' => 'profile-1', 'services.telnyx.dev_to' => null]);

    $message = SmsMessage::query()->create([
        'thread_id' => $thread->id, 'direction' => SmsMessage::DIRECTION_OUTBOUND, 'from_number' => '+12247354200', 'to_numbers' => ['+17735550001'],
        'text' => "Solo alfombra\n-GSC", 'status' => 'sending', 'sent_by_user_id' => $user->id,
        'raw_payload' => ['original_text' => 'Only carpet', 'sender_language' => 'English', 'recipient_language' => 'Spanish', 'source' => 'conversation_schedule_menu'],
    ]);

    (new SendGroupMms($message->id))->handle();

    $message->refresh();
    expect($message->status)->toBe('sent')
        ->and($message->provider_message_id)->toBe('tx-1')
        ->and(data_get($message->raw_payload, 'original_text'))->toBe('Only carpet')
        ->and(data_get($message->raw_payload, 'recipient_language'))->toBe('Spanish')
        ->and(data_get($message->raw_payload, 'record_type'))->toBe('message');

    // Every reader sees the English that was typed, not a round-trip of the Spanish.
    $this->partialMock(SmsTranslationService::class, fn ($mock) => $mock->shouldNotReceive('translate'));
    Livewire::test(SmsConversation::class, ['threadId' => $thread->id])->assertSee('Only carpet')->assertDontSee('Solo alfombra');
});
