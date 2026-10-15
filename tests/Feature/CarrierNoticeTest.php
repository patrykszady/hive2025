<?php

use App\Http\Controllers\VendorDocsController;
use App\Mail\RequestInsurance;
use App\Models\Vendor;
use App\Models\VendorDoc;
use App\Services\CarrierNoticeIntake;
use App\Services\NylasService;
use App\Support\Insurance\CarrierNotice;
use App\Support\Insurance\PdfText;
use App\Support\Insurance\PolicyNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// =========================================================================
// Hands free (Patryk, 2026-10-14): the certificate request asks agents to
// list the company as certificate holder with notice of cancellation, and
// the insurer's notice that reaches the certificates mailbox is pinned onto
// the certificate it names, matched by policy number, with nobody reading
// the inbox.
// =========================================================================

function noticedDoc(string $business, string $number, string $type = 'workers'): VendorDoc
{
    $vendor = Vendor::factory()->create(['business_name' => $business]);

    return VendorDoc::withoutEvents(fn () => VendorDoc::create([
        'type' => $type, 'vendor_id' => $vendor->id, 'belongs_to_vendor_id' => 1, 'number' => $number,
        'doc_filename' => 'cert-'.$vendor->id.'.pdf', 'effective_date' => '2026-10-08', 'expiration_date' => '2027-10-08',
    ]));
}

function noticeLetter(string $policy = '6JUB-A421140-4-25'): string
{
    return "TRAVELERS\nNOTICE OF CANCELLATION\nNamed Insured: M POWER SOLUTION CO\n5316 Greenview Rd, Oakwood Hills, IL\n"
        ."Policy Number: {$policy}\nYour policy will be cancelled effective 10/20/2026 at 12:01 A.M. for non-payment of premium.";
}

/** @return array<string, mixed> */
function noticeMessage(string $id = 'notice-1', string $subject = 'Policy notice', array $attachments = []): array
{
    return [
        'id' => $id, 'subject' => $subject, 'date' => strtotime('2026-10-15 14:00:00'),
        'from' => [['name' => 'Travelers Notices', 'email' => 'notices@travelers.example']],
        'body' => '<p>Please see the attached notice.</p>', 'attachments' => $attachments,
    ];
}

function pdfReads(string $text): void
{
    test()->mock(PdfText::class, fn ($mock) => $mock->shouldReceive('extract')->andReturn($text));
}

beforeEach(function () {
    $this->travelTo('2026-10-15 15:00:00');
});

it('tells a notice by its own words and never takes a certificate for one', function (string $text, ?string $kind) {
    expect(CarrierNotice::kind($text))->toBe($kind);
})->with([
    'cancellation' => [noticeLetter(), CarrierNotice::CANCELLATION],
    'intent to cancel' => ['Notice of Intent to Cancel. Unless the minimum amount due is received by 11/02/2026 your policy ends.', CarrierNotice::PENDING_CANCELLATION],
    'non-renewal' => ['NOTICE OF NONRENEWAL: policy WC123456 will not be renewed', CarrierNotice::NONRENEWAL],
    'reinstatement' => ['We have reinstated your policy. The notice of cancellation is withdrawn.', CarrierNotice::REINSTATEMENT],
    'an ACORD certificate quoting the cancellation clause' => ['CERTIFICATE OF LIABILITY INSURANCE … SHOULD ANY OF THE ABOVE DESCRIBED POLICIES BE CANCELLED BEFORE THE EXPIRATION DATE THEREOF, NOTICE WILL BE DELIVERED', null],
    'a billing reminder' => ['Your premium payment of $412.00 is due on 11/01/2026.', null],
]);

it('reads the day the change takes effect', function (string $text, ?string $date) {
    expect(CarrierNotice::effectiveDate($text)?->toDateString())->toBe($date);
})->with([
    'effective at 12:01 a.m.' => [noticeLetter(), '2026-10-20'],
    'cancellation date, month spelled out' => ['Cancellation Date: November 2, 2026', '2026-11-02'],
    'none stated' => ['Your policy is cancelled.', null],
]);

