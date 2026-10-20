<?php

namespace App\Services\VendorDocs;

use App\Jobs\RunPendingEwccvLookups;
use App\Livewire\VendorDocs\AuditShow;
use App\Models\AuditRequest;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\User;
use App\Models\Vendor;
use App\Services\PlaidService;
use App\Support\Ewccv\BridgedSession;
use Illuminate\Support\Carbon;

/**
 * The audit page actions that used to need either a real browser session
 * or a multi-MB file response, and so stayed web-only
 * (App\Services\VendorDocs\AppAuditSummary's docblock): bank-statement
 * gap detection and asking the bank again (App\Livewire\VendorDocs\
 * AuditShow::listStatements()/fetchMissingStatements(), lighter here —
 * only the statement LIST, never the PDFs themselves, which stay on the
 * web) and queueing EWCCV re-checks (AuditShow::verify_coverage_on_ewccv(),
 * identical: the actual scrape still runs server-side on its own schedule
 * via RunPendingEwccvLookups, exactly as the web leaves it).
 *
 * Re-derives AuditShow's month-gap arithmetic rather than duplicating it
 * by hand: AuditShow::missingStatementMonths() and ::statementPostedOn()
 * are already public static pure functions, called here unchanged (the
 * Livewire component itself is never instantiated).
 */
class AppAuditActions
{
    public function __construct(private PlaidService $plaid)
    {
    }

    /**
     * @param  list<int>  $bankAccountIds
     * @return list<array{bank_id: int, bank_name: string, from: string, to: string, label: string, requested_at: ?string, result: ?string}>
     */
    public function statementGaps(Vendor $company, string $start, string $end, array $bankAccountIds): array
    {
        return $this->gapsFor($this->postedDatesByBank($company, $bankAccountIds), $start, $end);
    }

    /**
     * Ask the bank again for $bankId's still-missing months —
     * AuditShow::fetchMissingStatements(), scoped to one bank. Months
     * already asked for are never asked twice (Plaid bills per statement);
     * a previously FAILED range may be retried.
     *
     * @param  list<int>  $bankAccountIds
     * @return array{requested: list<string>, gaps: list<array{bank_id: int, bank_name: string, from: string, to: string, label: string, requested_at: ?string, result: ?string}>}
     */
    public function refreshBank(Vendor $company, int $bankId, string $start, string $end, array $bankAccountIds, ?User $requestedBy): array
    {
        $banks = $this->postedDatesByBank($company, $bankAccountIds, $bankId);
        $gaps = $this->gapsFor($banks, $start, $end);

        $bank = Bank::query()->whereKey($bankId)->where('vendor_id', $company->id)->whereNotNull('plaid_access_token')->first();
        if (! $bank) {
            return ['requested' => [], 'gaps' => $gaps];
        }

        $options = is_array($bank->plaid_options) ? $bank->plaid_options : [];
        $requested = [];
        $earliest = today()->subYears(2)->addDay();
        $changed = false;

        foreach (collect($gaps)->where('bank_id', $bankId) as $gap) {
            if ($gap['requested_at'] && $gap['result'] !== 'FAILURE') {
                continue;
            }

            $rangeStart = Carbon::parse($gap['from'].'-01')->max($earliest);
            $rangeEnd = Carbon::parse($gap['to'].'-01')->endOfMonth()->addDays(3)->min(today());
            if ($rangeStart->gt($rangeEnd)) {
                continue;
            }

            $response = $this->plaid->refreshStatements($bank->plaid_access_token, $rangeStart->toDateString(), $rangeEnd->toDateString(), ['bank_id' => $bank->id, 'source' => 'audit-app']);
            if (($response['error'] ?? false) === true) {
                continue;
            }

            $options['statement_refreshes'][] = [
                'start' => $rangeStart->toDateString(),
                'end' => $rangeEnd->toDateString(),
                'requested_at' => now()->toIso8601String(),
                'requested_by' => $requestedBy?->id,
                'request_id' => $response['request_id'] ?? null,
                'result' => null,
            ];
            $changed = true;
            $requested[] = $bank->name.' '.$gap['label'];
        }

        if ($changed) {
            $bank->plaid_options = $options;
            $bank->save();
            $banks[$bankId]['refreshes'] = (array) $options['statement_refreshes'];
            $gaps = $this->gapsFor($banks, $start, $end);
        }

        return ['requested' => $requested, 'gaps' => $gaps];
    }

