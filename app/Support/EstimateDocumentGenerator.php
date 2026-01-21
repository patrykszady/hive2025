<?php

namespace App\Support;

use App\Models\EmailTemplate;
use App\Models\Estimate;
use App\Models\Vendor;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Browsershot\Browsershot;

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
        $projectFinances = $project?->finances ?? [];
        
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

        $view = view('misc.estimate', compact('estimate', 'vendor', 'client', 'clientContacts', 'project', 'sections', 'payments', 'title', 'estimate_total', 'estimate_total_words', 'type', 'reimbursements', 'contractBody', 'vendorLogoDataUrl', 'projectStatusTitle', 'projectFinances'))->render();

        $browsershot = Browsershot::html($view)
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
     * Replace placeholders in contract template with actual values.
     */
    protected static function renderContractTemplate(string $body, array $data): string
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

        // Ensure empty paragraphs have proper spacing (add non-breaking space)
        $body = preg_replace('/<p><\/p>/', '<p>&nbsp;</p>', $body);
        $body = preg_replace('/<p>\s*<\/p>/', '<p>&nbsp;</p>', $body);

        return $body;
    }

    /**
     * Render payment schedule HTML for contract template.
     */
    protected static function renderPaymentSchedule(?array $payments): string
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
