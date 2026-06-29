<?php

use App\Livewire\Sms\SmsConversation;
use App\Livewire\Sms\SmsNewThread;
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

    $filteredUsers = (new SmsConversation())->filterClientUsersToThreadParticipants(
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