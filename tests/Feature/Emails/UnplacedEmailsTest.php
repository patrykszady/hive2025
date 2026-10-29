<?php

/**
 * Emails the AI read but could not put on a project wait in the unplaced
 * inbox — on the web (/emails/unplaced, with a sidebar alert) and in the
 * app — until an Admin puts each on a project or dismisses it.
 */

use App\Jobs\ExtractProjectActionItems;
use App\Livewire\AppSidebar;
use App\Livewire\Emails\UnplacedEmails;
use App\Models\EmailMessage;
use App\Models\ProjectStatus;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/t3-fixtures.php';
require_once __DIR__.'/../../Support/service-call-fixtures.php';

beforeEach(function () {
    config(['scout.driver' => null]);
    Queue::fake();
});

/** Jenn's "Abbey plumbing" email: read by the AI, none of her projects fits. */
function unplacedEmail(array $world, array $overrides = []): EmailMessage
{
    return EmailMessage::factory()->create(array_merge([
        'belongs_to_vendor_id' => $world['vendor']->id,
        'client_id' => $world['client']->id,
        'from_email' => $world['katie']->email,
        'from_name' => 'Katie Love',
        'subject' => 'Abbey plumbing delivery',
        'summary' => 'Asks whether the plumbing order arrived and can be delivered Wednesday.',
        'action_items' => ['Patryk: Confirm the plumbing delivery for Wednesday'],
        'candidate_project_ids' => [$world['project']->id],
        'summarized_at' => now(),
    ], $overrides));
}

it('counts only read, unplaced, undismissed email as waiting', function () {
    $world = serviceCallWorld();
    $waiting = unplacedEmail($world);
    unplacedEmail($world, ['summarized_at' => null]);
    unplacedEmail($world, ['project_id' => $world['project']->id]);
    unplacedEmail($world, ['unplaced_dismissed_at' => now()]);

    expect(EmailMessage::unplaced()->pluck('id')->all())->toBe([$waiting->id])
        ->and($waiting->isUnplaced())->toBeTrue();
});

it('lets an Admin put an email on one of its candidate projects', function () {
    $world = serviceCallWorld();
    $email = unplacedEmail($world);
    $this->actingAs($world['admin']);

    Livewire::test(UnplacedEmails::class)
        ->assertSee('Abbey plumbing delivery')
        ->call('open', $email->id)
        ->assertSet('showEmailModal', true)
        ->assertSee('Which project is this about?')
        ->assertSee('Bathrooms & Basement')
        ->call('assign', $world['project']->id)
        ->assertSet('showEmailModal', false)
        ->assertDontSee('Abbey plumbing delivery');

    expect($email->fresh()->project_id)->toBe($world['project']->id);
    Queue::assertPushed(ExtractProjectActionItems::class, fn ($job) => $job->projectId === $world['project']->id);
});

it('brings the rest of the thread along when an email is put on a project', function () {
    $world = serviceCallWorld();
    $jenn = unplacedEmail($world, ['thread_id' => 'thread-abbey']);
    $kristin = unplacedEmail($world, ['thread_id' => 'thread-abbey', 'subject' => 'Re: Abbey plumbing delivery', 'from_name' => 'Kristin Holthouse']);
    $other = unplacedEmail($world, ['thread_id' => 'thread-other']);

    app(\App\Services\Emails\EmailPlacement::class)->place($jenn, $world['project']->id, $world['admin']);

    expect($jenn->fresh()->project_id)->toBe($world['project']->id)
        ->and($kristin->fresh()->project_id)->toBe($world['project']->id)
        ->and($other->fresh()->project_id)->toBeNull();
});

it('rematches the unplaced inbox by thread and by a client named in the subject', function () {
    $world = serviceCallWorld();
    $abbeys = sec3_makeClient($world['vendor']);
    $erin = sec3_makeClientUser($abbeys, 'erin');
    $erin->forceFill(['last_name' => 'Abbey'])->save();
    $kitchen = sec3_makeProject($world['vendor'], $abbeys, ['project_name' => 'Kitchen']);
    \App\Models\ProjectStatus::create(['project_id' => $kitchen->id, 'belongs_to_vendor_id' => $world['vendor']->id, 'status_code' => 6, 'start_date' => today()->format('Y-m-d')]);

    $named = unplacedEmail($world, ['subject' => 'Abbey-plumbing delivery', 'thread_id' => 'thread-1']);
    $placedSibling = unplacedEmail($world, ['thread_id' => 'thread-2', 'project_id' => $world['project']->id]);
    $reply = unplacedEmail($world, ['subject' => 'Re: grout', 'thread_id' => 'thread-2']);
    $nothing = unplacedEmail($world, ['subject' => 'Lunch', 'thread_id' => 'thread-3']);

    $this->artisan('emails:rematch-unplaced')->assertSuccessful();

    expect($reply->fresh()->project_id)->toBe($world['project']->id)
        ->and($named->fresh()->summarized_at)->toBeNull()
        ->and($named->fresh()->candidate_project_ids)->toContain($kitchen->id)
        ->and($nothing->fresh()->summarized_at)->not->toBeNull();
    Queue::assertPushed(\App\Jobs\SummarizeEmailMessage::class, fn ($job) => $job->emailMessageId === $named->id);
    // The reply carried action items: like a manual sort, its project's list is refreshed.
    Queue::assertPushed(ExtractProjectActionItems::class, fn ($job) => $job->projectId === $world['project']->id);
    Queue::assertNotPushed(\App\Jobs\SummarizeEmailMessage::class, fn ($job) => $job->emailMessageId === $nothing->id);
});

