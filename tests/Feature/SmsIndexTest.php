<?php

use App\Livewire\Sms\SmsIndex;
use App\Models\Client;
use App\Models\SmsGroupThread;
use App\Models\SmsThreadParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows messages and calls tabs for client users', function (): void {
    $clientUser = User::query()->create([
        'first_name' => 'Client',
        'last_name' => 'Viewer',
        'email' => 'client-viewer@example.com',
        'cell_phone' => '3128230569',
        'primary_vendor_id' => null,
    ]);

    $client = Client::factory()->create([
        'business_name' => 'Brodson Family',
    ]);

    $client->users()->attach($clientUser->id);

    $this->actingAs($clientUser);

    Livewire::test(SmsIndex::class)
        ->assertSee('Messages')
        ->assertSee('Calls');
});

it('prevents selecting another client users thread by id', function (): void {
    $mark = User::query()->create([
        'first_name' => 'Mark',
        'last_name' => 'Brodson',
        'email' => 'mark.sms-index@example.com',
        'cell_phone' => '3128230569',
        'primary_vendor_id' => null,
    ]);

    $gail = User::query()->create([
        'first_name' => 'Gail',
        'last_name' => 'Brodson',
        'email' => 'gail.sms-index@example.com',
        'cell_phone' => '3125551212',
        'primary_vendor_id' => null,
    ]);

    $client = Client::factory()->create([
        'business_name' => 'Brodson Family',
    ]);

    $client->users()->attach([$mark->id, $gail->id]);

    $marksThread = SmsGroupThread::query()->create([
        'from_number' => '+12245554444',
        'participants' => ['+13128230569'],
        'client_id' => $client->id,
        'last_activity_at' => now(),
    ]);

    $gailsThread = SmsGroupThread::query()->create([
        'from_number' => '+12245554444',
        'participants' => ['+13125551212'],
        'client_id' => $client->id,
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

    Livewire::test(SmsIndex::class)
        ->call('selectThread', $gailsThread->id)
        ->assertSet('threadId', null)
        ->call('selectThread', $marksThread->id)
        ->assertSet('threadId', $marksThread->id);
});

it('dispatches loadThread by default but skips it when requested', function (): void {
    $mark = User::query()->create([
        'first_name' => 'Mark',
        'last_name' => 'Skipload',
        'email' => 'mark.skipload@example.com',
        'cell_phone' => '3128230569',
        'primary_vendor_id' => null,
    ]);

    $client = Client::factory()->create([
        'business_name' => 'Skipload Family',
    ]);

    $client->users()->attach($mark->id);

    $thread = SmsGroupThread::query()->create([
        'from_number' => '+12245554444',
        'participants' => ['+13128230569'],
        'client_id' => $client->id,
        'last_activity_at' => now(),
    ]);

    $otherThread = SmsGroupThread::query()->create([
        'from_number' => '+12245554444',
        'participants' => ['+13128230569'],
        'client_id' => $client->id,
        'last_activity_at' => now()->subMinute(),
    ]);

    SmsThreadParticipant::query()->create([
        'thread_id' => $thread->id,
        'phone_number' => '+13128230569',
    ]);

    SmsThreadParticipant::query()->create([
        'thread_id' => $otherThread->id,
        'phone_number' => '+13128230569',
    ]);

    $this->actingAs($mark);

    // Default: selectThread re-dispatches loadThread to the conversation.
    Livewire::test(SmsIndex::class)
        ->call('selectThread', $thread->id)
        ->assertSet('threadId', $thread->id)
        ->assertDispatched('loadThread');

    // Click path: the browser already dispatched loadThread in parallel, so the
    // server must skip the redundant re-dispatch while still updating threadId.
    Livewire::test(SmsIndex::class)
        ->call('selectThread', $thread->id, true)
        ->assertSet('threadId', $thread->id)
        ->assertNotDispatched('loadThread');
});

