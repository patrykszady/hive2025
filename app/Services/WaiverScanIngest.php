<?php

namespace App\Services;

use App\Enums\LienWaiverStatus;
use App\Models\LienWaiver;
use App\Models\Project;
use App\Models\SwornStatement;
use App\Support\PdfMerger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Inbound pipeline for waivers@hive.contractors: vendors print, wet-sign and
 * notarize lien waivers / GCSS sworn statements, then email the scans back.
 * Every page we generate carries a Code 128 barcode ("HLW-{waiver id}" /
 * "HSS-{statement id}") plus the filename in the footer, so a scan can be
 * matched to its record even when OCR of the body text is poor.
 *
 * Flow per message (mirrors the certificates@ pipeline):
 *   Inbox -> [no usable attachment] -> Deleted Items
 *         -> attachments -> Azure Content Understanding (HiveWaivers20261)
 *         -> barcode/footer codes -> match + verify -> store scan, flip status
 *         -> all matched: PROCESSED/SAVED, anything failed: PROCESSED/ERROR.
 */
class WaiverScanIngest
{
    protected const LOG = 'waiver_scans';

    /** Attachment types worth sending to Azure CU. */
    protected const USABLE_MIMES = ['application/pdf', 'image/jpeg', 'image/png', 'image/tiff', 'image/heic'];

    protected const MAX_ATTACHMENT_BYTES = 25 * 1024 * 1024;

    public function __construct(
        protected NylasService $nylasService,
        protected ContentUnderstandingService $contentUnderstanding,
    ) {}

    /**
     * Poll the waivers mailbox inbox and process every message.
     *
     * @return array{messages:int, matched:int, errors:int, skipped:int}
     */
    public function processInbox(): array
    {
        $grantId = (string) config('nylas.waivers_grant_id');
        $inboxFolderId = (string) config('nylas.waivers_inbox_folder_id');

        $stats = ['messages' => 0, 'matched' => 0, 'errors' => 0, 'skipped' => 0];

        if ($grantId === '' || $inboxFolderId === '') {
            Log::channel(self::LOG)->warning('Waiver scan ingest skipped — nylas.waivers_* config missing.');

            return $stats;
        }

        $messages = $this->nylasService->getMessages($grantId, [
            'limit' => 10,
            'in' => $inboxFolderId,
        ]);

        // Wall-clock budget: the scheduled job wrapper is killed at 1800s, and
        // each CU analysis can poll for minutes. Stop taking on new messages
        // in time to finish cleanly; the rest stays in the inbox for the next
        // 10-minute run.
        $deadline = microtime(true) + 1140;

        foreach (($messages['data'] ?? []) as $message) {
            $messageId = $message['id'] ?? null;
            if (! $messageId) {
                continue;
            }

            if (microtime(true) > $deadline) {
                Log::channel(self::LOG)->info('Waiver scan ingest: time budget reached — deferring remaining messages to the next run.');
                break;
            }

            $stats['messages']++;

            $attachments = array_values(array_filter(
                $message['attachments'] ?? [],
                fn ($attachment) => ($attachment['is_inline'] ?? false) === false
                    && $this->isUsableAttachment($attachment),
            ));

            // Nothing we could possibly process — straight to Deleted Items,
            // same as the certificates pipeline.
            if (empty($attachments)) {
                $stats['skipped']++;
                $this->moveMessage($grantId, $messageId, (string) config('nylas.waivers_deleted_folder_id'));

                continue;
            }

            // A poison message must never abort the run (tries=1 on the
            // scheduled wrapper — an uncaught throw here would re-abort every
            // 10-minute run at the same message forever).
            try {
                $outcome = $this->processMessageAttachments($grantId, $messageId, $message, $attachments);
            } catch (\Throwable $e) {
                Log::channel(self::LOG)->error('Waiver scan: message processing threw — routing to error folder.', [
                    'message_id' => $messageId, 'error' => $e->getMessage(),
                ]);
                $outcome = ['matched' => 0, 'failed' => 1];
            }

            if ($outcome['matched'] > 0 && $outcome['failed'] === 0) {
                $stats['matched'] += $outcome['matched'];
                $this->moveMessage($grantId, $messageId, (string) config('nylas.waivers_saved_folder_id'));
            } else {
                $stats['matched'] += $outcome['matched'];
                $stats['errors']++;
                $this->moveMessage($grantId, $messageId, (string) config('nylas.waivers_error_folder_id'));
            }
        }

        Log::channel(self::LOG)->info('Waiver scan ingest run finished.', $stats);

        return $stats;
    }

