<?php

use App\Jobs\SendGroupMms;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectTimelapse;
use App\Models\ProjectTimelapseFrame;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\SmsThreadParticipant;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** @return array{vendor: Vendor, member: User} */
function appMsgImagesTeamUser(): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $member = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($member->id, ['role_id' => 2]);

    return compact('vendor', 'member');
}

function appMsgImagesProject(Vendor $vendor, ?Client $client = null): Project
{
    $client ??= Client::factory()->create();
    $client->vendors()->attach($vendor->id);

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Deck Build',
        'client_id' => $client->id,
        'address' => '55 Oak Ave',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => '60062',
        'belongs_to_vendor_id' => $vendor->id,
    ]));
    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);

    return $project;
}

/** A thread with every participant already opted in, so sendToThread() is allowed. */
function appMsgImagesOptedInThread(Vendor $vendor, Client $client, string $participant = '+18475550101'): SmsGroupThread
{
    $thread = SmsGroupThread::create([
        'from_number' => '+12247354200',
        'client_id' => $client->id,
        'vendor_id' => $vendor->id,
        'participants' => [$participant],
        'last_activity_at' => now(),
    ]);
    SmsThreadParticipant::create(['thread_id' => $thread->id, 'phone_number' => $participant, 'opted_in_at' => now()]);

    return $thread;
}

it('needs a token for every message-images endpoint', function () {
    $this->getJson('/api/app/v1/projects/1/message-images')->assertUnauthorized();
    $this->getJson('/api/app/v1/projects/1/message-images/threads')->assertUnauthorized();
    $this->postJson('/api/app/v1/projects/1/message-images/text')->assertUnauthorized();
});

it('lists photos texted about the project, with sender grouping and filtering', function () {
    ['vendor' => $vendor, 'member' => $member] = appMsgImagesTeamUser();
    Sanctum::actingAs($member, ['app']);

    $client = Client::factory()->create();
    $project = appMsgImagesProject($vendor, $client);

    $thread = SmsGroupThread::create([
        'from_number' => '+12247354200',
        'client_id' => $client->id,
        'vendor_id' => $vendor->id,
        'participants' => ['+18475550101'],
    ]);

    SmsMessage::create([
        'thread_id' => $thread->id,
        'direction' => 'inbound',
        'from_number' => '+18475550101',
        'media_urls' => ['/storage/sms-media/2026/08/wall.jpg', '/storage/sms-media/2026/08/plans.pdf'],
    ]);

    $response = $this->getJson("/api/app/v1/projects/{$project->id}/message-images")->assertOk();

    // The PDF on the same message must not appear — only images.
    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.url'))->toContain('/files/sms_media/')
        ->and($response->json('data.0.raw'))->toBe('/storage/sms-media/2026/08/wall.jpg');

    $sender = $response->json('data.0.sender');

    $filtered = $this->getJson("/api/app/v1/projects/{$project->id}/message-images?sender=".urlencode($sender))->assertOk();
    expect($filtered->json('data'))->toHaveCount(1)
        ->and($filtered->json('active_sender'))->toBe($sender);

    $empty = $this->getJson("/api/app/v1/projects/{$project->id}/message-images?sender=".urlencode('Nobody Ever'))->assertOk();
    // A filter matching nothing clears itself rather than blanking the card.
    expect($empty->json('data'))->toHaveCount(1)
        ->and($empty->json('active_sender'))->toBeNull();
});

it('does not surface another tenant thread sharing the project client', function () {
    ['vendor' => $vendor, 'member' => $member] = appMsgImagesTeamUser();
    Sanctum::actingAs($member, ['app']);

    $client = Client::factory()->create();
    $project = appMsgImagesProject($vendor, $client);

    $otherVendor = Vendor::factory()->create();
    $client->vendors()->attach($otherVendor->id);
    $foreignThread = SmsGroupThread::create([
        'from_number' => '+13125550199',
        'participants' => ['+13125550100'],
        'vendor_id' => $otherVendor->id,
        'client_id' => $client->id,
    ]);
    SmsMessage::create([
        'thread_id' => $foreignThread->id,
        'direction' => 'inbound',
        'from_number' => '+13125550100',
        'media_urls' => ['sms-media/foreign.jpg'],
    ]);

    $response = $this->getJson("/api/app/v1/projects/{$project->id}/message-images")->assertOk();
    expect($response->json('data'))->toBeEmpty();
});

