<?php

use App\Livewire\Sms\SmsConversation;
use App\Livewire\Sms\SmsNewThread;
use App\Models\BlockedCaller;
use App\Models\Client;
use App\Models\Project;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\SmsThreadParticipant;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use App\Services\GroupSmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

it('filters client users down to actual thread participants', function (): void {
    $participant = new User([
        'first_name' => 'Mary',
        'last_name' => 'Participant',
        'cell_phone' => '2245550001',
    ]);

    $nonParticipant = new User([
        'first_name' => 'Jon',
        'last_name' => 'Nonparticipant',
        'cell_phone' => '2245550002',
    ]);

    // Moved (verbatim) to ConversationPresenter so the offline fragment
    // endpoint shares the same participant filtering.
    $filteredUsers = \App\Support\Sms\ConversationPresenter::filterClientUsersToThreadParticipants(
        [$participant, $nonParticipant],
        ['+12245550001'],
    );

    expect($filteredUsers->pluck('first_name')->all())->toBe(['Mary']);
});

it('opens the media lightbox when a video is requested', function (): void {
    $component = new SmsConversation();

    $component->openVideoLightbox('sms-media/2026/05/demo.mp4');

    expect($component->showImageLightbox)->toBeTrue();
    expect($component->lightboxImageUrl)->toBe('sms-media/2026/05/demo.mp4');
});

it('allows common video formats in the sms attachment validation rule', function (): void {
    $rule = SmsConversation::attachmentValidationRule();

    expect($rule)->not->toContain('max:');
    expect($rule)->toContain('mp4');
    expect($rule)->toContain('mov');
    expect($rule)->toContain('webm');
    expect($rule)->toContain('m4v');
    expect($rule)->toContain('3gp');
    expect($rule)->toContain('avi');
});

it('decodes html entities in forward thread labels', function (): void {
    $component = new SmsConversation();
    $thread = new SmsGroupThread(['name' => 'Joanie &amp; John Delaney']);

    expect($component->forwardThreadLabel($thread))->toBe('Joanie & John Delaney');
});

it('decodes double-encoded html entities in forward thread labels', function (): void {
    $component = new SmsConversation();
    $thread = new SmsGroupThread(['name' => 'Mark &amp;amp; Gail Brodson']);

    expect($component->forwardThreadLabel($thread))->toBe('Mark & Gail Brodson');
});