    /**
     * Two passes over a message's attachments so multi-page documents that
     * arrive as separate per-page PDFs (a common scanner behavior) still work:
     *
     *   1. Analyze every attachment and GROUP by barcode: a multi-page GCSS
     *      scanned page-by-page yields the same HSS code on each page — its
     *      header (company/address) sits on page 1 while the signature and
     *      notary block sit on the last page, so completeness/corroboration
     *      only hold across the UNION of pages.
     *   2. Per code: merge the page contexts, merge the page PDFs into one
     *      document (Ghostscript), and apply once.
     *
     * @param  array<int, array<string, mixed>>  $attachments
     * @return array{matched:int, failed:int}
     */
    protected function processMessageAttachments(string $grantId, string $messageId, array $message, array $attachments): array
    {
        $matched = 0;
        $failed = 0;

        /** @var array<string, array{context: array, binaries: array<string, string>}> $documents */
        $documents = [];

        foreach ($attachments as $attachment) {
            $attachmentId = $attachment['id'] ?? null;
            if (! $attachmentId) {
                continue;
            }

            try {
                $binary = $this->nylasService->downloadAttachment($attachmentId, $grantId, $messageId);
            } catch (\Throwable $e) {
                $failed++;
                Log::channel(self::LOG)->error('Waiver scan: attachment download failed.', [
                    'message_id' => $messageId, 'attachment_id' => $attachmentId, 'error' => $e->getMessage(),
                ]);

                continue;
            }

            if (strlen($binary) === 0 || strlen($binary) > self::MAX_ATTACHMENT_BYTES) {
                $failed++;
                Log::channel(self::LOG)->warning('Waiver scan: attachment empty or too large.', [
                    'message_id' => $messageId, 'attachment_id' => $attachmentId, 'bytes' => strlen($binary),
                ]);

                continue;
            }

            try {
                $raw = $this->contentUnderstanding->analyzeBinary(
                    $binary,
                    (string) config('services.azure_cu.analyzer_id_waiver'),
                    self::LOG,
                );
            } catch (\Throwable $e) {
                $failed++;
                Log::channel(self::LOG)->error('Waiver scan: Azure CU analysis failed.', [
                    'message_id' => $messageId, 'attachment_id' => $attachmentId, 'error' => $e->getMessage(),
                ]);

                continue;
            }

            // Parsing untrusted content — contain any throw to this attachment.
            try {
                $codes = $this->resolveCodes($raw);
            } catch (\Throwable $e) {
                $failed++;
                Log::channel(self::LOG)->error('Waiver scan: code resolution failed for attachment.', [
                    'message_id' => $messageId, 'attachment_id' => $attachmentId, 'error' => $e->getMessage(),
                ]);

                continue;
            }

            if (empty($codes)) {
                $failed++;
                Log::channel(self::LOG)->warning('Waiver scan: no HLW/HSS code found in attachment.', [
                    'message_id' => $messageId, 'attachment_id' => $attachmentId,
                    'filename' => $attachment['filename'] ?? null,
                ]);

                continue;
            }

            foreach ($codes as $code => $context) {
                $documents[$code] = [
                    'context' => $this->mergeContexts($documents[$code]['context'] ?? null, $context),
                    'binaries' => ($documents[$code]['binaries'] ?? []) + [$attachmentId => $binary],
                ];
            }
        }

        foreach ($documents as $code => $document) {
            try {
                // Pages that arrived as separate PDFs become one stored document.
                $binaries = array_values($document['binaries']);
                $binary = count($binaries) === 1
                    ? $binaries[0]
                    : (PdfMerger::merge($binaries) ?? $binaries[0]);

                $result = str_starts_with($code, 'HLW-')
                    ? $this->applyToWaiver((int) substr($code, 4), $binary, $document['context'])
                    : $this->applyToStatement((int) substr($code, 4), $binary, $document['context']);
            } catch (\Throwable $e) {
                $result = 'error';
                Log::channel(self::LOG)->error('Waiver scan: applying matched document failed.', [
                    'message_id' => $messageId, 'code' => $code, 'error' => $e->getMessage(),
                ]);
            }

            if (in_array($result, ['signed', 'already_signed'], true)) {
                $matched++;
            } else {
                $failed++;
            }

            Log::channel(self::LOG)->info('Waiver scan: code processed.', [
                'message_id' => $messageId, 'code' => $code, 'result' => $result,
                'attachments' => array_keys($document['binaries']),
            ]);
        }

        return ['matched' => $matched, 'failed' => $failed];
    }

