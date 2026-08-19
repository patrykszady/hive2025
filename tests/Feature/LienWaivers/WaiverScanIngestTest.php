<?php

use App\Enums\LienWaiverStatus;
use App\Models\Client;
use App\Models\LienWaiver;
use App\Models\Project;
use App\Models\SwornStatement;
use App\Models\Vendor;
use App\Services\WaiverScanIngest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const WSI_GRANT = 'test-waivers-grant';
const WSI_INBOX = 'WAIVERSINBOXFOLDERID0000000001';
const WSI_SAVED = 'WAIVERSSAVEDFOLDERID0000000002';
const WSI_ERROR = 'WAIVERSERRORFOLDERID0000000003';
const WSI_DELETED = 'WAIVERSDELETEDFOLDER0000000004';

beforeEach(function () {
    Storage::fake('files');

    config()->set('nylas.waivers_grant_id', WSI_GRANT);
    config()->set('nylas.waivers_inbox_folder_id', WSI_INBOX);
    config()->set('nylas.waivers_saved_folder_id', WSI_SAVED);
    config()->set('nylas.waivers_error_folder_id', WSI_ERROR);
    config()->set('nylas.waivers_deleted_folder_id', WSI_DELETED);

    config()->set('services.azure_cu.endpoint', 'cu.test');
    config()->set('services.azure_cu.api_key', 'test-key');
    config()->set('services.azure_cu.api_version', 'test-v');
    config()->set('services.azure_cu.analyzer_id_waiver', 'HiveWaivers20261');
});

/**
 * GC + sub + project + one Sent waiver ready to be matched by scan.
 */
function waiverScanFixtures(): array
{
    $gc = Vendor::query()->create([
        'business_name' => 'GS Construction & Remodeling, Inc',
        'business_type' => 'Sub',
        'address' => '400 N Wheeling Rd', 'city' => 'Prospect Heights', 'state' => 'IL', 'zip_code' => '60070',
    ]);
    $sub = Vendor::query()->create([
        'business_name' => 'Accomplished J Plumbing, Inc',
        'business_type' => 'Sub',
        'address' => '930 E Northwest Hwy', 'city' => 'Mount Prospect', 'state' => 'IL', 'zip_code' => '60056',
    ]);

    // ProjectObserver::creating derives belongs_to_vendor_id from the auth user.
    $user = \App\Models\User::query()->create([
        'first_name' => 'Scan', 'last_name' => 'Tester',
        'email' => 'scan-' . \Illuminate\Support\Str::random(8) . '@example.test',
        'cell_phone' => '7' . random_int(100000000, 999999999),
        'password' => bcrypt('password'),
    ]);
    $user->forceFill(['primary_vendor_id' => $gc->id])->saveQuietly();
    $user->vendors()->attach($gc->id, ['role_id' => 1, 'is_employed' => true]);
    test()->actingAs($user->fresh());

    $project = Project::withoutGlobalScopes()->create([
        'project_name' => 'Home Renovation',
        'belongs_to_vendor_id' => $gc->id,
        'client_id' => Client::query()->create([
            'business_name' => 'Mark & Gail Brodson',
            'address' => '3154 Violet Ln', 'city' => 'Northbrook', 'state' => 'IL', 'zip_code' => '60062',
        ])->id,
        'address' => '3154 Violet Ln', 'city' => 'Northbrook', 'state' => 'IL', 'zip_code' => '60062',
    ]);

    $waiver = LienWaiver::withoutGlobalScopes()->create([
        'belongs_to_vendor_id' => $gc->id,
        'vendor_id' => $sub->id,
        'project_id' => $project->id,
        'type' => \App\Enums\LienWaiverType::ConditionalProgress,
        'status' => LienWaiverStatus::Sent,
        'amount' => 6000,
        'exceptions_amount' => 0,
        'through_date' => '2026-07-24',
        'jurisdiction' => 'US-IL',
    ]);

    return [$gc, $sub, $project, $waiver];
}

/**
 * Fake the entire Nylas + Azure CU conversation for one inbox message with
 * one PDF attachment whose CU analysis yields the given fields/markdown.
 * getMessages uses a raw Guzzle client (Http::fake can't reach it), so the
 * message listing is partial-mocked; download/move/CU ride Http::fake.
 */