it('puts an email on another open project, but never on another company\'s', function () {
    $world = serviceCallWorld();
    $email = unplacedEmail($world, ['action_items' => []]);
    $abbey = sec3_makeProject($world['vendor'], sec3_makeClient($world['vendor']), ['project_name' => 'Abbey Bath']);
    ProjectStatus::create(['project_id' => $abbey->id, 'belongs_to_vendor_id' => $world['vendor']->id, 'status_code' => 6, 'start_date' => today()->format('Y-m-d')]);
    $otherVendor = sec3_makeVendor('Other');
    $foreign = sec3_makeProject($otherVendor, sec3_makeClient($otherVendor));
    $this->actingAs($world['admin']);

    $page = Livewire::test(UnplacedEmails::class)->call('open', $email->id)->assertSee('Abbey Bath');

    $page->set('otherProjectId', $foreign->id)->call('assignOther')->assertHasErrors('project_id');
    expect($email->fresh()->project_id)->toBeNull();

    $page->set('otherProjectId', $abbey->id)->call('assignOther')->assertHasNoErrors();
    expect($email->fresh()->project_id)->toBe($abbey->id);
    Queue::assertNotPushed(ExtractProjectActionItems::class);
});

it('dismisses an email that is not about any project', function () {
    $world = serviceCallWorld();
    $email = unplacedEmail($world);
    $this->actingAs($world['admin']);

    Livewire::test(UnplacedEmails::class)->call('open', $email->id)->call('dismiss')->assertSee('Nothing to sort');

    expect($email->fresh()->unplaced_dismissed_at)->not->toBeNull()
        ->and(EmailMessage::unplaced()->count())->toBe(0);
});

it('keeps the inbox to Admins and only shows the sidebar alert while something waits', function () {
    $world = serviceCallWorld();
    $member = sec3_makeMember($world['vendor']);

    Livewire::actingAs($member)->test(UnplacedEmails::class)->assertForbidden();

    Cache::flush();
    $this->actingAs($world['admin']);
    Livewire::test(AppSidebar::class)->assertDontSee('Unplaced email');

    unplacedEmail($world);
    Livewire::test(AppSidebar::class)->assertSee('Unplaced email')->assertSee(route('emails.unplaced'));

    Cache::flush();
    $this->actingAs($member);
    Livewire::test(AppSidebar::class)->assertDontSee('Unplaced email');
});

it('sorts the inbox from the app too', function () {
    $world = serviceCallWorld(vendor: Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']));
    $email = unplacedEmail($world);
    $vendorOnly = unplacedEmail($world, ['client_id' => null, 'vendor_id' => Vendor::factory()->create()->id, 'subject' => 'Tile delivery']);
    $member = sec3_makeMember($world['vendor']);

    Sanctum::actingAs($member, ['app']);
    $this->getJson('/api/app/v1/emails/unplaced')->assertForbidden();
    $this->postJson("/api/app/v1/emails/{$email->id}/dismiss")->assertForbidden();
    $this->getJson('/api/app/v1/me')->assertOk()->assertJsonPath('user.permissions.unplaced_emails', false);

    Sanctum::actingAs($world['admin'], ['app']);
    $this->getJson('/api/app/v1/me')->assertOk()->assertJsonPath('user.permissions.unplaced_emails', true);
    $this->getJson('/api/app/v1/emails/unplaced')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.candidates.0.id', $world['project']->id);

    $this->getJson("/api/app/v1/emails/{$vendorOnly->id}")
        ->assertOk()
        ->assertJsonPath('data.unplaced', true);

    $this->postJson("/api/app/v1/emails/{$email->id}/place", ['project_id' => 999999])->assertUnprocessable();
    $this->postJson("/api/app/v1/emails/{$email->id}/place", ['project_id' => $world['project']->id])
        ->assertOk()
        ->assertJsonPath('data.unplaced', false)
        ->assertJsonPath('data.project.id', $world['project']->id)
        ->assertJsonPath('data.candidates', []);

    $this->postJson("/api/app/v1/emails/{$vendorOnly->id}/dismiss")->assertOk()->assertJsonPath('data.dismissed', true);
    $this->getJson('/api/app/v1/emails/unplaced')->assertOk()->assertJsonCount(0, 'data');
});

it('rematch puts an email already read with the same candidates on the client\'s one job in progress', function () {
    $world = serviceCallWorld(); // Bathrooms & Basement, Complete
    $kitchen = sec3_makeProject($world['vendor'], $world['client'], ['project_name' => 'Kitchen', 'address' => '415 Lincoln Ave']);
    ProjectStatus::create(['project_id' => $kitchen->id, 'belongs_to_vendor_id' => $world['vendor']->id, 'status_code' => 6, 'start_date' => today()->format('Y-m-d')]);
    $email = unplacedEmail($world, ['subject' => 'Fwd: Thank you for your delivery purchase', 'thread_id' => 'thread-9', 'body_text' => 'Delivery to 415 Lincoln Ave.']);
    $email->forceFill(['candidate_project_ids' => app(\App\Services\Emails\StakeholderEmailStore::class)->placementFor((string) $email->from_email, (array) $email->to, (array) $email->cc, (string) $email->subject, $email->thread_id, (int) $email->belongs_to_vendor_id, (string) $email->body_text)['candidate_ids']])->save();

    $this->artisan('emails:rematch-unplaced')->assertSuccessful();

    expect($email->fresh()->project_id)->toBe($kitchen->id);
});

