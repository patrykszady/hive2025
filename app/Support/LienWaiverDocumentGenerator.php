<?php

namespace App\Support;

use App\Models\LienWaiver;
use App\Services\GeoapifyService;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Browsershot\Browsershot;
use Throwable;

class LienWaiverDocumentGenerator
{
    /**
     * Render the lien waiver to a PDF and (optionally) persist it to the
     * 'files' disk. When the waiver has been signed, a "signed" copy is
     * produced — otherwise a "draft" copy.
     *
     * @return array{binary:string, filename:string, title:string, path?:string, relative_path?:string}
     */
    public static function generate(LienWaiver $waiver, bool $store = false): array
    {
        $waiver = LienWaiver::withoutGlobalScopes()
            ->with([
                // Scope-independent: documents render identically whether built
                // from the queue (guest) or an authenticated session, where
                // VendorScope could hide the claimant/payer.
                'vendor' => fn ($q) => $q->withoutGlobalScopes(),
                'payerVendor' => fn ($q) => $q->withoutGlobalScopes(),
                'project',
                'project.estimates',
                'check',
                'payment',
            ])
            ->find($waiver->getKey());

        if (! $waiver || ! $waiver->vendor || ! $waiver->project) {
            throw new RuntimeException('Lien waiver is missing data required to generate a document.');
        }

        // Manual waivers (no payerVendor, or payer is the homeowner) keep payer
        // info in notes JSON. When a manual override is present it always wins
        // over payerVendor — this covers the case where the GC issues a waiver
        // to the homeowner and the contractor==claimant on both sides.
        $payerOverride = self::extractPayerOverride($waiver);
        $effectivePayerVendor = $payerOverride ? null : $waiver->payerVendor;

        if (! $effectivePayerVendor && ! $payerOverride) {
            throw new RuntimeException('Lien waiver is missing payer information.');
        }

        $isSigned = $waiver->isSigned();
        $variant = $isSigned ? 'signed' : 'draft';

        $projectCounty = self::lookupProjectCounty($waiver->project);

        $html = view('pdf.lien-waiver', [
            'waiver' => $waiver,
            'vendor' => $waiver->vendor,
            'payerVendor' => $effectivePayerVendor,
            'payerOverride' => $payerOverride,
            'project' => $waiver->project,
            'projectCounty' => $projectCounty,
            'check' => $waiver->check,
            'payment' => $waiver->payment,
            'isSigned' => $isSigned,
            'isDraft' => ! $isSigned,
            'isSubWaiver' => $waiver->isSubWaiver(),
            'amountWords' => self::amountInWords($waiver),
            'affidavit' => self::buildAffidavitContext($waiver),
        ])->render();

        // The traditional Illinois form is a self-contained legal document that
        // must fit on a single page with no Hive header/footer chrome.
        $isIllinois = in_array(strtoupper((string) $waiver->jurisdiction), ['IL', 'US-IL'], true);

        // Illinois forms still get a minimal footer: the filename plus a
        // Code 128 barcode of the waiver id, so returned wet-signed scans can
        // be matched to the right waiver even when OCR struggles.
        $headerHtml = $isIllinois ? null : self::buildHeaderHtml($waiver);
        $footerHtml = $isIllinois ? self::buildIllinoisFooterHtml($waiver) : self::buildFooterHtml($waiver);

        $pdf = self::renderPdf($html, $headerHtml, $footerHtml, $isIllinois);

        $filename = self::filenameFor($waiver);

        $title = sprintf(
            '%s - Lien Waiver - %s',
            $effectivePayerVendor?->business_name ?? ($payerOverride['name'] ?? 'Contractor'),
            $waiver->vendor->business_name ?? 'Vendor',
        );

        // Store under a per-waiver folder so the clean, non-unique filename can't
        // collide between different waivers for the same vendor/date.
        $relativePath = sprintf('lien-waivers/%d/%d/%s', $waiver->project_id, $waiver->id, $filename);
        $absolutePath = null;

        // Never clobber an ingested wet-signed scan: once the waivers@ ingest
        // stored a returned scan in signed_path, a re-render must not replace
        // the legal original with a freshly generated PDF.
        $hasIngestedScan = $isSigned
            && $waiver->signed_path
            && str_contains($waiver->signed_path, '/scan-')
            && Storage::disk('files')->exists($waiver->signed_path);

        // renderPdf() falls back to raw HTML when Chrome is unavailable. That is
        // fine for a first render (something is better than nothing), but it
        // must never replace a document that already rendered properly —
        // re-pricing a waiver on a box with a broken Chrome would otherwise
        // swap a real PDF for HTML bytes named .pdf.
        $wouldDowngrade = $store
            && ! $hasIngestedScan
            && ! str_starts_with($pdf, '%PDF')
            && Storage::disk('files')->exists($relativePath)
            && str_starts_with((string) Storage::disk('files')->get($relativePath), '%PDF');

        if ($wouldDowngrade) {
            \Illuminate\Support\Facades\Log::warning('Lien waiver re-render produced HTML, not a PDF — keeping the existing document.', [
                'lien_waiver_id' => $waiver->id,
                'path' => $relativePath,
            ]);
        }

        if ($store && ! $hasIngestedScan && ! $wouldDowngrade) {
            Storage::disk('files')->put($relativePath, $pdf);
            $absolutePath = Storage::disk('files')->path($relativePath);

            $waiver->forceFill([
                $isSigned ? 'signed_path' : 'draft_path' => $relativePath,
            ])->saveQuietly();
        } elseif ($store && $hasIngestedScan) {
            $relativePath = $waiver->signed_path;
            $absolutePath = Storage::disk('files')->path($relativePath);
        }

        return array_filter([
            'binary' => $pdf,
            'filename' => $filename,
            'title' => $title,
            'path' => $absolutePath,
            'relative_path' => $store ? $relativePath : null,
        ]);
    }