function fakeScanPipeline(array $cuContents, array $attachments = null, array $sequentialResults = null, ?string $downloadBinary = null): void
{
    $message = [
        'id' => 'msg-1',
        'subject' => 'Signed waiver',
        'attachments' => $attachments ?? [[
            'id' => 'att-1',
            'is_inline' => false,
            'filename' => 'scan.pdf',
            'content_type' => 'application/pdf',
        ]],
    ];

    test()->partialMock(\App\Services\NylasService::class, function ($mock) use ($message) {
        $mock->shouldReceive('getMessages')->andReturn(['status' => 200, 'data' => [$message]]);
    });

    // Attachments are analyzed sequentially: submit N gets operation op-N,
    // whose poll returns $sequentialResults[N-1] (or $cuContents for all).
    $submitCount = 0;

    Http::fake(function ($request) use ($cuContents, $sequentialResults, &$submitCount, $downloadBinary) {
        $url = $request->url();
        $method = $request->method();

        // Testing env has no NYLAS_API_URI, so Nylas URLs are relative — match paths.
        if (str_contains($url, '/download')) {
            // Page-trimming needs a REAL, FPDI-parseable multi-page PDF to
            // operate on — the plain fake string below isn't one, and that's
            // deliberate: it keeps every OTHER test on the cheap fast path
            // (PdfPageExtractor::pageCount() returns null for it, so
            // trimToOwnPages() no-ops immediately).
            return Http::response($downloadBinary ?? '%PDF-1.4 fake-scan-binary');
        }

        if ($method === 'PATCH' && str_contains($url, 'messages/')) {
            return Http::response(['data' => ['id' => 'msg-1']]);
        }

        if (str_contains($url, 'cu.test') && str_contains($url, ':analyzeBinary')) {
            $submitCount++;

            return Http::response('', 202, ['Operation-Location' => 'https://cu.test/operations/op-' . $submitCount]);
        }

        if (preg_match('#cu\.test/operations/op-(\d+)#', $url, $m)) {
            $contents = $sequentialResults[(int) $m[1] - 1] ?? $cuContents;

            return Http::response([
                'status' => 'Succeeded',
                'result' => ['contents' => $contents],
            ]);
        }

        \Illuminate\Support\Facades\Log::channel('waiver_scans')->error('TEST FAKE: unexpected request', ['method' => $method, 'url' => $url]);

        return Http::response(['error' => 'unexpected request: ' . $url], 500);
    });
}

function pdfAttachment(string $id): array
{
    return ['id' => $id, 'is_inline' => false, 'filename' => $id . '.pdf', 'content_type' => 'application/pdf'];
}

function cuContentFor(string $code, array $overrides = [], bool $withAffidavit = true): array
{
    return [
        // has_affidavit is derived deterministically from this heading.
        'markdown' => ($withAffidavit ? "## CONTRACTOR'S AFFIDAVIT\n" : '')
            . '<!-- PageFooter: ![Code128](barcodes/1.1 "' . $code . '") lien-waiver-x.pdf -->',
        'fields' => array_merge([
            'FooterFilename' => ['type' => 'string', 'valueString' => ''],
            'CompanyAddressText' => ['type' => 'string', 'valueString' => '930 E Northwest Hwy, Mount Prospect, IL 60056'],
            'DocumentType' => ['type' => 'string', 'valueString' => 'lien_waiver'],
            'ClaimantCompanyName' => ['type' => 'string', 'valueString' => 'Accomplished J Plumbing, Inc'],
            'PropertyAddress' => ['type' => 'string', 'valueString' => '3154 Violet Ln, Northbrook, IL 60062'],
            'IsWetSigned' => ['type' => 'boolean', 'valueBoolean' => true],
            'HasAffidavitSection' => ['type' => 'boolean', 'valueBoolean' => true],
            'HasNotaryStamp' => ['type' => 'boolean', 'valueBoolean' => true],
            'SignedDate' => ['type' => 'date', 'valueDate' => '2026-07-25'],
            'WaiverSignatureText' => ['type' => 'string', 'valueString' => 'Rayde Jmy - Secretory'],
            'AffiantName' => ['type' => 'string', 'valueString' => 'Jerry Rohpa'],
            'AffiantPosition' => ['type' => 'string', 'valueString' => 'President'],
            'AffidavitDate' => ['type' => 'date', 'valueDate' => '2026-07-25'],
            'AffidavitSignatureText' => ['type' => 'string', 'valueString' => 'por m'],
            'NotaryDay' => ['type' => 'string', 'valueString' => '25'],
            'NotaryMonth' => ['type' => 'string', 'valueString' => 'July'],
            'NotaryYear' => ['type' => 'string', 'valueString' => '26'],
            'NotaryName' => ['type' => 'string', 'valueString' => 'Adriana Y Alvarez'],
            'NotarySignatureText' => ['type' => 'string', 'valueString' => 'Pinan Kll'],
            'NotaryCommissionNumber' => ['type' => 'string', 'valueString' => '840218'],
            'NotaryCommissionExpires' => ['type' => 'date', 'valueDate' => '2028-09-23'],
        ], $overrides),
    ];
}

function assertMovedTo(string $folderId): void
{
    Http::assertSent(function ($request) use ($folderId) {
        return $request->method() === 'PATCH'
            && str_contains($request->url(), '/messages/msg-1')
            && ($request['folders'] ?? null) === [$folderId];
    });
}

