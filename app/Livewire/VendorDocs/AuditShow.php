<?php

namespace App\Livewire\VendorDocs;

use App\Models\Check;
use App\Models\Transaction;
use App\Models\Vendor;
use Carbon\Carbon;
use Ilovepdf\Ilovepdf;
use Illuminate\Support\Collection;
use Illuminate\View\View;
// SimpleExcelWriter was used previously; now using OpenSpout directly for per-cell styles
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XLSXWriter;
use OpenSpout\Common\Entity\Row;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

class AuditShow extends Component
{
    #[Url(except: '')]
    public ?string $end_date = null;

    #[Url(except: '')]
    public ?string $audit_type = null;

    #[Url(as: 'bank_account_ids', except: '')]
    public array $bank_account_ids = [];

    public ?string $view = null;

    // Only used to render a static sort indicator in the header
    public array $vendorSortDir = [];

    public function mount(): void
    {
        // No heavy work here; computed properties will handle data.
        // You can set default sort directions if desired.
    }

    #[Computed]
    public function transactions_no_check(): Collection
    {
        if (!$this->end_date || empty($this->bank_account_ids)) {
            return collect();
        }
        $start = Carbon::parse($this->end_date)->subYear()->toDateString();
        $end = Carbon::parse($this->end_date)->toDateString();

        return Transaction::whereBetween('transaction_date', [$start, $end])
            ->whereIn('bank_account_id', $this->bank_account_ids)
            ->whereNotNull('check_number')
            ->whereNull('check_id')
            ->whereNull('expense_id')
            ->orderBy('transaction_date')
            ->get();
    }

    #[Computed]
    public function vendors_grouped_checks(): Collection
    {
        if (!$this->end_date || empty($this->bank_account_ids)) {
            return collect();
        }

        $start = Carbon::parse($this->end_date)->subYear()->toDateString();
        $end = Carbon::parse($this->end_date)->toDateString();

        $grouped = Check::whereBetween('date', [$start, $end])
            ->whereIn('bank_account_id', $this->bank_account_ids)
            ->whereNotNull('vendor_id')
            ->whereNull('user_id')
            ->where('vendor_id', '!=', auth()->user()->vendor->id)
            ->where('check_type', '!=', 'Cash')
            ->with('vendor')
            ->orderBy('date')
            ->get()
            ->groupBy('vendor_id')
            ->sortBy(function ($checks) {
                $vendor = $checks->first()->vendor;
                return $vendor ? strtolower($vendor->business_name) : '';
            });

        $auditEnd = Carbon::parse($end);

        return $grouped->map(function ($checks) use ($auditEnd) {
            $group = [
                'vendor' => $checks->first()->vendor,
                'checks' => $checks->values(),
            ];

            // Attach active Professional policy (if any)
            $vendor = $group['vendor'];
            $professional = $vendor->vendor_docs()
                ->where('type', 'professional')
                ->orderByDesc('effective_date')
                ->first();
            if ($professional) {
                $effective = Carbon::parse($professional->effective_date);
                $expires = Carbon::parse($professional->expiration_date);
                if ($effective->lte($auditEnd) && $expires->gte($auditEnd)) {
                    $group['professional_doc'] = $professional;
                }
            }

            // Mark covered checks for the selected audit type documents
            if ($this->audit_type) {
                $vendor_docs = $vendor->vendor_docs()->where('type', $this->audit_type)->get();
                foreach ($vendor_docs as $vendor_doc) {
                    $doc_checks = $group['checks']->whereBetween('date', [$vendor_doc->effective_date, $vendor_doc->expiration_date]);
                    foreach ($doc_checks as $vendor_check) {
                        $vendor_check->covered = true;
                    }
                }
            }

            return $group;
        })->values();
    }

    #[Computed]
    public function vendor_docs(): array
    {
        // Build a unique list of file paths for the current audit_type
        $files = collect();
        foreach ($this->vendors_grouped_checks as $group) {
            $vendor = $group['vendor'];
            $vendor_docs = $this->audit_type ? $vendor->vendor_docs()->where('type', $this->audit_type)->get() : collect();
            foreach ($vendor_docs as $vendor_doc) {
                // Only include if any checks fall within the doc window
                $hasCovered = $group['checks']->whereBetween('date', [$vendor_doc->effective_date, $vendor_doc->expiration_date])->isNotEmpty();
                if ($hasCovered) {
                    $files->push(storage_path('files/vendor_docs/' . $vendor_doc->doc_filename));
                }
            }
        }
        return $files->unique()->values()->toArray();
    }

