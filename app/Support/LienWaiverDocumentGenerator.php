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
                'vendor',
                'payerVendor',
                'project',
                'project.estimates',
                'check',
                'payment',
                'signatures',
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
            'signatures' => $waiver->signatures,
            'isSigned' => $isSigned,
            'isDraft' => ! $isSigned,
        ])->render();

        $headerHtml = self::buildHeaderHtml($waiver);
        $footerHtml = self::buildFooterHtml($waiver);

        $pdf = self::renderPdf($html, $headerHtml, $footerHtml);

        $filename = sprintf(
            'lien-waiver-%d-%s-%s.pdf',
            $waiver->id,
            $variant,
            $waiver->updated_at?->format('YmdHis') ?? now()->format('YmdHis'),
        );

        $title = sprintf(
            '%s - Lien Waiver - %s',
            $effectivePayerVendor?->business_name ?? ($payerOverride['name'] ?? 'Contractor'),
            $waiver->vendor->business_name ?? 'Vendor',
        );

        $relativePath = sprintf('lien-waivers/%d/%s', $waiver->project_id, $filename);
        $absolutePath = null;

        if ($store) {
            Storage::disk('files')->put($relativePath, $pdf);
            $absolutePath = Storage::disk('files')->path($relativePath);

            $waiver->forceFill([
                $isSigned ? 'signed_path' : 'draft_path' => $relativePath,
            ])->saveQuietly();
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
     * Render HTML → PDF using Browsershot (Chromium). Falls back to returning
     * the raw HTML wrapped as a binary string when headless Chrome isn't
     * available, so calling code can still persist *something* and surface a
     * preview link rather than throwing in environments without Chrome
     * (CI, lightweight workers, the test suite, etc.).
     */
    protected static function renderPdf(string $html, ?string $headerHtml = null, ?string $footerHtml = null): string
    {
        try {
            $shot = Browsershot::html($html)
                ->format('Letter')
                ->margins(24, 15, 22, 15)
                ->showBackground();

            if (! empty($headerHtml) || ! empty($footerHtml)) {
                $shot
                    ->showBrowserHeaderAndFooter()
                    ->headerHtml((string) $headerHtml)
                    ->footerHtml((string) $footerHtml);
            }

            return $shot->pdf();
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

        return <<<HTML
<div style="width:100%; font-size:8px; color:#4b5563; font-family:Arial, sans-serif; padding:0 10mm; box-sizing:border-box;">
    <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #d1d5db; padding-bottom:2px; width:100%;">
        <span><strong>Hive Contractors Lien Waiver</strong></span>
        <span>{$draftLabel}Doc {$documentRef} | {$projectName}{$customerDisplay} | {$estimateDisplay} | Through {$throughDate}</span>
    </div>
</div>
HTML;
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
        <span>Hash {$hashRef}</span>
        <span>{$draftLabel}Generated {$generatedAt}</span>
        <span>Page <span class="pageNumber"></span> of <span class="totalPages"></span></span>
    </div>
</div>
HTML;
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
     */
    protected static function lookupProjectCounty($project): ?string
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