it('matches a scanned waiver by barcode, stores the scan, and flips it to Signed', function () {
    [, , , $waiver] = waiverScanFixtures();

    fakeScanPipeline([cuContentFor('HLW-' . $waiver->id)]);

    $stats = app(WaiverScanIngest::class)->processInbox();

    expect($stats['matched'])->toBe(1)->and($stats['errors'])->toBe(0);

    $waiver->refresh();
    expect($waiver->status)->toBe(LienWaiverStatus::Signed)
        ->and($waiver->signed_at)->not->toBeNull()
        ->and($waiver->signed_path)->toStartWith("lien-waivers/{$waiver->project_id}/{$waiver->id}/scan-");

    Storage::disk('files')->assertExists($waiver->signed_path);
    expect(Storage::disk('files')->get($waiver->signed_path))->toStartWith('%PDF');

    // The notary identity and document dates land in the audit trail.
    $scanNotes = json_decode((string) $waiver->notes, true)['scan'] ?? [];
    expect($scanNotes['notary_name'] ?? null)->toBe('Adriana Y Alvarez')
        ->and($scanNotes['notary_commission_number'] ?? null)->toBe('840218')
        ->and($scanNotes['notary_signature_text'] ?? null)->toBe('Pinan Kll')
        ->and($scanNotes['affiant_name'] ?? null)->toBe('Jerry Rohpa')
        ->and($scanNotes['ingested_at'] ?? null)->not->toBeNull();

    assertMovedTo(WSI_SAVED);
});

it('tolerates an indeterminate HasNotaryStamp when the completeness audit passes', function () {
    [, , , $waiver] = waiverScanFixtures();

    fakeScanPipeline([cuContentFor('HLW-' . $waiver->id, [
        'HasNotaryStamp' => ['type' => 'boolean'],
    ])]);

    app(WaiverScanIngest::class)->processInbox();

    expect($waiver->refresh()->status)->toBe(LienWaiverStatus::Signed);
    assertMovedTo(WSI_SAVED);
});

it('matches via the deterministic markdown barcode annotation', function () {
    [, , , $waiver] = waiverScanFixtures();

    fakeScanPipeline([cuContentFor('HLW-' . $waiver->id, [
    ])]);

    app(WaiverScanIngest::class)->processInbox();

    expect($waiver->refresh()->status)->toBe(LienWaiverStatus::Signed);
});

it('falls back to the footer filename when no barcode decoded at all', function () {
    [, , , $waiver] = waiverScanFixtures();

    $content = cuContentFor('IGNORED', [
        'FooterFilename' => ['type' => 'string', 'valueString' => "lien-waiver-{$waiver->id}-accomplished-j-plumbing-inc-3154-violet-ln.pdf"],
    ]);
    $content['markdown'] = '<!-- PageFooter: no barcode readable -->';

    fakeScanPipeline([$content]);

    app(WaiverScanIngest::class)->processInbox();

    expect($waiver->refresh()->status)->toBe(LienWaiverStatus::Signed);
});

it('matches a scanned GCSS by HSS barcode and flips the statement to Signed', function () {
    [$gc, , $project] = waiverScanFixtures();

    $statement = SwornStatement::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $gc->id,
        'this_payment' => 60000,
        'status' => LienWaiverStatus::Sent,
        'filename' => 'sworn-statement-gs.pdf',
    ]);

    fakeScanPipeline([cuContentFor('HSS-' . $statement->id, [
        'DocumentType' => ['type' => 'string', 'valueString' => 'sworn_statement'],
        'ClaimantCompanyName' => ['type' => 'string', 'valueString' => 'GS Construction & Remodeling, Inc'],
        'StatementSignatureText' => ['type' => 'string', 'valueString' => 'Patryk Szady'],
    ])]);

    $stats = app(WaiverScanIngest::class)->processInbox();

    expect($stats['matched'])->toBe(1);

    $statement->refresh();
    expect($statement->status)->toBe(LienWaiverStatus::Signed)
        ->and($statement->signed_path)->toStartWith("sworn-statements/{$project->id}/{$statement->id}/scan-")
        ->and($statement->signed_at)->not->toBeNull();

    Storage::disk('files')->assertExists($statement->signed_path);
    assertMovedTo(WSI_SAVED);
});

