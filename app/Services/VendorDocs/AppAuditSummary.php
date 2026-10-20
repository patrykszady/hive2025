<?php

namespace App\Services\VendorDocs;

use App\Models\AuditRequest;
use App\Models\Bank;
use App\Models\EwccvCoverageCheck;
use App\Models\Vendor;
use App\Services\Audits\AuditReport;

/**
 * A lightweight read of the web's /vendor_docs/audit page
 * (App\Livewire\VendorDocs\{AuditIndex,AuditShow}) for the app: which bank
 * accounts can be audited, and — for a chosen period — EWCCV's coverage
 * counts and the policies it could not find. The certificate/Excel/bank-
 * statement downloads and "verify on EWCCV now" (both need a real browser
 * session or a multi-MB file response) stay web-only — see this track's
 * final report; the app's audit screen offers "Open in Hive" for those.
 */
class AppAuditSummary
{
    /**
     * AuditIndex::banks() — bank accounts available to audit, by bank.
     *
     * @return array<int, array{name: string, accounts: list<int>}>
     */
    public function banks(Vendor $company): array
    {
        return Bank::where('vendor_id', $company->id)
            ->whereNotNull('plaid_access_token')
            ->whereNotNull('plaid_ins_id')
            ->get()
            ->keyBy('id')
            ->map(function (Bank $bank) {
                $accountIds = $bank->institution_accounts()
                    ->whereIn('bank_accounts.type', ['Checking', 'Savings'])
                    ->pluck('bank_accounts.id')
                    ->all();

                return ['name' => $bank->name, 'accounts' => $accountIds];
            })
            ->filter(fn (array $b) => ! empty($b['accounts']))
            ->toArray();
    }

    /**
     * AuditShow's coverage_check_counts, not_found_policies and canDownload
     * for a chosen period — mirrors findOrStart()'s side effect (a workers
     * comp audit queues its EWCCV checks) exactly as visiting the web page
     * does.
     *
     * @param  list<int>  $bankAccountIds
     * @return array{coverage_counts: array<string, int>, not_found_policies: list<array<string, mixed>>, can_download: bool}
     */
    public function summary(Vendor $company, ?\App\Models\User $requestedBy, string $start, string $end, ?string $type, array $bankAccountIds): array
    {
        $coverageDate = $type === 'workers' ? AuditRequest::coverageDateFor($end) : null;
        $report = new AuditReport($company->id, $start, $end, $type, $bankAccountIds, $coverageDate);

        $auditRequest = $bankAccountIds !== []
            ? AuditRequest::findOrStart($company->id, $requestedBy, $start, $end, $type, $bankAccountIds)
            : null;

        return [
            'coverage_counts' => $report->coverageCounts(),
            'not_found_policies' => $this->notFoundPolicies($report),
            'can_download' => $auditRequest === null || $auditRequest->isDownloadable(),
        ];
    }

    /** AuditShow::not_found_policies(), unchanged. */
    private function notFoundPolicies(AuditReport $report): array
    {
        $rows = [];
        foreach ($report->groups() as $group) {
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
                ];
            }
        }

        return $rows;
    }
}