it('scores policy numbers for likeness in PHP as the matcher does', function () {
    expect(PolicyNumber::similarity('6-JUB-A421140-4-25', '6jub a421140 425'))->toBe(1.0)
        ->and(PolicyNumber::similarity('100134391', 'AFWCP100134391'))->toBe(0.95)
        ->and(round(PolicyNumber::similarity('6JUBA421140425', '6JUBA42114045'), 3))->toBe(round(13 / 14, 3))
        ->and(PolicyNumber::similarity('6JUBA421140425', '6JUBA421161325'))->toBeLessThan(PolicyNumber::LIKELY);
});

it('pins a cancellation onto the certificate whose policy number it names, and the row turns red', function () {
    $doc = noticedDoc('M Power Solution Company', '6-JUB-A421140-4-25');
    $other = noticedDoc('Power Solution Services Inc', '83WECIN4849');
    pdfReads(noticeLetter('6JUBA421140425'));

    $result = app(CarrierNoticeIntake::class)->handle(noticeMessage(), [['id' => 'a', 'type' => 'pdf', 'content' => '%PDF-1.7']]);

    expect($result)->toMatchArray(['kind' => 'cancellation', 'effective_date' => '2026-10-20', 'matched' => [$doc->id]])
        ->and($doc->fresh()->options['carrier_notice'])->toMatchArray([
            'kind' => 'cancellation', 'effective_date' => '2026-10-20', 'policy_score' => 1.0,
            'from' => 'Travelers Notices <notices@travelers.example>', 'message_id' => 'notice-1',
        ])
        ->and($other->fresh()->options)->not->toHaveKey('carrier_notice');

    $summary = $doc->fresh()->verification_summary;
    expect($summary)->toMatchArray(['verified' => false, 'verified_at' => null, 'alert' => true, 'alert_label' => 'cancelled'])
        ->and($summary['tip'])->toContain('Insurer notice: CANCELLED effective 10/20/2026 · received 10/15/2026 from Travelers Notices');

    // The same message read again changes nothing.
    app(CarrierNoticeIntake::class)->handle(noticeMessage(), [['id' => 'a', 'type' => 'pdf', 'content' => '%PDF-1.7']]);
    expect($doc->fresh()->options['carrier_notice']['message_ids'])->toBe(['notice-1']);
});

it('takes a number one character off only when the insured is named in the notice', function () {
    $ours = noticedDoc('M Power Solution Company', '6-JUB-A421140-4-25');
    $neighbour = noticedDoc('PHD Glass Inc', '6JUBA421140435');
    pdfReads(noticeLetter('6JUBA42114045'));

    $result = app(CarrierNoticeIntake::class)->handle(noticeMessage(), [['id' => 'a', 'type' => 'pdf', 'content' => '%PDF-1.7']]);

    expect($result['matched'])->toBe([$ours->id])
        ->and($ours->fresh()->options['carrier_notice']['policy_score'])->toBe(0.93)
        ->and($neighbour->fresh()->options)->not->toHaveKey('carrier_notice');
});

it('outranks an earlier EWCCV confirmation, and a reinstatement clears the alert', function () {
    $doc = noticedDoc('M Power Solution Company', '6-JUB-A421140-4-25');
    $doc->options = ['ewccv' => ['status' => 'tracking', 'checked_at' => '2026-10-14T22:00:00+00:00', 'matched_policy' => '6JUBA421140425', 'match_type' => 'policyNumber']];
    $doc->saveQuietly();
    expect($doc->fresh()->verification_summary['verified'])->toBeTrue();

    pdfReads(noticeLetter());
    app(CarrierNoticeIntake::class)->handle(noticeMessage(), [['id' => 'a', 'type' => 'pdf', 'content' => '%PDF-1.7']]);
    expect($doc->fresh()->verification_summary)->toMatchArray(['verified' => false, 'alert' => true]);

    pdfReads("Reinstatement Notice\nPolicy Number 6JUBA421140425 has been reinstated effective 10/20/2026.");
    app(CarrierNoticeIntake::class)->handle(noticeMessage('notice-2'), [['id' => 'b', 'type' => 'pdf', 'content' => '%PDF-1.7']]);
    $summary = $doc->fresh()->verification_summary;
    expect($summary['verified'])->toBeTrue()
        ->and($summary['tip'])->toContain('Insurer notice: reinstated as of 10/20/2026')
        ->and($doc->fresh()->options['carrier_notice']['message_ids'])->toBe(['notice-1', 'notice-2']);
});

