<?php

namespace App\Support;

use App\Models\EmailTemplate;
use App\Models\Estimate;
use App\Models\Vendor;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use RuntimeException;
use Spatie\Browsershot\Browsershot;
use Spatie\SimpleExcel\SimpleExcelWriter;

class EstimateDocumentGenerator
{
    /**
     * Generate an estimate PDF and optionally persist it to storage.
     *
     * @return array{binary:string, filename:string, title:string, path?:string, relative_path?:string}
     */
    public static function generate(Estimate $estimate, string $type = 'Estimate', bool $store = false, ?string $timezone = null): array
    {
        // PDFs should use the vendor's timezone, not browser timezone
        $timezone = $timezone ?? vendor_timezone();
        
        $estimate = Estimate::withoutGlobalScopes()
            ->with([
                'estimate_sections.estimate_line_items',
                'vendor' => fn ($query) => $query->withoutGlobalScopes(),
                'project' => fn ($query) => $query
                    ->withoutGlobalScopes()
                    ->with([
                        'client' => fn ($clientQuery) => $clientQuery->withoutGlobalScopes()->with('users'),
                        'payments' => fn ($paymentQuery) => $paymentQuery->withoutGlobalScopes(),
                        'latestStatus' => fn ($statusQuery) => $statusQuery->withoutGlobalScopes(),
                    ]),
            ])
            ->find($estimate->getKey());

        if (! $estimate || ! $estimate->vendor) {
            throw new RuntimeException('Estimate vendor data unavailable for document generation.');
        }

        $project = $estimate->project;

        $sections = $estimate->estimate_sections;
        $type = ucwords(strtolower($type));

        $estimateTotal = $sections->sum('total');
        $estimate_total = $estimateTotal;
        $estimate_total_words = ucwords(
            Number::spell((int) $estimateTotal) . ' dollars and ' .
            Number::spell((int) (($estimateTotal - (int) $estimateTotal) * 100)) . ' cents'
        );

        $payments = $project?->payments?->where('belongs_to_vendor_id', $estimate->vendor?->id ?? 0) ?? collect();

        $vendor = $estimate->vendor;
        $client = $project?->client ?? $estimate->client;
        $reimbursements = $estimate->reimbursments;

        $clientContacts = $client?->users ?? collect();

        $vendorLogoDataUrl = static::vendorLogoDataUrl($vendor);

        $projectStatusTitle = $project?->latestStatus?->title;
        $projectFinances = $project
            ? $project->financesForVendor($vendor->id)
            : [];
        
        $clientName = $client?->name ?? 'Unknown Client';
        $projectName = $project?->project_name ?? 'Unknown Project';
        $title = $clientName . ' - ' . $type . ' - ' . $projectName . ' - ' . $estimate->number;

        // Load contract template for estimates
        $contractBody = null;
        if ($type === 'Estimate') {
            $contractTemplate = EmailTemplate::withoutGlobalScopes()
                ->where('vendor_id', $vendor->id)
                ->where('type', 'contract')
                ->first();

            if ($contractTemplate) {
                $paymentScheduleHtml = static::renderPaymentSchedule($estimate->payments);

                $contractBody = static::renderContractTemplate($contractTemplate->body, [
                    'today_date' => now()->setTimezone($timezone)->format('m/d/Y'),
                    'vendor_name' => $vendor->business_name ?? 'Unknown Vendor',
                    'short_vendor_name' => data_get($vendor->options, 'short_name') ?: ($vendor->business_name ?? 'Unknown Vendor'),
                    'client_name' => $client?->name ?? 'Unknown Client',
                    'estimate_number' => $estimate->number,
                    'project_address' => $project?->full_address ?? 'No address on file',
                    'start_date' => $estimate->start_date?->format('m/d/Y') ?? 'START_DATE_HERE',
                    'end_date' => $estimate->end_date?->format('m/d/Y') ?? 'END_DATE_HERE',
                    'estimate_total' => money($estimate_total),
                    'estimate_total_words' => $estimate_total_words,
                    'payment_schedule' => $paymentScheduleHtml,
                    'current_year' => now()->setTimezone($timezone)->format('Y'),
                ]);
            }
        }

        // Load all signatures for this estimate
        $vendorUserIds = $vendor->users?->pluck('id')->toArray() ?? [];
        $allSignatures = $estimate->signatures()->orderBy('signed_at')->get()->map(function ($sig) use ($timezone, $vendorUserIds) {
            return [
                'data' => $sig->signature_data,
                'name' => $sig->signer_name,
                'date' => $sig->signed_at?->setTimezone($timezone)->format('m/d/Y g:i A'),
                'type' => $sig->signature_type,
                'role' => in_array($sig->user_id, $vendorUserIds) ? 'Contractor' : 'Client',
            ];
        });

        // Keep legacy single-signature variables for backward compatibility
        $signatureData = null;
        $signatureName = null;
        $signatureDate = null;

        $signature = $estimate->signature;
        if ($signature) {
            $signatureData = $signature->signature_data;
            $signatureName = $signature->signer_name;
            $signatureDate = $signature->signed_at->setTimezone($timezone)->format('m/d/Y g:i A');
        }

        $view = view('misc.estimate', compact('estimate', 'vendor', 'client', 'clientContacts', 'project', 'sections', 'payments', 'title', 'estimate_total', 'estimate_total_words', 'type', 'reimbursements', 'contractBody', 'vendorLogoDataUrl', 'projectStatusTitle', 'projectFinances', 'signatureData', 'signatureName', 'signatureDate', 'allSignatures'))->render();

        // Browsershot's setHtml() has aggressive SSRF protection that rejects HTML
        // containing file://, 127.x, localhost, etc. In queue-worker context (Horizon),
        // Livewire/Flux may inject script/link tags referencing APP_URL which triggers
        // these checks. Writing to a temp file and using htmlFromFilePath() bypasses
        // setHtml() entirely — Chrome loads the file directly. We still sanitize
        // unnecessary localhost resources to avoid Chrome trying to fetch them.
        $view = static::sanitizeHtmlForPdf($view);

        $tempHtmlPath = storage_path('app/temp/' . Str::uuid() . '.html');
        if (! is_dir(dirname($tempHtmlPath))) {
            mkdir(dirname($tempHtmlPath), 0755, true);
        }
        file_put_contents($tempHtmlPath, $view);

        try {
        $browsershot = Browsershot::htmlFromFilePath($tempHtmlPath)
            ->newHeadless()
            ->timeout(180)
            ->waitUntilNetworkIdle()
            ->addChromiumArguments([
                'no-sandbox',
                'disable-setuid-sandbox',
                'disable-dev-shm-usage',
                'disable-gpu',
                'disable-software-rasterizer',
                'allow-file-access-from-files',
                'disable-extensions',
                'disable-background-networking',
                'disable-sync',
                'disable-translate',
                'no-first-run',
                'safebrowsing-disable-auto-update',
                'disable-features=VizDisplayCompositor',
            ])
            ->scale(0.8)
            ->showBrowserHeaderAndFooter()
            ->showBackground()
            ->headerHtml('<div style="font-size: 10px; width: 100%; padding: 0; margin: 0 5mm 0 10mm; display: flex; justify-content: space-between;"><span>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</span><span>' . now()->setTimezone($timezone)->format('m/d/Y g:i A') . '</span></div>')
            ->footerHtml('<div style="font-size: 10px; text-align: right; width: 100%; padding: 0; margin: 0 5mm 0 10mm;"><span class="pageNumber"></span> / <span class="totalPages"></span></div>')
            ->margins(10, 5, 10, 5);

        // Set node binary path for NVM environments
        if ($nodeBinary = env('NODE_PATH')) {
            $browsershot->setNodeBinary($nodeBinary);
            
            // Also set the node modules path for Puppeteer
            $nodeDir = dirname($nodeBinary);
            $nodeModulesPath = dirname(dirname($nodeDir)) . '/lib/node_modules';
            if (is_dir($nodeModulesPath)) {
                $browsershot->setNodeModulePath($nodeModulesPath);
            }
            
            // Ensure Chrome path is in PATH for web server context
            $browsershot->setEnvironmentOptions([
                'DISPLAY' => ':0',
            ]);
        }

        if ($chromePath = env('CHROME_PATH')) {
            $browsershot->setChromePath($chromePath);
        }

        // Write HTML to temp file to avoid process argument length limits
        $browsershot->writeOptionsToFile();

        // Set process timeout to match browsershot timeout
        $browsershot->setProcessTimeout(180);

        $binary = $browsershot->pdf();

        } finally {
            // Clean up the temp HTML file
            if (file_exists($tempHtmlPath)) {
                @unlink($tempHtmlPath);
            }
        }

        $result = [
            'binary' => $binary,
            'filename' => $title . '.pdf',
            'title' => $title,
        ];

        if ($store) {
            $relativePath = 'temp/' . Str::uuid() . '.pdf';
            Storage::disk('local')->put($relativePath, $binary);

            $result['relative_path'] = $relativePath;
            $result['path'] = Storage::disk('local')->path($relativePath);
        }

        return $result;
    }

