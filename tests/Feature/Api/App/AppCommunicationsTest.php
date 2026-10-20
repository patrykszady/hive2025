<?php

use App\Models\CallLog;
use App\Models\Client;
use App\Models\EmailMessage;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// =========================================================================
// The app's Communications tab on a project (2026-10-19): the merged call/
// text/email timeline from ProjectCommunicationsTimeline, and one email's
// full detail — see App\Http\Controllers\Api\App\V1\
// ProjectCommunicationsController/EmailController and
// App\Http\Resources\Api\App\EmailResource.
// =========================================================================

beforeEach(function () {
    // No real Meilisearch round trips from fixture setup — same reasoning
    // as AppProjectsTest/AppCallsTest.
    config(['scout.driver' => null]);
});

/**
 * GS Construction is vendor id 1 — pinned explicitly (EnsureAppUserAllowed
 * requires it). $katie is a client contact with a cell phone, so a call/
 * text from her number resolves a name the same way
 * ProjectCommunicationsTimeline does on the web.
 *
 * @return array{vendor: Vendor, user: User, client: Client, katie: User, project: Project}
 */
function appCommWorld(int $role = 1): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $user = User::factory()->create(['first_name' => 'Patryk', 'last_name' => 'Crew', 'primary_vendor_id' => $vendor->id]);
    // Communications are Admin-only, like the web project page's card.
    $vendor->users()->attach($user->id, ['role_id' => $role, 'is_employed' => 1]);

    $client = Client::factory()->create();

    $katie = new User();
    $katie->forceFill([
        'first_name' => 'Katie',
        'last_name' => 'Love',
        'email' => 'katie.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'registration' => ['registered' => true],
    ]);
    $katie->save();
    $katie->clients()->attach($client->id);

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Bathrooms & Basement',
        'client_id' => $client->id,
        'belongs_to_vendor_id' => $vendor->id,
        'address' => '415 Lincoln Ave',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
    ]));
    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);

    if (! $client->vendors()->where('vendors.id', $vendor->id)->exists()) {
        $client->vendors()->attach($vendor->id);
    }

    ProjectStatus::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'status_code' => 6,
        'start_date' => today()->format('Y-m-d'),
    ]);

    return compact('vendor', 'user', 'client', 'katie', 'project');
}

/** A second vendor's project, not shared with GS Construction at all. */
function appCommForeignProject(): Project
{
    $otherVendor = Vendor::factory()->create();
    $otherClient = Client::factory()->create();

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Not Yours',
        'client_id' => $otherClient->id,
        'belongs_to_vendor_id' => $otherVendor->id,
        'address' => '2 Other St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
    ]));
    $project->vendors()->attach($otherVendor->id, ['client_id' => $otherClient->id]);

    return $project;
}

it('needs a token for the project timeline and for an email\'s detail', function () {
    $this->getJson('/api/app/v1/projects/1/communications')->assertUnauthorized();
    $this->getJson('/api/app/v1/emails/1')->assertUnauthorized();
});

