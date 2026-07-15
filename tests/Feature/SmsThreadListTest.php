<?php

use App\Livewire\Sms\SmsThreadList;
use App\Models\BlockedCaller;
use App\Models\Client;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\SmsThreadParticipant;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows vendor threads in the list', function (): void {
    $ownerVendor = Vendor::factory()->create([
        'business_name' => 'GS Construction',
    ]);

    $subjectVendor = Vendor::factory()->create([
        'business_name' => 'Smartech Electric',
    ]);

    $user = User::query()->create([
        'first_name' => 'Patryk',
        'last_name' => 'Tester',
        'email' => 'patryk.thread-list@example.com',
        'cell_phone' => '2245551111',
        'primary_vendor_id' => $ownerVendor->id,
    ]);

    SmsGroupThread::query()->create([
        'name' => 'Pawel Bach',
        'from_number' => '+12245554444',
        'participants' => ['+12245550001'],
        'vendor_id' => $ownerVendor->id,
        'subject_vendor_id' => $subjectVendor->id,
        'last_activity_at' => now(),
    ]);

    $this->actingAs($user);

    Livewire::test(SmsThreadList::class, ['isClientUser' => false])
        ->assertSee('Pawel Bach')
        ->assertSee('Smartech Electric');
});

it('shows participant names for client users in the list', function (): void {
    $ownerVendor = Vendor::factory()->create([
        'business_name' => 'Acme Vendor LLC',
    ]);

    $clientUser = User::query()->create([
        'first_name' => 'Mark',
        'last_name' => 'Brodson',
        'email' => 'mark.brodson@example.com',
        'cell_phone' => '3128230569',
        'primary_vendor_id' => null,
    ]);

    $otherParticipant = User::query()->create([
        'first_name' => 'Gail',
        'last_name' => 'Brodson',
        'email' => 'gail.brodson@example.com',
        'cell_phone' => '3125551212',
        'primary_vendor_id' => $ownerVendor->id,
    ]);

    $client = Client::factory()->create([
        'business_name' => null,
    ]);
    $client->users()->attach([$clientUser->id, $otherParticipant->id]);

    $thread = SmsGroupThread::query()->create([
        'from_number' => '+12245554444',
        'participants' => ['+13128230569', '+13125551212'],
        'client_id' => $client->id,
        'vendor_id' => $ownerVendor->id,
        'subject_vendor_id' => $ownerVendor->id,
        'last_activity_at' => now(),
    ]);

    SmsThreadParticipant::query()->create([
        'thread_id' => $thread->id,
        'phone_number' => '+13128230569',
    ]);

    SmsThreadParticipant::query()->create([
        'thread_id' => $thread->id,
        'phone_number' => '+13125551212',
    ]);

    $this->actingAs($clientUser);

    Livewire::test(SmsThreadList::class, ['isClientUser' => true])
        // Vendor display names strip trailing legal suffixes ("Acme Vendor Llc" → "Acme Vendor")
        ->assertSee('Acme Vendor, Mark & Gail Brodson');
});

it('shows only threads where the client user is a participant', function (): void {
    $ownerVendor = Vendor::factory()->create([
        'business_name' => 'GS Construction',
    ]);

    $mark = User::query()->create([
        'first_name' => 'Mark',
        'last_name' => 'Brodson',
        'email' => 'mark.only-threads@example.com',
        'cell_phone' => '3128230569',
        'primary_vendor_id' => null,
    ]);

    $gail = User::query()->create([
        'first_name' => 'Gail',
        'last_name' => 'Brodson',
        'email' => 'gail.only-threads@example.com',
        'cell_phone' => '3125551212',
        'primary_vendor_id' => $ownerVendor->id,
    ]);

    $client = Client::factory()->create([
        'business_name' => 'Brodson Family',
    ]);
    $client->users()->attach([$mark->id, $gail->id]);

    $marksThread = SmsGroupThread::query()->create([
        'from_number' => '+12245554444',
        'participants' => ['+13128230569'],
        'client_id' => $client->id,
        'vendor_id' => $ownerVendor->id,
        'last_activity_at' => now(),
    ]);

    $gailsThread = SmsGroupThread::query()->create([
        'from_number' => '+12245554444',
        'participants' => ['+13125551212'],
        'client_id' => $client->id,
        'vendor_id' => $ownerVendor->id,
        'last_activity_at' => now()->subMinute(),
    ]);

    SmsThreadParticipant::query()->create([
        'thread_id' => $marksThread->id,
        'phone_number' => '+13128230569',
    ]);

    SmsThreadParticipant::query()->create([
        'thread_id' => $gailsThread->id,
        'phone_number' => '+13125551212',
    ]);

    $this->actingAs($mark);

    Livewire::test(SmsThreadList::class, ['isClientUser' => true])
        ->assertSeeHtml('wire:key="thread-' . $marksThread->id . '"')
        ->assertDontSeeHtml('wire:key="thread-' . $gailsThread->id . '"');
});

it('prefers nickname over first name when resolving participant display names', function (): void {
    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $viewer = User::query()->create([
        'first_name' => 'Patryk',
        'last_name' => 'Tester',
        'email' => 'patryk.nickname-list@example.com',
        'cell_phone' => '2245551111',
        'primary_vendor_id' => $vendor->id,
    ]);

    User::query()->create([
        'first_name' => 'Stanislaw',
        'last_name' => 'Palupski',
        'nickname' => 'Stan',
        'email' => 'stan.nickname-list@example.com',
        'cell_phone' => '12245550003',
    ]);

    $this->actingAs($viewer);

    $display = Livewire::test(SmsThreadList::class, ['isClientUser' => false])
        ->instance()
        ->resolvePhoneDisplay('+12245550003');

    expect($display)->toBe('Stan Palupski');
});

