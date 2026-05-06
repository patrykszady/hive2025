<?php

use App\Livewire\Sms\SmsThreadList;
use App\Models\SmsGroupThread;
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

    Livewire::test(SmsThreadList::class)
        ->set('isClientUser', false)
        ->assertSee('Pawel Bach')
        ->assertSee('Smartech Electric');
});