it('merges a project\'s calls, texts and emails newest first, paged and filterable by kind', function () {
    $world = appCommWorld();
    Sanctum::actingAs($world['user'], ['app']);
    $digits = substr(preg_replace('/\D/', '', $world['katie']->cell_phone), -10);

    $call = CallLog::withoutEvents(fn () => CallLog::create([
        'direction' => 'incoming', 'from_number' => '+1'.$digits, 'to_number' => '+12247354200', 'status' => 'completed', 'duration_seconds' => 95,
    ]));
    $call->forceFill(['created_at' => now()->subHours(3)])->saveQuietly();

    $thread = SmsGroupThread::withoutEvents(fn () => SmsGroupThread::create(['client_id' => $world['client']->id, 'participants' => ['+1'.$digits], 'from_number' => '+12247354200']));
    $text = SmsMessage::withoutEvents(fn () => SmsMessage::create(['thread_id' => $thread->id, 'direction' => 'inbound', 'from_number' => '+1'.$digits, 'text' => 'Thursday works!']));
    $text->forceFill(['created_at' => now()->subHours(2)])->saveQuietly();

    // Explicit project_id (not just client_id) on every email fixture —
    // EmailMessage::forProject()'s "no project yet" allowance is being
    // narrowed on main to only unsummarized mail, so timeline tests here
    // don't lean on that behaviour at all.
    $email = EmailMessage::factory()->create([
        'belongs_to_vendor_id' => $world['vendor']->id,
        'client_id' => $world['client']->id,
        'project_id' => $world['project']->id,
        'subject' => 'Grout',
        'summary' => 'Katie reports cracked grout.',
        'sent_at' => now()->subHour(),
        'attachments' => [['path' => 'emails/x.jpg', 'name' => 'x.jpg', 'mime' => 'image/jpeg', 'size' => 1]],
    ]);

    $response = $this->getJson("/api/app/v1/projects/{$world['project']->id}/communications")->assertOk();

    expect(collect($response->json('data'))->map(fn ($item) => $item['kind'].':'.$item['id'])->all())
        ->toBe(["email:{$email->id}", "sms:{$text->id}", "call:{$call->id}"])
        ->and($response->json('data.0.attachment_count'))->toBe(1)
        ->and($response->json('data.1.who'))->toBe('Katie Love')
        ->and($response->json('data.2.direction'))->toBe('inbound')
        ->and($response->json('next_before'))->toBeNull();

    $firstPage = $this->getJson("/api/app/v1/projects/{$world['project']->id}/communications?limit=2")->assertOk();
    expect($firstPage->json('data'))->toHaveCount(2)
        ->and($firstPage->json('next_before'))->not->toBeNull();

    $secondPage = $this->getJson("/api/app/v1/projects/{$world['project']->id}/communications?limit=2&before=".urlencode((string) $firstPage->json('next_before')))->assertOk();
    expect(collect($secondPage->json('data'))->pluck('kind')->all())->toBe(['call']);

    $onlyEmails = $this->getJson("/api/app/v1/projects/{$world['project']->id}/communications?kinds[]=email")->assertOk();
    expect(collect($onlyEmails->json('data'))->pluck('kind')->unique()->all())->toBe(['email']);
});

it('rejects a bad kinds[] value and an out-of-range limit', function () {
    $world = appCommWorld();
    Sanctum::actingAs($world['user'], ['app']);

    $this->getJson("/api/app/v1/projects/{$world['project']->id}/communications?kinds[]=fax")->assertStatus(422);
    $this->getJson("/api/app/v1/projects/{$world['project']->id}/communications?limit=0")->assertStatus(422);
    $this->getJson("/api/app/v1/projects/{$world['project']->id}/communications?limit=101")->assertStatus(422);
});

it('keeps another vendor\'s project out of reach, and says when it does not exist', function () {
    $world = appCommWorld();
    Sanctum::actingAs($world['user'], ['app']);

    $theirs = appCommForeignProject();

    $this->getJson("/api/app/v1/projects/{$theirs->id}/communications")->assertForbidden();
    $this->getJson('/api/app/v1/projects/999999/communications')->assertNotFound();
});

it('keeps another vendor\'s email out of reach, and says when it does not exist', function () {
    $world = appCommWorld();
    Sanctum::actingAs($world['user'], ['app']);

    $theirs = EmailMessage::factory()->create(['belongs_to_vendor_id' => Vendor::factory()->create()->id]);

    $this->getJson("/api/app/v1/emails/{$theirs->id}")->assertForbidden();
    $this->getJson('/api/app/v1/emails/999999')->assertNotFound();
});

it('shows an email on no project only to the Admins who sort the unplaced inbox', function () {
    $world = appCommWorld(role: 2);
    Sanctum::actingAs($world['user'], ['app']);

    $unrelatedClient = Client::factory()->create();
    $email = EmailMessage::factory()->create([
        'belongs_to_vendor_id' => $world['vendor']->id,
        'client_id' => $unrelatedClient->id,
        'project_id' => null,
    ]);

    $this->getJson("/api/app/v1/emails/{$email->id}")->assertForbidden();

    $admin = User::factory()->create(['primary_vendor_id' => $world['vendor']->id]);
    $world['vendor']->users()->attach($admin->id, ['role_id' => 1, 'is_employed' => 1]);
    Sanctum::actingAs($admin, ['app']);

    $this->getJson("/api/app/v1/emails/{$email->id}")->assertOk();
});