describe('forwarding messages', function (): void {
    uses(RefreshDatabase::class);

    function makeForwardingFixture(): array
    {
        $ownerVendor = Vendor::factory()->create(['business_name' => 'GS Construction']);
        $subjectVendor = Vendor::factory()->create(['business_name' => 'Smartech Electric']);

        $user = User::query()->create([
            'first_name' => 'Patryk',
            'last_name' => 'Tester',
            'email' => 'forward-test-' . uniqid() . '@example.com',
            'cell_phone' => '2245551111',
            'primary_vendor_id' => $ownerVendor->id,
        ]);

        $source = SmsGroupThread::query()->create([
            'name' => 'Source Thread',
            'from_number' => '+12245554444',
            'participants' => ['+12245550001'],
            'vendor_id' => $ownerVendor->id,
            'subject_vendor_id' => $subjectVendor->id,
            'last_activity_at' => now(),
        ]);

        $target = SmsGroupThread::query()->create([
            'name' => 'Target Thread',
            'from_number' => '+12245554444',
            'participants' => ['+12245550002'],
            'vendor_id' => $ownerVendor->id,
            'subject_vendor_id' => $subjectVendor->id,
            'last_activity_at' => now()->subMinute(),
        ]);

        SmsThreadParticipant::query()->create([
            'thread_id' => $source->id,
            'phone_number' => '+12245550001',
            'opted_in_at' => now(),
        ]);

        SmsThreadParticipant::query()->create([
            'thread_id' => $target->id,
            'phone_number' => '+12245550002',
            'opted_in_at' => now(),
        ]);

        $msg1 = SmsMessage::query()->create([
            'thread_id' => $source->id,
            'direction' => SmsMessage::DIRECTION_INBOUND,
            'from_number' => '+12245550001',
            'to_number' => '+12245554444',
            'text' => 'First message',
            'status' => 'received',
        ]);

        $msg2 = SmsMessage::query()->create([
            'thread_id' => $source->id,
            'direction' => SmsMessage::DIRECTION_INBOUND,
            'from_number' => '+12245550001',
            'to_number' => '+12245554444',
            'text' => 'Second message',
            'status' => 'received',
        ]);

        return compact('user', 'source', 'target', 'msg1', 'msg2');
    }

    it('renders the composer with a stable thread key', function (): void {
        ['user' => $user, 'source' => $source] = makeForwardingFixture();
        $this->actingAs($user);

        Livewire::test(SmsConversation::class, ['threadId' => $source->id])
            ->assertSeeHtml('wire:key="sms-composer-' . $source->id . '"');
    });

    it('blocks client users from entering selection mode', function (): void {
        ['source' => $source] = makeForwardingFixture();

        $client = Client::query()->create(['business_name' => 'Acme Client']);
        $clientUser = User::query()->create([
            'first_name' => 'Mary',
            'last_name' => 'Client',
            'email' => 'mary-client-' . uniqid() . '@example.com',
            'cell_phone' => '2245559999',
            'primary_vendor_id' => null,
        ]);
        $clientUser->clients()->attach($client->id);

        expect($clientUser->fresh()->is_browsing_as_client)->toBeTrue();

        $this->actingAs($clientUser->fresh());

        Livewire::test(SmsConversation::class, ['threadId' => $source->id])
            ->call('enterSelectionMode')
            ->assertStatus(403);
    });

    it('toggles message selection on and off', function (): void {
        ['user' => $user, 'source' => $source, 'msg1' => $msg1] = makeForwardingFixture();
        $this->actingAs($user);

        Livewire::test(SmsConversation::class, ['threadId' => $source->id])
            ->call('enterSelectionMode')
            ->assertSet('selectionMode', true)
            ->call('toggleSelectMessage', $msg1->id)
            ->assertSet('selectedMessageIds', [$msg1->id])
            ->call('toggleSelectMessage', $msg1->id)
            ->assertSet('selectedMessageIds', []);
    });

    it('excludes the current thread from forwardable targets', function (): void {
        ['user' => $user, 'source' => $source, 'target' => $target] = makeForwardingFixture();
        $this->actingAs($user);

        $component = Livewire::test(SmsConversation::class, ['threadId' => $source->id]);
        $threads = $component->instance()->forwardableThreads;

        expect($threads->pluck('id')->all())
            ->toContain($target->id)
            ->not->toContain($source->id);
    });

    it('forwards selected messages to the target thread via the SMS service', function (): void {
        ['user' => $user, 'source' => $source, 'target' => $target, 'msg1' => $msg1, 'msg2' => $msg2]
            = makeForwardingFixture();

        $service = mock(GroupSmsService::class);
        $service->shouldReceive('sendToThread')
            ->once()
            ->andReturnUsing(function (SmsGroupThread $thread, string $text, array $media, ?int $userId) use ($target, $user) {
                expect($thread->id)->toBe($target->id);
                expect($userId)->toBe($user->id);
                expect($text)->toContain('Forwarded');

                return new SmsMessage();
            });
        app()->instance(GroupSmsService::class, $service);

        $this->actingAs($user);

        Livewire::test(SmsConversation::class, ['threadId' => $source->id])
            ->call('enterSelectionMode')
            ->call('toggleSelectMessage', $msg1->id)
            ->call('toggleSelectMessage', $msg2->id)
            ->call('openForwardModal')
            ->assertSet('showForwardModal', true)
            ->set('forwardTargetThreadId', $target->id)
            ->call('forwardMessages')
            ->assertHasNoErrors()
            ->assertSet('showForwardModal', false)
            ->assertSet('selectionMode', false)
            ->assertSet('selectedMessageIds', [])
            ->assertDispatched('sms-selection-cleared');
    });

    it('rejects forwarding to the same thread', function (): void {
        ['user' => $user, 'source' => $source, 'msg1' => $msg1] = makeForwardingFixture();
        $this->actingAs($user);

        Livewire::test(SmsConversation::class, ['threadId' => $source->id])
            ->call('enterSelectionMode')
            ->call('toggleSelectMessage', $msg1->id)
            ->call('openForwardModal')
            ->set('forwardTargetThreadId', $source->id)
            ->call('forwardMessages')
            ->assertHasErrors('forwardTargetThreadId');
    });

    it('opens forward modal with selected ids in one action', function (): void {
        ['user' => $user, 'source' => $source, 'msg1' => $msg1, 'msg2' => $msg2] = makeForwardingFixture();
        $this->actingAs($user);

        Livewire::test(SmsConversation::class, ['threadId' => $source->id])
            ->call('enterSelectionMode')
            ->call('openForwardModalWithSelection', [$msg1->id, (string) $msg2->id])
            ->assertSet('selectedMessageIds', [$msg1->id, $msg2->id])
            ->assertSet('showForwardModal', true);
    });

    it('does not reopen forward modal when selection mode has ended', function (): void {
        ['user' => $user, 'source' => $source, 'msg1' => $msg1] = makeForwardingFixture();
        $this->actingAs($user);

        Livewire::test(SmsConversation::class, ['threadId' => $source->id])
            ->call('enterSelectionMode')
            ->call('exitSelectionMode')
            ->call('openForwardModalWithSelection', [$msg1->id])
            ->assertSet('showForwardModal', false)
            ->assertSet('selectedMessageIds', []);
    });

    it('includes image attachments in grouped forwards', function (): void {
        ['user' => $user, 'source' => $source, 'target' => $target, 'msg1' => $msg1, 'msg2' => $msg2]
            = makeForwardingFixture();

        $msg1->update(['media_urls' => ['/storage/sms-attachments/photo-1.jpg']]);
        $msg2->update([
            'raw_payload' => [
                'media' => [
                    ['url' => '/storage/sms-attachments/photo-2.jpg'],
                ],
            ],
        ]);

        $service = mock(GroupSmsService::class);
        $service->shouldReceive('sendToThread')
            ->once()
            ->andReturnUsing(function (SmsGroupThread $thread, string $text, array $media, ?int $userId) use ($target, $user) {
                expect($thread->id)->toBe($target->id);
                expect($userId)->toBe($user->id);
                expect($text)->toContain('Forwarded');
                expect($media)->toContain('/storage/sms-attachments/photo-1.jpg');
                expect($media)->toContain('/storage/sms-attachments/photo-2.jpg');

                return new SmsMessage();
            });
        app()->instance(GroupSmsService::class, $service);

        $this->actingAs($user);

        Livewire::test(SmsConversation::class, ['threadId' => $source->id])
            ->call('enterSelectionMode')
            ->call('toggleSelectMessage', $msg1->id)
            ->call('toggleSelectMessage', $msg2->id)
            ->call('openForwardModal')
            ->assertSet('showForwardModal', true)
            ->set('forwardTargetThreadId', $target->id)
            ->call('forwardMessages')
            ->assertHasNoErrors();
    });

    it('shows original text when stored translated content is a prompt artifact', function (): void {
        ['user' => $user, 'source' => $source] = makeForwardingFixture();

        $message = SmsMessage::query()->create([
            'thread_id' => $source->id,
            'direction' => SmsMessage::DIRECTION_OUTBOUND,
            'from_number' => '+12245554444',
            'to_number' => '+12245550001',
            'text' => 'Please provide the SMS text you would like translated.',
            'status' => 'sent',
            'raw_payload' => [
                'original_text' => 'Start',
                'sender_language' => 'English',
                'recipient_language' => 'Polish',
            ],
        ]);

        $this->actingAs($user);

        $component = Livewire::test(SmsConversation::class, ['threadId' => $source->id]);
        $visible = $component->instance()->processedMessages['visible'];
        $rendered = $visible->firstWhere('id', $message->id);

        expect($rendered)->not->toBeNull();
        expect($rendered->translated_display_text)->toBe('Start');
    });

    it('adds a language badge and original toggle metadata when source language differs from viewer', function (): void {
        ['user' => $user, 'source' => $source] = makeForwardingFixture();

        $message = SmsMessage::query()->create([
            'thread_id' => $source->id,
            'direction' => SmsMessage::DIRECTION_OUTBOUND,
            'from_number' => '+12245554444',
            'to_number' => '+12245550001',
            'text' => 'Good morning',
            'status' => 'sent',
            'raw_payload' => [
                'original_text' => 'Dzien dobry',
                'sender_language' => 'Polish',
                'recipient_language' => 'English',
            ],
        ]);

        $this->actingAs($user);

        $component = Livewire::test(SmsConversation::class, ['threadId' => $source->id]);
        $visible = $component->instance()->processedMessages['visible'];
        $rendered = $visible->firstWhere('id', $message->id);

        expect($rendered)->not->toBeNull();
        expect($rendered->language_badge)->toBe('PL');
        expect($rendered->show_original_toggle)->toBeTrue();
        expect($rendered->original_display_text)->toBe('Dzien dobry');
    });

    it('does not mark English outbound text as Polish from sender preference metadata', function (): void {
        ['user' => $user, 'source' => $source] = makeForwardingFixture();

        $message = SmsMessage::query()->create([
            'thread_id' => $source->id,
            'direction' => SmsMessage::DIRECTION_OUTBOUND,
            'from_number' => '+12245554444',
            'to_number' => '+12245550001',
            'text' => "Hi Bonnie, can you please send your husband's contact info?",
            'status' => 'sent',
            'raw_payload' => [
                'original_text' => "Hi Bonnie, can you please send your husband's contact info?",
                'sender_language' => 'Polish',
                'recipient_language' => 'English',
            ],
        ]);

        $this->actingAs($user);

        $component = Livewire::test(SmsConversation::class, ['threadId' => $source->id]);
        $visible = $component->instance()->processedMessages['visible'];
        $rendered = $visible->firstWhere('id', $message->id);

        expect($rendered)->not->toBeNull();
        expect($rendered->language_badge)->toBeNull();
        expect($rendered->show_original_toggle)->toBeFalse();
    });

    it('does not translate schedule modal messages in conversation view', function (): void {
        ['user' => $user, 'source' => $source] = makeForwardingFixture();

        $user->update(['preferred_language' => 'Polish']);

        $scheduleBody = "Upcoming tasks:\n\nNext on Thursday 02/07:\n- Measure Windows\n1400 Kenilwood Lane\nRiverwoods, IL 60015\n- Measure Windows\n3154 Violet Ln\nNorthbrook, IL 60062\n\nSee the schedule: https://hive.contractors/v/86cb28b368149494";

        $message = SmsMessage::query()->create([
            'thread_id' => $source->id,
            'direction' => SmsMessage::DIRECTION_OUTBOUND,
            'from_number' => '+12245554444',
            'to_number' => '+12245550001',
            'text' => $scheduleBody,
            'status' => 'sent',
            'raw_payload' => [
                'source' => 'send_schedule_modal',
                'original_text' => 'Nadchodzace zadania',
                'sender_language' => 'Polish',
                'recipient_language' => 'English',
            ],
        ]);

        $this->actingAs($user->fresh());

        $component = Livewire::test(SmsConversation::class, ['threadId' => $source->id]);
        $visible = $component->instance()->processedMessages['visible'];
        $rendered = $visible->firstWhere('id', $message->id);

        expect($rendered)->not->toBeNull();
        expect($rendered->translated_display_text)->toBe($scheduleBody);
        expect($rendered->original_display_text)->toBe($scheduleBody);
        expect($rendered->language_badge)->toBeNull();
        expect($rendered->show_original_toggle)->toBeFalse();
    });

    it('does not call the translation API for English messages viewed by an English user', function (): void {
        ['user' => $user, 'source' => $source] = makeForwardingFixture();

        config(['services.openai.api_key' => 'test-key']);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'SHOULD NOT BE USED']]],
            ]),
        ]);

        $message = SmsMessage::query()->create([
            'thread_id' => $source->id,
            'direction' => SmsMessage::DIRECTION_INBOUND,
            'from_number' => '+12245550001',
            'to_number' => '+12245554444',
            'text' => 'Hi, can you please send me the project schedule today?',
            'status' => 'received',
        ]);

        $this->actingAs($user);

        $component = Livewire::test(SmsConversation::class, ['threadId' => $source->id]);
        $rendered = $component->instance()->processedMessages['visible']->firstWhere('id', $message->id);

        expect($rendered)->not->toBeNull();
        expect($rendered->translated_display_text)->toBe('Hi, can you please send me the project schedule today?');
        Http::assertNothingSent();
    });

    it('still translates a clearly foreign message for an English viewer', function (): void {
        ['user' => $user, 'source' => $source] = makeForwardingFixture();

        config(['services.openai.api_key' => 'test-key']);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'Good morning, see you tomorrow']]],
            ]),
        ]);

        $message = SmsMessage::query()->create([
            'thread_id' => $source->id,
            'direction' => SmsMessage::DIRECTION_INBOUND,
            'from_number' => '+12245550001',
            'to_number' => '+12245554444',
            'text' => 'Dzień dobry, do zobaczenia jutro',
            'status' => 'received',
        ]);

        $this->actingAs($user);

        $component = Livewire::test(SmsConversation::class, ['threadId' => $source->id]);
        $rendered = $component->instance()->processedMessages['visible']->firstWhere('id', $message->id);

        expect($rendered)->not->toBeNull();
        expect($rendered->translated_display_text)->toBe('Good morning, see you tomorrow');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.openai.com'));
    });

    /*
     * Hive reads in English. Previously each viewer saw the thread rendered
     * into their OWN preferred language, so the same message said different
     * things to different colleagues and cost a translation per render.
     */
    it('shows a foreign message in English even when the viewer prefers Polish', function (): void {
        ['user' => $user, 'source' => $source] = makeForwardingFixture();

        $user->update(['preferred_language' => 'Polish']);

        config(['services.openai.api_key' => 'test-key']);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'Good morning, see you tomorrow']]],
            ]),
        ]);

        $message = SmsMessage::query()->create([
            'thread_id' => $source->id,
            'direction' => SmsMessage::DIRECTION_INBOUND,
            'from_number' => '+12245550001',
            'to_number' => '+12245554444',
            'text' => 'Dzień dobry, do zobaczenia jutro',
            'status' => 'received',
        ]);

        $this->actingAs($user->fresh());

        $component = Livewire::test(SmsConversation::class, ['threadId' => $source->id]);
        $rendered = $component->instance()->processedMessages['visible']->firstWhere('id', $message->id);

        expect($rendered)->not->toBeNull();
        // English, NOT the viewer's Polish.
        expect($rendered->translated_display_text)->toBe('Good morning, see you tomorrow');
        // ...and the badge is their own language, i.e. the control that puts
        // this one message into Polish.
        expect($rendered->language_badge)->toBe('PL');
    });

    it('gives an English viewer no badge on an English message', function (): void {
        ['user' => $user, 'source' => $source] = makeForwardingFixture();

        $message = SmsMessage::query()->create([
            'thread_id' => $source->id,
            'direction' => SmsMessage::DIRECTION_INBOUND,
            'from_number' => '+12245550001',
            'to_number' => '+12245554444',
            'text' => 'See you tomorrow',
            'status' => 'received',
        ]);

        $this->actingAs($user);

        $component = Livewire::test(SmsConversation::class, ['threadId' => $source->id]);
        $rendered = $component->instance()->processedMessages['visible']->firstWhere('id', $message->id);

        expect($rendered)->not->toBeNull();
        expect($rendered->language_badge)->toBeNull();
    });

    it('translates one message on demand for a non-English reader, and back again', function (): void {
        ['user' => $user, 'source' => $source] = makeForwardingFixture();

        $user->update(['preferred_language' => 'Polish']);

        config(['services.openai.api_key' => 'test-key']);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'Do zobaczenia jutro']]],
            ]),
        ]);

        $message = SmsMessage::query()->create([
            'thread_id' => $source->id,
            'direction' => SmsMessage::DIRECTION_INBOUND,
            'from_number' => '+12245550001',
            'to_number' => '+12245554444',
            'text' => 'See you tomorrow',
            'status' => 'received',
        ]);

        $this->actingAs($user->fresh());

        $component = Livewire::test(SmsConversation::class, ['threadId' => $source->id]);

        // The badge renders on an ENGLISH message for this reader — the case
        // the old original-only toggle could never offer them.
        $component->assertSeeHtml('toggleMessageTranslation('.$message->id.')');

        // Nothing is translated until asked — that is the point of sunsetting
        // the automatic pass.
        expect($component->instance()->viewerTranslations)->toBe([]);

        $component->call('toggleMessageTranslation', $message->id);
        expect($component->instance()->viewerTranslations[$message->id])->toBe('Do zobaczenia jutro');

        // Pressing again returns the message to English.
        $component->call('toggleMessageTranslation', $message->id);
        expect($component->instance()->viewerTranslations)->toBe([]);
    });

    it('does not translate on demand for an English reader', function (): void {
        ['user' => $user, 'source' => $source] = makeForwardingFixture();

        Http::fake();

        $message = SmsMessage::query()->create([
            'thread_id' => $source->id,
            'direction' => SmsMessage::DIRECTION_INBOUND,
            'from_number' => '+12245550001',
            'to_number' => '+12245554444',
            'text' => 'See you tomorrow',
            'status' => 'received',
        ]);

        $this->actingAs($user);

        Livewire::test(SmsConversation::class, ['threadId' => $source->id])
            ->call('toggleMessageTranslation', $message->id);

        Http::assertNothingSent();
    });

    it('prefers a cached english_text over guessing the language', function (): void {
        ['user' => $user, 'source' => $source] = makeForwardingFixture();

        Http::fake();

        // Unaccented Spanish: the keyword heuristic reads this as English and
        // leaves it alone, which is exactly why sms:backfill-english exists.
        $message = SmsMessage::query()->create([
            'thread_id' => $source->id,
            'direction' => SmsMessage::DIRECTION_INBOUND,
            'from_number' => '+12245550001',
            'to_number' => '+12245554444',
            'text' => 'Cuando nos vemos no hay prisa',
            'status' => 'received',
            'raw_payload' => ['english_text' => "When do we see each other? There's no rush."],
        ]);

        $this->actingAs($user);

        $component = Livewire::test(SmsConversation::class, ['threadId' => $source->id]);
        $rendered = $component->instance()->processedMessages['visible']->firstWhere('id', $message->id);

        expect($rendered->translated_display_text)->toBe("When do we see each other? There's no rush.");
        // Cached, so no API call is needed to render the thread.
        Http::assertNothingSent();
    });

    it('does not read Spanish as Polish because they share the letter o-acute', function (): void {
        ['user' => $user, 'source' => $source] = makeForwardingFixture();

        Http::fake();

        // "demostración" carries ó, which is in BOTH alphabets. While the
        // Polish character class was tested first and included it, this put a
        // PL badge on an entirely Spanish thread.
        $message = SmsMessage::query()->create([
            'thread_id' => $source->id,
            'direction' => SmsMessage::DIRECTION_OUTBOUND,
            'from_number' => '+12245554444',
            'to_number' => '+12245550001',
            'text' => '¿Puedo traer un cheque el día de la demostración o necesitas el dinero antes?',
            'status' => 'sent',
            'raw_payload' => ['english_text' => 'Can I bring a check on the day of the demonstration?'],
        ]);

        $this->actingAs($user);

        $component = Livewire::test(SmsConversation::class, ['threadId' => $source->id]);
        $rendered = $component->instance()->processedMessages['visible']->firstWhere('id', $message->id);

        expect($rendered->language_badge)->toBe('ES');
    });

    it('shows the same English text in the thread list and the conversation', function (): void {
        ['user' => $user, 'source' => $source] = makeForwardingFixture();

        Http::fake();

        // The card beside the conversation used to read display_text straight
        // off the row, so one message appeared in two languages at once.
        $message = SmsMessage::query()->create([
            'thread_id' => $source->id,
            'direction' => SmsMessage::DIRECTION_INBOUND,
            'from_number' => '+12245550001',
            'to_number' => '+12245554444',
            'text' => 'Cuando nos vemos no hay prisa',
            'status' => 'received',
            'raw_payload' => ['english_text' => "When do we see each other? There's no rush.\n-GS"],
        ]);

        $this->actingAs($user);

        $component = Livewire::test(SmsConversation::class, ['threadId' => $source->id]);
        $rendered = $component->instance()->processedMessages['visible']->firstWhere('id', $message->id);

        // Same accessor the thread-list preview reads.
        expect($message->fresh()->english_display_text)
            ->toBe($rendered->translated_display_text)
            // ...and the crew signature is not shown inside Hive.
            ->toBe("When do we see each other? There's no rush.");
    });

    it('strips the crew signature from a translated message', function (): void {
        $message = new SmsMessage([
            'text' => 'Hola',
            'raw_payload' => ['english_text' => "Let me know.\n-GS "],
        ]);

        expect($message->english_display_text)->toBe('Let me know.');
    });

    it('shows the 3-dot actions menu for image-only messages', function (): void {
        ['user' => $user, 'source' => $source] = makeForwardingFixture();

        $imageOnly = SmsMessage::query()->create([
            'thread_id' => $source->id,
            'direction' => SmsMessage::DIRECTION_INBOUND,
            'from_number' => '+12245550001',
            'to_number' => '+12245554444',
            'text' => '',
            'media_urls' => ['/storage/sms-attachments/image-only.jpg'],
            'status' => 'received',
        ]);

        $this->actingAs($user);

        // The bubble renders the shared-menu trigger (dispatches
        // sms-message-menu with this message's id) even with no text.
        Livewire::test(SmsConversation::class, ['threadId' => $source->id])
            ->assertSeeHtml('sms-message-menu')
            ->assertSeeHtml('id: ' . $imageOnly->id . ',');
    });

    it('refreshes and marks read when an incoming message targets the open thread', function (): void {
        ['user' => $user, 'source' => $source] = makeForwardingFixture();

        $this->actingAs($user);

        $component = Livewire::test(SmsConversation::class, ['threadId' => $source->id]);

        $component->call('handleIncomingMessage', ['threadId' => $source->id])
            ->assertDispatched('sms-new-message-received');
    });

    it('ignores incoming messages for a different thread', function (): void {
        ['user' => $user, 'source' => $source, 'target' => $target] = makeForwardingFixture();

        $this->actingAs($user);

        $component = Livewire::test(SmsConversation::class, ['threadId' => $source->id]);

        $component->call('handleIncomingMessage', ['threadId' => $target->id])
            ->assertNotDispatched('sms-new-message-received');
    });
});

