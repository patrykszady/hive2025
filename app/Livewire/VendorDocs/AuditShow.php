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
use Livewire\Attributes\On;
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

    /**
     * Months the audit needs that the bank has not given Plaid, per bank,
     * found by the last statements download: list<array{bank_id: int,
     * bank_name: string, from: string, to: string, label: string,
     * requested_at: ?string, result: ?string}>.
     */
    public array $statementGaps = [];


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

    /**
     * An expense was just created from one of the Missing Expenses rows (the
     * expense form announces the transaction it used): read the list again,
     * so the row leaves the table without a page reload.
     */
    #[On('transaction-used')]
    public function transactionUsed(int $transactionId): void
    {
        unset($this->transactions_no_check);
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
     * The policies EWCCV did not find, with what it was asked and what it
     * listed instead — the ones to question before the auditor does.
     *
     * @return list<array{vendor: string, vendor_id: int, number: string, term: string, as_of: string, reason: string, searched: list<string>, candidates: list<string>}>
     */
    #[Computed]
    public function not_found_policies(): array
    {
        $rows = [];
        foreach ($this->vendors_grouped_checks as $group) {
            if (! ($group['vendor'] instanceof Vendor)) {
                continue;
            }
            foreach ($group['applicable_docs'] ?? [] as $doc) {
                $check = $doc->audit_check ?? null;
                if (! ($check instanceof EwccvCoverageCheck) || $check->status !== EwccvCoverageCheck::STATUS_NOT_FOUND) {
                    continue;
                }
                $rows[] = [
                    'vendor' => (string) $group['vendor']->business_name,
                    'vendor_id' => (int) $group['vendor']->id,
                    'number' => (string) $doc->number,
                    'term' => $doc->effective_date->format('m/d/Y').' – '.$doc->expiration_date->format('m/d/Y'),
                    'as_of' => $check->coverage_date->format('m/d/Y'),
                    'reason' => str_replace('_', ' ', (string) ($check->reason ?: 'no results')),
                    'searched' => collect((array) $check->searched)
                        ->map(fn ($s) => is_array($s) && filled($s['term'] ?? null) ? sprintf('%s "%s" → %s', $s['type'] ?? 'name', $s['term'], filled($s['reason'] ?? null) ? str_replace('_', ' ', (string) $s['reason']) : (($s['count'] ?? 0).' result(s)')) : null)
                        ->filter()->values()->all(),
                    'candidates' => collect((array) $check->candidates)
                        ->map(fn ($c) => is_array($c) && filled($c['employerName'] ?? null) ? $c['employerName'].(filled($c['policyNumber'] ?? null) ? ' (policy '.$c['policyNumber'].')' : '') : null)
                        ->filter()->values()->all(),
                ];
            }
        }

        return $rows;
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

        $queued = $this->auditRequest?->queueChecks() ?? 0;
        unset($this->report, $this->coverage_checks, $this->coverage_check_counts, $this->vendors_grouped_checks, $this->auditRequest, $this->canDownload);

        $shown = $this->coverageDate->format('m/d/Y');
        if ($queued === 0) {
            $this->toast(text: "Every vendor in this audit already has an EWCCV answer for {$shown}, or is queued for one.", heading: 'EWCCV', duration: 5000);

            return;
        }

        if (BridgedSession::canSearch()) {
            RunPendingEwccvLookups::dispatch();
            $this->toast(text: "Checking {$queued} vendor(s) on EWCCV as of {$shown} now.", heading: 'EWCCV', duration: 6000);

            return;
        }

        $this->toast(text: "{$queued} vendor(s) queued for EWCCV as of {$shown}; the audit package does not wait for them.", heading: 'EWCCV', duration: 6000);
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
            text: 'The package is being built; it is emailed to you in a few minutes.',
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

        // The package built for the email is the download.
        $stored = $this->auditRequest?->pdf_path;
        if ($stored && \Illuminate\Support\Facades\Storage::disk('local')->exists($stored)) {
            return \Illuminate\Support\Facades\Storage::disk('local')->download($stored, basename($stored));
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

        $storedXlsx = $this->auditRequest?->xlsx_path;
        if ($storedXlsx && \Illuminate\Support\Facades\Storage::disk('local')->exists($storedXlsx)) {
            return \Illuminate\Support\Facades\Storage::disk('local')->download($storedXlsx, basename($storedXlsx));
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

            ['statements' => $allStatements, 'banks' => $banks, 'errors' => $errors] = $this->listStatements($plaidService);

            // Every statement the audit needs: months Plaid has not got are
            // asked of the bank right away, each range only once (2026-10-16).
            $requested = $this->requestMissingStatements($banks, $plaidService);
            $this->statementGaps = $this->statementGapsFor($this->withSavedRefreshes($banks));

            if ($allStatements->isEmpty()) {
                if ($this->statementGaps !== []) {
                    $this->toast(
                        text: $requested !== []
                            ? 'Plaid had none of these statements yet, so they were requested from the bank ('.implode(', ', $requested).'). They usually arrive within minutes; download again then.'
                            : 'Plaid has no statements for this period yet; see the note at the top.',
                        heading: 'Statements on the way',
                        duration: 10000,
                        variant: 'warning',
                    );

                    return;
                }

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

            $pdf = $this->mergeStatements($allStatements, $plaidService);

            if ($pdf === null) {
                $this->toast('Failed to build the statements PDF.', 'error');
                return;
            }

            // An incomplete year says so in its name, so it is not sent on as the full set.
            $filename = 'Bank-Statements-' . $currentVendor->id . '-' . $vendorNameSlug . '-' . $this->start_date . '-to-' . $this->end_date . ($this->statementGaps !== [] ? '-partial' : '') . '.pdf';

            \Log::channel('plaid_statements')->info('Bank statements download successful', [
                'vendor_id' => $currentVendor->id,
                'total_statements' => $allStatements->count(),
                'banks_processed' => $allStatements->pluck('bank_name')->unique()->values()->toArray(),
                'missing' => collect($this->statementGaps)->map(fn (array $gap) => $gap['bank_name'].' '.$gap['label'])->all(),
                'filename' => $filename,
                'date_range' => [$this->start_date, $this->end_date],
            ]);

            if ($this->statementGaps !== []) {
                $this->toast(
                    text: 'Missing '.collect($this->statementGaps)->map(fn (array $gap) => $gap['label'])->implode(', ').($requested !== []
                        ? '; requested from the bank just now. They usually arrive within minutes; download again then.'
                        : '; see the note at the top.'),
                    heading: 'Partial statements',
                    duration: 10000,
                    variant: 'warning',
                );
            }

            return response()->streamDownload(function () use ($pdf) {
                echo $pdf;
            }, $filename, [
                'Content-Type' => 'application/pdf',
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

    /**
     * Ask again for months the bank failed to give (the note's "Try again").
     * Months asked for and still pending are never asked twice: Plaid bills
     * per statement extracted, and again for a range requested twice.
     */
    public function fetchMissingStatements(int $bankId): void
    {
        $plaidService = app(PlaidService::class);
        ['banks' => $banks] = $this->listStatements($plaidService, $bankId);

        $requested = $this->requestMissingStatements($banks, $plaidService, retryFailed: true);

        if ($requested !== []) {
            $this->toast(
                text: 'Asked the bank again for '.implode(', ', $requested).'. They usually arrive within minutes; download again then.',
                heading: 'Statements requested',
                duration: 8000,
                variant: 'success',
            );
        }

        $this->statementGaps = collect($this->statementGaps)
            ->reject(fn (array $gap) => $gap['bank_id'] === $bankId)
            ->merge($this->statementGapsFor($this->withSavedRefreshes($banks)))
            ->values()
            ->all();
    }

    /**
     * Request each bank's missing months (statementGapsFor) from Plaid, skipping
     * ranges already asked for — and, unless retrying, ones the bank failed.
     *
     * @param  array<int, array{name: string, posted: list<string>, refreshes: list<array<string, mixed>>}>  $banks
     * @return list<string> labels of the months requested ("Citibank Aug 2025 – Mar 2026")
     */
    protected function requestMissingStatements(array $banks, PlaidService $plaidService, bool $retryFailed = false): array
    {
        $requested = [];
        $earliest = today()->subYears(2)->addDay();

        foreach (collect($this->statementGapsFor($banks))->groupBy('bank_id') as $bankId => $gaps) {
            $bank = \App\Models\Bank::query()
                ->whereKey($bankId)
                ->where('vendor_id', auth()->user()->vendor->id)
                ->whereNotNull('plaid_access_token')
                ->first();

            if (! $bank) {
                continue;
            }

            $options = is_array($bank->plaid_options) ? $bank->plaid_options : [];
            $added = false;

            foreach ($gaps as $gap) {
                if ($gap['requested_at'] && ! ($retryFailed && $gap['result'] === 'FAILURE')) {
                    continue;
                }

                // Plaid picks statements by posted date: the month's last day or
                // the next few days, so the range runs a few days past the month.
                $start = Carbon::parse($gap['from'].'-01')->max($earliest);
                $end = Carbon::parse($gap['to'].'-01')->endOfMonth()->addDays(3)->min(today());

                if ($start->gt($end)) {
                    continue;
                }

                $response = $plaidService->refreshStatements($bank->plaid_access_token, $start->toDateString(), $end->toDateString(), ['bank_id' => $bank->id, 'source' => 'audit']);

                if (($response['error'] ?? false) === true) {
                    $this->toast(
                        text: 'Plaid would not fetch '.$gap['label'].' from '.$bank->name.': '.($response['error_message'] ?? 'unknown error'),
                        heading: 'Could not request statements',
                        duration: 10000,
                        variant: 'danger',
                    );

                    continue;
                }

                $options['statement_refreshes'][] = [
                    'start' => $start->toDateString(),
                    'end' => $end->toDateString(),
                    'requested_at' => now()->toIso8601String(),
                    'requested_by' => auth()->id(),
                    'request_id' => $response['request_id'] ?? null,
                    'result' => null,
                ];
                $added = true;
                $requested[] = $bank->name.' '.$gap['label'];

                \Log::channel('plaid_statements')->info('Statements refresh requested', [
                    'bank_id' => $bank->id,
                    'range' => [$start->toDateString(), $end->toDateString()],
                    'vendor_id' => auth()->user()->vendor->id,
                ]);
            }

            if ($added) {
                $bank->plaid_options = $options;
                $bank->save();
            }
        }

        return $requested;
    }

    /**
     * The listed banks with the refresh requests now saved on them.
     *
     * @param  array<int, array{name: string, posted: list<string>, refreshes: list<array<string, mixed>>}>  $banks
     * @return array<int, array{name: string, posted: list<string>, refreshes: list<array<string, mixed>>}>
     */
    protected function withSavedRefreshes(array $banks): array
    {
        $saved = \App\Models\Bank::query()->whereIn('id', array_keys($banks))->get()->keyBy('id');

        foreach ($banks as $bankId => $bank) {
            $banks[$bankId]['refreshes'] = (array) ($saved[$bankId]?->plaid_options['statement_refreshes'] ?? []);
        }

        return $banks;
    }

    /**
     * The selected accounts' statements from Plaid: those posted in the audit
     * period (plus STATEMENT_GRACE_DAYS), and, per bank whose accounts Plaid
     * lists, every posted date it has, for finding missing months.
     *
     * @return array{statements: Collection, banks: array<int, array{name: string, posted: list<string>, refreshes: list<array<string, mixed>>}>, errors: Collection}
     */
    protected function listStatements(PlaidService $plaidService, ?int $onlyBankId = null): array
    {
        $bankAccounts = BankAccount::whereIn('id', $this->bank_account_ids)
            ->with('bank')
            ->whereHas('bank', function ($query) {
                $query->whereNotNull('plaid_access_token');
            })
            ->when($onlyBankId, fn ($query) => $query->where('bank_id', $onlyBankId))
            ->get()
            ->groupBy('bank_id');

        $allStatements = collect();
        $errors = collect();
        $banks = [];

        foreach ($bankAccounts as $bankId => $accounts) {
            $bank = $accounts->first()->bank;

            $statementsResponse = $plaidService->getStatements(
                $bank->plaid_access_token,
                null,
                ['bank_id' => $bank->id],
            );

            if (isset($statementsResponse['error'])) {
                $errorMessage = $statementsResponse['error_message'] ?? 'Unknown error';
                $errorBody = $statementsResponse['error_body'] ?? null;

                if ($errorBody && isset($errorBody['error_code'])) {
                    $errorMessage = match ($errorBody['error_code']) {
                        'INVALID_ACCESS_TOKEN' => "Bank connection expired - please reconnect '{$bank->name}'",
                        'ITEM_NOT_SUPPORTED' => "Bank statements not supported for '{$bank->name}'",
                        'PRODUCT_NOT_READY' => "Bank statements not yet available for '{$bank->name}' - try again later",
                        'PRODUCT_NOT_ENABLED' => "Bank statements not enabled for '{$bank->name}' - please reconnect with statements access",
                        default => "Error getting statements for '{$bank->name}': ".($errorBody['error_message'] ?? $errorMessage),
                    };
                } elseif (str_contains($errorMessage, "'statements' product is not enabled")) {
                    $errorMessage = "Bank statements not enabled for '{$bank->name}' - please reconnect with statements access";
                }

                $errors->push($errorMessage);

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

            $statementsFound = 0;
            foreach ($statementsResponse['accounts'] ?? [] as $account) {
                // Only the selected accounts; one Plaid lists for this bank.
                $bankAccount = $accounts->firstWhere('plaid_account_id', $account['account_id']);
                if (! $bankAccount) {
                    continue;
                }

                $banks[$bank->id] ??= [
                    'name' => $bank->name,
                    'posted' => [],
                    'refreshes' => (array) ($bank->plaid_options['statement_refreshes'] ?? []),
                ];

                foreach ($account['statements'] ?? [] as $statement) {
                    $postedOn = self::statementPostedOn($statement);
                    if (! $postedOn) {
                        continue;
                    }

                    $banks[$bank->id]['posted'][] = $postedOn->toDateString();

                    if ($postedOn->between(
                        Carbon::parse($this->start_date)->startOfDay(),
                        Carbon::parse($this->end_date)->addDays(self::STATEMENT_GRACE_DAYS)->endOfDay(),
                    )) {
                        $allStatements->push([
                            'statement_id' => $statement['statement_id'],
                            'bank_name' => $bank->name,
                            'bank_id' => $bank->id,
                            'account_name' => $bankAccount->account_number,
                            'statement_date' => $postedOn->toDateString(),
                            'access_token' => $bank->plaid_access_token,
                        ]);
                        $statementsFound++;
                    }
                }
            }

            \Log::channel('plaid_statements')->info('Statements successfully retrieved from bank', [
                'bank_id' => $bank->id,
                'bank_name' => $bank->name,
                'bank_account_ids' => $accounts->pluck('id')->toArray(),
                'statements_found' => $statementsFound,
                'vendor_id' => auth()->user()->vendor->id,
                'date_range' => [$this->start_date, $this->end_date],
            ]);
        }

        return ['statements' => $allStatements, 'banks' => $banks, 'errors' => $errors];
    }

    /**
     * @param  array<int, array{name: string, posted: list<string>, refreshes: list<array<string, mixed>>}>  $banks
     * @return list<array{bank_id: int, bank_name: string, from: string, to: string, label: string, requested_at: ?string, result: ?string}>
     */
    protected function statementGapsFor(array $banks): array
    {
        $gaps = [];

        foreach ($banks as $bankId => $bank) {
            foreach (self::missingStatementMonths($bank['posted'], (string) $this->start_date, (string) $this->end_date) as $run) {
                $runStart = Carbon::parse($run['from'].'-01');
                $runEnd = Carbon::parse($run['to'].'-01')->endOfMonth();
                $request = collect($bank['refreshes'])
                    ->filter(fn (array $refresh) => Carbon::parse($refresh['start'])->lte($runEnd) && Carbon::parse($refresh['end'])->gte($runStart))
                    ->sortByDesc('requested_at')
                    ->first();

                $gaps[] = [
                    'bank_id' => (int) $bankId,
                    'bank_name' => $bank['name'],
                    'from' => $run['from'],
                    'to' => $run['to'],
                    'label' => $run['from'] === $run['to']
                        ? $runStart->format('M Y')
                        : $runStart->format('M Y').' – '.$runEnd->format('M Y'),
                    'requested_at' => $request['requested_at'] ?? null,
                    'result' => $request['result'] ?? null,
                ];
            }
        }

        return $gaps;
    }

    /**
     * The audit's months (start through end, but none still running) with no
     * statement posted for them, as runs of consecutive months. A statement
     * posted in a month's first days covers the month before.
     *
     * @param  list<string>  $postedDates
     * @return list<array{from: string, to: string}>
     */
    public static function missingStatementMonths(array $postedDates, string $start, string $end): array
    {
        $covered = collect($postedDates)
            ->map(fn (string $date) => Carbon::parse($date))
            ->map(fn (Carbon $date) => ($date->day <= 5 ? $date->copy()->subMonthNoOverflow() : $date)->format('Y-m'))
            ->unique();

        $lastClosedMonth = today()->startOfMonth()->subMonth();
        $last = Carbon::parse($end)->startOfMonth()->min($lastClosedMonth);
        $runs = [];
        $run = null;

        for ($month = Carbon::parse($start)->startOfMonth(); $month->lte($last); $month->addMonth()) {
            $key = $month->format('Y-m');

            if ($covered->contains($key)) {
                if ($run) {
                    $runs[] = $run;
                    $run = null;
                }

                continue;
            }

            $run = $run ? ['from' => $run['from'], 'to' => $key] : ['from' => $key, 'to' => $key];
        }

        if ($run) {
            $runs[] = $run;
        }

        return $runs;
    }

    /** A statement posted this long after the audit ends still covers its last weeks. */
    public const STATEMENT_GRACE_DAYS = 35;

    /**
     * When the bank posted a statement. Plaid's /statements/list gives each
     * statement only month, year and date_posted, with no start or end date;
     * reading end_date threw on the first statement, and the download only
     * ever said "An error occurred" (2026-10-15).
     *
     * @param  array<string, mixed>  $statement
     */
    public static function statementPostedOn(array $statement): ?Carbon
    {
        $posted = $statement['date_posted'] ?? $statement['posted_date'] ?? null;
        if (filled($posted)) {
            try {
                return Carbon::parse($posted)->startOfDay();
            } catch (\Throwable) {
                // fall through to month and year
            }
        }
        if (! empty($statement['year']) && ! empty($statement['month'])) {
            return Carbon::create((int) $statement['year'], (int) $statement['month'], 1)->endOfMonth()->startOfDay();
        }

        return null;
    }

    /**
     * Every statement in one PDF, oldest first (then by account), each once:
     * an auditor reads a year of statements as one document, not a zip of
     * files (2026-10-16). Merged here, not by a third-party service — these
     * are bank statements. A statement Plaid lists twice with identical
     * bytes is kept once.
     */
    private function mergeStatements(Collection $statements, PlaidService $plaidService): ?string
    {
        $binaries = [];

        foreach ($statements->unique('statement_id')->sortBy(fn (array $statement) => $statement['statement_date'].'|'.$statement['account_name'])->values() as $statement) {
            $pdfContent = $plaidService->downloadStatement(
                $statement['access_token'],
                $statement['statement_id'],
                ['bank_id' => $statement['bank_id'] ?? null],
            );

            if (! is_string($pdfContent) || ! str_starts_with($pdfContent, '%PDF')) {
                \Log::channel('plaid_statements')->warning('Failed to download individual statement', [
                    'statement_id' => $statement['statement_id'],
                    'bank_name' => $statement['bank_name'],
                    'account_name' => $statement['account_name'],
                    'statement_date' => $statement['statement_date'],
                    'error' => is_array($pdfContent) ? $pdfContent : 'not a PDF',
                    'vendor_id' => auth()->user()->vendor->id,
                ]);

                continue;
            }

            $binaries[sha1($pdfContent)] = $pdfContent;
        }

        return \App\Support\PdfMerger::merge(array_values($binaries));
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
