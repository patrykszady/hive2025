<?php

use App\Http\Requests\Api\App\SendThreadMessageRequest;
use App\Jobs\SendGroupMms;
use App\Livewire\Sms\SmsConversation;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\SmsThreadParticipant;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Patryk, 2026-11-12: "on desktop, right now we're not allowed to send
 * multiple images at once ... be able to select multiple on the photo
 * selector". The composer's paper-clip takes several photos or videos in one
 * pick, a second pick adds to the first, each one can be taken off again, and
 * they all leave in one text — up to the ten the app's API takes.
 */

/** @return array{user: User, thread: SmsGroupThread} */
function composerAttachmentsWorld(): array
{
    // GS is vendor 1, as in every other texting test: thread visibility (SmsGroupThread::visibleToVendor) is keyed on it.
    $gs = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $client = Vendor::factory()->create(['business_name' => 'J Peterson Design']);

    $patryk = User::query()->create([
        'first_name' => 'Patryk', 'last_name' => 'Tester', 'email' => 'attachments-'.uniqid().'@example.com',
        'cell_phone' => '2245559000', 'primary_vendor_id' => $gs->id, 'preferred_language' => 'English',
    ]);
    $gs->users()->attach($patryk->id, ['role_id' => 1, 'is_employed' => 1]);

    $thread = SmsGroupThread::query()->create([
        'name' => 'J Peterson Design', 'from_number' => '+12247354200', 'participants' => ['+17735550001'],
        'vendor_id' => $gs->id, 'subject_vendor_id' => $client->id, 'last_activity_at' => now(),
    ]);
    SmsThreadParticipant::query()->create(['thread_id' => $thread->id, 'phone_number' => '+17735550001', 'opted_in_at' => now()]);

    test()->actingAs($patryk);
    Storage::fake('public');
    Bus::fake([SendGroupMms::class]);
    Http::fake(['api.telnyx.com/*' => Http::response(['data' => ['id' => 'tx-1', 'record_type' => 'message', 'to' => [['phone_number' => '+17735550001', 'status' => 'queued']]]])]);

    return ['user' => $patryk, 'thread' => $thread];
}

/** @return array<int, string> */
function waitingAttachmentNames(Testable $component): array
{
    return array_map(fn ($file): string => $file->getClientOriginalName(), $component->get('attachments'));
}

it('takes several photos in one pick and sends them in one text', function () {
    ['thread' => $thread] = composerAttachmentsWorld();

    Livewire::test(SmsConversation::class, ['threadId' => $thread->id])
        ->set('newAttachments', [
            UploadedFile::fake()->image('cabinets.jpg'),
            UploadedFile::fake()->image('island.png'),
            UploadedFile::fake()->image('window.jpg'),
        ])
        ->assertHasNoErrors()
        ->assertCount('attachments', 3)
        ->assertSet('newAttachments', [])
        ->call('sendMessage')
        ->assertHasNoErrors()
        ->assertCount('attachments', 0);

    $texts = SmsMessage::query()->where('thread_id', $thread->id)->get();

    expect($texts)->toHaveCount(1)
        ->and($texts->first()->media_urls)->toHaveCount(3);

    foreach ($texts->first()->media_urls as $url) {
        expect($url)->toStartWith('/storage/sms-attachments/');
        Storage::disk('public')->assertExists(Str::after($url, '/storage/'));
    }
});

it('adds a second pick to the first and takes one photo off again', function () {
    ['thread' => $thread] = composerAttachmentsWorld();

    $component = Livewire::test(SmsConversation::class, ['threadId' => $thread->id])
        ->set('newAttachments', [UploadedFile::fake()->image('one.jpg'), UploadedFile::fake()->image('two.jpg')])
        ->set('newAttachments', [UploadedFile::fake()->image('three.jpg')])
        ->assertHasNoErrors();

    expect(waitingAttachmentNames($component))->toBe(['one.jpg', 'two.jpg', 'three.jpg']);

    $component->call('removeAttachment', 1);

    expect(waitingAttachmentNames($component))->toBe(['one.jpg', 'three.jpg']);
});

it('lets the paper-clip pick several files and shows each waiting photo with its own remove button', function () {
    ['thread' => $thread] = composerAttachmentsWorld();

    $component = Livewire::test(SmsConversation::class, ['threadId' => $thread->id]);

    // Flux's uploader sends files up the moment they are picked or dropped.
    preg_match('/<ui-file-upload\b[^>]*>/', $component->html(), $uploader);
    expect($uploader[0] ?? '')
        ->toContain('wire:model="newAttachments"')
        ->toContain('multiple')
        ->toContain('accept="image/*,video/*');

    $component
        ->assertDontSeeHtml('wire:click="removeAttachment(0)"')
        ->set('newAttachments', [UploadedFile::fake()->image('one.jpg'), UploadedFile::fake()->image('two.jpg')])
        ->assertSeeHtml('wire:click="removeAttachment(0)"')
        ->assertSeeHtml('wire:click="removeAttachment(1)"')
        ->assertDontSeeHtml('wire:click="removeAttachment(2)"');
});