describe('thread header naming', function (): void {
    uses(RefreshDatabase::class);

    it('shows client nickname in the conversation header', function (): void {
        $ownerVendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

        $viewer = User::query()->create([
            'first_name' => 'Patryk',
            'last_name' => 'Tester',
            'email' => 'conversation-header-nickname-' . uniqid() . '@example.com',
            'cell_phone' => '2245553113',
            'primary_vendor_id' => $ownerVendor->id,
        ]);

        $client = Client::factory()->create([
            'business_name' => null,
        ]);

        $bonnie = User::query()->create([
            'first_name' => 'Bonnie',
            'last_name' => 'Bates',
            'email' => 'bonnie.conversation-header@example.com',
            'cell_phone' => '2245554211',
            'primary_vendor_id' => null,
        ]);

        $bradley = User::query()->create([
            'first_name' => 'Bradley',
            'nickname' => 'Brad',
            'last_name' => 'Bates',
            'email' => 'brad.conversation-header@example.com',
            'cell_phone' => '2245554212',
            'primary_vendor_id' => null,
        ]);

        $client->users()->attach([$bonnie->id, $bradley->id]);

        $thread = SmsGroupThread::query()->create([
            'name' => 'Bonnie & Bradley Bates',
            'from_number' => '+12245554444',
            'participants' => ['+12245554211', '+12245554212'],
            'client_id' => $client->id,
            'vendor_id' => $ownerVendor->id,
            'last_activity_at' => now(),
        ]);

        SmsThreadParticipant::query()->create([
            'thread_id' => $thread->id,
            'phone_number' => '+12245554211',
            'opted_in_at' => now(),
        ]);

        SmsThreadParticipant::query()->create([
            'thread_id' => $thread->id,
            'phone_number' => '+12245554212',
            'opted_in_at' => now(),
        ]);

        $this->actingAs($viewer);

        Livewire::test(SmsConversation::class, ['threadId' => $thread->id])
            ->assertSee('Brad Bates')
            ->assertSee('Bonnie');
    });
});