    /**
     * Union of what two pages of the same document revealed: filled blanks
     * win over empty ones, positive evidence (a signature seen on ANY page)
     * wins over a page that lacks it.
     */
    protected function mergeContexts(?array $base, array $incoming): array
    {
        if ($base === null) {
            return $incoming;
        }

        foreach (['company', 'address', 'type'] as $key) {
            $base[$key] = $base[$key] ?? $incoming[$key] ?? null;
        }

        // true wins (evidence exists somewhere); explicit false beats unknown.
        foreach (['wet_signed', 'notary_stamp'] as $key) {
            $base[$key] = ($base[$key] === true || ($incoming[$key] ?? null) === true)
                ? true
                : ($base[$key] ?? $incoming[$key] ?? null);
        }

        $base['has_affidavit'] = ($base['has_affidavit'] ?? false) || ($incoming['has_affidavit'] ?? false);

        $base['details'] = ($base['details'] ?? []) + ($incoming['details'] ?? []);

        return $base;
    }

    /** Hard cap on identities honoured per attachment (bulk-flip guard). */
    protected const MAX_CODES_PER_ATTACHMENT = 10;

    /**
     * Pull HLW-/HSS- identities out of the CU result, with the field context
     * of the page each appeared on. Deliberately narrow sources — NOT the free
     * page text, where body content like "HSS 6,000" (hollow structural
     * section next to an amount) would fabricate codes, and NOT an LLM echo
     * field (it grounds on random spans and can hallucinate):
     *   1. decoded barcode annotations in the markdown: ![Code128](... "HLW-2")
     *      — emitted deterministically by the barcode engine
     *   2. the footer filename ("lien-waiver-{id}-...", OCR-tolerant since the
     *      field is already scoped to the footer card)
     *
     * @return array<string, array{company:?string, address:?string, wet_signed:?bool, type:?string, notary_stamp:?bool, has_affidavit:?bool, details:array}>
     */
    public function resolveCodes(array $raw): array
    {
        $codes = [];

        foreach (($raw['result']['contents'] ?? []) as $content) {
            $fields = ContentUnderstandingService::normalizeFieldValues($content['fields'] ?? []);

            $context = [
                'company' => isset($fields['ClaimantCompanyName']) ? (string) $fields['ClaimantCompanyName'] : null,
                'address' => isset($fields['PropertyAddress']) ? (string) $fields['PropertyAddress'] : null,
                // Tri-state: only an explicit boolean counts; a present-but-
                // null field means CU could not tell — that must NOT reject.
                'wet_signed' => is_bool($fields['IsWetSigned'] ?? null) ? $fields['IsWetSigned'] : null,
                'type' => isset($fields['DocumentType']) ? (string) $fields['DocumentType'] : null,
                'notary_stamp' => is_bool($fields['HasNotaryStamp'] ?? null) ? $fields['HasNotaryStamp'] : null,
                // Deterministic: the printed "CONTRACTOR'S AFFIDAVIT" heading
                // is always in the OCR text when the section exists — no LLM
                // judgement needed (the boolean field proved unreliable).
                'has_affidavit' => preg_match('/CONTRACTOR.{0,3}S\s+AFFIDAVIT/iu', (string) ($content['markdown'] ?? $content['content'] ?? '')) === 1,
                // Extracted document details — completeness is computed from
                // these in code (deterministic), and they're persisted onto
                // the record's notes on signing. Handwriting transcriptions
                // and dates are best-effort; the notary stamp text is solid.
                'details' => array_filter([
                    'waiver_date' => $fields['SignedDate'] ?? null,
                    'waiver_signature_text' => $fields['WaiverSignatureText'] ?? null,
                    'company_address' => $fields['CompanyAddressText'] ?? null,
                    'affiant_name' => $fields['AffiantName'] ?? null,
                    'affiant_position' => $fields['AffiantPosition'] ?? null,
                    'affidavit_date' => $fields['AffidavitDate'] ?? null,
                    'affidavit_signature_text' => $fields['AffidavitSignatureText'] ?? null,
                    'statement_signature_text' => $fields['StatementSignatureText'] ?? null,
                    'notary_day' => $fields['NotaryDay'] ?? null,
                    'notary_month' => $fields['NotaryMonth'] ?? null,
                    'notary_year' => $fields['NotaryYear'] ?? null,
                    'notary_name' => $fields['NotaryName'] ?? null,
                    'notary_signature_text' => $fields['NotarySignatureText'] ?? null,
                    'notary_commission_number' => $fields['NotaryCommissionNumber'] ?? null,
                    'notary_commission_expires' => $fields['NotaryCommissionExpires'] ?? null,
                    // A value must contain at least one letter or digit — the
                    // form's printed "*" markers sometimes extract as values
                    // and must never count as a filled blank.
                ], fn ($v) => is_string($v) && preg_match('/[\p{L}\p{N}]/u', $v) === 1),
            ];

            $markdown = (string) ($content['markdown'] ?? $content['content'] ?? '');

            // 1. Genuine decoded barcodes only — CU annotates each decoded
            //    barcode as an image element with the value as the title.
            if (preg_match_all('/!\[[^\]]*Code[^\]]*\]\([^)"]*"\s*(H(?:LW|SS)-\d{1,10})\s*"\s*\)/i', $markdown, $matches)) {
                foreach ($matches[1] as $value) {
                    if (preg_match('/^H(LW|SS)-(\d+)$/i', trim($value), $parts)) {
                        $code = 'H' . strtoupper($parts[1]) . '-' . (int) $parts[2];
                        $codes[$code] = $codes[$code] ?? $context;
                    }
                }
            }

            // 2. Footer filename fallback: "lien-waiver-{id}-..." carries the
            //    same id as the barcode (GCSS filenames do not include the id).
            //    OCR-tolerant about hyphens/whitespace: the field is scoped to
            //    the footer card so loose matching is safe here.
            $filename = (string) ($fields['FooterFilename'] ?? '');
            if (preg_match('/lien[\s-]*waiver[\s-]*(\d{1,10})\b/i', $filename, $match)) {
                $code = 'HLW-' . (int) $match[1];
                $codes[$code] = $codes[$code] ?? $context;
            }
        }

        if (count($codes) > self::MAX_CODES_PER_ATTACHMENT) {
            Log::channel(self::LOG)->warning('Waiver scan: attachment yielded an implausible number of codes — capping.', [
                'found' => count($codes), 'kept' => self::MAX_CODES_PER_ATTACHMENT,
            ]);
            $codes = array_slice($codes, 0, self::MAX_CODES_PER_ATTACHMENT, true);
        }

        return $codes;
    }