    /**
     * The canonical filename for a waiver's PDF: id, claimant, estimate
     * number, homeowner, jobsite address and through date. Public so the
     * download route can name stored files consistently even when the file
     * on disk was saved under an older naming scheme.
     */
    public static function filenameFor(LienWaiver $waiver): string
    {
        $vendor = $waiver->vendor ?? \App\Models\Vendor::withoutGlobalScopes()->find($waiver->vendor_id);
        $vendorSlug = \Illuminate\Support\Str::slug((string) ($vendor?->business_name ?? 'vendor')) ?: 'vendor';
        $dateSlug = ($waiver->through_date ?? $waiver->updated_at ?? now())->format('Y-m-d');
        $estimateNumber = (string) ($waiver->project?->estimates?->first()?->number ?? '');
        $owner = $waiver->project?->client()->withoutGlobalScopes()->first();
        $drawNumber = $waiver->sworn_statement_id
            ? \App\Models\SwornStatement::find($waiver->sworn_statement_id)?->drawNumber()
            : null;

        return collect([
            'lien-waiver',
            $waiver->id,
            $vendorSlug,
            $estimateNumber !== '' ? \Illuminate\Support\Str::slug($estimateNumber) : null,
            \Illuminate\Support\Str::slug((string) ($owner?->name ?? '')),
            \Illuminate\Support\Str::slug((string) ($waiver->project?->address ?? '')),
            $drawNumber ? 'draw-' . $drawNumber : null,
            $dateSlug,
        ])->filter(fn ($part) => $part !== null && $part !== '')->implode('-') . '.pdf';
    }

    /**
     * Render HTML → PDF using Browsershot (Chromium). Falls back to returning
     * the raw HTML wrapped as a binary string when headless Chrome isn't
     * available, so calling code can still persist *something* and surface a
     * preview link rather than throwing in environments without Chrome
     * (CI, lightweight workers, the test suite, etc.).
     */
    /**
     * Per-field options for the fillable blanks marked up in the Blade
     * template. Sizes match the printed text so a typed value sits on the rule
     * rather than floating above it.
     */
    protected const FILLABLE_FIELDS = [
        'waiver_date' => ['size' => 9, 'maxlen' => 12],
        'claimant_address' => ['size' => 9],
        'affiant_name' => ['size' => 9],
        'affiant_position' => ['size' => 9],
    ];