it('keeps ten and says so when more are picked', function () {
    ['thread' => $thread] = composerAttachmentsWorld();

    $twelve = array_map(fn (int $number): UploadedFile => UploadedFile::fake()->image("photo-{$number}.jpg"), range(1, 12));

    $component = Livewire::test(SmsConversation::class, ['threadId' => $thread->id])
        ->set('newAttachments', $twelve)
        ->assertCount('attachments', SmsConversation::MAX_ATTACHMENTS)
        ->assertHasErrors('attachments')
        ->assertSee('A text carries up to 10 photos or videos.');

    expect(waitingAttachmentNames($component))->toBe(array_map(fn (int $number): string => "photo-{$number}.jpg", range(1, 10)));

    $component->call('removeAttachment', 0)->assertHasNoErrors();
});

it('refuses a pick holding a file that is not a photo or video and keeps what was waiting', function () {
    ['thread' => $thread] = composerAttachmentsWorld();

    $component = Livewire::test(SmsConversation::class, ['threadId' => $thread->id])
        ->set('newAttachments', [UploadedFile::fake()->image('kitchen.jpg')])
        ->set('newAttachments', [UploadedFile::fake()->image('pantry.jpg'), UploadedFile::fake()->create('estimate.pdf', 20, 'application/pdf')])
        ->assertHasErrors('newAttachments.1')
        ->assertSee('Only photos and videos can be attached')
        ->assertSet('newAttachments', []);

    expect(waitingAttachmentNames($component))->toBe(['kitchen.jpg']);
});

it('clears an earlier refusal when the next pick is fine', function () {
    ['thread' => $thread] = composerAttachmentsWorld();

    Livewire::test(SmsConversation::class, ['threadId' => $thread->id])
        ->set('newAttachments', [UploadedFile::fake()->create('estimate.pdf', 20, 'application/pdf')])
        ->assertHasErrors('newAttachments.0')
        ->set('newAttachments', [UploadedFile::fake()->image('kitchen.jpg')])
        ->assertHasNoErrors()
        ->assertCount('attachments', 1);
});

it('says so when an upload does not finish, and keeps what was already waiting', function () {
    ['thread' => $thread] = composerAttachmentsWorld();

    $component = Livewire::test(SmsConversation::class, ['threadId' => $thread->id])
        ->set('newAttachments', [UploadedFile::fake()->image('kitchen.jpg')])
        ->call('_uploadErrored', 'newAttachments', null, true)
        ->assertHasErrors('newAttachments')
        ->assertSee('The upload did not finish. Add the files again.');

    expect(waitingAttachmentNames($component))->toBe(['kitchen.jpg']);
});

it('keeps Send and Schedule disabled until every file is fully uploaded', function () {
    ['thread' => $thread] = composerAttachmentsWorld();

    $html = Livewire::test(SmsConversation::class, ['threadId' => $thread->id])->html();

    preg_match('/<button\b[^>]*aria-label="Send message"[^>]*>/', $html, $send);
    preg_match('/<button\b[^>]*aria-label="Schedule send"[^>]*>/', $html, $schedule);

    expect($send[0] ?? '')->toContain('x-bind:disabled="uploading || false"')
        ->and($schedule[0] ?? '')->toContain('x-bind:disabled="uploading || false"')
        ->and($html)->toContain('x-on:livewire-upload-start="uploadStarted()"')
        ->and($html)->toContain('x-on:livewire-upload-finish="uploading = false"')
        ->and($html)->toContain('if (this.uploading) return;');
});

it('still needs words or a photo before it sends', function () {
    ['thread' => $thread] = composerAttachmentsWorld();

    Livewire::test(SmsConversation::class, ['threadId' => $thread->id])
        ->call('sendMessage')
        ->assertHasErrors('newMessage');

    expect(SmsMessage::query()->where('thread_id', $thread->id)->count())->toBe(0);
});

it('schedules a text with every waiting photo', function () {
    ['thread' => $thread] = composerAttachmentsWorld();

    Livewire::test(SmsConversation::class, ['threadId' => $thread->id])
        ->set('newMessage', 'Photos from today')
        ->set('newAttachments', [UploadedFile::fake()->image('before.jpg'), UploadedFile::fake()->image('after.jpg')])
        ->call('scheduleMessage', 'tomorrow_8am')
        ->assertHasNoErrors()
        ->assertCount('attachments', 0);

    $scheduled = SmsMessage::query()->where('thread_id', $thread->id)->sole();

    expect($scheduled->status)->toBe('scheduled')
        ->and($scheduled->media_urls)->toHaveCount(2);
});

it('holds the app to the same number of photos per text', function () {
    expect((new SendThreadMessageRequest())->rules()['media'])->toContain('max:'.SmsConversation::MAX_ATTACHMENTS);
});