it('lists conversations the photos could go to, filtered by search', function () {
    ['vendor' => $vendor, 'member' => $member] = appMsgImagesTeamUser();
    Sanctum::actingAs($member, ['app']);
    $project = appMsgImagesProject($vendor);

    SmsGroupThread::create(['from_number' => '+12247354200', 'name' => 'Kitchen Crew', 'vendor_id' => $vendor->id, 'participants' => ['+18475550101'], 'last_activity_at' => now()]);
    SmsGroupThread::create(['from_number' => '+12247354201', 'name' => 'Office', 'vendor_id' => $vendor->id, 'participants' => ['+18475550102'], 'last_activity_at' => now()->subHour()]);

    $all = $this->getJson("/api/app/v1/projects/{$project->id}/message-images/threads")->assertOk();
    expect($all->json('data'))->toHaveCount(2)
        ->and($all->json('data.0.label'))->toBe('Kitchen Crew'); // most recent first

    $filtered = $this->getJson("/api/app/v1/projects/{$project->id}/message-images/threads?q=office")->assertOk();
    expect($filtered->json('data'))->toHaveCount(1)
        ->and($filtered->json('data.0.label'))->toBe('Office');
});

it('texts selected project photos to a conversation without ever running the send job', function () {
    Storage::fake('files');
    Queue::fake();
    ['vendor' => $vendor, 'member' => $member] = appMsgImagesTeamUser();
    Sanctum::actingAs($member, ['app']);

    $client = Client::factory()->create();
    $project = appMsgImagesProject($vendor, $client);
    $thread = appMsgImagesOptedInThread($vendor, $client);

    $album = ProjectTimelapse::generalFor($project);
    Storage::disk('files')->put("timelapse/{$project->id}/shot.jpg", 'BYTES');
    $frame = ProjectTimelapseFrame::create([
        'project_timelapse_id' => $album->id, 'filename' => 'shot.jpg',
        'path' => "timelapse/{$project->id}/shot.jpg", 'disk' => 'files', 'sort_order' => 1,
    ]);

    $response = $this->postJson("/api/app/v1/projects/{$project->id}/message-images/text", [
        'thread_id' => $thread->id,
        'frame_ids' => [$frame->id],
        'note' => 'Here is the deck so far',
    ])->assertOk();

    expect($response->json('data.sent'))->toBeTrue()
        ->and($response->json('data.count'))->toBe(1);

    expect(SmsMessage::where('thread_id', $thread->id)->where('text', 'Here is the deck so far')->exists())->toBeTrue();
    // The real send (Telnyx API call) lives in the queued job — faked, never run.
    Queue::assertPushed(SendGroupMms::class);
});

it('refuses texting with no photos selected, an unknown conversation, or a conversation awaiting START', function () {
    Storage::fake('files');
    Queue::fake();
    ['vendor' => $vendor, 'member' => $member] = appMsgImagesTeamUser();
    Sanctum::actingAs($member, ['app']);

    $client = Client::factory()->create();
    $project = appMsgImagesProject($vendor, $client);
    $thread = appMsgImagesOptedInThread($vendor, $client);

    $this->postJson("/api/app/v1/projects/{$project->id}/message-images/text", ['thread_id' => $thread->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('frame_ids');

    $album = ProjectTimelapse::generalFor($project);
    Storage::disk('files')->put("timelapse/{$project->id}/shot.jpg", 'BYTES');
    $frame = ProjectTimelapseFrame::create([
        'project_timelapse_id' => $album->id, 'filename' => 'shot.jpg',
        'path' => "timelapse/{$project->id}/shot.jpg", 'disk' => 'files', 'sort_order' => 1,
    ]);

    $this->postJson("/api/app/v1/projects/{$project->id}/message-images/text", [
        'thread_id' => 999999999,
        'frame_ids' => [$frame->id],
    ])->assertStatus(422)->assertJsonValidationErrors('thread_id');

    // A thread with a participant who never opted in.
    $pendingThread = SmsGroupThread::create([
        'from_number' => '+12247354299', 'client_id' => $client->id, 'vendor_id' => $vendor->id,
        'participants' => ['+18475550199'], 'last_activity_at' => now(),
    ]);

    $this->postJson("/api/app/v1/projects/{$project->id}/message-images/text", [
        'thread_id' => $pendingThread->id,
        'frame_ids' => [$frame->id],
    ])->assertStatus(422)->assertJson(['reason' => 'pending_opt_in']);

    Queue::assertNotPushed(SendGroupMms::class);
});