    protected static function renderPdf(string $html, ?string $headerHtml = null, ?string $footerHtml = null, bool $compact = false): string
    {
        try {
            $shot = Browsershot::html($html)
                ->format('Letter')
                ->showBackground();

            // Same as the estimate/project/sheet generators: honour an explicit
            // browser path. Without it these two were the only documents left
            // relying on Puppeteer's own resolution, so a machine with a
            // working CHROME_PATH still failed to render waivers.
            if ($chromePath = env('CHROME_PATH')) {
                $shot->setChromePath($chromePath);
            }

            // Compact (no-chrome) forms get tight uniform margins — deeper at
            // the bottom when a footer (filename + barcode) rides along; the
            // standard layout leaves room for the running header/footer.
            $compact
                ? $shot->margins(9, 12, empty($footerHtml) ? 9 : 21, 12)
                : $shot->margins(24, 15, 22, 15);

            if (! empty($headerHtml) || ! empty($footerHtml)) {
                $shot
                    ->showBrowserHeaderAndFooter()
                    // A truly empty template makes Chromium print its default
                    // date/title header — a blank span suppresses it.
                    ->headerHtml((string) ($headerHtml ?: '<span></span>'))
                    ->footerHtml((string) ($footerHtml ?: '<span></span>'));
            }

            // Chromium cannot emit form fields, so the blanks are rendered as
            // marker links and rewritten into real AcroForm text fields here.
            // A no-op on documents that carry no markers, and on the HTML
            // fallback below.
            return PdfAcroFormOverlay::apply($shot->pdf(), self::FILLABLE_FIELDS);
        } catch (Throwable $e) {
            // Fall back to HTML payload so storage/path tracking still works.
            return $html;
        }
    }

    protected static function buildHeaderHtml(LienWaiver $waiver): string
    {
        $projectName = self::escapeHtml($waiver->project?->project_name ?? 'Unknown Project');
        $throughDate = self::escapeHtml($waiver->through_date?->format('m/d/Y') ?? 'N/A');
        $documentRef = self::escapeHtml('LW-' . $waiver->id);
        
        // Get estimate number (first estimate if multiple)
        $estimateNumber = $waiver->project?->estimates?->first()?->number;
        $estimateDisplay = $estimateNumber ? self::escapeHtml('Est #' . $estimateNumber) : '';
        
        // Get customer/owner name
        $customerName = $waiver->project?->client?->name;
        $customerDisplay = $customerName ? self::escapeHtml(' | ' . $customerName) : '';
        $draftLabel = $waiver->isSigned() ? '' : '<span style="color:#b91c1c;font-weight:700;">DRAFT COPY</span> | ';

        $revisionLabel = self::revisionLabel($waiver);
        $revisionTag = $revisionLabel !== ''
            ? '<span style="color:#b91c1c;font-weight:700;">' . self::escapeHtml($revisionLabel) . '</span> | '
            : '';

        return <<<HTML
<div style="width:100%; font-size:8px; color:#4b5563; font-family:Arial, sans-serif; padding:0 10mm; box-sizing:border-box;">
    <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #d1d5db; padding-bottom:2px; width:100%;">
        <span><strong>Hive Contractors Lien Waiver</strong></span>
        <span>{$draftLabel}Doc {$documentRef} | {$projectName}{$customerDisplay} | {$estimateDisplay} | Through {$throughDate}</span>
    </div>
</div>
HTML;
    }

    /**
     * Footer for the compact Illinois forms: the document's filename in small
     * print with a Code 128 barcode ("HLW-{id}") underneath. Vendors print,
     * wet-sign, notarize and scan these back — the barcode survives bad scans
     * far better than tiny text, so Azure Content Understanding can still
     * match the scan to the right waiver and flip it to Signed.
     */
    public static function barcodeValueFor(LienWaiver $waiver): string
    {
        return 'HLW-' . $waiver->id;
    }

    protected static function buildIllinoisFooterHtml(LienWaiver $waiver): string
    {
        return PdfDocumentFooter::build(
            self::barcodeValueFor($waiver),
            self::filenameFor($waiver),
            revisionLabel: self::revisionLabel($waiver),
        );
    }

    /**
     * "REV-{n}" once a waiver has been re-priced, empty before that.
     *
     * The barcode identifies the waiver but not its version, so without this a
     * vendor could sign the superseded copy still sitting in their inbox and it
     * would scan back in against a figure that no longer exists. Printed only
     * from revision 2 on, so every document issued before this feature — and
     * every waiver never edited — looks exactly as it always did.
     */
    public static function revisionLabel(LienWaiver $waiver): string
    {
        $revision = (int) ($waiver->document_revision ?: 1);

        return $revision > 1 ? 'REV-' . $revision : '';
    }

