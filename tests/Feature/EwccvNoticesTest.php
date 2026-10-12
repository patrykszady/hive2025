<?php

use App\Console\Commands\SyncEwccvNotices;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorDoc;
use App\Notifications\EwccvNoticeNotification;
use App\Services\NylasService;
use App\Support\Ewccv\MailFolders;
use App\Support\Ewccv\NoticeParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// =========================================================================
// EWCCV notices (2026-10-12): the cancellation / non-renewal / reinstatement
// emails a tracked policy produces are read from the certificates mailbox,
// pinned to the workers comp doc, and texted to the admins.
// =========================================================================

/**
 * @return array{gs: Vendor, admin: User, doc: VendorDoc}
 */
function noticeFixture(): array
{
    $gs = Vendor::factory()->create(['business_name' => 'GS Construction']);
    $admin = User::factory()->create(['first_name' => 'Patryk', 'cell_phone' => '2249993880']);
    $gs->users()->attach($admin->id, ['role_id' => 1]);

    $sub = Vendor::factory()->create(['business_name' => 'Green Dump Inc']);
    $doc = VendorDoc::withoutEvents(fn () => VendorDoc::create([
        'type' => 'workers', 'vendor_id' => $sub->id, 'belongs_to_vendor_id' => $gs->id,
        'number' => 'WC5-39S-342438-046', 'doc_filename' => 'jw.pdf',
        'effective_date' => now()->subMonths(2)->toDateString(), 'expiration_date' => now()->addMonths(10)->toDateString(),
        'options' => ['ewccv' => ['status' => 'tracking', 'checked_at' => now()->subDay()->toIso8601String()]],
    ]));

    return ['gs' => $gs, 'admin' => $admin, 'doc' => $doc];
}

function ewccvMail(string $id, string $subject, string $body, int $minutesAgo = 30): array
{
    return [
        'id' => $id,
        'subject' => $subject,
        'from' => [['email' => 'donotreply@ewccv.com', 'name' => 'Workers Compensation Coverage Verification']],
        'body' => $body,
        'date' => now()->subMinutes($minutesAgo)->timestamp,
    ];
}

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    config(['nylas.certificates_grant_id' => 'grant-1']);
});

it('pins a cancellation to the policy it names, turns the doc red, texts the admins, and never twice', function () {
    ['gs' => $gs, 'admin' => $admin, 'doc' => $doc] = noticeFixture();

    $mails = ['data' => [
        ewccvMail('m-signin', 'Sign in to Workers Compensation Coverage Verification', '<p>Click the button below to Sign In</p>', 90),
        ewccvMail('m-cancel', 'Policy Cancellation Notice', '<p>A cancellation has been reported for the policy you are tracking.</p><p>Employer: JW GREEN DUMP INC<br>Policy Number: WC539S342438046<br>Effective: 10/12/2026</p>', 20),
    ]];
    $this->mock(NylasService::class, fn ($mock) => $mock->shouldReceive('getMessages')->andReturn($mails));

    $this->artisan('ewccv:notices', ['--belongs-to-vendor-id' => $gs->id, '--no-filing' => true])
        ->expectsOutputToContain('Notices 1, stamped 1, texts 1, unmatched 0, other mail 1, filed 0.')
        ->assertExitCode(0);

    $doc->refresh();
    expect($doc->options['ewccv']['status'])->toBe('cancelled')
        ->and($doc->options['ewccv']['notice']['kind'])->toBe('cancellation')
        ->and($doc->options['ewccv']['notice']['message_id'])->toBe('m-cancel')
        ->and($doc->ewccv_summary['tracking'])->toBeFalse()
        ->and($doc->ewccv_summary['alert'])->toBeTrue()
        ->and($doc->ewccv_summary['tip'])->toContain('CANCELLED')
        ->and($doc->verification_summary['alert'])->toBeTrue()
        ->and($doc->verification_summary['verified_at'])->toBeNull();

    Notification::assertSentTo($admin, EwccvNoticeNotification::class, function (EwccvNoticeNotification $n) use ($admin) {
        return $n->kind === 'cancellation'
            && str_contains($n->toTelnyx($admin), "Green Dump Inc's workers comp policy WC5-39S-342438-046 was CANCELLED");
    });
    expect(Storage::disk('local')->exists(SyncEwccvNotices::BOOKMARK))->toBeTrue();

    // The same mail read again (the bookmark overlaps an hour): nothing new.
    Notification::fake();
    $this->artisan('ewccv:notices', ['--belongs-to-vendor-id' => $gs->id, '--no-filing' => true])
        ->expectsOutputToContain('Notices 1, stamped 0, texts 0')
        ->assertExitCode(0);
    Notification::assertNothingSent();
});

