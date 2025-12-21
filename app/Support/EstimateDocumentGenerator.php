<?php

namespace App\Support;

use App\Models\Estimate;
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
    public static function generate(Estimate $estimate, string $type = 'Estimate', bool $store = false): array
    {
        $estimate = Estimate::withoutGlobalScopes()
            ->with([
                'estimate_sections.estimate_line_items',
                'vendor' => fn ($query) => $query->withoutGlobalScopes(),
                'project' => fn ($query) => $query
                    ->withoutGlobalScopes()
                    ->with([
                        'client' => fn ($clientQuery) => $clientQuery->withoutGlobalScopes(),
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
        $client = $estimate->client;
        $reimbursements = $estimate->reimbursments;
        
        $clientName = $client?->name ?? 'Unknown Client';
        $projectName = $project?->project_name ?? 'Unknown Project';
        $title = $clientName . ' - ' . $type . ' - ' . $projectName . ' - ' . $estimate->number;

        $view = view('misc.estimate', compact('estimate', 'vendor', 'client', 'project', 'sections', 'payments', 'title', 'estimate_total', 'estimate_total_words', 'type', 'reimbursements'))->render();

        $binary = Browsershot::html($view)
            ->newHeadless()
            ->addChromiumArguments([
                'no-sandbox',
                'disable-setuid-sandbox',
                'disable-dev-shm-usage',
                'disable-gpu',
                'single-process',
            ])
            ->scale(0.8)
            ->showBrowserHeaderAndFooter()
            ->showBackground()
            ->headerHtml('<div style="font-size: 10px; width: 100%; padding: 0; margin: 0 5mm 0 10mm; display: flex; justify-content: space-between;"><span>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</span><span>' . now()->format('m/d/Y g:i A') . '</span></div>')
            ->footerHtml('<div style="font-size: 10px; text-align: right; width: 100%; padding: 0; margin: 0 5mm 0 10mm;"><span class="pageNumber"></span> / <span class="totalPages"></span></div>')
            ->margins(10, 5, 10, 5)
            ->pdf();

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
}