it('merges a multi-page GCSS scanned as separate per-page PDFs', function () {
    [$gc, , $project] = waiverScanFixtures();

    $statement = SwornStatement::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $gc->id,
        'this_payment' => 60000,
        'status' => LienWaiverStatus::Sent,
        'filename' => 'sworn-statement-gs.pdf',
    ]);

    // Page 1: header (company/address + the vendor table) — no signature, no notary.
    $page1 = [
        'markdown' => '<!-- PageFooter: ![Code128](barcodes/1.1 "HSS-' . $statement->id . '") -->',
        'fields' => [
            'DocumentType' => ['type' => 'string', 'valueString' => 'sworn_statement'],
            'ClaimantCompanyName' => ['type' => 'string', 'valueString' => 'GS Construction & Remodeling, Inc'],
            'PropertyAddress' => ['type' => 'string', 'valueString' => '3154 Violet Ln, Northbrook, IL 60062'],
        ],
    ];

    // Page 2: the tail — SIGNED, jurat, notary signature and stamp; NO company/address.
    $page2 = [
        'markdown' => '<!-- PageFooter: ![Code128](barcodes/2.1 "HSS-' . $statement->id . '") -->',
        'fields' => [
            'DocumentType' => ['type' => 'string', 'valueString' => 'sworn_statement'],
            'IsWetSigned' => ['type' => 'boolean', 'valueBoolean' => true],
            'StatementSignatureText' => ['type' => 'string', 'valueString' => 'Patryk Szady'],
            'NotaryDay' => ['type' => 'string', 'valueString' => '25'],
            'NotaryMonth' => ['type' => 'string', 'valueString' => 'July'],
            'NotaryYear' => ['type' => 'string', 'valueString' => '26'],
            'NotarySignatureText' => ['type' => 'string', 'valueString' => 'Pinan Kll'],
            'NotaryName' => ['type' => 'string', 'valueString' => 'Adriana Y Alvarez'],
            'NotaryCommissionNumber' => ['type' => 'string', 'valueString' => '840218'],
            'HasNotaryStamp' => ['type' => 'boolean', 'valueBoolean' => true],
        ],
    ];

    fakeScanPipeline(
        [],
        [pdfAttachment('att-1'), pdfAttachment('att-2')],
        sequentialResults: [[$page1], [$page2]],
    );

    $stats = app(WaiverScanIngest::class)->processInbox();

    // Neither page alone is complete/corroborated — the union is both.
    expect($stats['matched'])->toBe(1)->and($stats['errors'])->toBe(0);

    $statement->refresh();
    expect($statement->status)->toBe(LienWaiverStatus::Signed)
        ->and($statement->signed_path)->not->toBeNull();
    Storage::disk('files')->assertExists($statement->signed_path);

    // The notary identity from page 2 made it into the merged context.
    assertMovedTo(WSI_SAVED);
});

it('processes a mixed bundle: two waivers plus a two-page GCSS, one attachment per page', function () {
    [$gc, $sub, $project, $waiverA] = waiverScanFixtures();

    $waiverB = LienWaiver::withoutGlobalScopes()->create([
        'belongs_to_vendor_id' => $gc->id,
        'vendor_id' => $sub->id,
        'project_id' => $project->id,
        'type' => \App\Enums\LienWaiverType::ConditionalProgress,
        'status' => LienWaiverStatus::Sent,
        'amount' => 1200,
        'exceptions_amount' => 0,
        'through_date' => '2026-07-24',
        'jurisdiction' => 'US-IL',
    ]);

    $statement = SwornStatement::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $gc->id,
        'this_payment' => 60000,
        'status' => LienWaiverStatus::Sent,
        'filename' => 'sworn-statement-gs.pdf',
    ]);

    $gcssPage1 = [
        'markdown' => '<!-- ![Code128](barcodes/1.1 "HSS-' . $statement->id . '") -->',
        'fields' => [
            'DocumentType' => ['type' => 'string', 'valueString' => 'sworn_statement'],
            'ClaimantCompanyName' => ['type' => 'string', 'valueString' => 'GS Construction & Remodeling, Inc'],
            'PropertyAddress' => ['type' => 'string', 'valueString' => '3154 Violet Ln, Northbrook, IL 60062'],
        ],
    ];
    $gcssPage2 = [
        'markdown' => '<!-- ![Code128](barcodes/2.1 "HSS-' . $statement->id . '") -->',
        'fields' => [
            'DocumentType' => ['type' => 'string', 'valueString' => 'sworn_statement'],
            'StatementSignatureText' => ['type' => 'string', 'valueString' => 'Patryk Szady'],
            'NotaryDay' => ['type' => 'string', 'valueString' => '25'],
            'NotaryMonth' => ['type' => 'string', 'valueString' => 'July'],
            'NotaryYear' => ['type' => 'string', 'valueString' => '26'],
            'NotarySignatureText' => ['type' => 'string', 'valueString' => 'Pinan Kll'],
            'NotaryCommissionNumber' => ['type' => 'string', 'valueString' => '840218'],
            'HasNotaryStamp' => ['type' => 'boolean', 'valueBoolean' => true],
        ],
    ];

    fakeScanPipeline(
        [],
        [pdfAttachment('att-1'), pdfAttachment('att-2'), pdfAttachment('att-3'), pdfAttachment('att-4')],
        sequentialResults: [
            [cuContentFor('HLW-' . $waiverA->id)],
            [cuContentFor('HLW-' . $waiverB->id)],
            [$gcssPage1],
            [$gcssPage2],
        ],
    );

    $stats = app(WaiverScanIngest::class)->processInbox();

    expect($stats['matched'])->toBe(3)->and($stats['errors'])->toBe(0);

    expect($waiverA->refresh()->status)->toBe(LienWaiverStatus::Signed)
        ->and($waiverB->refresh()->status)->toBe(LienWaiverStatus::Signed)
        ->and($statement->refresh()->status)->toBe(LienWaiverStatus::Signed);

    assertMovedTo(WSI_SAVED);
});