    /**
     * Generate an estimate XLSX spreadsheet.
     *
     * @return array{binary:string, filename:string, title:string}
     */
    public static function generateXlsx(Estimate $estimate): array
    {
        $estimate = Estimate::withoutGlobalScopes()
            ->with([
                'estimate_sections.estimate_line_items',
                'vendor' => fn ($query) => $query->withoutGlobalScopes(),
                'project' => fn ($query) => $query
                    ->withoutGlobalScopes()
                    ->with([
                        'client' => fn ($clientQuery) => $clientQuery->withoutGlobalScopes(),
                    ]),
            ])
            ->find($estimate->getKey());

        if (! $estimate || ! $estimate->vendor) {
            throw new RuntimeException('Estimate vendor data unavailable for XLSX generation.');
        }

        $client = $estimate->project?->client ?? $estimate->client;
        $clientName = $client?->name ?? 'Unknown Client';
        $projectName = $estimate->project?->project_name ?? 'Unknown Project';
        $filename = $clientName . ' - Estimate - ' . $projectName . ' - ' . $estimate->number . '.xlsx';

        $tempPath = storage_path('app/temp/' . Str::uuid() . '.xlsx');

        $border = new Border(
            new BorderPart(Border::BOTTOM, Color::BLACK, Border::WIDTH_THICK, Border::STYLE_SOLID)
        );

        $writer = SimpleExcelWriter::create($tempPath)
            ->addHeader([
                '',
                'title',
                'category',
                'sub_category',
                'quantity',
                'unit',
                'cost',
                'total',
            ]);

        $writer->addRow([]);

        foreach ($estimate->estimate_sections as $index => $section) {
            $writer->addRow([
                'title' => $section->name,
                '' => '',
                'category' => null,
                'sub_category' => null,
                'quantity' => null,
                'unit' => null,
                'cost' => null,
                'total' => $section->total,
            ], (new Style)->setFontBold()->setBorder($border));

            foreach ($section->estimate_line_items as $lineItem) {
                $hideUnitFields = $lineItem->unit_type === 'no_unit';

                $writer->addRow([
                    '' => ($index + 1) . '.' . (($lineItem->order ?? 0) + 1),
                    'title' => $lineItem->name,
                    'category' => $lineItem->category,
                    'sub_category' => $lineItem->sub_category,
                    'quantity' => $hideUnitFields ? null : $lineItem->quantity,
                    'unit' => $hideUnitFields ? null : $lineItem->unit_type,
                    'cost' => $hideUnitFields ? null : $lineItem->cost,
                    'total' => $lineItem->total,
                ]);
            }

            $writer->addRow([]);
        }

        $writer->close();

        $binary = file_get_contents($tempPath);
        @unlink($tempPath);

        return [
            'binary' => $binary,
            'filename' => $filename,
            'title' => $clientName . ' - Estimate - ' . $projectName . ' - ' . $estimate->number,
        ];
    }

