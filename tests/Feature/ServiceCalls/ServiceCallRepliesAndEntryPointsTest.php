<?php

/**
 * The schedule-link email and text a service call sends, and the three ways
 * an email reaches the intake: Hive's own inbox reader, a lead the site
 * pushes, and the site asking before it files a lead.
 */

use App\Jobs\SendServiceCallScheduleEmail;
use App\Jobs\TextServiceCallScheduleLink;
use App\Mail\ServiceCallScheduleMail;
use App\Models\AppNotification;
use App\Models\Lead;
use App\Models\Project;
use App\Models\ServiceCallEmail;
use App\Models\SmsGroupThread;
use App\Models\Task;
use App\Services\CrewLeadEmailService;
use App\Services\GroupSmsService;
use App\Services\ServiceCalls\ServiceCallEmailIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/t3-fixtures.php';
require_once __DIR__.'/../../Support/service-call-fixtures.php';

beforeEach(function () {
    config([
        'service_calls.email_intake.enabled' => true,
        'services.openai.api_key' => 'test-key',
        'scout.driver' => null,
        'email_tracking.provider' => 'mailtrap',
        'mail.from.address' => 'hello@hive.contractors',
    ]);
    Queue::fake();
});

function openedServiceCall(): array
{
    $world = serviceCallWorld();
    $world['vendor']->forceFill(['business_email' => 'crew.'.uniqid().'@gs.construction'])->save();
    fakeServiceCallVerdict(['project_id' => $world['project']->id]);
    $row = app(ServiceCallEmailIntake::class)->consider(katieEmail($world));

    return $world + ['row' => $row];
}

it('emails the schedule link as a reply in the client\'s own thread, once', function () {
    Mail::fake();
    ['row' => $row, 'katie' => $katie, 'jon' => $jon, 'project' => $project, 'vendor' => $vendor] = openedServiceCall();

    (new SendServiceCallScheduleEmail($row->id))->handle();
    (new SendServiceCallScheduleEmail($row->id))->handle();

    $token = Project::withoutGlobalScopes()->find($project->id)->schedule_token;
    expect($token)->not->toBeEmpty();

    Mail::assertSent(ServiceCallScheduleMail::class, function (ServiceCallScheduleMail $mail) use ($katie, $jon, $token) {
        return $mail->hasTo($katie->email)
            && $mail->hasTo(mb_strtolower($jon->email))
            && $mail->emailSubject === 'Re: Grout'
            && $mail->inReplyTo === '<katie-original@mail.gmail.com>'
            && str_contains($mail->emailBody, 'Hi Katie,')
            && str_contains($mail->emailBody, 'the cracked grout in both bathrooms')
            && str_contains($mail->emailBody, "/s/{$token}");
    });
    // The client's copy and the office copy — and nothing on the second run.
    Mail::assertSent(ServiceCallScheduleMail::class, 2);
    Mail::assertSent(ServiceCallScheduleMail::class, fn ($mail) => $mail->hasTo($vendor->business_email));

    expect($row->fresh()->emailed_at)->not->toBeNull();
});

it('texts the client the schedule link through the normal SMS pipeline, once', function () {
    ['row' => $row, 'katie' => $katie, 'project' => $project, 'admin' => $admin] = openedServiceCall();

    $this->mock(GroupSmsService::class)
        ->shouldReceive('sendNewGroup')
        ->once()
        ->withArgs(function (array $phones, string $text, ?int $projectId, ?int $clientId, ?int $sentBy) use ($katie, $project, $admin) {
            return $phones === [GroupSmsService::formatE164($katie->cell_phone)]
                && str_starts_with($text, "Hi Katie,\n\nThanks for letting us know about the cracked grout in both bathrooms.")
                && (str_contains($text, '/s/') || str_contains($text, '/l/'))
                && $projectId === $project->id
                && $clientId === $project->client_id
                && $sentBy === $admin->id;
        })
        ->andReturn(new SmsGroupThread);

    app()->call([new TextServiceCallScheduleLink($row->id), 'handle']);
    app()->call([new TextServiceCallScheduleLink($row->id), 'handle']);

    expect($row->fresh()->texted_at)->not->toBeNull();
});

