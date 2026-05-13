<?php

use App\Livewire\Sms\SendScheduleModal;
use App\Models\Client;
use App\Models\Project;
use App\Models\SmsGroupThread;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('uses vendor contact first name in schedule greeting for vendor-subject threads', function (): void {
    $ownerVendor = Vendor::factory()->create([
        'business_name' => 'GS Construction',
    ]);

    $ownerUser = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.schedule-modal@example.com',
        'cell_phone' => '2245550099',
        'primary_vendor_id' => $ownerVendor->id,
    ]);

    $this->actingAs($ownerUser);

    $subjectVendor = Vendor::factory()->create([
        'business_name' => 'Smartech Electric',
    ]);

    $subjectUser = User::query()->create([
        'first_name' => 'Pawel',
        'last_name' => 'Bach',
        'email' => 'pawel.vendor-thread@example.com',
        'cell_phone' => '2245550001',
        'primary_vendor_id' => $subjectVendor->id,
    ]);

    $subjectVendor->users()->attach($subjectUser->id, [
        'is_employed' => true,
        'role_id' => 1,
    ]);

    $client = Client::factory()->create();
    $project = Project::query()->create([
        'project_name' => 'Vendor Thread Project',
        'client_id' => $client->id,
        'address' => '3154 Violet Ln',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => 60062,
        'belongs_to_vendor_id' => $ownerVendor->id,
    ]);

    Task::query()->create([
        'title' => 'Foundation',
        'project_id' => $project->id,
        'vendor_id' => $subjectVendor->id,
        'type' => 'Task',
        'start_date' => today(),
        'end_date' => today(),
    ]);

    $thread = SmsGroupThread::query()->create([
        'name' => 'Pawel Bach',
        'from_number' => '+12245554444',
        'participants' => ['+12245550001'],
        'vendor_id' => $ownerVendor->id,
        'subject_vendor_id' => $subjectVendor->id,
        'last_activity_at' => now(),
    ]);

    $preview = Livewire::test(SendScheduleModal::class)
        ->call('open', $thread->id)
        ->get('previewMessage');

    expect($preview)->toStartWith('Hi Pawel,');
});