describe('thread spam actions', function (): void {
    uses(RefreshDatabase::class);

    function makeSpamFixture(): array
    {
        $ownerVendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

        $user = User::query()->create([
            'first_name' => 'Patryk',
            'last_name' => 'Tester',
            'email' => 'sms-spam-' . uniqid() . '@example.com',
            'cell_phone' => '2245557111',
            'primary_vendor_id' => $ownerVendor->id,
        ]);

        $thread = SmsGroupThread::query()->create([
            'name' => 'Spam Thread',
            'from_number' => '+12249993880',
            'participants' => ['+12245550001'],
            'vendor_id' => $ownerVendor->id,
            'last_activity_at' => now(),
        ]);

        SmsThreadParticipant::query()->create([
            'thread_id' => $thread->id,
            'phone_number' => '+12245550001',
            'opted_in_at' => now(),
        ]);

        return compact('user', 'thread');
    }

    it('marks thread participant numbers as spam', function (): void {
        ['user' => $user, 'thread' => $thread] = makeSpamFixture();

        $this->actingAs($user);

        Livewire::test(SmsConversation::class, ['threadId' => $thread->id])
            ->call('markThreadAsSpam');

        $blocked = BlockedCaller::query()->where('phone_number', '+12245550001')->first();

        expect($blocked)->not->toBeNull();
        expect($blocked->reason)->toBe('Manually marked as spam from messages')
            ->and($blocked->blocked_by_user_id)->toBe($user->id)
            ->and($blocked->auto_blocked)->toBeFalse();
    });

    it('does not duplicate blocked entries when marking same thread as spam twice', function (): void {
        ['user' => $user, 'thread' => $thread] = makeSpamFixture();

        $this->actingAs($user);

        Livewire::test(SmsConversation::class, ['threadId' => $thread->id])
            ->call('markThreadAsSpam')
            ->call('markThreadAsSpam');

        expect(BlockedCaller::query()->where('phone_number', '+12245550001')->count())->toBe(1);
    });

    it('unblocks thread participant numbers', function (): void {
        ['user' => $user, 'thread' => $thread] = makeSpamFixture();

        BlockedCaller::query()->create([
            'phone_number' => '+12245550001',
            'reason' => 'Manually marked as spam from messages',
            'blocked_by_user_id' => $user->id,
            'auto_blocked' => false,
        ]);

        $this->actingAs($user);

        Livewire::test(SmsConversation::class, ['threadId' => $thread->id])
            ->call('unblockThreadSpam');

        expect(BlockedCaller::query()->where('phone_number', '+12245550001')->exists())->toBeFalse();
    });

    it('dispatches sms-spam-changed so the thread list repaints', function (): void {
        ['user' => $user, 'thread' => $thread] = makeSpamFixture();

        $this->actingAs($user);

        Livewire::test(SmsConversation::class, ['threadId' => $thread->id])
            ->call('markThreadAsSpam')
            ->assertDispatched('sms-spam-changed')
            ->call('unblockThreadSpam')
            ->assertDispatched('sms-spam-changed');
    });
});