it('moves messages without usable attachments straight to deleted', function () {
    waiverScanFixtures();

    fakeScanPipeline([], [[
        'id' => 'att-1',
        'is_inline' => false,
        'filename' => 'logo.gif',
        'content_type' => 'image/gif',
    ]]);

    $stats = app(WaiverScanIngest::class)->processInbox();

    expect($stats['skipped'])->toBe(1)->and($stats['matched'])->toBe(0);
    assertMovedTo(WSI_DELETED);
});

it('routes unmatched barcodes to the error folder without touching anything', function () {
    [, , , $waiver] = waiverScanFixtures();

    fakeScanPipeline([cuContentFor('HLW-999999')]);

    $stats = app(WaiverScanIngest::class)->processInbox();

    expect($stats['errors'])->toBe(1)
        ->and($waiver->refresh()->status)->toBe(LienWaiverStatus::Sent);
    assertMovedTo(WSI_ERROR);
});

it('refuses to sign a cancelled waiver', function () {
    [, , , $waiver] = waiverScanFixtures();
    $waiver->forceFill(['status' => LienWaiverStatus::Cancelled])->saveQuietly();

    fakeScanPipeline([cuContentFor('HLW-' . $waiver->id)]);

    app(WaiverScanIngest::class)->processInbox();

    expect($waiver->refresh()->status)->toBe(LienWaiverStatus::Cancelled)
        ->and($waiver->signed_path)->toBeNull();
    assertMovedTo(WSI_ERROR);
});

it('rejects a scan whose document text contradicts the barcode match', function () {
    [, , , $waiver] = waiverScanFixtures();

    fakeScanPipeline([cuContentFor('HLW-' . $waiver->id, [
        'ClaimantCompanyName' => ['type' => 'string', 'valueString' => 'Zzz Unrelated Roofing Co of Texas'],
        'PropertyAddress' => ['type' => 'string', 'valueString' => '990 Desert Rd, Phoenix, AZ 85001'],
    ])]);

    app(WaiverScanIngest::class)->processInbox();

    expect($waiver->refresh()->status)->toBe(LienWaiverStatus::Sent)
        ->and($waiver->signed_path)->toBeNull();
    assertMovedTo(WSI_ERROR);
});

it('rejects an incomplete document — required blanks unfilled', function () {
    [, , , $waiver] = waiverScanFixtures();

    fakeScanPipeline([cuContentFor('HLW-' . $waiver->id, [
        'NotaryDay' => ['type' => 'string'],
        'NotarySignatureText' => ['type' => 'string'],
    ])]);

    app(WaiverScanIngest::class)->processInbox();

    expect($waiver->refresh()->status)->toBe(LienWaiverStatus::Sent)
        ->and($waiver->signed_path)->toBeNull();
    assertMovedTo(WSI_ERROR);
});

it('rejects a waiver with an affidavit but no notary stamp evidence', function () {
    [, , , $waiver] = waiverScanFixtures();

    fakeScanPipeline([cuContentFor('HLW-' . $waiver->id, [
        'HasNotaryStamp' => ['type' => 'boolean', 'valueBoolean' => false],
        'NotaryName' => ['type' => 'string'],
        'NotaryCommissionNumber' => ['type' => 'string'],
    ])]);

    app(WaiverScanIngest::class)->processInbox();

    expect($waiver->refresh()->status)->toBe(LienWaiverStatus::Sent);
    assertMovedTo(WSI_ERROR);
});

it('never counts a printed asterisk marker as a filled blank', function () {
    [, , , $waiver] = waiverScanFixtures();

    fakeScanPipeline([cuContentFor('HLW-' . $waiver->id, [
        'WaiverSignatureText' => ['type' => 'string', 'valueString' => '*'],
    ])]);

    app(WaiverScanIngest::class)->processInbox();

    expect($waiver->refresh()->status)->toBe(LienWaiverStatus::Sent);
    assertMovedTo(WSI_ERROR);
});

it('signs a waiver-only document (no affidavit) with date, address and signature filled', function () {
    [, , , $waiver] = waiverScanFixtures();

    fakeScanPipeline([cuContentFor('HLW-' . $waiver->id, [
        'HasNotaryStamp' => ['type' => 'boolean'],
    ], withAffidavit: false)]);

    app(WaiverScanIngest::class)->processInbox();

    expect($waiver->refresh()->status)->toBe(LienWaiverStatus::Signed);
    assertMovedTo(WSI_SAVED);
});

it('rejects a waiver-only document whose ADDRESS line is blank', function () {
    [, , , $waiver] = waiverScanFixtures();

    fakeScanPipeline([cuContentFor('HLW-' . $waiver->id, [
        'CompanyAddressText' => ['type' => 'string'],
    ], withAffidavit: false)]);

    app(WaiverScanIngest::class)->processInbox();

    expect($waiver->refresh()->status)->toBe(LienWaiverStatus::Sent);
    assertMovedTo(WSI_ERROR);
});

