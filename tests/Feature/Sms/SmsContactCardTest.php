<?php

use App\Jobs\StoreSmsMedia;
use App\Livewire\Sms\SmsConversation;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\SmsThreadParticipant;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * A contact card shared over MMS (Telnyx delivers it as text/x-vcard) is
 * stored as .vcf and shown in the thread as a card, not as a broken image.
 * Jennifer's card on 2026-09-17 landed as .bin and read "Image unavailable".
 */
const JENNIFER_VCF = "BEGIN:VCARD\r\nVERSION:2.1\r\nN:Kowalski;Jennifer;;;\r\nFN:Jennifer Kowalski\r\nORG:Lakeside Realty\r\nTEL;CELL:+18475550123\r\nEND:VCARD\r\n";

function contactCardFixture(): array
{
    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);
    $user = User::query()->create([
        'first_name' => 'Patryk', 'last_name' => 'Tester', 'email' => 'card-'.uniqid().'@example.com',
        'cell_phone' => '2245551111', 'primary_vendor_id' => $vendor->id,
    ]);
    $thread = SmsGroupThread::query()->create([
        'name' => 'Jennifer', 'from_number' => '+12245554444', 'participants' => ['+18475550100'],
        'vendor_id' => $vendor->id, 'last_activity_at' => now(),
    ]);
    SmsThreadParticipant::query()->create(['thread_id' => $thread->id, 'phone_number' => '+18475550100', 'opted_in_at' => now()]);

    return compact('vendor', 'user', 'thread');
}

it('stores a shared contact as .vcf', function (): void {
    Storage::fake('files');
    Http::fake(['media.telnyx.com/*' => Http::response(JENNIFER_VCF, 200, ['Content-Type' => 'text/x-vcard'])]);
    ['thread' => $thread] = contactCardFixture();
    $message = SmsMessage::query()->create([
        'thread_id' => $thread->id, 'direction' => SmsMessage::DIRECTION_INBOUND, 'from_number' => '+18475550100',
        'to_number' => '+12245554444', 'text' => null, 'status' => 'received',
        'media_urls' => ['https://media.telnyx.com/abc123'],
    ]);

    (new StoreSmsMedia($message->id))->handle();

    $stored = $message->fresh()->media_urls[0];
    expect($stored)->toEndWith('.vcf');
    Storage::disk('files')->assertExists($stored);
    expect(SmsMessage::isContactCardUrl($stored))->toBeTrue();
    expect(SmsMessage::isImageUrl($stored))->toBeFalse();
    expect(SmsMessage::mimeForUrl($stored))->toBe('text/vcard');
    expect($message->fresh()->contactCards()[0]['name'])->toBe('Jennifer Kowalski');
});

it('shows the card in the thread instead of a broken image', function (): void {
    Storage::fake('files');
    Storage::disk('files')->put('sms-media/2026/09/card.vcf', JENNIFER_VCF);
    ['user' => $user, 'thread' => $thread] = contactCardFixture();
    SmsMessage::query()->create([
        'thread_id' => $thread->id, 'direction' => SmsMessage::DIRECTION_INBOUND, 'from_number' => '+18475550100',
        'to_number' => '+12245554444', 'text' => null, 'status' => 'received',
        'media_urls' => ['sms-media/2026/09/card.vcf'],
    ]);
    $this->actingAs($user);

    $component = Livewire::test(SmsConversation::class, ['threadId' => $thread->id])
        ->assertSee('Jennifer Kowalski')
        ->assertSee('Lakeside Realty')
        ->assertSee('+18475550123')
        ->assertSee('tel:+18475550123', false)
        ->assertSee('Save contact (.vcf)');

    // The .vcf is never handed to an <img> (the lightbox template elsewhere on
    // the page carries its own static alt text, so match on the file itself).
    expect(preg_match('/<img[^>]*card\.vcf/', $component->html()))->toBe(0);
});

it('refiles cards that were stored as .bin before the fix', function (): void {
    Storage::fake('files');
    Storage::disk('files')->put('sms-media/2026/09/was-a-card.bin', JENNIFER_VCF);
    Storage::disk('files')->put('sms-media/2026/09/really-binary.bin', "\x89PNG\r\n\x1a\nnot a card");
    ['thread' => $thread] = contactCardFixture();
    $make = fn (string $path) => SmsMessage::query()->create([
        'thread_id' => $thread->id, 'direction' => SmsMessage::DIRECTION_INBOUND, 'from_number' => '+18475550100',
        'to_number' => '+12245554444', 'text' => null, 'status' => 'received', 'media_urls' => [$path],
    ]);
    $card = $make('sms-media/2026/09/was-a-card.bin');
    $binary = $make('sms-media/2026/09/really-binary.bin');

    $this->artisan('sms:refile-contact-cards', ['--dry-run' => true])->assertSuccessful();
    expect($card->fresh()->media_urls[0])->toEndWith('.bin');

    $this->artisan('sms:refile-contact-cards')->expectsOutputToContain('contact cards refiled=1')->assertSuccessful();
    expect($card->fresh()->media_urls[0])->toBe('sms-media/2026/09/was-a-card.vcf');
    Storage::disk('files')->assertExists('sms-media/2026/09/was-a-card.vcf');
    Storage::disk('files')->assertMissing('sms-media/2026/09/was-a-card.bin');
    expect($binary->fresh()->media_urls[0])->toEndWith('really-binary.bin');

    // Second run: nothing left to do.
    $this->artisan('sms:refile-contact-cards')->expectsOutputToContain('contact cards refiled=0')->assertSuccessful();
});
