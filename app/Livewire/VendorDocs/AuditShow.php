<?php

namespace App\Livewire\VendorDocs;

use App\Jobs\RunPendingEwccvLookups;
use App\Models\AuditRequest;
use App\Models\Check;
use App\Models\EwccvCoverageCheck;
use App\Services\Audits\AuditReport;
use App\Services\Audits\CertificateMerger;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Models\VendorDoc;
use App\Models\BankAccount;
use App\Services\PlaidService;
use App\Support\Ewccv\BridgedSession;
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

    /** The report behind this page: the same builder the emailed package uses. */
    #[Computed]
    public function report(): AuditReport
    {
        return new AuditReport(
            (int) auth()->user()->vendor->id,
            (string) $this->start_date,
            (string) $this->end_date,
            $this->audit_type ?: null,
            array_map('intval', $this->bank_account_ids),
            $this->audit_type === 'workers' && $this->end_date ? AuditRequest::coverageDateFor($this->end_date) : null,
        );
    }

    /**
     * The request this page stands for: created on first sight (a workers
     * comp audit then queues its EWCCV checks), so an audit opened by link
     * is tracked like one started from the form.
     */
    #[Computed]
    public function auditRequest(): ?AuditRequest
    {
        if (! $this->end_date || ! $this->start_date || empty($this->bank_account_ids)) {
            return null;
        }

        return AuditRequest::findOrStart((int) auth()->user()->vendor->id, auth()->user(), (string) $this->start_date, (string) $this->end_date, $this->audit_type ?: null, array_map('intval', $this->bank_account_ids));
    }

    /** Downloads wait until EWCCV has answered for every sub; the package is emailed then. */
    #[Computed]
    public function canDownload(): bool
    {
        $audit = $this->auditRequest;

        return $audit === null || $audit->isDownloadable();
    }

    #[Computed]
    public function vendors_grouped_checks(): Collection
    {
        if (! $this->end_date || empty($this->bank_account_ids)) {
            return collect();
        }

        return $this->report->groups();
    }

    /** The EWCCV coverage date this audit is checked against: the end date minus two days. */
    #[Computed]
    public function coverageDate(): ?\Illuminate\Support\Carbon
    {
        return $this->audit_type === 'workers' && $this->end_date ? AuditRequest::coverageDateFor($this->end_date) : null;
    }

    /**
     * EWCCV's coverage checks as of the coverage date, by vendor id. A
     * workers comp audit asks "was the sub covered two days before the
     * period ended?", which the tracking state (today's coverage) cannot say.
     *
     * @return Collection<int, EwccvCoverageCheck>
     */
    #[Computed]
    public function coverage_checks(): Collection
    {
        return $this->report->coverageChecks();
    }

    /**
     * @return array{covered: int, not_found: int, pending: int, unchecked: int, total: int}
     */
    #[Computed]
    public function coverage_check_counts(): array
    {
        return $this->report->coverageCounts();
    }

    /**
     * Ask EWCCV again for every sub still without an answer as of the
     * coverage date (a failed attempt is put back); the checks wait for a
     * browser session when none is at hand.
     */
    public function verify_coverage_on_ewccv(): void
    {
        $this->authorize('viewAny', VendorDoc::class);
        if ($this->audit_type !== 'workers' || ! $this->end_date) {
            return;
        }

        $own = (int) auth()->user()->vendor->id;
        $date = $this->coverageDate->toDateString();
        $queued = 0;
        foreach ($this->report->vendorsNeedingCoverage() as $vendorId) {
            if (EwccvCoverageCheck::request($vendorId, $own, $date)) {
                $queued++;
            }
        }
        unset($this->report, $this->coverage_checks, $this->coverage_check_counts, $this->vendors_grouped_checks, $this->auditRequest, $this->canDownload);

        $shown = $this->coverageDate->format('m/d/Y');
        if ($queued === 0) {
            $this->toast(text: "Every vendor in this audit already has an EWCCV answer for {$shown}, or is waiting for one.", heading: 'EWCCV', duration: 5000);

            return;
        }

        if (BridgedSession::canSearch()) {
            RunPendingEwccvLookups::dispatch();
            $this->toast(text: "Checking {$queued} vendor(s) on EWCCV as of {$shown} now; the package is emailed when every answer is in.", heading: 'EWCCV', duration: 6000);

            return;
        }

        $this->toast(text: "{$queued} vendor(s) queued for EWCCV as of {$shown}. They run when a browser session is handed over: open ewccv.com with the Hive extension.", heading: 'EWCCV', duration: 8000, variant: 'warning');
    }

    /** @return list<string> the certificate files the package merges */
    #[Computed]
    public function vendor_docs(): array
    {
        return $this->report->certificateFiles()['sources'];
    }

    /** A download before the EWCCV answers are in: say so, and that the package is emailed. */
    protected function refuseUntilReady(): bool
    {
        if ($this->canDownload) {
            return false;
        }
        $this->toast(
            text: 'EWCCV is still checking coverage for this audit; the package is emailed to you the moment every answer is in.',
            heading: 'Not ready yet',
            duration: 7000,
            variant: 'warning',
        );

        return true;
    }

    public function download_documents()
    {
        if ($this->refuseUntilReady()) {
            return;
        }

        $files = $this->report->certificateFiles();
        if ($files['missing'] !== []) {
            $list = collect($files['missing']);
            $this->toast(text: 'Some policy files were not found: '.$list->take(5)->implode(', ').($list->count() > 5 ? ' and '.($list->count() - 5).' more' : ''), heading: 'Missing files', duration: 7000, variant: 'danger');

            return;
        }
        if ($files['sources'] === []) {
            $this->toast(text: 'No policy documents found for the selected filters and date range.', heading: 'Nothing to download', duration: 5000, variant: 'warning');

            return;
        }

        $filename = $this->report->filenameBase();
        $content = app(CertificateMerger::class)->merge($files['sources'], $filename);

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
        if ($this->refuseUntilReady()) {
            return;
        }

        $filename = $this->report->filenameBase().'.xlsx';
        $content = $this->report->xlsx();

        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function download_bank_statements()
    {
        if ($this->refuseUntilReady()) {
            return;
        }

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

    /** The Coverage column of a policy row in the export: "WC verified 10/12/2026 (EWCCV)" and the like. */
    public static function verificationCell(VendorDoc $doc): ?string
    {
        return AuditReport::verificationCell($doc);
    }

    #[Title('Audit')]
    public function render(): View
    {
        $this->authorize('viewAny', VendorDoc::class);
        return view('livewire.vendor-docs.audit-show');
    }
}