    protected static function buildFooterHtml(LienWaiver $waiver): string
    {
        $hash = (string) ($waiver->document_hash ?: $waiver->computeDocumentHash());
        $hashRef = strlen($hash) > 28
            ? substr($hash, 0, 16) . '...' . substr($hash, -8)
            : $hash;

        $hashRef = self::escapeHtml($hashRef !== '' ? $hashRef : 'N/A');
        
        // Use vendor's timezone for the "Generated" timestamp
        $vendorTimezone = $waiver->vendor?->timezone ?? config('app.timezone', 'UTC');
        $generatedAt = self::escapeHtml(now()->setTimezone($vendorTimezone)->format('m/d/Y g:i A T'));
        $draftLabel = $waiver->isSigned() ? '' : '<span style="color:#b91c1c;font-weight:700;">DRAFT COPY</span> | ';

        return <<<HTML
<div style="width:100%; font-size:8px; color:#4b5563; font-family:Arial, sans-serif; padding:0 10mm; box-sizing:border-box;">
    <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid #d1d5db; padding-top:2px; width:100%;">
        <span>{$revisionTag}Hash {$hashRef}</span>
        <span>{$draftLabel}Generated {$generatedAt}</span>
        <span>Page <span class="pageNumber"></span> of <span class="totalPages"></span></span>
    </div>
</div>
HTML;
    }

    /**
     * The waiver amount written out in words, matching the traditional form's
     * "for and in consideration of ___ (write out amount)" blank — e.g.
     * "Sixty Thousand and 00/100". Null when the waiver has no fixed amount
     * (unconditional final = paid in full).
     */
    protected static function amountInWords(LienWaiver $waiver): ?string
    {
        $amount = $waiver->amount;

        if ($amount === null || $waiver->type === \App\Enums\LienWaiverType::UnconditionalFinal) {
            return null;
        }

        $dollars = (int) floor((float) $amount);
        $cents = (int) round(((float) $amount - $dollars) * 100);

        if (class_exists(\NumberFormatter::class)) {
            $formatter = new \NumberFormatter('en_US', \NumberFormatter::SPELLOUT);
            $words = ucwords((string) $formatter->format($dollars));
        } else {
            $words = number_format($dollars);
        }

        return sprintf('%s and %02d/100', $words, $cents);
    }