it('shows an email filed with no project yet, once the viewer can see any of its client\'s projects', function () {
    $world = appCommWorld();
    Sanctum::actingAs($world['user'], ['app']);

    $email = EmailMessage::factory()->create([
        'belongs_to_vendor_id' => $world['vendor']->id,
        'client_id' => $world['client']->id,
        'project_id' => null,
        'summarized_at' => now(),
    ]);

    $this->getJson("/api/app/v1/emails/{$email->id}")
        ->assertOk()
        ->assertJsonPath('data.project', null);
});

it('shows an email\'s full detail: AI read, latest-part body, and a downloadable signed attachment', function () {
    $world = appCommWorld();
    Sanctum::actingAs($world['user'], ['app']);
    Storage::fake('files');
    Storage::disk('files')->put('emails/1/photo.jpg', 'fake-image-bytes');
    Storage::disk('files')->put('emails/1/quote.pdf', '%PDF fake');

    $email = EmailMessage::factory()->create([
        'belongs_to_vendor_id' => $world['vendor']->id,
        'client_id' => $world['client']->id,
        'project_id' => $world['project']->id,
        'from_email' => $world['katie']->email,
        'from_name' => 'Katie Love',
        'to' => ['office@gs.construction'],
        'cc' => ['jon@example.test'],
        'subject' => 'Grout',
        'summary' => 'Katie reports cracked grout.',
        'action_items' => ['Seal the grout'],
        'topics' => ['grout'],
        'next_steps' => ['Schedule a visit'],
        'sentiment' => 'neutral',
        'intent' => 'service request',
        'body_text' => "Can you come seal the grout?\n\nOn Mon, Oct 12, 2026 at 9:00 AM Patryk wrote:\n> Sure thing.",
        'attachments' => [
            ['path' => 'emails/1/photo.jpg', 'name' => 'photo.jpg', 'mime' => 'image/jpeg', 'size' => 17],
            ['path' => 'emails/1/quote.pdf', 'name' => 'quote.pdf', 'mime' => 'application/pdf', 'size' => 9],
        ],
    ]);

    $response = $this->getJson("/api/app/v1/emails/{$email->id}")->assertOk();

    expect($response->json('data.subject'))->toBe('Grout')
        ->and($response->json('data.direction'))->toBe('inbound')
        ->and($response->json('data.from'))->toBe(['email' => $world['katie']->email, 'name' => 'Katie Love'])
        ->and($response->json('data.to'))->toBe(['office@gs.construction'])
        ->and($response->json('data.cc'))->toBe(['jon@example.test'])
        ->and($response->json('data.summary'))->toBe('Katie reports cracked grout.')
        ->and($response->json('data.action_items'))->toBe(['Seal the grout'])
        ->and($response->json('data.topics'))->toBe(['grout'])
        ->and($response->json('data.next_steps'))->toBe(['Schedule a visit'])
        ->and($response->json('data.sentiment'))->toBe('neutral')
        ->and($response->json('data.intent'))->toBe('service request')
        ->and($response->json('data.body_latest'))->toBe('Can you come seal the grout?')
        ->and($response->json('data.body_full'))->toContain('On Mon, Oct 12, 2026')
        ->and($response->json('data.project'))->toBe(['id' => $world['project']->id, 'name' => $world['project']->project_name])
        ->and($response->json('data.attachments.0.name'))->toBe('photo.jpg')
        ->and($response->json('data.attachments.0.thumb_url'))->not->toBeNull()
        ->and($response->json('data.attachments.1.thumb_url'))->toBeNull();

    // The signed links download with no session, like the web/app's other
    // attachment routes (see AppCallsTest's recording_url assertion).
    auth()->forgetGuards();
    $this->get($response->json('data.attachments.0.url'))->assertOk();
    $this->get($response->json('data.attachments.1.url'))->assertOk();
});