    public function download_documents()
    {
        // Gather source files to merge (must exist on disk)
        $sources = collect($this->vendor_docs)
            ->filter(fn ($p) => is_string($p) && file_exists($p))
            ->values();

        if ($sources->isEmpty()) {
            $this->addError('vendor_docs', 'No vendor documents found on disk to download.');
            return;
        }

        // Use a timestamped filename; we'll stream directly to the browser
        $filename = 'audit-'.auth()->user()->vendor->id.'-'.date('Y-m-d-H-i-s');

        try {
            $ilovepdf = new Ilovepdf(env('I_LOVE_PDF_PUBLIC'), env('I_LOVE_PDF_SECRET'));
            $task = $ilovepdf->newTask('merge');

            foreach ($sources as $file) {
                $task->addFile($file);
            }

            $task->setOutputFilename($filename);
            $task->execute();

            // Get merged PDF bytes in memory (no disk write)
            $content = $task->blob();
        } catch (\Throwable $e) {
            $this->addError('vendor_docs', 'Failed to build PDF: '.$e->getMessage());
            return;
        }

        if (!is_string($content) || $content === '') {
            $this->addError('vendor_docs', 'Merged PDF content is empty.');
            return;
        }

        // Stream the file to the browser as a download (no disk write)
        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, $filename.'.pdf', [
            'Content-Type' => 'application/pdf',
            'Content-Length' => strlen($content),
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    public function export_xlsx()
    {
        $filename = 'Audit-'.auth()->user()->vendor->id.'-'.date('Y-m-d-H-i-s').'.xlsx';

        return response()->streamDownload(function () use ($filename) {
            // Styles
            $underlineBorder = new Border(
                new BorderPart(Border::BOTTOM, Color::BLACK, Border::WIDTH_THICK, Border::STYLE_SOLID)
            );
            $vendorHeaderStyle = (new Style())->setBorder($underlineBorder)->setFontBold();

            // Coverage cell-only styles (provide RGB; OpenSpout will convert to ARGB internally)
            $coverageGreen = (new Style())->setFontColor(Color::GREEN);
            $coverageOrange = (new Style())->setFontColor('C05621'); // dark orange
            $coverageRed = (new Style())->setFontColor(Color::RED);

            // OpenSpout writer (per-cell styling capability)
            $writer = new XLSXWriter();
            $writer->openToFile('php://output');

            // Header row
            $writer->addRow(Row::fromValues([
                'Vendor', 'Date', 'Payment', 'Amount', 'Coverage',
            ]));

            foreach ($this->vendors_grouped_checks as $group) {
                $vendor = $group['vendor'];

                // Vendor header row (underline across all columns)
                $writer->addRow(Row::fromValues([
                    $vendor->business_name, null, null, null, null,
                ], $vendorHeaderStyle));

                // Optional subheading row (Retail or Professional policy)
                if ($vendor->business_type === 'Retail') {
                    $writer->addRow(Row::fromValues([
                        $vendor->business_name." is Retail and doesn't require coverage.", null, null, null, null,
                    ]));
                } elseif (isset($group['professional_doc'])) {
                    $doc = $group['professional_doc'];
                    $writer->addRow(Row::fromValues([
                        'Professional policy active ('
                            . $doc->effective_date->format('m/d/Y')
                            . '–' . $doc->expiration_date->format('m/d/Y') . ')',
                        null, null, null, null,
                    ]));
                }

                // Detail rows
                foreach ($group['checks'] as $check) {
                    $coverage = $check->covered
                        ? 'Covered'
                        : ((isset($group['professional_doc']) || $vendor->business_type === 'Retail') ? 'Not Applicable' : 'Not Covered');

                    // Build values array (Coverage will be styled per-cell)
                    $values = [
                        null,
                        $check->date->format('m/d/Y'),
                        $check->payment_type,
                        money($check->amount),
                        $coverage,
                    ];

                    // Coverage cell with per-cell color style (column index 4)
                    $coverageStyle = match ($coverage) {
                        'Covered' => $coverageGreen,
                        'Not Applicable' => $coverageOrange,
                        default => $coverageRed,
                    };
                    $writer->addRow(Row::fromValuesWithStyles($values, null, [4 => $coverageStyle]));
                }

                // Spacer row between vendors
                $writer->addRow(Row::fromValues(['', '', '', '', '']));
            }
            $writer->close();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    #[Title('Audit')]
    public function render(): View
    {
        return view('livewire.vendor-docs.audit-show');
    }
}