it('rejects a scan the analyzer says is not wet-signed when nothing corroborates it', function () {
    [, , , $waiver] = waiverScanFixtures();

    // No affidavit and no seal: the boolean is the only signal there is, so it
    // stays fully in force for waiver-only (retail/material) documents.
    fakeScanPipeline([cuContentFor('HLW-' . $waiver->id, [
        'IsWetSigned' => ['type' => 'boolean', 'valueBoolean' => false],
        'HasNotaryStamp' => ['type' => 'boolean'],
        'NotaryName' => ['type' => 'string'],
        'NotaryCommissionNumber' => ['type' => 'string'],
    ], withAffidavit: false)]);

    app(WaiverScanIngest::class)->processInbox();

    expect($waiver->refresh()->status)->toBe(LienWaiverStatus::Sent);
    assertMovedTo(WSI_ERROR);
});

it('signs a notarized scan even when the analyzer says it is not wet-signed', function () {
    // Regression: a returned National Construction Rental waiver — hand-signed
    // in blue ink and notarized — was bounced to the error folder because the
    // LLM-judged IsWetSigned came back false. Re-analyzing the same PDF
    // returned null, so the field is not stable enough to veto a seal.
    [, , , $waiver] = waiverScanFixtures();

    fakeScanPipeline([cuContentFor('HLW-' . $waiver->id, [
        'IsWetSigned' => ['type' => 'boolean', 'valueBoolean' => false],
    ])]);

    app(WaiverScanIngest::class)->processInbox();

    expect($waiver->refresh()->status)->toBe(LienWaiverStatus::Signed);
    assertMovedTo(WSI_SAVED);
});

it('reads the waiver id off an OCR-mangled footer filename when the barcode will not decode', function () {
    // Regression: a CamScanner return hard-thresholded the page to B/W, which
    // merged the Code 128 bars (nothing decoded) AND mangled the footer text to
    // "lien-wa ver-12-pimg-corpentry-inx-...". An exact "lien-waiver" match
    // missed it and the whole message went to the error folder.
    [, , , $waiver] = waiverScanFixtures();

    // No decoded-barcode annotation in the markdown: the footer filename is the
    // only route left, exactly as in the real scan.
    fakeScanPipeline([array_merge(
        cuContentFor('HLW-' . $waiver->id, [
            'FooterFilename' => ['type' => 'string', 'valueString' => "lien-wa ver-{$waiver->id}-pimg-corpentry-inx-:-263-364-214-mark-geil-orodson-3154-viclet-in-craw-1-7026-07-28.odf"],
        ]),
        ['markdown' => "## CONTRACTOR'S AFFIDAVIT\n<!-- PageFooter: no readable barcode -->"],
    )]);

    app(WaiverScanIngest::class)->processInbox();

    expect($waiver->refresh()->status)->toBe(LienWaiverStatus::Signed);
    assertMovedTo(WSI_SAVED);
});

it('does not mistake a GCSS footer filename for a lien waiver id', function () {
    [, , , $waiver] = waiverScanFixtures();

    fakeScanPipeline([array_merge(
        cuContentFor('HLW-' . $waiver->id, [
            'FooterFilename' => ['type' => 'string', 'valueString' => 'sworn-statement-7-3154-violet-ln-draw-1-2026-07-28.pdf'],
        ]),
        ['markdown' => "## CONTRACTOR'S AFFIDAVIT\n<!-- PageFooter: no readable barcode -->"],
    )]);

    app(WaiverScanIngest::class)->processInbox();

    expect($waiver->refresh()->status)->toBe(LienWaiverStatus::Sent);
    assertMovedTo(WSI_ERROR);
});

it('treats an already-signed waiver with a stored scan as a success without overwriting it', function () {
    [, , , $waiver] = waiverScanFixtures();

    Storage::disk('files')->put('lien-waivers/1/2/scan-original.pdf', '%PDF-original');
    $waiver->forceFill([
        'status' => LienWaiverStatus::Signed,
        'signed_path' => 'lien-waivers/1/2/scan-original.pdf',
        'signed_at' => now()->subDay(),
    ])->saveQuietly();

    fakeScanPipeline([cuContentFor('HLW-' . $waiver->id)]);

    $stats = app(WaiverScanIngest::class)->processInbox();

    expect($stats['matched'])->toBe(1)
        ->and($waiver->refresh()->signed_path)->toBe('lien-waivers/1/2/scan-original.pdf')
        ->and(Storage::disk('files')->get('lien-waivers/1/2/scan-original.pdf'))->toBe('%PDF-original');
    assertMovedTo(WSI_SAVED);
});