it('a reinstatement puts the policy back to tracking, and an unmatched notice is reported, not dropped', function () {
    ['gs' => $gs, 'admin' => $admin, 'doc' => $doc] = noticeFixture();
    $doc->forceFill(['options' => ['ewccv' => ['status' => 'cancelled', 'notice' => ['kind' => 'cancellation', 'received_at' => now()->subDays(3)->toIso8601String(), 'message_id' => 'old']]]])->saveQuietly();

    $mails = ['data' => [
        ewccvMail('m-reinst', 'Policy Reinstatement Notice', 'The policy WC5-39S-342438-046 for JW GREEN DUMP INC has been reinstated.', 15),
        ewccvMail('m-stranger', 'Policy Cancellation Notice', 'Employer: SOMEONE ELSE LLC Policy Number: ZZ-999-111', 10),
    ]];
    $this->mock(NylasService::class, fn ($mock) => $mock->shouldReceive('getMessages')->andReturn($mails));

    $this->artisan('ewccv:notices', ['--belongs-to-vendor-id' => $gs->id, '--no-filing' => true])
        ->expectsOutputToContain('unmatched: ')
        ->expectsOutputToContain('Notices 2, stamped 1, texts 1, unmatched 1')
        ->assertExitCode(0);

    $doc->refresh();
    expect($doc->options['ewccv']['status'])->toBe('tracking')
        ->and($doc->ewccv_summary['tracking'])->toBeTrue()
        ->and($doc->ewccv_summary['alert'])->toBeFalse()
        ->and($doc->ewccv_summary['tip'])->toContain('reinstated');
    Notification::assertSentTo($admin, EwccvNoticeNotification::class, fn ($n) => $n->kind === 'reinstatement');
});

it('a dry run shows the matches and changes nothing', function () {
    ['gs' => $gs, 'doc' => $doc] = noticeFixture();
    $this->mock(NylasService::class, fn ($mock) => $mock->shouldReceive('getMessages')->andReturn(['data' => [
        ewccvMail('m-cancel', 'Policy Cancellation Notice', 'Policy Number: WC5-39S-342438-046 has been cancelled', 5),
    ]]));

    $this->artisan('ewccv:notices', ['--belongs-to-vendor-id' => $gs->id, '--dry-run' => true])
        ->expectsOutputToContain('Green Dump Inc')
        ->assertExitCode(0);

    expect($doc->fresh()->options['ewccv']['status'])->toBe('tracking')
        ->and(Storage::disk('local')->exists(SyncEwccvNotices::BOOKMARK))->toBeFalse();
    Notification::assertNothingSent();
});

it('classifies EWCCV mail and finds the policy numbers it names', function () {
    $cancel = ewccvMail('a', 'Coverage Cancellation', '<p>Policy No: R2WC-795013</p><p>Employer Name: MORENOS DRYWALL INC</p>');
    $nonrenewal = ewccvMail('b', 'Notice', 'The policy 83WECBP6H9V will not be renewed.');
    $confirm = ewccvMail('c', 'Workers Compensation Coverage Verification', 'You are now tracking policy AW9173630 for TBD PRO PAINTING.');
    $received = ewccvMail('c2', 'FW: We received your ewccv.com subscription', "Thank You For Subscribing! You have successfully subscribed this email address to receive notification of any cancellation/reinstatement for the following policy. Subscription Info Employer Name: MORENOS DRYWALL INC State: Illinois Policy Number: R2WC795013");
    $footer = ewccvMail('c3', 'Workers Compensation Coverage Verification', 'Your tracked policy R2WC795013 is active. To cancel this subscription, use the link below.');
    $signIn = ewccvMail('d', 'Sign in to Workers Compensation Coverage Verification', 'Click to sign in');
    $unrelated = ['id' => 'e', 'subject' => 'Re: COI Request', 'from' => [['email' => 'agent@insurance.test']], 'body' => 'Attached', 'date' => time()];

    expect(NoticeParser::kind($cancel))->toBe('cancellation')
        ->and(NoticeParser::policyNumbers($cancel))->toBe(['R2WC795013'])
        ->and(NoticeParser::employer($cancel))->toBe('MORENOS DRYWALL INC')
        ->and(NoticeParser::kind($nonrenewal))->toBe('nonrenewal')
        ->and(NoticeParser::policyNumbers($nonrenewal, ['83WECBP6H9V']))->toBe(['83WECBP6H9V'])
        ->and(NoticeParser::kind($confirm))->toBe('confirmation')
        ->and(NoticeParser::kind($received))->toBe('confirmation')
        ->and(NoticeParser::kind($footer))->toBe('other')
        ->and(NoticeParser::kind($signIn))->toBe('sign_in')
        ->and(NoticeParser::isFromEwccv($signIn))->toBeTrue()
        ->and(NoticeParser::isFromEwccv($unrelated))->toBeFalse();
});

