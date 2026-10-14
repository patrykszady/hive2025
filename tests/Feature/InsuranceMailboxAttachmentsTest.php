<?php

use App\Http\Controllers\VendorDocsController;
use App\Services\NylasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * The certificates mailbox reads only certificates and files each message
 * once. On 2026-10-14 M Power's certificate, forwarded from Outlook, was
 * saved, but the message went to Error: its ATT0002-4 fragments were read as
 * PDFs, a 20x13 icon and a logo as photos, and each attachment re-filed it.
 */
function forwardedCertificateAttachments(): array
{
    return [
        ['id' => 'pdf', 'filename' => 'GS Construction & Remodeling.pdf', 'content_type' => 'application/pdf', 'size' => 112171, 'is_inline' => false],
        ['id' => 'att2', 'filename' => 'ATT0002', 'content_type' => 'application/octet-stream', 'size' => 4869, 'is_inline' => false],
        ['id' => 'icon', 'filename' => 'attachment.png', 'content_type' => 'image/png', 'size' => 1883, 'is_inline' => false],
        ['id' => 'att3', 'filename' => 'ATT0003', 'content_type' => 'application/octet-stream', 'size' => 2893, 'is_inline' => false],
        ['id' => 'logo', 'filename' => 'LOGO PNG SMALL.png', 'content_type' => 'image/png', 'size' => 51456, 'is_inline' => false],
        ['id' => 'att4', 'filename' => 'ATT0004', 'content_type' => 'application/octet-stream', 'size' => 541, 'is_inline' => false],
    ];
}

function attachmentContents(): array
{
    return ['pdf' => "%PDF-1.7\ncertificate", 'att2' => '<html>forwarded</html>', 'att3' => 'BEGIN:VCARD', 'att4' => 'x', 'icon' => 'png', 'logo' => 'png', 'photo' => 'jpeg', 'unnamed' => "%PDF-1.4\ncertificate"];
}

/** A reader whose document reading is stubbed: it returns $results in turn and records what it was given. */
function mailboxReader(array $attachments, array $results, array &$moves): VendorDocsController
{
    Storage::fake('files');
    config([
        'nylas.certificates_grant_id' => 'grant',
        'nylas.certificates_inbox_folder_id' => 'inbox',
        'nylas.certificates_saved_folder_id' => 'saved',
        'nylas.certificates_error_folder_id' => 'error',
        'nylas.certificates_deleted_folder_id' => 'deleted',
    ]);
    $contents = attachmentContents();
    $nylas = Mockery::mock(NylasService::class);
    $nylas->shouldReceive('getMessages')->andReturn(['data' => [['id' => 'msg', 'subject' => 'Fw: certyficate', 'attachments' => $attachments]]]);
    $nylas->shouldReceive('downloadAttachment')->andReturnUsing(fn ($id) => $contents[$id] ?? '');
    $nylas->shouldReceive('moveEmailToFolder')->andReturnUsing(function ($messageId, $folderId) use (&$moves) {
        $moves[] = $folderId;

        return true;
    });

    return new class($nylas, $results) extends VendorDocsController
    {
        public array $read = [];

        public function __construct(NylasService $nylas, private array $results)
        {
            parent::__construct($nylas);
        }

        public function handleVendorDocProcessing($filePath, $docType, $vendorId = null, $belongsToVendorId = null, $messageId = null, $grantId = null, $sourceLabel = null)
        {
            $this->read[] = basename((string) $filePath);
            $result = array_shift($this->results) ?? false;
            if ($result === false) {
                $this->moveEmailBasedOnMatchingResults($messageId, $grantId, null, null);
            }

            return $result;
        }

        protected function splitPdfIntoPages(string $relativePath, string $attachmentId): array
        {
            return [];
        }
    };
}

it('reads only the PDF of a forwarded certificate, not the fragments, icon or logo beside it', function (): void {
    $moves = [];
    $documents = mailboxReader([], [], $moves)->certificateDocuments(forwardedCertificateAttachments(), fn ($id) => attachmentContents()[$id]);

    expect(array_column($documents, 'id'))->toBe(['pdf'])
        ->and($documents[0]['type'])->toBe('pdf');
});

/** A real PNG of the given size, for the page-shape check. */
function pngOf(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagepng($image);
    imagedestroy($image);

    return (string) ob_get_clean();
}

it('reads a certificate scan beside the PDF, but not a signature banner or a headshot', function (): void {
    $moves = [];
    $contents = attachmentContents() + ['scan' => pngOf(1700, 2200), 'banner' => pngOf(2000, 400), 'headshot' => pngOf(500, 500)];
    $documents = mailboxReader([], [], $moves)->certificateDocuments([
        ['id' => 'pdf', 'filename' => 'COI.pdf', 'content_type' => 'application/pdf', 'size' => 112171],
        ['id' => 'scan', 'filename' => 'scan0001.png', 'content_type' => 'image/png', 'size' => 420_000],
        ['id' => 'banner', 'filename' => 'signature.png', 'content_type' => 'image/png', 'size' => 300_000],
        ['id' => 'headshot', 'filename' => 'me.png', 'content_type' => 'image/png', 'size' => 150_000],
    ], fn ($id) => $contents[$id]);

    expect(array_column($documents, 'id'))->toBe(['pdf', 'scan'])
        ->and(array_column($documents, 'type'))->toBe(['pdf', 'png']);
});

it('reads a photo of a certificate when there is no PDF, never an icon', function (): void {
    $moves = [];
    $documents = mailboxReader([], [], $moves)->certificateDocuments([
        ['id' => 'icon', 'filename' => 'attachment.png', 'content_type' => 'image/png', 'size' => 1883],
        ['id' => 'photo', 'filename' => 'IMG_2201.JPG', 'content_type' => 'image/jpeg', 'size' => 2_400_000],
    ], fn ($id) => attachmentContents()[$id]);

    expect(array_column($documents, 'id'))->toBe(['photo'])
        ->and($documents[0]['type'])->toBe('jpg');
});

it('reads an unnamed attachment as a PDF when it is one', function (): void {
    $moves = [];
    $documents = mailboxReader([], [], $moves)->certificateDocuments([
        ['id' => 'unnamed', 'filename' => 'ATT0005', 'content_type' => 'application/octet-stream', 'size' => 90000],
    ], fn ($id) => attachmentContents()[$id]);

    expect($documents)->toHaveCount(1)
        ->and($documents[0]['type'])->toBe('pdf');
});

it('files the message once, as saved, when its certificate is saved whatever came with it', function (): void {
    $moves = [];
    $reader = mailboxReader(forwardedCertificateAttachments(), [true], $moves);

    $reader->fetchMessagesFromInsuranceMailbox();

    expect($reader->read)->toBe(['attachment_pdf.pdf'])
        ->and($moves)->toBe(['saved']);
});

it('files the message to Error when a certificate could not be read', function (): void {
    $moves = [];
    $reader = mailboxReader(forwardedCertificateAttachments(), [false], $moves);

    $reader->fetchMessagesFromInsuranceMailbox();

    expect($moves)->toBe(['error']);
});

it('moves a message with nothing but logos and fragments to the deleted folder', function (): void {
    $moves = [];
    $reader = mailboxReader(array_values(array_filter(forwardedCertificateAttachments(), fn ($a) => $a['id'] !== 'pdf')), [], $moves);

    $reader->fetchMessagesFromInsuranceMailbox();

    expect($reader->read)->toBe([])
        ->and($moves)->toBe(['deleted']);
});