it('keeps communications to Admins, like the web project page, and says so on the project', function () {
    ['user' => $member, 'project' => $project, 'client' => $client, 'vendor' => $vendor] = appCommWorld(role: 2);
    $email = EmailMessage::factory()->create(['belongs_to_vendor_id' => $vendor->id, 'client_id' => $client->id, 'project_id' => $project->id]);
    Sanctum::actingAs($member, ['app']);

    $this->getJson("/api/app/v1/projects/{$project->id}/communications")->assertForbidden();
    $this->getJson("/api/app/v1/emails/{$email->id}")->assertForbidden();
    $this->getJson("/api/app/v1/projects/{$project->id}")->assertOk()->assertJsonPath('data.permissions.view_communications', false);

    $admin = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($admin->id, ['role_id' => 1, 'is_employed' => 1]);
    Sanctum::actingAs($admin, ['app']);

    $this->getJson("/api/app/v1/projects/{$project->id}")->assertOk()->assertJsonPath('data.permissions.view_communications', true);
});

// =========================================================================
// The unplaced email inbox (/emails/unplaced): emails the AI could not put
// on a project, for an Admin to sort — App\Http\Controllers\Api\App\V1\
// EmailController::unplaced(), mirroring App\Livewire\Emails\
// UnplacedEmails.
// =========================================================================

it('needs a token to list the unplaced inbox', function () {
    $this->getJson('/api/app/v1/emails/unplaced')->assertUnauthorized();
});

it('keeps the unplaced inbox to Admins only, like the web', function () {
    $world = appCommWorld(role: 2);
    Sanctum::actingAs($world['user'], ['app']);

    $this->getJson('/api/app/v1/emails/unplaced')->assertForbidden();
});

it('lists unplaced emails newest first, excluding placed/dismissed/unsummarized ones', function () {
    $world = appCommWorld();
    Sanctum::actingAs($world['user'], ['app']);

    $unplaced = EmailMessage::factory()->create([
        'belongs_to_vendor_id' => $world['vendor']->id, 'subject' => 'Unplaced', 'project_id' => null,
        'summarized_at' => now(), 'unplaced_dismissed_at' => null, 'sent_at' => now(),
    ]);
    EmailMessage::factory()->create([
        'belongs_to_vendor_id' => $world['vendor']->id, 'subject' => 'Already placed', 'project_id' => $world['project']->id,
        'summarized_at' => now(), 'sent_at' => now(),
    ]);
    EmailMessage::factory()->create([
        'belongs_to_vendor_id' => $world['vendor']->id, 'subject' => 'Dismissed', 'project_id' => null,
        'summarized_at' => now(), 'unplaced_dismissed_at' => now(), 'sent_at' => now(),
    ]);
    EmailMessage::factory()->create([
        'belongs_to_vendor_id' => $world['vendor']->id, 'subject' => 'Not yet summarized', 'project_id' => null,
        'summarized_at' => null, 'sent_at' => now(),
    ]);
    EmailMessage::factory()->create([
        'belongs_to_vendor_id' => Vendor::factory()->create()->id, 'subject' => 'Someone else\'s inbox', 'project_id' => null,
        'summarized_at' => now(), 'sent_at' => now(),
    ]);

    $response = $this->getJson('/api/app/v1/emails/unplaced')->assertOk();

    expect(collect($response->json('data'))->pluck('subject')->all())->toBe(['Unplaced']);
});

it('pages the unplaced inbox 25 at a time, like the web\'s own paginator, instead of hard-capping at 100', function () {
    $world = appCommWorld();
    Sanctum::actingAs($world['user'], ['app']);

    collect(range(1, 30))->each(fn (int $i) => EmailMessage::factory()->create([
        'belongs_to_vendor_id' => $world['vendor']->id,
        'subject' => "Email {$i}",
        'project_id' => null,
        'summarized_at' => now(),
        'sent_at' => now()->subMinutes($i),
    ]));

    $first = $this->getJson('/api/app/v1/emails/unplaced')->assertOk();
    expect($first->json('data'))->toHaveCount(25)
        ->and($first->json('next_cursor'))->not->toBeNull();

    $second = $this->getJson('/api/app/v1/emails/unplaced?cursor='.$first->json('next_cursor'))->assertOk();
    expect($second->json('data'))->toHaveCount(5)
        ->and($second->json('next_cursor'))->toBeNull();

    // No email appears on both pages.
    $firstIds = collect($first->json('data'))->pluck('id');
    $secondIds = collect($second->json('data'))->pluck('id');
    expect($firstIds->intersect($secondIds))->toBeEmpty();
});
