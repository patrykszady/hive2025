<?php

namespace App\Livewire\VendorDocs;

use App\Models\Check;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Models\VendorDoc;
use App\Models\BankAccount;
use App\Services\PlaidService;
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
            // user.vendors carries the pivot both the role check and the
            // via-vendor lookup below need — otherwise each employee costs
            // 2-3 pivot queries per render.
            ->with('user.vendors')
            ->orderBy('date')
            ->get()
            ->groupBy('user_id');

        // Promote certain employees to vendor-like groups:
        // If employee has role Member for this vendor AND via_vendor_id on the user_vendor pivot, treat as vendor, not employee.
        $viaVendorEmployees = $employeeChecksByUser->filter(function ($checks) use ($authVendorId) {
            $user = $checks->first()->user;
            if (! $user) return false;
            $role = method_exists($user, 'getRoleForVendor') ? $user->getRoleForVendor($authVendorId) : null;
            $viaVendorId = $user->vendors->firstWhere('id', $authVendorId)?->pivot?->via_vendor_id;
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
            $viaVendorId = $user?->vendors->firstWhere('id', $authVendorId)?->pivot?->via_vendor_id;
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

    // All docs for all groups in ONE query — this used to be 3 queries per
    // vendor group (professional, audit-type, applicable).
    $groupVendorIds = $mergedGroups
        ->map(fn ($group) => $group['vendor'] instanceof Vendor ? $group['vendor']->id : null)
        ->filter()
        ->unique()
        ->values();

    $docsByVendor = $groupVendorIds->isNotEmpty()
        ? \App\Models\VendorDoc::whereIn('vendor_id', $groupVendorIds)
            ->whereIn('type', array_values(array_filter(['professional', $this->audit_type])))
            ->with('agent')
            ->get()
            ->groupBy('vendor_id')
        : collect();

    return $mergedGroups->map(function ($group) use ($auditEnd, $startDateStr, $endDateStr, $docsByVendor) {
            $vendor = $group['vendor'];

            // Only real vendors have docs; pseudo vendors (employees) skip this block
            if ($vendor instanceof Vendor) {
                $vendorDocs = collect($docsByVendor->get($vendor->id) ?? []);

                // Attach active Professional policy (if any)
                $professional = $vendorDocs
                    ->where('type', 'professional')
                    ->sortByDesc('effective_date')
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
                    $vendor_docs = $vendorDocs->where('type', $this->audit_type);
                    foreach ($vendor_docs as $vendor_doc) {
                        $doc_checks = $group['checks']->whereBetween('date', [$vendor_doc->effective_date, $vendor_doc->expiration_date]);
                        foreach ($doc_checks as $vendor_check) {
                            $vendor_check->covered = true;
                        }
                    }

                    // Collect policies applicable to this audit window
                    $applicable = $vendorDocs
                        ->where('type', $this->audit_type)
                        ->filter(fn ($doc) => $doc->effective_date?->format('Y-m-d') <= $endDateStr
                            && $doc->expiration_date?->format('Y-m-d') >= $startDateStr)
                        ->sortByDesc('expiration_date');
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

    public function download_bank_statements()
    {
        if (empty($this->bank_account_ids)) {
            $this->toast('Please select bank accounts first.', 'error');
            return;
        }

        try {
            $plaidService = app(PlaidService::class);
            $currentVendor = auth()->user()->vendor;
            $vendorNameSlug = Str::slug($currentVendor->name);
            
            // Get bank accounts with their banks, only where the bank has a plaid_access_token
            $bankAccounts = BankAccount::whereIn('id', $this->bank_account_ids)
                ->with('bank')
                ->whereHas('bank', function($query) {
                    $query->whereNotNull('plaid_access_token');
                })
                ->get()
                ->groupBy('bank_id');

            // Track banks without Plaid connection (but don't log or show errors)
            $allBankAccounts = BankAccount::whereIn('id', $this->bank_account_ids)
                ->with('bank')
                ->get();
            
            $skippedBanks = $allBankAccounts->filter(function($account) {
                return !$account->bank || !$account->bank->plaid_access_token;
            });

            $allStatements = collect();
            $errors = collect();

            foreach ($bankAccounts as $bankId => $accounts) {
                $bank = $accounts->first()->bank;

                // Get statements for this bank
                $statementsResponse = $plaidService->getStatements(
                    $bank->plaid_access_token,
                    null,
                    ['bank_id' => $bank->id],
                );
                
                if (isset($statementsResponse['error'])) {
                    $errorMessage = $statementsResponse['error_message'] ?? 'Unknown error';
                    $errorBody = $statementsResponse['error_body'] ?? null;
                    
                    // More specific error handling
                    if ($errorBody && isset($errorBody['error_code'])) {
                        switch ($errorBody['error_code']) {
                            case 'INVALID_ACCESS_TOKEN':
                                $errorMessage = "Bank connection expired - please reconnect '{$bank->name}'";
                                break;
                            case 'ITEM_NOT_SUPPORTED':
                                $errorMessage = "Bank statements not supported for '{$bank->name}'";
                                break;
                            case 'PRODUCT_NOT_READY':
                                $errorMessage = "Bank statements not yet available for '{$bank->name}' - try again later";
                                break;
                            case 'PRODUCT_NOT_ENABLED':
                                $errorMessage = "Bank statements not enabled for '{$bank->name}' - please reconnect with statements access";
                                break;
                            default:
                                $defaultMessage = $errorBody['error_message'] ?? $errorMessage;
                                $errorMessage = "Error getting statements for '{$bank->name}': {$defaultMessage}";
                        }
                    } else if (str_contains($errorMessage, "'statements' product is not enabled")) {
                        $errorMessage = "Bank statements not enabled for '{$bank->name}' - please reconnect with statements access";
                    }
                    
                    $errors->push($errorMessage);
                    
                    // Log detailed error for debugging
                    \Log::channel('plaid_statements')->error('Plaid statements API error', [
                        'bank_id' => $bank->id,
                        'bank_name' => $bank->name,
                        'bank_account_ids' => $accounts->pluck('id')->toArray(),
                        'account_numbers' => $accounts->pluck('account_number')->toArray(),
                        'vendor_id' => auth()->user()->vendor->id,
                        'date_range' => [$this->start_date, $this->end_date],
                        'error_response' => $statementsResponse,
                        'user_friendly_message' => $errorMessage,
                    ]);
                    
                    continue;
                }

                // Filter statements within our date range and for our accounts
                if (isset($statementsResponse['accounts'])) {
                    $statementsFound = 0;
                    foreach ($statementsResponse['accounts'] as $account) {
                        // Check if this account is in our selected bank accounts
                        $bankAccount = $accounts->firstWhere('plaid_account_id', $account['account_id']);
                        if (!$bankAccount) continue;

                        if (isset($account['statements'])) {
                            foreach ($account['statements'] as $statement) {
                                $statementDate = Carbon::parse($statement['end_date']);
                                $startDate = Carbon::parse($this->start_date);
                                $endDate = Carbon::parse($this->end_date);

                                // Include statements that overlap with our audit period
                                if ($statementDate->between($startDate, $endDate) || 
                                    Carbon::parse($statement['start_date'])->between($startDate, $endDate)) {
                                    
                                    $allStatements->push([
                                        'statement_id' => $statement['statement_id'],
                                        'bank_name' => $bank->name,
                                        'bank_id' => $bank->id,
                                        'account_name' => $bankAccount->account_number,
                                        'statement_date' => $statement['end_date'],
                                        'access_token' => $bank->plaid_access_token,
                                    ]);
                                    $statementsFound++;
                                }
                            }
                        }
                    }
                    
                    // Log successful statement retrieval
                    \Log::channel('plaid_statements')->info('Statements successfully retrieved from bank', [
                        'bank_id' => $bank->id,
                        'bank_name' => $bank->name,
                        'bank_account_ids' => $accounts->pluck('id')->toArray(),
                        'statements_found' => $statementsFound,
                        'vendor_id' => auth()->user()->vendor->id,
                        'date_range' => [$this->start_date, $this->end_date],
                    ]);
                }
            }

            if ($allStatements->isEmpty()) {
                // Only show actual API errors, skip banks not connected to Plaid
                $connectionErrors = collect();
                $configurationErrors = collect();
                $otherErrors = collect();
                
                foreach ($errors as $error) {
                    if (str_contains($error, 'not enabled') || str_contains($error, 'reconnect with statements access')) {
                        $configurationErrors->push($error);
                    } elseif (str_contains($error, 'expired') || str_contains($error, 'reconnect')) {
                        $connectionErrors->push($error);
                    } else {
                        $otherErrors->push($error);
                    }
                }
                
                $messages = collect()
                    ->merge($connectionErrors)
                    ->merge($configurationErrors)
                    ->merge($otherErrors);
                
                if ($messages->isNotEmpty()) {
                    $errorMessage = $messages->count() === 1 
                        ? $messages->first()
                        : "Multiple issues found:\n• " . $messages->implode("\n• ");
                    
                    $this->toast(
                        text: $errorMessage,
                        heading: 'Cannot Download Statements',
                        duration: 10000,
                        variant: 'danger'
                    );
                } else {
                    $this->toast(
                        text: 'No bank statements found for the selected date range and accounts.',
                        heading: 'No Statements Available',
                        duration: 5000,
                        variant: 'warning'
                    );
                }
                return;
            }

            // If we have statements to download, create a ZIP file
            $zipContent = $this->createStatementsZip($allStatements, $plaidService);
            
            if ($zipContent === null) {
                $this->toast('Failed to create statements archive.', 'error');
                return;
            }

            $filename = 'Bank-Statements-' . $currentVendor->id . '-' . $vendorNameSlug . '-' . $this->start_date . '-to-' . $this->end_date . '.zip';

            // Log successful download
            \Log::channel('plaid_statements')->info('Bank statements download successful', [
                'vendor_id' => $currentVendor->id,
                'total_statements' => $allStatements->count(),
                'banks_processed' => $allStatements->pluck('bank_name')->unique()->values()->toArray(),
                'filename' => $filename,
                'date_range' => [$this->start_date, $this->end_date],
            ]);

            return response()->streamDownload(function () use ($zipContent) {
                echo $zipContent;
            }, $filename, [
                'Content-Type' => 'application/zip',
            ]);

        } catch (\Exception $e) {
            \Log::channel('plaid_statements')->error('Exception during bank statements download', [
                'vendor_id' => auth()->user()->vendor->id,
                'bank_account_ids' => $this->bank_account_ids,
                'date_range' => [$this->start_date, $this->end_date],
                'exception' => [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ],
            ]);
            
            $this->toast('An error occurred while downloading statements. Please try again.', 'error');
        }
    }

    private function createStatementsZip($statements, PlaidService $plaidService): ?string
    {
        $zip = new \ZipArchive();
        $tempZipPath = tempnam(sys_get_temp_dir(), 'bank_statements_');
        
        if ($zip->open($tempZipPath, \ZipArchive::CREATE) !== TRUE) {
            return null;
        }

        foreach ($statements as $statement) {
            try {
                $pdfContent = $plaidService->downloadStatement(
                    $statement['access_token'], 
                    $statement['statement_id'],
                    ['bank_id' => $statement['bank_id'] ?? null],
                );

                if (is_array($pdfContent) && isset($pdfContent['error'])) {
                    \Log::channel('plaid_statements')->warning('Failed to download individual statement', [
                        'statement_id' => $statement['statement_id'],
                        'bank_name' => $statement['bank_name'],
                        'account_name' => $statement['account_name'],
                        'statement_date' => $statement['statement_date'],
                        'error' => $pdfContent,
                        'vendor_id' => auth()->user()->vendor->id,
                    ]);
                    continue;
                }

                $filename = sprintf(
                    '%s_%s_%s.pdf',
                    Str::slug($statement['bank_name']),
                    $statement['account_name'],
                    $statement['statement_date']
                );

                $zip->addFromString($filename, $pdfContent);
                
            } catch (\Exception $e) {
                \Log::channel('plaid_statements')->error('Error processing individual statement for ZIP', [
                    'statement_id' => $statement['statement_id'],
                    'bank_name' => $statement['bank_name'],
                    'account_name' => $statement['account_name'],
                    'statement_date' => $statement['statement_date'],
                    'vendor_id' => auth()->user()->vendor->id,
                    'exception' => [
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ],
                ]);
            }
        }

        $zip->close();
        
        $content = file_get_contents($tempZipPath);
        unlink($tempZipPath);
        
        return $content;
    }

    #[Title('Audit')]
    public function render(): View
    {
        $this->authorize('viewAny', VendorDoc::class);
        return view('livewire.vendor-docs.audit-show');
    }
}