    /**
     * Queue EWCCV re-checks for every sub still unanswered as of the
     * coverage date, and run them now when a browser session is available
     * — AuditShow::verify_coverage_on_ewccv(), unchanged.
     *
     * @param  list<int>  $bankAccountIds
     * @return array{queued: int, dispatched: bool}
     */
    public function verifyEwccv(Vendor $company, ?User $requestedBy, string $start, string $end, ?string $type, array $bankAccountIds): array
    {
        if ($type !== 'workers') {
            return ['queued' => 0, 'dispatched' => false];
        }

        $audit = AuditRequest::findOrStart($company->id, $requestedBy, $start, $end, $type, $bankAccountIds);
        $queued = $audit->queueChecks();

        if ($queued === 0) {
            return ['queued' => 0, 'dispatched' => false];
        }

        if (BridgedSession::canSearch()) {
            RunPendingEwccvLookups::dispatch();

            return ['queued' => $queued, 'dispatched' => true];
        }

        return ['queued' => $queued, 'dispatched' => false];
    }

    /**
     * Each selected bank's posted statement dates (plus any saved refresh
     * requests), from Plaid's statement list — never the statements
     * themselves. AuditShow::listStatements(), trimmed to only what gap
     * detection needs.
     *
     * @param  list<int>  $bankAccountIds
     * @return array<int, array{name: string, posted: list<string>, refreshes: list<array<string, mixed>>}>
     */
    private function postedDatesByBank(Vendor $company, array $bankAccountIds, ?int $onlyBankId = null): array
    {
        $bankAccounts = BankAccount::whereIn('id', $bankAccountIds)
            ->where('vendor_id', $company->id)
            ->with('bank')
            ->whereHas('bank', fn ($query) => $query->whereNotNull('plaid_access_token'))
            ->when($onlyBankId, fn ($query) => $query->where('bank_id', $onlyBankId))
            ->get()
            ->groupBy('bank_id');

        $banks = [];

        foreach ($bankAccounts as $bankId => $accounts) {
            $bank = $accounts->first()->bank;
            $response = $this->plaid->getStatements($bank->plaid_access_token, null, ['bank_id' => $bank->id]);

            if (isset($response['error'])) {
                continue;
            }

            foreach ($response['accounts'] ?? [] as $account) {
                if (! $accounts->firstWhere('plaid_account_id', $account['account_id'])) {
                    continue;
                }

                $banks[$bank->id] ??= [
                    'name' => $bank->name,
                    'posted' => [],
                    'refreshes' => (array) ($bank->plaid_options['statement_refreshes'] ?? []),
                ];

                foreach ($account['statements'] ?? [] as $statement) {
                    $postedOn = AuditShow::statementPostedOn($statement);
                    if ($postedOn) {
                        $banks[$bank->id]['posted'][] = $postedOn->toDateString();
                    }
                }
            }
        }

        return $banks;
    }

    /**
     * @param  array<int, array{name: string, posted: list<string>, refreshes: list<array<string, mixed>>}>  $banks
     * @return list<array{bank_id: int, bank_name: string, from: string, to: string, label: string, requested_at: ?string, result: ?string}>
     */
    private function gapsFor(array $banks, string $start, string $end): array
    {
        $gaps = [];

        foreach ($banks as $bankId => $bank) {
            foreach (AuditShow::missingStatementMonths($bank['posted'], $start, $end) as $run) {
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
}