    /**
     * @param  array{company:?string, address:?string, wet_signed:?bool, type:?string}  $context
     */
    protected function applyToWaiver(int $waiverId, string $binary, array $context): string
    {
        $waiver = LienWaiver::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->find($waiverId);

        if (! $waiver) {
            return 'unmatched';
        }

        if ($waiver->status === LienWaiverStatus::Cancelled) {
            Log::channel(self::LOG)->warning('Waiver scan: refusing to sign a cancelled waiver.', ['lien_waiver_id' => $waiverId]);

            return 'rejected_cancelled';
        }

        if ($context['wet_signed'] === false) {
            Log::channel(self::LOG)->warning('Waiver scan: document is not wet-signed.', ['lien_waiver_id' => $waiverId]);

            return 'rejected_unsigned';
        }

        $missing = $this->missingItems($context, requireNotary: (bool) ($context['has_affidavit'] ?? true));
        if ($missing !== []) {
            Log::channel(self::LOG)->warning('Waiver scan: document is incomplete — not marking Signed.', [
                'lien_waiver_id' => $waiverId,
                'missing' => $missing,
                'extracted' => $context['details'] ?? [],
            ]);

            return 'rejected_incomplete';
        }

        // The barcode is a lookup key, not authorization: the document text
        // must corroborate the match before we flip a legal status.
        $vendor = $waiver->vendor()->withoutGlobalScopes()->first();
        $project = Project::withoutGlobalScopes()->find($waiver->project_id);

        if (! $this->corroborates($context, $vendor?->business_name, $project?->address)) {
            Log::channel(self::LOG)->warning('Waiver scan: document text does not corroborate the barcode match.', [
                'lien_waiver_id' => $waiverId,
                'doc_company' => $context['company'], 'doc_address' => $context['address'],
                'expected_company' => $vendor?->business_name, 'expected_address' => $project?->address,
            ]);

            return 'rejected_mismatch';
        }

        // A scan we already ingested stays canonical — don't overwrite it.
        // An e-signed waiver (signed_path without '/scan-') is different: the
        // wet-notarized paper copy supersedes the e-sign PDF, so store it and
        // point signed_path at the scan (the e-sign PDF remains on disk and
        // the signature audit rows are untouched).
        if ($waiver->status === LienWaiverStatus::Signed && $waiver->signed_path
            && str_contains($waiver->signed_path, '/scan-')
            && Storage::disk('files')->exists($waiver->signed_path)) {
            return 'already_signed';
        }

        $path = sprintf('lien-waivers/%d/%d/scan-%s', $waiver->project_id, $waiver->id, \App\Support\LienWaiverDocumentGenerator::filenameFor($waiver));

        if (! Storage::disk('files')->put($path, $binary)) {
            Log::channel(self::LOG)->error('Waiver scan: failed to store scan file — not flipping status.', ['lien_waiver_id' => $waiverId, 'path' => $path]);

            return 'store_failed';
        }

        // Record what the analyzer read off the executed document (notary
        // identity from the seal, dates, affiant transcription) for the
        // legal audit trail.
        $notes = json_decode((string) $waiver->notes, true) ?: [];
        $notes['scan'] = ($context['details'] ?? []) + ['ingested_at' => now()->toDateTimeString()];

        $waiver->forceFill([
            'signed_path' => $path,
            'status' => LienWaiverStatus::Signed,
            'signed_at' => $waiver->signed_at ?? now(),
            'notes' => json_encode($notes),
        ])->saveQuietly();

        return 'signed';
    }