it('supersedes an e-signed PDF with the returned wet-notarized scan', function () {
    [, , , $waiver] = waiverScanFixtures();

    // e-sign flow stored a generated PDF (no '/scan-' marker in the path).
    Storage::disk('files')->put('lien-waivers/1/2/esigned.pdf', '%PDF-esign');
    $waiver->forceFill([
        'status' => LienWaiverStatus::Signed,
        'signed_path' => 'lien-waivers/1/2/esigned.pdf',
        'signed_at' => now()->subDay(),
    ])->saveQuietly();

    fakeScanPipeline([cuContentFor('HLW-' . $waiver->id)]);

    app(WaiverScanIngest::class)->processInbox();

    $waiver->refresh();
    expect($waiver->signed_path)->toStartWith("lien-waivers/{$waiver->project_id}/{$waiver->id}/scan-")
        ->and(Storage::disk('files')->get($waiver->signed_path))->toStartWith('%PDF-1.4')
        // The e-sign PDF is kept on disk, just no longer canonical.
        ->and(Storage::disk('files')->get('lien-waivers/1/2/esigned.pdf'))->toBe('%PDF-esign');
});

it('rejects a scan offering no company or address to corroborate the barcode (fail closed)', function () {
    [, , , $waiver] = waiverScanFixtures();

    fakeScanPipeline([cuContentFor('HLW-' . $waiver->id, [
        'ClaimantCompanyName' => ['type' => 'string'],
        'PropertyAddress' => ['type' => 'string'],
    ])]);

    app(WaiverScanIngest::class)->processInbox();

    expect($waiver->refresh()->status)->toBe(LienWaiverStatus::Sent)
        ->and($waiver->signed_path)->toBeNull();
    assertMovedTo(WSI_ERROR);
});

it('treats an indeterminate IsWetSigned (field present, no value) as unknown, not unsigned', function () {
    [, , , $waiver] = waiverScanFixtures();

    fakeScanPipeline([cuContentFor('HLW-' . $waiver->id, [
        'IsWetSigned' => ['type' => 'boolean'],
    ])]);

    app(WaiverScanIngest::class)->processInbox();

    expect($waiver->refresh()->status)->toBe(LienWaiverStatus::Signed);
    assertMovedTo(WSI_SAVED);
});

it('ignores HLW/HSS-lookalike free text in the page body (no fabricated codes)', function () {
    [, , , $waiver] = waiverScanFixtures();

    $content = cuContentFor('IGNORED', [
        'FooterFilename' => ['type' => 'string', 'valueString' => ''],
    ]);
    // Real-world GCSS body text: "HSS" (hollow structural section) next to an
    // amount column must NOT fabricate an HSS-6 code; free text with typed
    // codes must not match either.
    $content['markdown'] = "Steel HSS\n6,000.00 column\nAlso typed text HLW-{$waiver->id} should not count";

    fakeScanPipeline([$content]);

    $stats = app(WaiverScanIngest::class)->processInbox();

    expect($stats['matched'])->toBe(0)
        ->and($waiver->refresh()->status)->toBe(LienWaiverStatus::Sent);
    assertMovedTo(WSI_ERROR);
});

it('re-rendering a waiver never clobbers an ingested scan', function () {
    [, , , $waiver] = waiverScanFixtures();

    fakeScanPipeline([cuContentFor('HLW-' . $waiver->id)]);
    app(WaiverScanIngest::class)->processInbox();

    $waiver->refresh();
    $scanPath = $waiver->signed_path;
    $scanBytes = Storage::disk('files')->get($scanPath);

    // A later generate(store: true) — e.g. an admin re-render — must keep the scan.
    \App\Support\LienWaiverDocumentGenerator::generate($waiver, store: true);

    $waiver->refresh();
    expect($waiver->signed_path)->toBe($scanPath)
        ->and(Storage::disk('files')->get($scanPath))->toBe($scanBytes);
});

/**
 * A real, FPDI-parseable N-page PDF (plain FPDF, not FPDI — nothing to
 * import). Page-trimming needs a genuine multi-page document to operate on;
 * every other test in this file deliberately uses the non-parseable fake
 * string so it stays on the untouched fast path.
 */
function buildMultiPagePdf(int $pages): string
{
    $pdf = new \FPDF();
    for ($i = 1; $i <= $pages; $i++) {
        $pdf->AddPage();
        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(0, 10, "Page {$i}");
    }

    return $pdf->Output('S');
}

it('excludes an unrelated attached page from the stored scan', function () {
    [, , , $waiver] = waiverScanFixtures();
    $code = 'HLW-' . $waiver->id;

    // Page 1 = the real waiver (barcode + footer marker); page 2 = a vendor's
    // own invoice glued on by their scanner — no barcode, no footer marker.
    $content = cuContentFor($code);
    $content['startPageNumber'] = 1;
    $content['endPageNumber'] = 2;
    $content['paragraphs'] = [
        ['content' => 'Please email the signed copy back to waivers@hive.contractors ASAP.', 'source' => 'D(1,0,0,0,0,0,0,0,0)'],
        ['content' => 'INVOICE', 'source' => 'D(2,0,0,0,0,0,0,0,0)'],
    ];

    fakeScanPipeline([$content], downloadBinary: buildMultiPagePdf(2));

    $stats = app(WaiverScanIngest::class)->processInbox();

    expect($stats['matched'])->toBe(1)->and($stats['errors'])->toBe(0);

    $waiver->refresh();
    $stored = Storage::disk('files')->get($waiver->signed_path);
    expect(\App\Support\PdfPageExtractor::pageCount($stored))->toBe(1);
});