it('prefers nickname in client thread card title over stored thread name', function (): void {
    $ownerVendor = Vendor::factory()->create([
        'business_name' => 'GS Construction',
    ]);

    $viewer = User::query()->create([
        'first_name' => 'Patryk',
        'last_name' => 'Tester',
        'email' => 'patryk.thread-list-client-nickname@example.com',
        'cell_phone' => '2245551111',
        'primary_vendor_id' => $ownerVendor->id,
    ]);

    $client = Client::factory()->create([
        'business_name' => null,
    ]);

    $bonnie = User::query()->create([
        'first_name' => 'Bonnie',
        'last_name' => 'Bates',
        'email' => 'bonnie.thread-list-client@example.com',
        'cell_phone' => '2245554121',
        'primary_vendor_id' => null,
    ]);

    $bradley = User::query()->create([
        'first_name' => 'Bradley',
        'nickname' => 'Brad',
        'last_name' => 'Bates',
        'email' => 'brad.thread-list-client@example.com',
        'cell_phone' => '2245554122',
        'primary_vendor_id' => null,
    ]);

    $client->users()->attach([$bonnie->id, $bradley->id]);

    $thread = SmsGroupThread::query()->create([
        'name' => 'Bonnie & Bradley Bates',
        'from_number' => '+12245554444',
        'participants' => ['+12245554121', '+12245554122'],
        'client_id' => $client->id,
        'vendor_id' => $ownerVendor->id,
        'last_activity_at' => now(),
    ]);

    SmsThreadParticipant::query()->create([
        'thread_id' => $thread->id,
        'phone_number' => '+12245554121',
    ]);

    SmsThreadParticipant::query()->create([
        'thread_id' => $thread->id,
        'phone_number' => '+12245554122',
    ]);

    $this->actingAs($viewer);

    $component = Livewire::test(SmsThreadList::class, ['isClientUser' => false]);
    $label = $component->instance()->clientDisplayNameForThread($thread->fresh(['client.users']));

    expect($label)->toContain('Brad')
        ->and($label)->toContain('Bonnie')
        ->and($label)->not->toContain('Bradley');
});

it('renders decoded latest message preview text on cards', function (): void {
    $ownerVendor = Vendor::factory()->create([
        'business_name' => 'GS Construction',
    ]);

    $subjectVendor = Vendor::factory()->create([
        'business_name' => 'Stan Palupski Construction, Inc',
    ]);

    $user = User::query()->create([
        'first_name' => 'Patryk',
        'last_name' => 'Tester',
        'email' => 'patryk.thread-list-decoding@example.com',
        'cell_phone' => '2245551111',
        'primary_vendor_id' => $ownerVendor->id,
    ]);

    $thread = SmsGroupThread::query()->create([
        'name' => 'Stan Palupski',
        'from_number' => '+12245554444',
        'participants' => ['+12245550001'],
        'vendor_id' => $ownerVendor->id,
        'subject_vendor_id' => $subjectVendor->id,
        'last_activity_at' => now(),
    ]);

    SmsMessage::query()->create([
        'thread_id' => $thread->id,
        'direction' => SmsMessage::DIRECTION_OUTBOUND,
        'from_number' => '+12245554444',
        'to_number' => '+12245550001',
        'text' => "CzeÅ›Ä‡ Stan Palupski Con...\n-GSC",
        'status' => 'sent',
        'sent_by_user_id' => $user->id,
    ]);

    $this->actingAs($user);

    Livewire::test(SmsThreadList::class, ['isClientUser' => false])
        ->assertSee('You:')
        ->assertSee('Cześć Stan Palupski Con...')
        ->assertDontSee('CzeÅ›Ä‡ Stan Palupski Con...');
});

it('shows spam badge and muted styling when a thread participant is blocked', function (): void {
    $ownerVendor = Vendor::factory()->create([
        'business_name' => 'GS Construction',
    ]);

    $user = User::query()->create([
        'first_name' => 'Patryk',
        'last_name' => 'Tester',
        'email' => 'patryk.thread-list-spam@example.com',
        'cell_phone' => '2245551111',
        'primary_vendor_id' => $ownerVendor->id,
    ]);

    $thread = SmsGroupThread::query()->create([
        'name' => 'Spam Prospect',
        'from_number' => '+12245554444',
        'participants' => ['+12245550001'],
        'vendor_id' => $ownerVendor->id,
        'last_activity_at' => now(),
    ]);

    SmsThreadParticipant::query()->create([
        'thread_id' => $thread->id,
        'phone_number' => '+12245550001',
    ]);

    BlockedCaller::query()->create([
        'phone_number' => '+12245550001',
        'reason' => 'Manually marked as spam from messages',
        'blocked_by_user_id' => $user->id,
        'auto_blocked' => false,
    ]);

    $this->actingAs($user);

    Livewire::test(SmsThreadList::class, ['isClientUser' => false])
        ->assertSeeHtml('wire:key="thread-' . $thread->id . '"')
        ->assertSeeHtml('bg-amber-100')
        ->assertSeeHtml('opacity-65 grayscale');
});
