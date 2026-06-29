<?php

use App\Livewire\Sms\SmsThreadList;
use App\Models\Client;
use App\Models\SmsGroupThread;
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
        ->assertSee('Acme Vendor Llc, Mark & Gail Brodson');
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
