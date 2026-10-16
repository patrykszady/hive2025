<?php

use App\Livewire\Projects\EmailTrackingTable;
use App\Models\Client;
use App\Models\EmailTracking;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * The project card shows one row per email sent: its type AND when it went
 * out. Opens of two different Signing Invites used to fold into one
 * "Opened x14" row (2026-10-15).
 *
 * @return array{0: Vendor, 1: User, 2: Project}
 */
function sendGroupingWorld(): array
{
    $vendor = Vendor::factory()->create();
    $admin = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Kitchen',
        'client_id' => Client::factory()->create()->id,
        'address' => '806 Parkside',
        'city' => 'Lake Zurich',
        'state' => 'IL',
        'zip_code' => '60047',
        'belongs_to_vendor_id' => $vendor->id,
    ]));

    return [$vendor, $admin, $project];
}

function trackedSend(Project $project, string $template, string $to, string $at): EmailTracking
{
    return EmailTracking::withoutGlobalScopes()->create([
        'belongs_to_vendor_id' => $project->belongs_to_vendor_id,
        'project_id' => $project->id,
        'event_type' => 'sent',
        'email_template_name' => $template,
        'recipient_emails' => [$to],
        'event_at' => $at,
    ]);
}

function trackedOpen(EmailTracking $sent, string $at): EmailTracking
{
    return EmailTracking::withoutGlobalScopes()->create([
        'belongs_to_vendor_id' => $sent->belongs_to_vendor_id,
        'project_id' => $sent->project_id,
        'event_type' => 'opened',
        'email_template_name' => $sent->email_template_name,
        'recipient_emails' => $sent->recipient_emails,
        'event_at' => $at,
        'metadata' => ['linked_sent_id' => $sent->id],
    ]);
}

/** @return list<string> "Opened x2 Signing Invite" per row, newest first */
function projectTrackingRows(User $admin, Project $project): array
{
    return collect(Livewire::actingAs($admin)
        ->test(EmailTrackingTable::class, ['projectId' => $project->id])
        ->instance()
        ->emailTrackingEvents()
        ->items())
        ->map(fn ($row) => ucfirst($row->event_type).' x'.$row->event_count.' '.$row->email_template_name)
        ->all();
}

it('keeps two sends of one email type on two rows, each with its own opens', function () {
    [, $admin, $project] = sendGroupingWorld();

    $older = trackedSend($project, 'Signing Invite', 'jess@example.com', '2026-09-01 10:00:00');
    trackedOpen($older, '2026-09-01 11:00:00');
    trackedOpen($older, '2026-09-02 09:00:00');
    $latest = trackedSend($project, 'Signing Invite', 'jess@example.com', '2026-10-14 10:00:00');
    trackedOpen($latest, '2026-10-14 12:00:00');

    expect(projectTrackingRows($admin, $project))->toBe([
        'Opened x1 Signing Invite',
        'Opened x2 Signing Invite',
    ]);
});

it('shows a send to a couple as one email, whoever opened it', function () {
    [, $admin, $project] = sendGroupingWorld();

    // One Signing Invite to husband and wife is two 'sent' rows a second
    // apart. Only Emily opens it; Jess's copy must not add a second row.
    $toEmily = trackedSend($project, 'Signing Invite', 'emily@example.com', '2026-06-10 15:52:40');
    trackedSend($project, 'Signing Invite', 'jess@example.com', '2026-06-10 15:52:41');
    trackedOpen($toEmily, '2026-06-10 16:00:00');
    trackedOpen($toEmily, '2026-06-12 08:00:00');

    expect(projectTrackingRows($admin, $project))->toBe(['Opened x2 Signing Invite']);
});

it('keeps different email types sent together on their own rows', function () {
    [, $admin, $project] = sendGroupingWorld();

    $estimate = trackedSend($project, 'Initial Estimate', 'jess@example.com', '2026-08-19 19:04:38');
    $invite = trackedSend($project, 'Signing Invite', 'jess@example.com', '2026-08-19 19:04:39');
    trackedOpen($estimate, '2026-08-19 20:00:00');
    trackedOpen($invite, '2026-08-19 20:00:05');

    expect(projectTrackingRows($admin, $project))->toBe([
        'Opened x1 Signing Invite',
        'Opened x1 Initial Estimate',
    ]);
});

it('orders rows by when each email was sent, not by its latest open', function () {
    [, $admin, $project] = sendGroupingWorld();

    $estimate = trackedSend($project, 'Initial Estimate', 'jess@example.com', '2026-08-19 19:04:38');
    trackedSend($project, 'Estimate Follow Up', 'jess@example.com', '2026-09-18 00:01:31');
    trackedOpen($estimate, '2026-10-14 12:00:00');

    expect(projectTrackingRows($admin, $project))->toBe([
        'Sent x1 Estimate Follow Up',
        'Opened x1 Initial Estimate',
    ]);
});

it('shows the two newest sends before the history toggle, dated when they went out in the company\'s time', function () {
    [$vendor, $admin, $project] = sendGroupingWorld();
    $vendor->update(['timezone' => 'America/Chicago']);
    $this->travelTo('2026-10-16 03:00:00');

    $latest = trackedSend($project, 'Estimate Follow Up', 'jess@example.com', '2026-10-15 23:50:58');
    trackedOpen($latest, '2026-10-16 00:33:51');
    trackedSend($project, 'Estimate Follow Up', 'jess@example.com', '2026-09-18 00:01:31');
    trackedSend($project, 'Initial Estimate', 'jess@example.com', '2026-08-19 19:04:38');

    $html = Livewire::actingAs($admin)
        ->test(EmailTrackingTable::class, ['projectId' => $project->id])
        ->html();
    [$visible, $history] = explode('x-collapse', $html, 2);

    expect($visible)->toContain('>Sent<')
        ->toContain('Sent 10/15/26 6:50 PM · Opened 10/15/26 7:33 PM')
        ->toContain('09/17/26')
        ->not->toContain('Initial Estimate')
        ->and($history)->toContain('Initial Estimate')
        ->toContain('08/19/26');
});