describe('poll fallback', function (): void {
    uses(RefreshDatabase::class);

    function makePollFixture(): array
    {
        $ownerVendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

        $user = User::query()->create([
            'first_name' => 'Patryk',
            'last_name' => 'Tester',
            'email' => 'sms-poll-' . uniqid() . '@example.com',
            'cell_phone' => '2245557111',
            'primary_vendor_id' => $ownerVendor->id,
        ]);

        $thread = SmsGroupThread::query()->create([
            'name' => 'Poll Thread',
            'from_number' => '+12249993880',
            'participants' => ['+12245550001'],
            'vendor_id' => $ownerVendor->id,
            'last_activity_at' => now(),
        ]);

        SmsThreadParticipant::query()->create([
            'thread_id' => $thread->id,
            'phone_number' => '+12245550001',
            'opted_in_at' => now(),
        ]);

        return compact('user', 'thread');
    }

    it('skips rendering when nothing changed', function (): void {
        ['user' => $user, 'thread' => $thread] = makePollFixture();

        $this->actingAs($user);

        $component = Livewire::test(SmsConversation::class, ['threadId' => $thread->id]);
        $fingerprint = $component->get('pollFingerprint');

        expect($fingerprint)->not->toBeNull();

        $component->call('pollForUpdates')
            ->assertSet('pollFingerprint', $fingerprint);
    });

    it('picks up messages that arrived without a broadcast', function (): void {
        ['user' => $user, 'thread' => $thread] = makePollFixture();

        $this->actingAs($user);

        $component = Livewire::test(SmsConversation::class, ['threadId' => $thread->id]);
        $fingerprint = $component->get('pollFingerprint');

        SmsMessage::query()->create([
            'thread_id' => $thread->id,
            'from_number' => '+12245550001',
            'text' => 'Missed broadcast message',
            'direction' => 'inbound',
            'status' => 'received',
        ]);

        $component->call('pollForUpdates')
            ->assertSee('Missed broadcast message');

        expect($component->get('pollFingerprint'))->not->toBe($fingerprint);
    });

    it('skips rendering when no thread is selected', function (): void {
        ['user' => $user] = makePollFixture();

        $this->actingAs($user);

        Livewire::test(SmsConversation::class, ['threadId' => null])
            ->call('pollForUpdates')
            ->assertSet('pollFingerprint', null);
    });
});