    /**
     * @param  array{company:?string, address:?string, wet_signed:?bool, type:?string}  $context
     */
    protected function applyToStatement(int $statementId, string $binary, array $context): string
    {
        $statement = SwornStatement::query()->find($statementId);

        if (! $statement) {
            return 'unmatched';
        }

        if ($context['wet_signed'] === false) {
            Log::channel(self::LOG)->warning('Waiver scan: GCSS is not wet-signed.', ['sworn_statement_id' => $statementId]);

            return 'rejected_unsigned';
        }

        // A GCSS is always sworn before a notary — completeness and the
        // stamp are unconditionally required.
        $missing = $this->missingItems($context, requireNotary: true, isStatement: true);
        if ($missing !== []) {
            Log::channel(self::LOG)->warning('Waiver scan: GCSS is incomplete — not marking Signed.', [
                'sworn_statement_id' => $statementId,
                'missing' => $missing,
                'extracted' => $context['details'] ?? [],
            ]);

            return 'rejected_incomplete';
        }

        $project = Project::withoutGlobalScopes()->find($statement->project_id);
        $contractor = \App\Models\Vendor::withoutGlobalScopes()->find($statement->belongs_to_vendor_id);

        if (! $this->corroborates($context, $contractor?->business_name, $project?->address)) {
            Log::channel(self::LOG)->warning('Waiver scan: GCSS text does not corroborate the barcode match.', [
                'sworn_statement_id' => $statementId,
                'doc_company' => $context['company'], 'doc_address' => $context['address'],
                'expected_company' => $contractor?->business_name, 'expected_address' => $project?->address,
            ]);

            return 'rejected_mismatch';
        }

        if ($statement->status === LienWaiverStatus::Signed && $statement->signed_path
            && Storage::disk('files')->exists($statement->signed_path)) {
            return 'already_signed';
        }

        $path = sprintf('sworn-statements/%d/%d/scan-%s', $statement->project_id, $statement->id, $statement->filename ?: 'sworn-statement.pdf');

        if (! Storage::disk('files')->put($path, $binary)) {
            Log::channel(self::LOG)->error('Waiver scan: failed to store GCSS scan file — not flipping status.', ['sworn_statement_id' => $statementId, 'path' => $path]);

            return 'store_failed';
        }

        $statement->forceFill([
            'signed_path' => $path,
            'status' => LienWaiverStatus::Signed,
            'signed_at' => now(),
        ])->save();

        return 'signed';
    }

