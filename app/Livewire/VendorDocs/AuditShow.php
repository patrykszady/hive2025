<?php

namespace App\Livewire\VendorDocs;

use App\Models\Check;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Models\VendorDoc;
use Carbon\Carbon;
use Ilovepdf\Ilovepdf;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Illuminate\Support\Str;
use Flux\Concerns\InteractsWithComponents;
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
    use InteractsWithComponents;
    #[Url(except: '')]
    public ?string $end_date = null;

    #[Url(except: '')]
    public ?string $start_date = null;

    #[Url(except: '')]
    public ?string $audit_type = null;

    #[Url(except: '')]
    public array $bank_account_ids = [];

    public ?string $view = null;


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
        $start = $this->start_date;
        $end = $this->end_date;

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

        $start = $this->start_date;
        $end = $this->end_date;

        $authVendorId = auth()->user()->vendor->id;

        // Checks written to the authenticated vendor (own vendor) — will be shown first
        $ownVendorChecks = Check::whereBetween('date', [$start, $end])
            ->whereIn('bank_account_id', $this->bank_account_ids)
            ->where('vendor_id', $authVendorId)
            ->whereNull('user_id')
            ->where('check_type', '!=', 'Cash')
            ->with('vendor')
            ->orderBy('date')
            ->get();

        // Vendor-issued checks (existing behavior)
        $vendorChecksByVendor = Check::whereBetween('date', [$start, $end])
            ->whereIn('bank_account_id', $this->bank_account_ids)
            ->whereNotNull('vendor_id')
            ->whereNull('user_id')
            ->where('vendor_id', '!=', $authVendorId)
            ->where('check_type', '!=', 'Cash')
            ->with('vendor')
            ->orderBy('date')
            ->get()
            ->groupBy('vendor_id');

        // User-owned checks where the user is employed by the authenticated vendor
        $employeeChecksByUser = Check::whereBetween('date', [$start, $end])
            ->whereIn('bank_account_id', $this->bank_account_ids)
            ->whereNotNull('user_id')
            ->where('check_type', '!=', 'Cash')
            // ->whereHas('user', function ($q) use ($authVendorId) {
            //     $q->where('vendor_id', $authVendorId);
            // })
            ->with('user')
            ->orderBy('date')
            ->get()
            ->groupBy('user_id');

        // Promote certain employees to vendor-like groups:
        // If employee has role Member for this vendor AND via_vendor_id on the user_vendor pivot, treat as vendor, not employee.
        $viaVendorEmployees = $employeeChecksByUser->filter(function ($checks) use ($authVendorId) {
            $user = $checks->first()->user;
            if (! $user) return false;
            $role = method_exists($user, 'getRoleForVendor') ? $user->getRoleForVendor($authVendorId) : null;
            $viaVendorId = null;
            if (method_exists($user, 'vendors')) {
                try {
                    $pivot = $user->vendors()->where('vendors.id', $authVendorId)->first()?->pivot;
                    $viaVendorId = $pivot->via_vendor_id ?? null;
                } catch (\Throwable $e) {
                    $viaVendorId = null;
                }
            }
            return $role === 'Member' && !is_null($viaVendorId);
        });

        $regularEmployeeChecksByUser = $employeeChecksByUser->reject(function ($checks, $userId) use ($viaVendorEmployees) {
            return $viaVendorEmployees->has($userId);
        });

        // Normalize to unified group structures
        $groupsFromVendors = $vendorChecksByVendor->map(function ($checks) {
            return [
                'vendor' => $checks->first()->vendor, // App\Models\Vendor
                'checks' => $checks->values(),
            ];
        });

        $groupsFromEmployees = $regularEmployeeChecksByUser->map(function ($checks) {
            $user = $checks->first()->user;

            // Create a lightweight pseudo-vendor object for sorting/rendering
            $pseudoVendor = new class($user) {
                public string $business_name;
                public string $business_type = 'Employee';
                public function __construct($user)
                {
                    $this->business_name = $user?->full_name;
                }
            };

            return [
                'vendor' => $pseudoVendor,
                'checks' => $checks->values(),
                'is_user' => true,
                'user' => $user,
            ];
        });

        // Treat via-vendor members as vendors (non-employee group) grouped under the actual via_vendor Vendor
        $viaVendorBuckets = [];
        foreach ($viaVendorEmployees as $checks) {
            $user = $checks->first()->user;
            $viaVendorId = null;
            try {
                $pivot = $user?->vendors()->where('vendors.id', $authVendorId)->first()?->pivot;
                $viaVendorId = $pivot->via_vendor_id ?? null;
            } catch (\Throwable $e) {
                $viaVendorId = null;
            }
            if (!$viaVendorId) {
                continue;
            }
            if (!array_key_exists($viaVendorId, $viaVendorBuckets)) {
                $viaVendorBuckets[$viaVendorId] = collect();
            }
            $viaVendorBuckets[$viaVendorId] = $viaVendorBuckets[$viaVendorId]->merge($checks);
        }

        $groupsFromViaMembers = collect($viaVendorBuckets)->map(function ($checks, $viaVendorId) {
            $vendor = Vendor::find($viaVendorId);
            if (!$vendor) {
                // Fallback: pseudo vendor using first user's name if vendor not found
                $firstUser = $checks->first()?->user;
                $vendor = new class($firstUser) {
                    public string $business_name;
                    public ?string $business_type = 'Vendor';
                    public function __construct($user)
                    {
                        $this->business_name = $user?->full_name ?? 'Unknown';
                    }
                };
            }
            return [
                'vendor' => $vendor,
                'checks' => $checks->values(),
                'via_vendor' => true,
            ];
        });

        // Prepare the own vendor group (if any)
        $ownVendorGroup = null;
        if ($ownVendorChecks->isNotEmpty()) {
            $ownVendorGroup = [
                'vendor' => $ownVendorChecks->first()->vendor ?? auth()->user()->vendor,
                'checks' => $ownVendorChecks->values(),
            ];
        }

        // Merge and sort other groups with employees last, then by name
        $otherGroups = $groupsFromVendors
            ->merge($groupsFromViaMembers)
            ->merge($groupsFromEmployees)
            ->sort(function ($a, $b) {
                $aIsUser = (bool)($a['is_user'] ?? false);
                $bIsUser = (bool)($b['is_user'] ?? false);
                if ($aIsUser !== $bIsUser) {
                    // Non-employee groups first
                    return $aIsUser <=> $bIsUser;
                }
                $aName = strtolower($a['vendor']->business_name ?? '');
                $bName = strtolower($b['vendor']->business_name ?? '');
                return $aName <=> $bName;
            })
            ->values();

        // Ensure own vendor group is first
        $mergedGroups = collect();
        if ($ownVendorGroup) {
            $mergedGroups->push($ownVendorGroup);
        }
        $mergedGroups = $mergedGroups->merge($otherGroups);

    $auditEnd = $end;

    $startDateStr = $start; // reuse start/end for queries below
    $endDateStr = $end;

    return $mergedGroups->map(function ($group) use ($auditEnd, $startDateStr, $endDateStr) {
            $vendor = $group['vendor'];

            // Only real vendors have docs; pseudo vendors (employees) skip this block
            if ($vendor instanceof Vendor) {
                // Attach active Professional policy (if any)
                $professional = $vendor->vendor_docs()
                    ->where('type', 'professional')
                    ->orderByDesc('effective_date')
                    ->first();
                if ($professional) {
                    $effective = $professional->effective_date;
                    $expires = $professional->expiration_date;
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

                    // Collect policies applicable to this audit window
                    $applicable = $vendor->vendor_docs()
                        ->where('type', $this->audit_type)
                        ->whereDate('effective_date', '<=', $endDateStr)
                        ->whereDate('expiration_date', '>=', $startDateStr)
                        ->with('agent')
                        ->orderByDesc('expiration_date')
                        ->get();
                    if ($applicable->isNotEmpty()) {
                        $group['applicable_docs'] = $applicable->values();
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
            $vendor_docs = ($this->audit_type && $vendor instanceof Vendor)
                ? $vendor->vendor_docs()->where('type', $this->audit_type)->get()
                : collect();
            foreach ($vendor_docs as $vendor_doc) {
                // Only include if any checks fall within the doc window
                $hasCovered = $group['checks']->whereBetween('date', [$vendor_doc->effective_date, $vendor_doc->expiration_date])->isNotEmpty();
                if ($hasCovered) {
                    // Resolve path via the configured storage disk to avoid path drift
                    $path = \Storage::disk('files')->path('vendor_docs/'.($vendor_doc->doc_filename));
                    if (file_exists($path)) {
                        $files->push($path);
                    }
                }
            }
        }
        return $files->unique()->values()->toArray();
    }

    public function download_documents()
    {
        // Build expected file list and collect any missing files to notify via Flux toast
        $expected = collect();
        $missing = collect();

        foreach ($this->vendors_grouped_checks as $group) {
            $vendor = $group['vendor'];
            if (!($vendor instanceof Vendor) || !$this->audit_type) {
                continue;
            }

            $vendor_docs = $vendor->vendor_docs()->where('type', $this->audit_type)->get();
            foreach ($vendor_docs as $vendor_doc) {
                $hasCovered = $group['checks']
                    ->whereBetween('date', [$vendor_doc->effective_date, $vendor_doc->expiration_date])
                    ->isNotEmpty();

                if (!$hasCovered) {
                    continue;
                }

                $path = \Storage::disk('files')->path('vendor_docs/' . $vendor_doc->doc_filename);
                if (file_exists($path)) {
                    $expected->push($path);
                } else {
                    $missing->push($vendor_doc->doc_filename);
                }
            }
        }

        $sources = $expected->unique()->values();

        // If any expected files are missing, show an error toast and stop
        if ($missing->isNotEmpty()) {
            $list = $missing->unique()->values();
            // Keep the toast concise if many files are missing
            $preview = $list->take(5)->implode(', ');
            $more = $list->count() > 5 ? ' and ' . ($list->count() - 5) . ' more' : '';
            $this->toast(
                text: 'Some policy files were not found: ' . $preview . $more,
                heading: 'Missing files',
                duration: 7000,
                variant: 'danger',
            );
            return;
        }

        // If none remain, warn and stop
        if ($sources->isEmpty()) {
            $this->toast(
                text: 'No policy documents found for the selected filters and date range.',
                heading: 'Nothing to download',
                duration: 5000,
                variant: 'warning',
            );
            return;
        }

    // Build filename with vendor name and audit date range
        $currentVendor = auth()->user()->vendor;
        $vendorNameSlug = Str::slug($currentVendor->name);
        $endStr = $this->end_date;
        $startStr = $this->start_date;
        $filename = 'Audit-'.$currentVendor->id.'-'.$vendorNameSlug.'-'.$startStr.'-to-'.$endStr;

        $ilovepdf = new Ilovepdf(env('I_LOVE_PDF_PUBLIC'), env('I_LOVE_PDF_SECRET'));
        $task = $ilovepdf->newTask('merge');

    foreach ($sources as $file) {
            $task->addFile($file);
        }

        $task->setOutputFilename($filename);
        $task->execute();

        // Get merged PDF bytes in memory (no disk write)
        $content = $task->blob();

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
        $currentVendor = auth()->user()->vendor;
        $vendorNameSlug = Str::slug($currentVendor->name);
        $endStr = $this->end_date;
        $startStr = $this->start_date;
        $filename = 'Audit-'.$currentVendor->id.'-'.$vendorNameSlug.'-'.$startStr.'-to-'.$endStr.'.xlsx';

        return response()->streamDownload(function () use ($filename) {
            $authVendorId = auth()->user()->vendor->id;
            // Styles
            $underlineBorder = new Border(
                new BorderPart(Border::BOTTOM, Color::BLACK, Border::WIDTH_THICK, Border::STYLE_SOLID)
            );
            $vendorHeaderStyle = (new Style())->setBorder($underlineBorder)->setFontBold();
            $boldStyle = (new Style())->setFontBold();

            // Coverage cell-only styles
            $coverageGreen = (new Style())->setFontColor(Color::GREEN);
            $coverageOrange = (new Style())->setFontColor('C05621'); // dark orange
            $coverageRed = (new Style())->setFontColor(Color::RED);

            // OpenSpout writer
            $writer = new XLSXWriter();
            $writer->openToFile('php://output');

            // Name the first sheet
            $writer->getCurrentSheet()->setName('By Vendor');

            // Sheet 1 header
            $writer->addRow(Row::fromValues([
                'Vendor', 'Date', 'Payment', 'Amount', 'Coverage',
            ]));

            foreach ($this->vendors_grouped_checks as $group) {
                $vendor = $group['vendor'];

                // Vendor header row (underline across all columns)
                $writer->addRow(Row::fromValues([
                    $vendor->business_name, null, null, null, null,
                ], $vendorHeaderStyle));

                // Optional subheading row (employees, Retail, or professional policy)
                if (($group['is_user'] ?? false) === true) {
                    $role = $group['user']?->getRoleForVendor($authVendorId) ?? 'No Role';
                    if ($role === 'Admin') {
                        $writer->addRow(Row::fromValues([
                            $vendor->business_name . ' is a stakeholder in the business and is excluded.', null, null, null, null,
                        ]));
                    } else {
                        $writer->addRow(Row::fromValues([
                            $vendor->business_name . ' is an employee payment subject to audit review.', null, null, null, null,
                        ]));
                    }
                } elseif ($vendor->business_type === 'Retail') {
                    $writer->addRow(Row::fromValues([
                        $vendor->business_name." is Retail and doesn't require coverage.", null, null, null, null,
                    ]));
                } elseif (isset($group['professional_doc'])) {
                    $doc = $group['professional_doc'];
                    $writer->addRow(Row::fromValues([
                        'Professional policy active (' . $doc->effective_date->format('m/d/Y') . '–' . $doc->expiration_date->format('m/d/Y') . ')',
                        null, null, null, null,
                    ]));
                }

                // Applicable policies block (if any)
                if (isset($group['applicable_docs']) && ($group['applicable_docs']?->count() ?? 0) > 0) {
                    // $writer->addRow(Row::fromValues(['Applicable Policies', null, null, null, null], $boldStyle));
                    foreach ($group['applicable_docs'] as $doc) {
                        $writer->addRow(Row::fromValues([
                            $doc->number . ' (' . $doc->effective_date->format('m/d/Y') . '–' . $doc->expiration_date->format('m/d/Y') . ')',
                            null, null, null, null,
                        ]));
                    }
                    $writer->addRow(Row::fromValues(['', '', '', '', '']));
                }

                // Detail rows
                foreach ($group['checks'] as $check) {
                    $isUser = (bool)($group['is_user'] ?? false);
                    if ($isUser) {
                        $role = $group['user']?->getRoleForVendor($authVendorId) ?? 'No Role';
                        $coverage = ($role === 'Admin') ? 'Not Applicable' : 'Not Covered';
                    } else {
                        $coverage = $check->covered
                            ? 'Covered'
                            : ((isset($group['professional_doc']) || $vendor->business_type === 'Retail' || !empty($vendor->category_id)) ? 'Not Applicable' : 'Not Covered');
                    }

                    $values = [
                        null,
                        $check->date->format('m/d/Y'),
                        $check->payment_label . ' ' . $check->check_number,
                        money($check->amount),
                        $coverage,
                    ];

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

            // =========================
            // Sheet 2: All Checks (desc)
            // =========================
            $checks = [];
            foreach ($this->vendors_grouped_checks as $group) {
                $vendor = $group['vendor'];
                $doc = $group['professional_doc'] ?? null;
                $isUser = (bool)($group['is_user'] ?? false);

                foreach ($group['checks'] as $check) {
                    if ($isUser) {
                        $role = $group['user']?->getRoleForVendor($authVendorId) ?? 'No Role';
                        $coverage = ($role === 'Admin') ? 'Not Applicable' : 'Not Covered';
                    } else {
                        $coverage = $check->covered
                            ? 'Covered'
                            : (($doc || $vendor->business_type === 'Retail' || !empty($vendor->category_id)) ? 'Not Applicable' : 'Not Covered');
                    }

                    $checks[] = [
                        'ts' => $check->date instanceof \Carbon\Carbon ? $check->date->getTimestamp() : strtotime((string) $check->date),
                        'type' => (string) $check->check_type,
                        'number' => (string) $check->check_number,
                        'date' => $check->date->format('m/d/Y'),
                        'vendor' => $vendor->business_name,
                        'payment' => $check->payment_label . ' ' . $check->check_number,
                        'amount' => money($check->amount),
                        'coverage' => $coverage,
                    ];
                }
            }

            // Sort by check_type (asc), then check_number (asc, natural)
            usort($checks, function ($a, $b) {
                $typeCmp = strcasecmp($a['type'] ?? '', $b['type'] ?? '');
                if ($typeCmp !== 0) {
                    return $typeCmp;
                }
                return strnatcasecmp((string)($a['number'] ?? ''), (string)($b['number'] ?? ''));
            });

            // Create and name the second sheet
            $writer->addNewSheetAndMakeItCurrent()->setName('All Checks');

            // Sheet 2 header
            $writer->addRow(Row::fromValues([
                'Date', 'Vendor', 'Payment', 'Amount', 'Coverage',
            ]));

            // Rows (coverage colored per cell)
            foreach ($checks as $row) {
                $coverageStyle = match ($row['coverage']) {
                    'Covered' => $coverageGreen,
                    'Not Applicable' => $coverageOrange,
                    default => $coverageRed,
                };

                $writer->addRow(Row::fromValuesWithStyles(
                    [$row['date'], $row['vendor'], $row['payment'], $row['amount'], $row['coverage']],
                    null,
                    [4 => $coverageStyle]
                ));
            }

            $writer->close();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    #[Title('Audit')]
    public function render(): View
    {
        $this->authorize('viewAny', VendorDoc::class);
        return view('livewire.vendor-docs.audit-show');
    }
}