it('leaves EWCCV mail and certificates alone', function () {
    noticedDoc('M Power Solution Company', '6-JUB-A421140-4-25');
    pdfReads('CERTIFICATE OF LIABILITY INSURANCE  POLICY NUMBER 6JUBA421140425  SHOULD ANY OF THE ABOVE DESCRIBED POLICIES BE CANCELLED');

    expect(app(CarrierNoticeIntake::class)->handle(noticeMessage('cert', 'Certificate'), [['id' => 'a', 'type' => 'pdf', 'content' => '%PDF-1.7']]))->toBeNull()
        ->and(app(CarrierNoticeIntake::class)->handle([
            'id' => 'ewccv', 'subject' => 'Notice of Cancellation', 'from' => [['email' => 'donotreply@ewccv.com']],
            'body' => 'The policy 6JUBA421140425 has been cancelled', 'date' => time(),
        ], []))->toBeNull();
});

it('files a notice from the mailbox without reading it as a certificate: matched to Saved, unmatched to Error', function (string $policy, string $folder) {
    noticedDoc('M Power Solution Company', '6-JUB-A421140-4-25');
    pdfReads(noticeLetter($policy));
    Storage::fake('files');
    config(['nylas.certificates_grant_id' => 'grant', 'nylas.certificates_inbox_folder_id' => 'inbox', 'nylas.certificates_saved_folder_id' => 'saved', 'nylas.certificates_error_folder_id' => 'error', 'nylas.certificates_deleted_folder_id' => 'deleted']);
    $moves = [];
    $nylas = Mockery::mock(NylasService::class);
    $nylas->shouldReceive('getMessages')->andReturn(['data' => [noticeMessage('msg', 'Notice', [['id' => 'pdf', 'filename' => 'notice.pdf', 'content_type' => 'application/pdf', 'size' => 90000, 'is_inline' => false]])]]);
    $nylas->shouldReceive('downloadAttachment')->andReturn("%PDF-1.7\nnotice");
    $nylas->shouldReceive('moveEmailToFolder')->andReturnUsing(function ($messageId, $folderId) use (&$moves) {
        $moves[] = $folderId;

        return true;
    });
    $reader = new class($nylas) extends VendorDocsController
    {
        public int $certificatesRead = 0;

        public function handleVendorDocProcessing($filePath, $docType, $vendorId = null, $belongsToVendorId = null, $messageId = null, $grantId = null, $sourceLabel = null)
        {
            $this->certificatesRead++;

            return true;
        }
    };

    $reader->fetchMessagesFromInsuranceMailbox();

    expect($moves)->toBe([$folder])
        ->and($reader->certificatesRead)->toBe(0);
})->with([
    'names a certificate on file' => ['6JUBA421140425', 'saved'],
    'names none' => ['ZZ9999999999', 'error'],
]);

it('asks the agent for certificate holder status with notice of cancellation', function () {
    $holder = Vendor::factory()->create(['business_name' => 'GS Construction & Remodeling']);
    $sub = Vendor::factory()->create(['business_name' => 'M Power Solution Company']);
    config(['nylas.certificates_email' => 'certificates@hive.contractors']);

    $html = (new RequestInsurance(collect(), $sub, $holder))->render();

    expect($html)->toContain('as Certificate Holder with notice of cancellation')
        ->toContain('certificates@hive.contractors');
});

it('reads the text of a real PDF with Ghostscript', function () {
    if (trim((string) shell_exec('command -v gs')) === '') {
        $this->markTestSkipped('Ghostscript is not installed here.');
    }
    $path = tempnam(sys_get_temp_dir(), 'notice_').'.pdf';
    exec('gs -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=pdfwrite -sOutputFile='.escapeshellarg($path).' -c "/Helvetica findfont 14 scalefont setfont 72 720 moveto (NOTICE OF CANCELLATION Policy 6JUBA421140425) show showpage"');

    $text = app(PdfText::class)->extract((string) file_get_contents($path));
    @unlink($path);

    expect($text)->toContain('NOTICE OF CANCELLATION')->toContain('6JUBA421140425')
        ->and(app(PdfText::class)->extract('not a pdf'))->toBe('');
});