    /**
     * Contract math for the Contractor's Affidavit section. The claimant's
     * original bid (type 1) is the base contract; every change order (type >= 2,
     * i.e. sections added after the signed contract) is an "extra". Amount paid
     * is every payment already recorded on the project prior to this waiver.
     */
    protected static function buildAffidavitContext(LienWaiver $waiver): array
    {
        $project = $waiver->project;

        $bidQuery = fn () => \App\Models\Bid::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->where('vendor_id', $waiver->vendor_id);

        $originalContract = (float) $bidQuery()->where('type', 1)->sum('amount');
        $extras = (float) $bidQuery()->where('type', '>', 1)->sum('amount');
        $contractTotal = $originalContract + $extras;

        // "Amount paid" = everything previously paid to THIS claimant. Each
        // party's affidavit swears its own contract math: the GC's counts the
        // owner's draws; a sub's counts what the GC has paid them (expenses and
        // check-paid payment rows). The full every-sub listing lives on the
        // standalone GCSS sworn statement, not here.
        if ($waiver->isSubWaiver()) {
            $cutoff = $waiver->payment_id
                ? $waiver->payment?->date
                : $waiver->through_date;

            $expensesPaid = (float) \App\Models\Expense::withoutGlobalScopes()
                ->where('project_id', $project->id)
                ->where('vendor_id', $waiver->vendor_id)
                ->whereNull('deleted_at')
                ->when($cutoff, fn ($q) => $q->whereDate('date', '<=', $cutoff->toDateString()))
                ->sum('amount');

            $checkPaid = (float) \App\Models\Payment::withoutGlobalScopes()
                ->where('project_id', $project->id)
                ->when($waiver->payment_id, fn ($q) => $q->where('id', '!=', $waiver->payment_id))
                ->whereIn('check_id', function ($q) use ($waiver) {
                    $q->select('id')->from('checks')
                        ->where('vendor_id', $waiver->vendor_id)
                        ->whereNull('deleted_at');
                })
                ->when($cutoff, fn ($q) => $q->whereDate('date', '<=', $cutoff->toDateString()))
                ->sum('amount');

            $totalPaid = $expensesPaid + $checkPaid;

            if ($waiver->payment_id) {
                // Auto-generated from a specific payment: that payment is "this
                // payment"; everything paid before it is prior. (The excluded
                // payment isn't in $totalPaid, so no double-count.)
                $thisPayment = (float) ($waiver->payment?->amount ?? 0);
                $amountPaid = (float) $totalPaid;
            } else {
                // Manual "to date" waiver documents money already in hand — all
                // of it is prior/paid-to-date, with no new payment made now.
                $amountPaid = (float) $totalPaid;
                $thisPayment = 0.0;
            }
        } else {
            $paymentsQuery = fn () => \App\Models\Payment::withoutGlobalScopes()
                ->where('project_id', $project->id);

            if ($waiver->payment_id) {
                $paymentDate = $waiver->payment?->date;
                $amountPaid = (float) $paymentsQuery()
                    ->where('id', '!=', $waiver->payment_id)
                    ->when($paymentDate, fn ($q) => $q->whereDate('date', '<=', $paymentDate->toDateString()))
                    ->sum('amount');
                $thisPayment = (float) ($waiver->payment?->amount ?? $waiver->amount ?? 0);
            } else {
                $amountPaid = (float) $paymentsQuery()
                    ->when($waiver->through_date, fn ($q) => $q->whereDate('date', '<=', $waiver->through_date->toDateString()))
                    ->sum('amount');
                $thisPayment = (float) ($waiver->amount ?? 0);
            }
        }

        // No contract on file (a sub with no recorded bid): the money that has
        // flowed to the claimant stands in as the contract amount, so the
        // affidavit reads sensibly ("the contract is $30,000 on which he has
        // received $30,000") instead of a blank. Record the sub's actual bid to
        // override this — if the real contract exceeds what's been paid, the
        // balance-due column will then reflect the amount still owing.
        if ($contractTotal <= 0.0) {
            $contractTotal = $amountPaid + $thisPayment;
            $originalContract = $contractTotal;
            $extras = 0.0;
        }

        if ($waiver->type === \App\Enums\LienWaiverType::UnconditionalFinal) {
            $thisPayment = max(0, $contractTotal - $amountPaid);
        }

        return [
            'original_contract' => $originalContract,
            'extras' => $extras,
            'contract_total' => $contractTotal,
            'amount_paid' => $amountPaid,
            'this_payment' => $thisPayment,
            'balance_due' => (float) max(0, $contractTotal - $amountPaid - $thisPayment),
        ];
    }

    protected static function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Extract a manual payer payload (for waivers issued to a project client
     * rather than to a known Vendor record) from the waiver's notes column.
     * Notes are expected to be JSON of the shape:
     *   {"payer": {"name": "...", "address": "...", "city_state_zip": "..."}}
     *
     * @return array{name:string, address?:string, city_state_zip?:string}|null
     */
    protected static function extractPayerOverride(LienWaiver $waiver): ?array
    {
        if (empty($waiver->notes)) {
            return null;
        }

        $decoded = json_decode((string) $waiver->notes, true);

        if (! is_array($decoded) || empty($decoded['payer']['name'])) {
            return null;
        }

        return [
            'name' => (string) $decoded['payer']['name'],
            'address' => (string) ($decoded['payer']['address'] ?? ''),
            'city_state_zip' => (string) ($decoded['payer']['city_state_zip'] ?? ''),
        ];
    }

    /**
     * Resolve the U.S. county for a project's jobsite address using Geoapify.
     * Returns null when the address is incomplete or the lookup fails — the
     * PDF will then leave the COUNTY OF ____ line blank for manual fill-in.
     * (Public so sibling document generators can share the lookup.)
     */
    public static function lookupProjectCounty($project): ?string
    {
        if (! $project) {
            return null;
        }

        $address = trim(implode(', ', array_filter([
            trim((string) ($project->address ?? '')),
            trim((string) ($project->city ?? '')),
            trim(trim((string) ($project->state ?? '')) . ' ' . trim((string) ($project->zip_code ?? ''))),
        ])));

        if ($address === '') {
            return null;
        }

        try {
            return app(GeoapifyService::class)->lookupCounty($address);
        } catch (Throwable $e) {
            return null;
        }
    }
}