describe('scheduled messages', function (): void {
    uses(RefreshDatabase::class);

    it('creates a no-date schedule-only draft from the conversation menu', function (): void {
        $ownerVendor = Vendor::factory()->create(['business_name' => 'GS Construction']);
        $subjectVendor = Vendor::factory()->create(['business_name' => 'RG Tile']);

        $user = User::query()->create([
            'first_name' => 'Patryk',
            'last_name' => 'Tester',
            'email' => 'scheduled-only-' . uniqid() . '@example.com',
            'cell_phone' => '2245553000',
            'primary_vendor_id' => $ownerVendor->id,
        ]);

        $thread = SmsGroupThread::query()->create([
            'name' => 'Scheduled Thread',
            'from_number' => '+12245554444',
            'participants' => ['+12245550001'],
            'vendor_id' => $ownerVendor->id,
            'subject_vendor_id' => $subjectVendor->id,
            'last_activity_at' => now(),
        ]);

        SmsThreadParticipant::query()->create([
            'thread_id' => $thread->id,
            'phone_number' => '+12245550001',
            'opted_in_at' => now(),
        ]);

        $this->actingAs($user);

        Livewire::test(SmsConversation::class, ['threadId' => $thread->id])
            ->set('newMessage', 'Please review this when ready')
            ->call('scheduleMessage', 'schedule_only');

        $scheduled = SmsMessage::query()
            ->where('thread_id', $thread->id)
            ->latest('id')
            ->first();

        expect($scheduled)->not->toBeNull();
        expect($scheduled->status)->toBe('scheduled');
        expect($scheduled->scheduled_at)->toBeNull();
        expect(data_get($scheduled->raw_payload, 'schedule_only'))->toBeTrue();
    });

    it('shows draft for scheduled messages without a send date', function (): void {
        $ownerVendor = Vendor::factory()->create(['business_name' => 'GS Construction']);
        $subjectVendor = Vendor::factory()->create(['business_name' => 'RG Tile']);

        $user = User::query()->create([
            'first_name' => 'Patryk',
            'last_name' => 'Tester',
            'email' => 'draft-badge-' . uniqid() . '@example.com',
            'cell_phone' => '2245553001',
            'primary_vendor_id' => $ownerVendor->id,
        ]);

        $thread = SmsGroupThread::query()->create([
            'name' => 'Draft Thread',
            'from_number' => '+12245554444',
            'participants' => ['+12245550001'],
            'vendor_id' => $ownerVendor->id,
            'subject_vendor_id' => $subjectVendor->id,
            'last_activity_at' => now(),
        ]);

        SmsThreadParticipant::query()->create([
            'thread_id' => $thread->id,
            'phone_number' => '+12245550001',
            'opted_in_at' => now(),
        ]);

        SmsMessage::query()->create([
            'thread_id' => $thread->id,
            'direction' => SmsMessage::DIRECTION_OUTBOUND,
            'from_number' => '+12245554444',
            'to_numbers' => ['+12245550001'],
            'text' => "Draft text\n-GSC",
            'status' => 'scheduled',
            'scheduled_at' => null,
            'sent_by_user_id' => $user->id,
            'raw_payload' => [
                'original_text' => 'Draft text',
                'sender_language' => 'English',
                'recipient_language' => 'English',
                'schedule_only' => true,
            ],
        ]);

        $this->actingAs($user);

        Livewire::test(SmsConversation::class, ['threadId' => $thread->id])
            ->assertSee('Draft');
    });

    it('edits a scheduled message before sending', function (): void {
        $ownerVendor = Vendor::factory()->create(['business_name' => 'GS Construction']);
        $subjectVendor = Vendor::factory()->create(['business_name' => 'RG Tile']);

        $user = User::query()->create([
            'first_name' => 'Patryk',
            'last_name' => 'Tester',
            'email' => 'scheduled-edit-' . uniqid() . '@example.com',
            'cell_phone' => '2245553111',
            'primary_vendor_id' => $ownerVendor->id,
        ]);

        $thread = SmsGroupThread::query()->create([
            'name' => 'Scheduled Thread',
            'from_number' => '+12245554444',
            'participants' => ['+12245550001'],
            'vendor_id' => $ownerVendor->id,
            'subject_vendor_id' => $subjectVendor->id,
            'last_activity_at' => now(),
        ]);

        SmsThreadParticipant::query()->create([
            'thread_id' => $thread->id,
            'phone_number' => '+12245550001',
            'opted_in_at' => now(),
        ]);

        $message = SmsMessage::query()->create([
            'thread_id' => $thread->id,
            'direction' => SmsMessage::DIRECTION_OUTBOUND,
            'from_number' => '+12245554444',
            'to_numbers' => ['+12245550001'],
            'text' => "Old scheduled text\n-PS",
            'status' => 'scheduled',
            'sent_by_user_id' => $user->id,
            'raw_payload' => [
                'original_text' => 'Old scheduled text',
                'sender_language' => 'English',
                'recipient_language' => 'English',
            ],
        ]);

        $this->actingAs($user);

        Livewire::test(SmsConversation::class, ['threadId' => $thread->id])
            ->call('openEditScheduledMessage', $message->id)
            ->assertSet('editScheduledId', $message->id)
            ->assertSet('newMessage', 'Old scheduled text')
            ->set('newMessage', 'Updated scheduled text')
            ->call('sendMessage')
            ->assertSet('editScheduledId', null);

        expect($message->fresh()->text)->toBe("Updated scheduled text\n-PS");
        expect(data_get($message->fresh()->raw_payload, 'original_text'))->toBe('Updated scheduled text');
    });

    it('marks vendor tasks requested when sending a no-date scheduled draft now', function (): void {
        $ownerVendor = Vendor::factory()->create(['business_name' => 'GS Construction']);
        $subjectVendor = Vendor::factory()->create(['business_name' => 'RG Tile']);

        $user = User::query()->create([
            'first_name' => 'Patryk',
            'last_name' => 'Tester',
            'email' => 'scheduled-send-now-' . uniqid() . '@example.com',
            'cell_phone' => '2245553222',
            'primary_vendor_id' => $ownerVendor->id,
        ]);

        $this->actingAs($user);

        $client = Client::factory()->create();
        $project = Project::query()->create([
            'project_name' => 'Scheduled Send Project',
            'client_id' => $client->id,
            'address' => '3154 Violet Ln',
            'city' => 'Northbrook',
            'state' => 'IL',
            'zip_code' => 60062,
            'belongs_to_vendor_id' => $ownerVendor->id,
        ]);

        $task = Task::query()->create([
            'title' => 'Scheduled task',
            'project_id' => $project->id,
            'vendor_id' => $subjectVendor->id,
            'type' => 'Task',
            'start_date' => today(),
            'end_date' => today(),
        ]);

        $thread = SmsGroupThread::query()->create([
            'name' => 'Scheduled Thread',
            'from_number' => '+12245554444',
            'participants' => ['+12245550001'],
            'vendor_id' => $ownerVendor->id,
            'subject_vendor_id' => $subjectVendor->id,
            'last_activity_at' => now(),
        ]);

        SmsThreadParticipant::query()->create([
            'thread_id' => $thread->id,
            'phone_number' => '+12245550001',
            'opted_in_at' => now(),
        ]);

        $message = SmsMessage::query()->create([
            'thread_id' => $thread->id,
            'direction' => SmsMessage::DIRECTION_OUTBOUND,
            'from_number' => '+12245554444',
            'to_numbers' => ['+12245550001'],
            'text' => "Draft schedule\n-GSC",
            'status' => 'scheduled',
            'scheduled_at' => null,
            'raw_payload' => [
                'source' => 'send_schedule_modal',
                'scheduled_task_ids' => [$task->id],
            ],
        ]);

        Livewire::test(SmsConversation::class, ['threadId' => $thread->id])
            ->call('sendScheduledNow', $message->id);

        expect($message->fresh()->status)->not->toBe('scheduled');
        expect($task->fresh()->vendor_status)->toBe(Task::VENDOR_STATUS_REQUESTED);
    });

    it('re-signs a scheduled draft with the sending user signature', function (): void {
        $ownerVendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

        $author = User::query()->create([
            'first_name' => 'Patryk',
            'last_name' => 'Author',
            'email' => 'resign-author-' . uniqid() . '@example.com',
            'cell_phone' => '2245553111',
            'primary_vendor_id' => $ownerVendor->id,
        ]);

        $sender = User::query()->create([
            'first_name' => 'Greg',
            'last_name' => 'Sender',
            'email' => 'resign-sender-' . uniqid() . '@example.com',
            'cell_phone' => '2245553112',
            'primary_vendor_id' => $ownerVendor->id,
        ]);

        $thread = SmsGroupThread::query()->create([
            'name' => 'Resign Thread',
            'from_number' => '+12245554444',
            'participants' => ['+12245550001'],
            'vendor_id' => $ownerVendor->id,
            'last_activity_at' => now(),
        ]);

        SmsThreadParticipant::query()->create([
            'thread_id' => $thread->id,
            'phone_number' => '+12245550001',
            'opted_in_at' => now(),
        ]);

        $message = SmsMessage::query()->create([
            'thread_id' => $thread->id,
            'direction' => SmsMessage::DIRECTION_OUTBOUND,
            'from_number' => '+12245554444',
            'to_numbers' => ['+12245550001'],
            'text' => "Draft body here\n-PS",
            'status' => 'scheduled',
            'scheduled_at' => null,
            'sent_by_user_id' => $author->id,
            'raw_payload' => ['source' => 'send_schedule_modal'],
        ]);

        $this->actingAs($sender);

        Livewire::test(SmsConversation::class, ['threadId' => $thread->id])
            ->call('sendScheduledNow', $message->id);

        $expectedSignature = SmsNewThread::getSignature($sender->id);

        $fresh = $message->fresh();
        expect($fresh->text)->toBe("Draft body here\n{$expectedSignature}");
        expect($fresh->sent_by_user_id)->toBe($sender->id);
        expect($fresh->text)->not->toContain('-PS');
    });
});