it('files each EWCCV mail under Workers Comp by what it is, creating the subfolders once', function () {
    ['gs' => $gs] = noticeFixture();
    $root = 'root-id';
    $existing = ['data' => ['data' => [
        ['id' => 'inbox-id', 'name' => 'Inbox', 'parent_id' => $root],
        ['id' => 'wc-id', 'name' => 'Workers Comp', 'parent_id' => $root],
    ]]];
    $moved = [];
    $this->mock(NylasService::class, function ($mock) use ($existing, &$moved) {
        $mock->shouldReceive('getMessages')->andReturn(['data' => [
            ['id' => 'm-signin', 'subject' => 'Sign in to Workers Compensation Coverage Verification', 'from' => [['email' => 'donotreply@ewccv.com']], 'body' => 'Click', 'date' => now()->subMinutes(30)->timestamp, 'folders' => ['inbox-id']],
            ['id' => 'm-confirm', 'subject' => 'FW: We received your ewccv.com subscription', 'from' => [['email' => 'patryk.szady@live.com']], 'body' => 'We received your subscription for policy AFWCP100134391', 'date' => now()->subMinutes(20)->timestamp, 'folders' => ['wc-id']],
            ['id' => 'm-cancel', 'subject' => 'Policy Cancellation Notice', 'from' => [['email' => 'donotreply@ewccv.com']], 'body' => 'Policy Number: ZZ-999-111 cancelled', 'date' => now()->subMinutes(10)->timestamp, 'folders' => ['wc-id']],
        ]]);
        $mock->shouldReceive('getFolders')->once()->andReturn($existing);
        $mock->shouldReceive('createFolder')->once()->with('grant-1', MailFolders::SIGN_IN, 'wc-id')->andReturn(['status' => 200, 'data' => ['data' => ['id' => 'signin-id', 'name' => MailFolders::SIGN_IN, 'parent_id' => 'wc-id']]]);
        $mock->shouldReceive('createFolder')->once()->with('grant-1', MailFolders::SUBSCRIPTIONS, 'wc-id')->andReturn(['status' => 200, 'data' => ['data' => ['id' => 'subs-id', 'name' => MailFolders::SUBSCRIPTIONS, 'parent_id' => 'wc-id']]]);
        $mock->shouldReceive('moveEmailToFolder')->andReturnUsing(function ($messageId, $folderId) use (&$moved) {
            $moved[$messageId] = $folderId;

            return true;
        });
    });

    $this->artisan('ewccv:notices', ['--belongs-to-vendor-id' => $gs->id])
        ->expectsOutputToContain('filed 3.')
        ->assertExitCode(0);

    // The sign-in link and the confirmation move into their subfolders; the
    // cancellation already sits under Workers Comp and stays put.
    expect($moved)->toBe(['m-signin' => 'signin-id', 'm-confirm' => 'subs-id'])
        ->and(json_decode(Storage::disk('local')->get(MailFolders::FILE), true))->toBe(['Workers Comp' => 'wc-id', MailFolders::SIGN_IN => 'signin-id', MailFolders::SUBSCRIPTIONS => 'subs-id']);

    // Ids are remembered: a second run lists nothing and creates nothing.
    $this->artisan('ewccv:notices', ['--belongs-to-vendor-id' => $gs->id])->assertExitCode(0);
});

it('takes a wrong notice off a doc once its mail reads as a confirmation', function () {
    ['gs' => $gs, 'doc' => $doc] = noticeFixture();
    $doc->forceFill(['options' => ['ewccv' => ['status' => 'tracking', 'notice' => ['kind' => 'reinstatement', 'message_id' => 'm-conf', 'received_at' => now()->subHour()->toIso8601String()], 'notice_message_ids' => ['m-conf']]]])->saveQuietly();
    $this->mock(NylasService::class, fn ($mock) => $mock->shouldReceive('getMessages')->andReturn(['data' => [
        ewccvMail('m-conf', 'FW: We received your ewccv.com subscription', 'Thank You For Subscribing! notification of any cancellation/reinstatement for the following policy. Policy Number: WC539S342438046', 30),
    ]]));

    $this->artisan('ewccv:notices', ['--belongs-to-vendor-id' => $gs->id, '--no-filing' => true])
        ->expectsOutputToContain('repaired: the notice on WC5-39S-342438-046')
        ->assertExitCode(0);

    expect($doc->fresh()->options['ewccv'])->not->toHaveKey('notice')
        ->and($doc->fresh()->ewccv_summary['tip'])->toBe('EWCCV coverage tracking enabled');
    Notification::assertNothingSent();
});
