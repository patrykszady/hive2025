<?php

namespace App\Support;

use App\Models\Bid;
use App\Models\Check;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Vendor;
use Illuminate\Support\Str;
use Spatie\Browsershot\Browsershot;
use Throwable;

/**
 * Sworn Statement of Contractor to Owner (the traditional Illinois "GCSS"
 * form, 770 ILCS 60/5): one row per sub/supplier on the project with contract,
 * paid-to-date, this-payment and balance columns, footed against the GC's
 * total contract, plus the notary block. Generated per draw, printed, signed
 * and notarized on paper — so no e-sign flow and no DRAFT watermark.
 */
class SwornStatementGenerator
{
    /**
     * Vendor business types that default to being listed on the statement.
     * Retail/incidental vendors (big-box stores, couriers, bank fees) stay
     * off unless the user opts them in.
     */
    protected const LISTED_TYPES = ['Sub', '1099', 'Supplier'];

    /**
     * Prefill one editable row per vendor with money on this project.
     * Discovery matches the lien-waiver claimant list: expenses, check-paid
     * payments and bids — the GC itself is excluded (it gets the balancing
     * line at generate time).
     *
     * @return array<int, array{vendor_id:int, name:string, include:bool, kind:string, contract:string, paid:float, this_payment:string}>
     */
    public static function buildRows(Project $project, ?Vendor $contractor): array
    {
        $projectId = (int) $project->id;
        $gcId = (int) ($contractor?->id ?? 0);

        // Paid to date per vendor: expenses…
        $expensePaid = Expense::withoutGlobalScopes()
            ->where('project_id', $projectId)
            ->whereNull('deleted_at')
            ->selectRaw('vendor_id, SUM(amount) AS total')
            ->whereNotNull('vendor_id')
            ->groupBy('vendor_id')
            ->pluck('total', 'vendor_id');

        // …plus payment rows written against a vendor's check.
        $checkVendorByCheckId = Check::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereIn('id', function ($q) use ($projectId) {
                $q->select('check_id')->from('payments')
                    ->where('project_id', $projectId)
                    ->whereNotNull('check_id');
            })
            ->pluck('vendor_id', 'id');

        $checkPaid = [];
        if ($checkVendorByCheckId->isNotEmpty()) {
            Payment::withoutGlobalScopes()
                ->where('project_id', $projectId)
                ->whereIn('check_id', $checkVendorByCheckId->keys())
                ->get(['check_id', 'amount'])
                ->each(function ($payment) use (&$checkPaid, $checkVendorByCheckId) {
                    $vendorId = (int) $checkVendorByCheckId[$payment->check_id];
                    $checkPaid[$vendorId] = ($checkPaid[$vendorId] ?? 0) + (float) $payment->amount;
                });
        }

        // Contract amounts (base bid + change orders) per vendor.
        $bidTotals = Bid::withoutGlobalScopes()
            ->where('project_id', $projectId)
            ->selectRaw('vendor_id, SUM(amount) AS total')
            ->groupBy('vendor_id')
            ->pluck('total', 'vendor_id');

        $vendorIds = collect($expensePaid->keys())
            ->merge(array_keys($checkPaid))
            ->merge($bidTotals->keys())
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->reject(fn ($id) => $id === $gcId || $id === 0)
            ->values();

        if ($vendorIds->isEmpty()) {
            return [];
        }

        $vendors = Vendor::withoutGlobalScopes()
            ->whereIn('id', $vendorIds)
            ->orderBy('business_name')
            ->get(['id', 'business_name', 'business_type', 'sheets_type', 'work_type_id']);

        // Each vendor's saved default work type prefills its Kind of Work.
        $workTypeNames = \App\Models\WorkType::query()
            ->whereIn('id', $vendors->pluck('work_type_id')->filter())
            ->pluck('name', 'id');

        return $vendors
            ->map(function ($vendor) use ($expensePaid, $checkPaid, $bidTotals, $workTypeNames) {
                $paid = (float) ($expensePaid[$vendor->id] ?? 0) + (float) ($checkPaid[$vendor->id] ?? 0);
                $contract = (float) ($bidTotals[$vendor->id] ?? 0);

                return [
                    'vendor_id' => (int) $vendor->id,
                    'name' => (string) $vendor->business_name,
                    'include' => in_array($vendor->business_type, self::LISTED_TYPES, true)
                        || $contract > 0,
                    'kind' => (string) ($workTypeNames[$vendor->work_type_id] ?? ''),
                    // Retail vendors supply goods/services, not labor & material
                    // packages — their kind prints as typed, without the suffix.
                    'is_retail' => $vendor->business_type === 'Retail',
                    // Material suppliers (P&L "Materials" sheets type) can lien
                    // for materials furnished — title companies want their
                    // waivers (waiver only, no affidavit).
                    'is_material' => $vendor->sheets_type === 'Materials',
                    'contract' => $contract > 0 ? number_format($contract, 2) : '',
                    'paid' => round($paid, 2),
                    'this_payment' => '',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Render the sworn statement PDF for one draw.
     *
     * @param array $rows        Rows as produced by buildRows() (after user edits);
     *                           money fields may contain thousands separators.
     * @param float $thisPayment The draw amount the owner is paying the GC now.
     * @return array{binary:string, filename:string}
     */
    public static function generate(Project $project, Vendor $contractor, array $rows, float $thisPayment, ?int $drawNumber = null, ?int $statementId = null): array
    {
        $context = self::buildContext($project, $contractor, $rows, $thisPayment, $drawNumber);
        $footerHtml = self::buildFooterHtml($statementId, $context['filename']);

        // Two-pass render: the form's "Page 1 of N" needs the real page
        // count, which is only known after rendering once.
        $binary = self::renderPdf(view('pdf.sworn-statement', $context['view'] + ['totalPages' => 1])->render(), $footerHtml);

        if (str_starts_with($binary, '%PDF')) {
            $pages = max(1, preg_match_all('#/Type\s*/Page\b#', $binary));

            if ($pages > 1) {
                $binary = self::renderPdf(view('pdf.sworn-statement', $context['view'] + ['totalPages' => $pages])->render(), $footerHtml);
            }
        }

        return [
            'binary' => $binary,
            'filename' => $context['filename'],
        ];
    }

    /**
     * The scan-matching identity a GCSS barcode encodes ("HSS-{id}") —
     * shared with the future ingest that links returned scans back to the
     * statement, mirroring LienWaiverDocumentGenerator::barcodeValueFor().
     */
    public static function barcodeValueFor(int $statementId): string
    {
        return 'HSS-' . $statementId;
    }

    /**
     * Per-page footer — the shared card (barcode + filename + return-email
     * instruction), plus the native page counter on the right.
     */
    protected static function buildFooterHtml(?int $statementId, string $filename): string
    {
        return \App\Support\PdfDocumentFooter::build(
            $statementId !== null ? self::barcodeValueFor($statementId) : '',
            $filename,
            withPageCounter: true,
        );
    }

    /**
     * Assemble the view data and filename for the statement — separated from
     * generate() so tests can assert on the rendered HTML (Browsershot returns
     * an opaque binary when Chrome is available).
     *
     * @return array{view: array, filename: string}
     */
    public static function buildContext(Project $project, Vendor $contractor, array $rows, float $thisPayment, ?int $drawNumber = null): array
    {
        $projectId = (int) $project->id;

        $gcBids = fn () => Bid::withoutGlobalScopes()
            ->where('project_id', $projectId)
            ->where('vendor_id', $contractor->id);

        // Change orders split by sign: additions are "Extras to Contract",
        // deductions are "Credits to Contract" (shown positive). The adjusted
        // total — original + extras − credits — is the real contract value the
        // table foots against.
        $originalContract = (float) $gcBids()->where('type', 1)->sum('amount');
        $extras = (float) $gcBids()->where('type', '>', 1)->where('amount', '>', 0)->sum('amount');
        $credits = abs((float) $gcBids()->where('type', '>', 1)->where('amount', '<', 0)->sum('amount'));
        $grossTotal = $originalContract + $extras;
        $adjustedTotal = $grossTotal - $credits;

        $ownerPaid = (float) Payment::withoutGlobalScopes()
            ->where('project_id', $projectId)
            ->sum('amount');

        $toFloat = fn ($v) => $v === null || $v === '' ? null : (float) str_replace(',', '', (string) $v);

        $kindSuffix = 'labor & material (incl. extras)';

        $lines = collect($rows)
            ->filter(fn ($row) => ! empty($row['include']))
            ->map(function ($row) use ($toFloat, $kindSuffix) {
                $contract = $toFloat($row['contract'] ?? null);
                $paid = (float) ($row['paid'] ?? 0);
                $this_ = $toFloat($row['this_payment'] ?? null) ?? 0.0;
                $kind = trim((string) ($row['kind'] ?? ''));

                // Retail vendors (suppliers, rentals, haulers) print their kind
                // as typed — the labor & material suffix belongs to trade subs.
                if (! empty($row['is_retail'])) {
                    $kindLabel = $kind;
                } else {
                    $kindLabel = $kind !== '' ? $kind . ' — ' . $kindSuffix : ucfirst($kindSuffix);
                }

                // No-bid fallback: the money that has flowed to the vendor
                // stands in as the contract (mirrors the waiver affidavit), so
                // the statement shows an amount and a 0.00 balance instead of
                // blanks — retail rows included.
                if ($contract === null && ($paid + $this_) > 0) {
                    $contract = $paid + $this_;
                }

                return [
                    'name' => (string) ($row['name'] ?? ''),
                    'kind' => $kindLabel,
                    'contract' => $contract,
                    'paid' => $paid,
                    'this_payment' => $this_,
                    'balance' => $contract !== null ? $contract - $paid - $this_ : null,
                ];
            })
            ->values();

        // The GC's own line absorbs the remainder so every column foots to the
        // summary boxes (standard practice when filling the form by hand).
        $subContracts = (float) $lines->sum(fn ($l) => $l['contract'] ?? 0);
        $subPaid = (float) $lines->sum('paid');
        $subThis = (float) $lines->sum('this_payment');

        $gcLine = [
            'name' => (string) $contractor->business_name,
            'kind' => 'General construction — ' . $kindSuffix,
            'contract' => max(0, $adjustedTotal - $subContracts),
            'paid' => max(0, $ownerPaid - $subPaid),
            'this_payment' => max(0, $thisPayment - $subThis),
        ];
        $gcLine['balance'] = max(0, $gcLine['contract'] - $gcLine['paid'] - $gcLine['this_payment']);

        $summary = [
            'original_contract' => $originalContract,
            'extras' => $extras,
            'contract_total' => $grossTotal,
            'credits' => $credits,
            'adjusted_total' => $adjustedTotal,
            'work_completed' => $ownerPaid + $thisPayment,
            'prev_paid' => $ownerPaid,
            'this_payment' => $thisPayment,
            'balance_due' => $adjustedTotal - $ownerPaid - $thisPayment,
        ];

        // ClientScope limits clients to ones linked to the auth vendor — load
        // directly so the owner line always fills in.
        $owner = $project->client()->withoutGlobalScopes()->first();

        // The affiant is whoever generates the statement; their business title
        // at the contractor comes from the user↔vendor pivot.
        $affiantUser = auth()->user();
        $affiantName = trim((string) ($affiantUser?->first_name ?? '') . ' ' . (string) ($affiantUser?->last_name ?? ''));
        $affiantPosition = $affiantUser
            ? (string) (\App\Models\UserVendor::query()
                ->where('user_id', $affiantUser->id)
                ->where('vendor_id', $contractor->id)
                ->value('position') ?? '')
            : '';

        $estimateNumber = (string) ($project->estimates?->first()?->number ?? '');

        $filename = collect([
            'sworn-statement',
            Str::slug((string) ($contractor->business_name ?? '')),
            $estimateNumber !== '' ? Str::slug($estimateNumber) : null,
            Str::slug((string) ($owner?->name ?? '')),
            Str::slug((string) ($project->address ?? '')),
            $drawNumber ? 'draw-' . $drawNumber : null,
            now()->format('Y-m-d'),
        ])->filter(fn ($part) => $part !== null && $part !== '')->implode('-') . '.pdf';

        return [
            'view' => [
                'project' => $project,
                'contractor' => $contractor,
                'affiantName' => $affiantName,
                'affiantPosition' => $affiantPosition,
                'ownerName' => (string) ($owner?->name ?? ''),
                'projectCounty' => LienWaiverDocumentGenerator::lookupProjectCounty($project),
                'lines' => $lines->all(),
                'gcLine' => $gcLine,
                'summary' => $summary,
                'statementDate' => now(),
            ],
            'filename' => $filename,
        ];
    }

    /**
     * HTML → single-page PDF, same compact no-chrome treatment as the Illinois
     * lien waiver. Falls back to the raw HTML when headless Chrome isn't
     * available (CI, test suite) so callers can still stream *something*.
     */
    protected static function renderPdf(string $html, ?string $footerHtml = null): string
    {
        try {
            return Browsershot::html($html)
                ->format('Letter')
                ->showBackground()
                ->margins(9, 12, 21, 12)
                ->showBrowserHeaderAndFooter()
                ->headerHtml('<span></span>')
                ->footerHtml($footerHtml ?: self::buildFooterHtml(null, ''))
                ->pdf();
        } catch (Throwable $e) {
            return $html;
        }
    }
}