describe('remote message edits', function (): void {
    uses(RefreshDatabase::class);

    function makeEditFixture(): array
    {
        $ownerVendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

        $user = User::query()->create([
            'first_name' => 'Patryk',
            'last_name' => 'Tester',
            'email' => 'edit-test-' . uniqid() . '@example.com',
            'cell_phone' => '2245551111',
            'primary_vendor_id' => $ownerVendor->id,
        ]);

        $thread = SmsGroupThread::query()->create([
            'name' => 'Edit Thread',
            'from_number' => '+12245554444',
            'participants' => ['+12245550001'],
            'vendor_id' => $ownerVendor->id,
            'last_activity_at' => now(),
        ]);

        SmsThreadParticipant::query()->create([
            'thread_id' => $thread->id,
            'phone_number' => '+12245550001',
            'opted_in_at' => now(),
        ]);

        return compact('user', 'thread');
    }

    it('applies a Spanish "Editado como" edit to the original message and hides the notification', function (): void {
        ['user' => $user, 'thread' => $thread] = makeEditFixture();

        $original = SmsMessage::query()->create([
            'thread_id' => $thread->id,
            'direction' => SmsMessage::DIRECTION_INBOUND,
            'from_number' => '+12245550001',
            'text' => 'Re Axel product for the front entrance: I just spoke to my rep and he said that the siding can be mitered for a column casing.',
            'status' => 'received',
            'created_at' => now()->subMinutes(2),
        ]);

        SmsMessage::query()->create([
            'thread_id' => $thread->id,
            'direction' => SmsMessage::DIRECTION_INBOUND,
            'from_number' => '+12245550001',
            'text' => "Editado como: \u{201c}Re Azek product for the front entrance: I just spoke to my rep and he said that the siding can be mitered for a column casing.\u{201d}",
            'status' => 'received',
            'created_at' => now()->subMinute(),
        ]);

        $this->actingAs($user);

        $component = Livewire::test(SmsConversation::class, ['threadId' => $thread->id]);
        $processed = $component->instance()->processedMessages;

        expect($processed['visible'])->toHaveCount(1);

        $visible = $processed['visible']->first();
        expect((int) $visible->id)->toBe((int) $original->id)
            ->and($visible->text)->toContain('Re Azek product')
            ->and($visible->was_edited)->toBeTrue();

        $component->assertSee('(Edited)')
            ->assertSee('Re Azek product')
            ->assertDontSee('Editado como');
    });

    it('applies an English "Edited to" edit', function (): void {
        ['user' => $user, 'thread' => $thread] = makeEditFixture();

        $original = SmsMessage::query()->create([
            'thread_id' => $thread->id,
            'direction' => SmsMessage::DIRECTION_INBOUND,
            'from_number' => '+12245550001',
            'text' => 'We can meet Thurdsay at 9am to walk the site.',
            'status' => 'received',
            'created_at' => now()->subMinutes(2),
        ]);

        SmsMessage::query()->create([
            'thread_id' => $thread->id,
            'direction' => SmsMessage::DIRECTION_INBOUND,
            'from_number' => '+12245550001',
            'text' => 'Edited to "We can meet Thursday at 9am to walk the site."',
            'status' => 'received',
            'created_at' => now()->subMinute(),
        ]);

        $this->actingAs($user);

        $processed = Livewire::test(SmsConversation::class, ['threadId' => $thread->id])
            ->instance()->processedMessages;

        expect($processed['visible'])->toHaveCount(1)
            ->and($processed['visible']->first()->text)->toContain('Thursday')
            ->and($processed['visible']->first()->was_edited)->toBeTrue()
            ->and((int) $processed['visible']->first()->id)->toBe((int) $original->id);
    });

    it('leaves an edit notification visible when no original message matches', function (): void {
        ['user' => $user, 'thread' => $thread] = makeEditFixture();

        SmsMessage::query()->create([
            'thread_id' => $thread->id,
            'direction' => SmsMessage::DIRECTION_INBOUND,
            'from_number' => '+12245550001',
            'text' => 'Completely unrelated message about invoices.',
            'status' => 'received',
            'created_at' => now()->subMinutes(2),
        ]);

        SmsMessage::query()->create([
            'thread_id' => $thread->id,
            'direction' => SmsMessage::DIRECTION_INBOUND,
            'from_number' => '+12245550001',
            'text' => 'Edited to "The crew will arrive tomorrow with the scaffolding equipment."',
            'status' => 'received',
            'created_at' => now()->subMinute(),
        ]);

        $this->actingAs($user);

        $processed = Livewire::test(SmsConversation::class, ['threadId' => $thread->id])
            ->instance()->processedMessages;

        // No confident match → keep both messages, don't guess.
        expect($processed['visible'])->toHaveCount(2);
    });

    it('does not treat a normal message as an edit', function (): void {
        ['user' => $user, 'thread' => $thread] = makeEditFixture();

        SmsMessage::query()->create([
            'thread_id' => $thread->id,
            'direction' => SmsMessage::DIRECTION_INBOUND,
            'from_number' => '+12245550001',
            'text' => 'The permit was edited to reflect the new address.',
            'status' => 'received',
        ]);

        $this->actingAs($user);

        $processed = Livewire::test(SmsConversation::class, ['threadId' => $thread->id])
            ->instance()->processedMessages;

        expect($processed['visible'])->toHaveCount(1)
            ->and($processed['visible']->first()->was_edited ?? false)->toBeFalse();
    });
});