it('keeps every page of a genuine multi-page document even when one page barcode fails to scan', function () {
    [$gc, , $project] = waiverScanFixtures();

    $statement = SwornStatement::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $gc->id,
        'this_payment' => 60000,
        'status' => LienWaiverStatus::Sent,
        'filename' => 'sworn-statement-gs.pdf',
    ]);
    $code = 'HSS-' . $statement->id;

    // Both pages are genuinely part of the same GCSS: page 1's barcode
    // decodes fine, page 2's does not (a crumpled corner) — but page 2 still
    // carries the return-email footer, so it must be kept, not dropped.
    $content = [
        'markdown' => '<!-- PageFooter: ![Code128](barcodes/1.1 "' . $code . '") -->',
        'startPageNumber' => 1,
        'endPageNumber' => 2,
        'paragraphs' => [
            ['content' => 'Please email the signed copy back to waivers@hive.contractors ASAP.', 'source' => 'D(1,0,0,0,0,0,0,0,0)'],
            ['content' => 'Please email the signed copy back to waivers@hive.contractors ASAP.', 'source' => 'D(2,0,0,0,0,0,0,0,0)'],
        ],
        'fields' => [
            'DocumentType' => ['type' => 'string', 'valueString' => 'sworn_statement'],
            'ClaimantCompanyName' => ['type' => 'string', 'valueString' => 'GS Construction & Remodeling, Inc'],
            'PropertyAddress' => ['type' => 'string', 'valueString' => '3154 Violet Ln, Northbrook, IL 60062'],
            'IsWetSigned' => ['type' => 'boolean', 'valueBoolean' => true],
            'StatementSignatureText' => ['type' => 'string', 'valueString' => 'Patryk Szady'],
            'NotaryDay' => ['type' => 'string', 'valueString' => '25'],
            'NotaryMonth' => ['type' => 'string', 'valueString' => 'July'],
            'NotaryYear' => ['type' => 'string', 'valueString' => '26'],
            'NotarySignatureText' => ['type' => 'string', 'valueString' => 'Pinan Kll'],
            'NotaryName' => ['type' => 'string', 'valueString' => 'Adriana Y Alvarez'],
            'NotaryCommissionNumber' => ['type' => 'string', 'valueString' => '840218'],
            'HasNotaryStamp' => ['type' => 'boolean', 'valueBoolean' => true],
        ],
    ];

    fakeScanPipeline([$content], downloadBinary: buildMultiPagePdf(2));

    $stats = app(WaiverScanIngest::class)->processInbox();

    expect($stats['matched'])->toBe(1)->and($stats['errors'])->toBe(0);

    $statement->refresh();
    $stored = Storage::disk('files')->get($statement->signed_path);
    expect(\App\Support\PdfPageExtractor::pageCount($stored))->toBe(2);
});

it('does not trim when there is no page-level evidence to decide safely (matched via footer filename, real multi-page attachment)', function () {
    [, , , $waiver] = waiverScanFixtures();

    // No barcode anywhere in the markdown — matched purely via the footer
    // filename fallback, exactly like the "falls back to the footer
    // filename" test above, but this time backed by a REAL 2-page PDF. With
    // zero barcode-page evidence, selectPagesToKeep() must return null and
    // trimToOwnPages() must leave the attachment untouched.
    $content = cuContentFor('IGNORED', [
        'FooterFilename' => ['type' => 'string', 'valueString' => "lien-waiver-{$waiver->id}-accomplished-j-plumbing-inc-3154-violet-ln.pdf"],
    ]);
    $content['markdown'] = '<!-- PageFooter: no barcode readable -->';

    fakeScanPipeline([$content], downloadBinary: buildMultiPagePdf(2));

    $stats = app(WaiverScanIngest::class)->processInbox();

    expect($stats['matched'])->toBe(1);

    $waiver->refresh();
    $stored = Storage::disk('files')->get($waiver->signed_path);
    expect(\App\Support\PdfPageExtractor::pageCount($stored))->toBe(2);
});

it('PdfPageExtractor keeps only the requested pages, in the order given', function () {
    $binary = buildMultiPagePdf(3);

    expect(\App\Support\PdfPageExtractor::pageCount($binary))->toBe(3);

    $onlyFirst = \App\Support\PdfPageExtractor::extractPages($binary, [1]);
    expect(\App\Support\PdfPageExtractor::pageCount($onlyFirst))->toBe(1);

    $reordered = \App\Support\PdfPageExtractor::extractPages($binary, [3, 1]);
    expect(\App\Support\PdfPageExtractor::pageCount($reordered))->toBe(2);

    expect(\App\Support\PdfPageExtractor::extractPages($binary, [99]))->toBeNull()
        ->and(\App\Support\PdfPageExtractor::extractPages('not a pdf', [1]))->toBeNull()
        ->and(\App\Support\PdfPageExtractor::pageCount('not a pdf'))->toBeNull();
});