it('opens the call from Hive\'s own inbox reader instead of filing a lead reply', function () {
    $world = serviceCallWorld();
    fakeServiceCallVerdict(['project_id' => $world['project']->id]);

    $outcome = app(CrewLeadEmailService::class)->processPersonalInboxMessage([
        'id' => 'nylas-msg-1',
        'thread_id' => 'nylas-thread-1',
        'date' => now()->subMinutes(5)->getTimestamp(),
        'subject' => 'Grout',
        'from' => [['email' => $world['katie']->email, 'name' => 'Katie Love']],
        'to' => [['email' => $world['mailbox']]],
        'cc' => [['email' => $world['jon']->email]],
        'body' => '<p>I have officially noticed grout cracking in both bathrooms -- could we find a time for you to come seal those up?</p>',
        'headers' => [['name' => 'Message-ID', 'value' => '<katie-original@mail.gmail.com>']],
    ], 'grant-1', $world['mailbox']);

    expect($outcome)->toBe('service_call');

    $row = ServiceCallEmail::sole();
    expect($row->message_key)->toBe(sha1('<katie-original@mail.gmail.com>'))
        ->and($row->outcome)->toBe(ServiceCallEmail::OUTCOME_OPENED)
        ->and(data_get($row->verdict, 'reply.rfc_message_id'))->toBe('<katie-original@mail.gmail.com>');
});

it('archives the lead the site pushes for a service-call email and tells it so', function () {
    $world = serviceCallWorld();
    fakeServiceCallVerdict(['project_id' => $world['project']->id]);
    Sanctum::actingAs($world['admin']);
    $leadsBefore = Lead::withoutGlobalScopes()->whereNull('deleted_at')->count();
    $key = sha1('<katie-original@mail.gmail.com>');

    $payload = [
        'external_id' => $key,
        'source' => 'crew-email',
        'name' => 'Katie Love',
        'email' => $world['katie']->email,
        'subject' => 'Grout',
        'message' => 'I have officially noticed grout cracking in both bathrooms -- could we find a time for you to come seal those up?',
        'in_reply_to' => '<katie-original@mail.gmail.com>',
        'extracted' => ['mailbox' => $world['mailbox'], 'is_lead' => true, 'confidence' => 0.9],
    ];

    $response = $this->postJson('/api/v1/leads', $payload)
        ->assertSuccessful()
        ->assertJsonPath('created', false)
        ->assertJsonPath('service_call.project_id', $world['project']->id);

    $leadId = $response->json('data.id');
    expect(Lead::withoutGlobalScopes()->whereNull('deleted_at')->count())->toBe($leadsBefore)
        ->and(Lead::withoutGlobalScopes()->onlyTrashed()->find($leadId))->not->toBeNull()
        ->and(AppNotification::where('type', 'lead_created')->exists())->toBeFalse()
        ->and(Task::withoutGlobalScopes()->where('project_id', $world['project']->id)->count())->toBe(1);

    // A retry from the site gets the same archived lead, not another call.
    $this->postJson('/api/v1/leads', $payload)->assertSuccessful()->assertJsonPath('data.id', $leadId);
    expect(Task::withoutGlobalScopes()->where('project_id', $world['project']->id)->count())->toBe(1);
});

it('answers the site\'s pre-check: service call or not', function () {
    $world = serviceCallWorld();
    fakeServiceCallVerdict(['project_id' => $world['project']->id]);
    Sanctum::actingAs($world['admin']);

    $this->postJson('/api/v1/service-calls/from-email', [
        'message_key' => sha1('<katie-original@mail.gmail.com>'),
        'from_email' => $world['katie']->email,
        'from_name' => 'Katie Love',
        'cc' => [$world['jon']->email],
        'subject' => 'Grout',
        'body' => 'I have officially noticed grout cracking in both bathrooms -- could we find a time for you to come seal those up?',
        'mailbox' => $world['mailbox'],
        'received_at' => now()->subMinutes(3)->toIso8601String(),
    ])->assertSuccessful()
        ->assertJsonPath('service_call', true)
        ->assertJsonPath('project_id', $world['project']->id);

    $this->postJson('/api/v1/service-calls/from-email', [
        'message_key' => sha1('<someone-new@example.test>'),
        'from_email' => 'new.homeowner@example.test',
        'subject' => 'Kitchen remodel',
        'body' => 'Looking for a quote on a kitchen.',
    ])->assertSuccessful()->assertJsonPath('service_call', false);

    $this->postJson('/api/v1/service-calls/from-email', [])->assertUnprocessable();
});
