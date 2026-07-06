<?php

use App\Livewire\Tasks\TaskCreate;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('stores sms image urls on task options when prefilling from sms', function (): void {
    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $user = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner.task-sms-media@example.com',
        'cell_phone' => (string) random_int(2000000000, 9999999999),
        'primary_vendor_id' => $vendor->id,
    ]);
    $vendor->users()->attach($user->id, ['is_employed' => true, 'role_id' => 1]);

    test()->actingAs($user);

    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);

    $project = Project::query()->create([
        'project_name' => 'Project ' . uniqid(),
        'client_id' => $client->id,
        'belongs_to_vendor_id' => $vendor->id,
        'address' => '123 Main St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => 60601,
    ]);

    Livewire::test(TaskCreate::class)
        ->call('prefillTaskFromSms', [
            'title' => 'Fix Electrical Outlet',
            'type' => 'Task',
            'project_id' => $project->id,
            'client_id' => $client->id,
            'vendor_id' => $vendor->id,
            'checklist' => [
                ['text' => 'First item', 'completed' => false],
                ['text' => 'Second item', 'completed' => false],
            ],
            'sms_media_urls' => [
                '/files/sms_media/sms-media/example-one.jpg',
                '/files/sms_media/sms-media/example-two.jpg',
            ],
        ])
        ->assertSet('form.title', 'Fix Electrical Outlet')
        ->assertSet('form.checklist.0.text', 'First item')
        ->assertSet('form.checklist.1.text', 'Second item');

    $task = \App\Models\Task::query()->latest('id')->first();

    expect($task)->not->toBeNull()
        ->and(data_get($task?->options, 'sms_media_urls'))->toBe([
            '/files/sms_media/sms-media/example-one.jpg',
            '/files/sms_media/sms-media/example-two.jpg',
        ]);
});