    protected static function vendorLogoDataUrl(Vendor $vendor): ?string
    {
        $vendorLogoPath = data_get($vendor, 'options.logo');

        if (! is_string($vendorLogoPath) || $vendorLogoPath === '') {
            return null;
        }

        try {
            $logoAbsolutePath = Storage::disk('public')->path($vendorLogoPath);
        } catch (\Throwable) {
            return null;
        }

        if (! is_string($logoAbsolutePath) || ! is_file($logoAbsolutePath)) {
            return null;
        }

        $mime = mime_content_type($logoAbsolutePath) ?: 'image/png';

        return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($logoAbsolutePath));
    }

    /**
     * Sanitize rendered HTML so Browsershot's SSRF protection doesn't reject it.
     *
     * Removes <script> and <link> tags pointing to localhost/127.x addresses
     * (injected by Livewire/Flux in queue-worker context), and neutralizes any
     * remaining localhost URL references.
     */
    protected static function sanitizeHtmlForPdf(string $html): string
    {
        // Remove <script> tags with localhost/127.x src attributes
        $html = preg_replace('#<script[^>]*src=["\'][^"\']*(?:127\.0\.0\.\d|localhost)[^"\']*["\'][^>]*>.*?</script>#is', '', $html);

        // Remove <link> tags with localhost/127.x href attributes
        $html = preg_replace('#<link[^>]*href=["\'][^"\']*(?:127\.0\.0\.\d|localhost)[^"\']*["\'][^>]*/?>#is', '', $html);

        // Neutralize any remaining //127.x or //localhost references that would
        // trigger Browsershot's regex check. Replace the protocol-relative prefix
        // with a harmless placeholder so the URL becomes inert.
        $html = preg_replace('#(//)\s*(127\.\d)#i', '//removed-local-ref-$2', $html);
        $html = preg_replace('#(//)\s*(localhost)#i', '//removed-local-ref', $html);

        return $html;
    }

    /**
     * Replace placeholders in contract template with actual values.
     */
    public static function renderContractTemplate(string $body, array $data): string
    {
        foreach ($data as $key => $value) {
            $placeholder = '{{' . $key . '}}';

            if ($key === 'payment_schedule') {
                $value = trim(strip_tags((string) $value)) === ''
                    ? static::paymentScheduleFallbackHtml()
                    : $value;
            }

            $body = str_replace($placeholder, (string) $value, $body);
        }

        // Convert page break markers to actual CSS page breaks
        $body = str_replace(
            ['<p>---PAGE BREAK---</p>', '---PAGE BREAK---'],
            '<div style="page-break-before: always;"></div>',
            $body
        );

        // Strip manual signature lines (replaced by digital signature)
        $body = preg_replace('#<p>\s*Signed\s*</p>#i', '', $body);
        $body = preg_replace('#<p>\s*Owner or Authorized Representative\s*_+.*?</p>#is', '', $body);
        $body = preg_replace('#<p>\s*Contractor\s*_+.*?</p>#is', '', $body);

        // Ensure empty paragraphs have proper spacing (add non-breaking space)
        $body = preg_replace('/<p><\/p>/', '<p>&nbsp;</p>', $body);
        $body = preg_replace('/<p>\s*<\/p>/', '<p>&nbsp;</p>', $body);

        return $body;
    }

    /**
     * Render payment schedule HTML for contract template.
     */
    public static function renderPaymentSchedule(?array $payments): string
    {
        if (empty($payments)) {
            return '';
        }

        $html = '<table class="min-w-full divide-y divide-gray-300"><thead><tr>';
        $html .= '<th class="px-3 py-2 text-sm font-semibold text-left text-gray-900">Payment</th>';
        $html .= '<th class="px-3 py-2 text-sm font-semibold text-left text-gray-900">Description</th>';
        $html .= '<th class="px-3 py-2 text-sm font-semibold text-left text-gray-900">Amount</th>';
        $html .= '</tr></thead><tbody class="divide-y divide-gray-200">';

        foreach ($payments as $key => $payment) {
            $isLast = $key === array_key_last($payments);
            $amount = ($isLast && empty($payment['amount'])) ? 'Balance' : money($payment['amount']);

            $html .= '<tr>';
            $html .= '<td class="px-3 py-2 text-sm text-gray-900 whitespace-nowrap">Payment ' . ($key + 1) . '</td>';
            $html .= '<td class="px-3 py-2 text-sm text-gray-900 whitespace-nowrap">' . htmlspecialchars($payment['description'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td class="px-3 py-2 text-sm text-gray-900 whitespace-nowrap">' . $amount . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        return $html;
    }

    protected static function paymentScheduleFallbackHtml(): string
    {
        return '<p><b><i>PAYMENT SCHEDULE HERE. Available when this Contract is ready to sign.</i></b></p>';
    }
}