    /**
     * Every required blank must be filled before a document counts as
     * executed. Completeness is computed HERE from the individually
     * extracted fields (deterministic — no model self-audit): a blank counts
     * as filled when the analyzer extracted any value for it. Returns the
     * list of missing item keys; empty means complete.
     *
     * @param  array{details:array, notary_stamp:?bool}  $context
     * @return array<int, string>
     */
    protected function missingItems(array $context, bool $requireNotary, bool $isStatement = false): array
    {
        $details = $context['details'] ?? [];
        $missing = [];

        if ($isStatement) {
            if (empty($details['statement_signature_text'])) {
                $missing[] = 'statement_signature';
            }
        } else {
            if (empty($details['waiver_date'])) {
                $missing[] = 'waiver_date';
            }
            if (empty($details['waiver_signature_text'])) {
                $missing[] = 'waiver_signature';
            }

            // Waiver-only documents (no affidavit — retail/material suppliers
            // whose address we often don't have) must also fill the ADDRESS
            // line; on full waivers it's pre-printed and the affidavit rules
            // carry the completeness burden.
            if (! $requireNotary && empty($details['company_address'])) {
                $missing[] = 'company_address';
            }
        }

        if ($requireNotary) {
            if (! $isStatement) {
                if (empty($details['affiant_name'])) {
                    $missing[] = 'affiant_name';
                }
                if (empty($details['affiant_position'])) {
                    $missing[] = 'affiant_position';
                }
                if (empty($details['affidavit_date'])) {
                    $missing[] = 'affidavit_date';
                }
                if (empty($details['affidavit_signature_text'])) {
                    $missing[] = 'affidavit_signature';
                }
            }

            if (empty($details['notary_day']) || empty($details['notary_month']) || empty($details['notary_year'])) {
                $missing[] = 'notary_date';
            }
            if (empty($details['notary_signature_text'])) {
                $missing[] = 'notary_signature';
            }

            // Stamp evidence: the explicit boolean, or the stamp's own text
            // (notary name / commission number) having been extracted.
            $hasStamp = $context['notary_stamp'] === true
                || ! empty($details['notary_name'])
                || ! empty($details['notary_commission_number']);
            if (! $hasStamp) {
                $missing[] = 'notary_stamp';
            }
        }

        return $missing;
    }

    /**
     * A match is corroborated when the document's company name or property
     * address resembles what we expect for the matched record. Fails CLOSED:
     * the barcode is guessable untrusted input, so a document that offers no
     * readable company/address to check against goes to the error folder for
     * a human instead of being trusted on the barcode alone.
     */
    protected function corroborates(array $context, ?string $expectedCompany, ?string $expectedAddress): bool
    {
        $companyScore = $this->similarity($context['company'] ?? null, $expectedCompany);
        $addressScore = $this->similarity($context['address'] ?? null, $expectedAddress);

        if ($companyScore === null && $addressScore === null) {
            return false;
        }

        return max($companyScore ?? 0.0, $addressScore ?? 0.0) >= 0.55;
    }

    protected function similarity(?string $a, ?string $b): ?float
    {
        $a = strtolower(trim((string) $a));
        $b = strtolower(trim((string) $b));

        if ($a === '' || $b === '') {
            return null;
        }

        similar_text($a, $b, $percent);

        return $percent / 100;
    }

    protected function isUsableAttachment(array $attachment): bool
    {
        $mime = strtolower((string) ($attachment['content_type'] ?? ''));
        if (in_array(strtok($mime, ';'), self::USABLE_MIMES, true)) {
            return true;
        }

        $extension = strtolower(pathinfo((string) ($attachment['filename'] ?? ''), PATHINFO_EXTENSION));

        return in_array($extension, ['pdf', 'jpg', 'jpeg', 'png', 'tif', 'tiff', 'heic'], true);
    }

    protected function moveMessage(string $grantId, string $messageId, string $folderId): void
    {
        if ($folderId === '') {
            Log::channel(self::LOG)->error('Waiver scan: no target folder configured — message stays in the inbox and WILL reprocess.', [
                'message_id' => $messageId,
            ]);

            return;
        }

        // moveEmailToFolder swallows transport errors and returns false — a
        // failed move means the message stays in the inbox and re-analyzes
        // (and re-bills Azure CU) every run until someone notices.
        try {
            $moved = $this->nylasService->moveEmailToFolder($messageId, $folderId, $grantId);
        } catch (\Throwable $e) {
            $moved = false;
        }

        if (! $moved) {
            Log::channel(self::LOG)->error('Waiver scan: failed to move message out of the inbox — it will reprocess next run.', [
                'message_id' => $messageId, 'target_folder_id' => $folderId,
            ]);
        }
    }
}