describe('tapback reactions', function (): void {
    uses(RefreshDatabase::class);

    it('attaches a mojibake Polish like tapback to the quoted message', function (): void {
        $ownerVendor = Vendor::factory()->create(['business_name' => 'GS Construction']);
        $subjectVendor = Vendor::factory()->create(['business_name' => 'Smartech Electric']);

        $user = User::query()->create([
            'first_name' => 'Patryk',
            'last_name' => 'Tester',
            'email' => 'tapback-test-' . uniqid() . '@example.com',
            'cell_phone' => '2245551111',
            'primary_vendor_id' => $ownerVendor->id,
        ]);

        $thread = SmsGroupThread::query()->create([
            'name' => 'Tapback Thread',
            'from_number' => '+12245554444',
            'participants' => ['+12245550001'],
            'vendor_id' => $ownerVendor->id,
            'subject_vendor_id' => $subjectVendor->id,
            'last_activity_at' => now(),
        ]);

        SmsThreadParticipant::query()->create([
            'thread_id' => $thread->id,
            'phone_number' => '+12245550001',
            'opted_in_at' => now(),
        ]);

        $original = SmsMessage::query()->create([
            'thread_id' => $thread->id,
            'direction' => SmsMessage::DIRECTION_INBOUND,
            'from_number' => '+12245550001',
            'to_number' => '+12245554444',
            'text' => "Siema Grzesiek, bÄ™dziemy uÅ¼ywaÄ‡ ten numer zÄ™by Grzesiek i ja mieliÅ›my te same informacje caÅ‚y czas. - Patryk\n-PS",
            'status' => 'received',
        ]);

        SmsMessage::query()->create([
            'thread_id' => $thread->id,
            'direction' => SmsMessage::DIRECTION_INBOUND,
            'from_number' => '+12245550001',
            'to_number' => '+12245554444',
            'text' => "Dodano â€žkciuk wÂ gÃ³rÄ™â€ do â€žSiema Grzesiek, bÄ™dziemy uÅ¼ywaÄ‡ ten numer zÄ™by Grzesiek i ja mieliÅ›my te same informacje caÅ‚y czas. - Patryk\n-PSâ€",
            'status' => 'received',
        ]);

        $this->actingAs($user);

        $component = Livewire::test(SmsConversation::class, ['threadId' => $thread->id]);
        $processed = $component->instance()->processedMessages;

        expect($processed['visible'])->toHaveCount(1)
            ->and((int) $processed['visible']->first()->id)->toBe((int) $original->id)
            ->and($processed['reactions'][$original->id]['👍'] ?? null)->toBeArray();
    });
});