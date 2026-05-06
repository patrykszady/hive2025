<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Distribution;
use App\Models\Expense;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ExpenseAutoMatchController extends Controller
{
    public function runNoProjectExpenseAutoMatchRoute(): JsonResponse
    {
        $this->runNoProjectExpenseAutoMatch(
            onlyBelongsToVendorIds: null,
            onDecision: null,
            onSummary: null,
            summaryAll: false,
            includeNullStatus: true,
            includeNullSplits: true,
        );

        return response()->json(['ok' => true]);
    }

    /**
     * Auto-match "No Project" expenses to a distribution or project based on purchase order.
     */
    public function runNoProjectExpenseAutoMatch(
        ?array $onlyBelongsToVendorIds = null,
        ?callable $onDecision = null,
        ?callable $onSummary = null,
        bool $summaryAll = false,
        bool $includeNullStatus = false,
        bool $includeNullSplits = false,
    ): void
    {
        $hiveVendorsQuery = Vendor::hiveVendors();

        if (is_array($onlyBelongsToVendorIds) && $onlyBelongsToVendorIds !== []) {
            $vendorIds = array_values(array_unique(array_filter(array_map(
                fn ($id) => (int) $id,
                $onlyBelongsToVendorIds
            ), fn (int $id) => $id > 0)));

            if ($vendorIds !== []) {
                $hiveVendorsQuery->whereIn('id', $vendorIds);
            }
        }

        $hiveVendors = $hiveVendorsQuery->get();

        foreach ($hiveVendors as $hiveVendor) {
            // Include expenses belonging to Sub-type vendors (e.g. forwarded receipts billed to a sub-contractor)
            $subVendorIds = Vendor::withoutGlobalScopes()
                ->where('business_type', 'Sub')
                ->where('id', '!=', $hiveVendor->id)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $matchableVendorIds = array_values(array_unique(array_merge([(int) $hiveVendor->id], $subVendorIds)));

            $distributionIndex = null;
            $distributionNameById = [];

            $projects = Project::withoutGlobalScopes()
                ->where('belongs_to_vendor_id', $hiveVendor->id)
                ->select(['id', 'project_name', 'address', 'created_at'])
                ->get();

            $projectDisplayById = $projects
                ->mapWithKeys(function (Project $project): array {
                    $raw = trim(((string) ($project->address ?? '')).' '.((string) ($project->project_name ?? '')));
                    $display = $raw !== '' ? $raw : (string) ($project->project_name ?? '');

                    return [(int) $project->id => trim($display)];
                })
                ->all();

            // Build a map of project_id => list of client name variants for PO matching.
            // Includes business_name plus, for each associated user, the first name,
            // last name, and full name as separate variants so a PO containing any
            // of those tokens (e.g. just a first name like "Zora") can match.
            $clientNamesByProjectId = [];
            $projectClientRows = DB::table('project_vendor')
                ->where('project_vendor.vendor_id', $hiveVendor->id)
                ->whereIn('project_vendor.project_id', $projects->pluck('id')->all())
                ->whereNotNull('project_vendor.client_id')
                ->join('clients', 'clients.id', '=', 'project_vendor.client_id')
                ->select('project_vendor.project_id', 'clients.id as client_id', 'clients.business_name')
                ->get();

            $clientIdsByProjectId = [];
            foreach ($projectClientRows as $row) {
                $projectId = (int) $row->project_id;
                $clientId = (int) ($row->client_id ?? 0);
                if ($projectId <= 0 || $clientId <= 0) {
                    continue;
                }

                $clientNamesByProjectId[$projectId] ??= [];
                $clientIdsByProjectId[$projectId] ??= [];
                $clientIdsByProjectId[$projectId][] = $clientId;

                $businessName = trim((string) ($row->business_name ?? ''));
                if ($businessName !== '') {
                    $clientNamesByProjectId[$projectId][] = $businessName;
                }
            }

            $allClientIds = [];
            foreach ($clientIdsByProjectId as $ids) {
                foreach ($ids as $id) {
                    $allClientIds[] = $id;
                }
            }
            $allClientIds = array_values(array_unique($allClientIds));

            $usersByClientId = [];
            if ($allClientIds !== []) {
                $userRows = DB::table('client_user')
                    ->whereIn('client_user.client_id', $allClientIds)
                    ->join('users', 'users.id', '=', 'client_user.user_id')
                    ->select('client_user.client_id', 'users.first_name', 'users.last_name')
                    ->get();

                foreach ($userRows as $userRow) {
                    $cid = (int) $userRow->client_id;
                    $usersByClientId[$cid] ??= [];
                    $usersByClientId[$cid][] = [
                        'first_name' => trim((string) ($userRow->first_name ?? '')),
                        'last_name' => trim((string) ($userRow->last_name ?? '')),
                    ];
                }
            }

            foreach ($clientIdsByProjectId as $projectId => $clientIds) {
                foreach (array_unique($clientIds) as $clientId) {
                    foreach (($usersByClientId[$clientId] ?? []) as $user) {
                        $first = $user['first_name'] ?? '';
                        $last = $user['last_name'] ?? '';

                        if ($first !== '') {
                            $clientNamesByProjectId[$projectId][] = $first;
                        }
                        if ($last !== '') {
                            $clientNamesByProjectId[$projectId][] = $last;
                        }
                        if ($first !== '' && $last !== '') {
                            $clientNamesByProjectId[$projectId][] = $first.' '.$last;
                        }
                    }
                }

                $clientNamesByProjectId[$projectId] = array_values(array_unique(array_filter(
                    array_map('trim', $clientNamesByProjectId[$projectId])
                )));
            }

            $projectStatuses = ProjectStatus::withoutGlobalScopes()
                ->where('belongs_to_vendor_id', $hiveVendor->id)
                ->whereIn('project_id', $projects->pluck('id')->all())
                ->get(['project_id', 'status_code', 'start_date']);

            $statusesByProjectId = [];
            foreach ($projectStatuses as $status) {
                $projectId = (int) ($status->project_id ?? 0);
                if ($projectId <= 0) {
                    continue;
                }

                $startDateValue = $status->start_date;
                $startDate = null;

                if ($startDateValue instanceof \DateTimeInterface) {
                    $startDate = $startDateValue->format('Y-m-d');
                } elseif (is_string($startDateValue) && $startDateValue !== '') {
                    $startDate = substr($startDateValue, 0, 10);
                }

                if (! $startDate) {
                    continue;
                }

                $statusesByProjectId[$projectId] ??= [];
                $statusesByProjectId[$projectId][] = [
                    'code' => (int) ($status->status_code ?? 0),
                    'start_date' => $startDate,
                ];
            }

            foreach ($statusesByProjectId as $projectId => $statuses) {
                usort($statuses, fn (array $a, array $b) => strcmp($a['start_date'], $b['start_date']));
                $statusesByProjectId[$projectId] = $statuses;
            }

            $projectCandidates = $projects
                ->map(function (Project $project) use ($statusesByProjectId, $clientNamesByProjectId): ?array {
                    $variants = [];

                    $normalizedAddress = $this->normalizeText((string) ($project->address ?? ''));
                    if ($normalizedAddress !== '') {
                        $variants[] = $normalizedAddress;
                    }

                    $normalizedFull = $this->normalizeText(trim(((string) ($project->address ?? '')).' '.((string) ($project->project_name ?? ''))));
                    if ($normalizedFull !== '' && ! in_array($normalizedFull, $variants, true)) {
                        $variants[] = $normalizedFull;
                    }

                    $normalizedName = $this->normalizeText((string) ($project->project_name ?? ''));
                    if ($normalizedName !== '' && ! in_array($normalizedName, $variants, true)) {
                        $variants[] = $normalizedName;
                    }

                    // Include each client name (business name, first name, last name,
                    // and full name) as its own variant so a PO containing any of them
                    // can match the project.
                    foreach (($clientNamesByProjectId[(int) $project->id] ?? []) as $clientName) {
                        $normalizedClient = $this->normalizeText((string) $clientName);
                        if ($normalizedClient !== '' && ! in_array($normalizedClient, $variants, true)) {
                            $variants[] = $normalizedClient;
                        }
                    }

                    if ($variants === []) {
                        return null;
                    }

                    $projectId = (int) $project->id;
                    $createdAt = $project->created_at?->timestamp ?? 0;

                    return [
                        'id' => $projectId,
                        'created_at' => (int) $createdAt,
                        'statuses' => $statusesByProjectId[$projectId] ?? [],
                        'variants' => $variants,
                    ];
                })
                ->filter()
                ->values()
                ->all();

            $projectStatusAtDate = static function (array $statuses, string $date): ?array {
                $best = null;

                foreach ($statuses as $status) {
                    if (($status['start_date'] ?? '') === '' || ($status['code'] ?? null) === null) {
                        continue;
                    }

                    if ($status['start_date'] > $date) {
                        continue;
                    }

                    if ($best === null || $status['start_date'] >= $best['start_date']) {
                        $best = $status;
                    }
                }

                return $best;
            };

            $projectStatusPriority = static function (int $statusCode): int {
                return match ($statusCode) {
                    6 => 60,
                    8 => 55,
                    5 => 50,
                    4 => 40,
                    3 => 30,
                    2 => 20,
                    1 => 10,
                    7 => 5,
                    default => 0,
                };
            };

            $isBetterProjectCandidate = static function (float $score, int $priority, ?string $statusStartDate, int $createdAt, int $projectId, array $currentBest): bool {
                if ($score !== ($currentBest['score'] ?? null)) {
                    return $score > (float) ($currentBest['score'] ?? 0);
                }

                if ($priority !== ($currentBest['priority'] ?? null)) {
                    return $priority > (int) ($currentBest['priority'] ?? 0);
                }

                $bestStatusStart = $currentBest['status_start_date'] ?? null;
                if (($statusStartDate ?? '') !== ($bestStatusStart ?? '')) {
                    return ($statusStartDate ?? '') > ($bestStatusStart ?? '');
                }

                if ($createdAt !== (int) ($currentBest['created_at'] ?? 0)) {
                    return $createdAt > (int) ($currentBest['created_at'] ?? 0);
                }

                return $projectId > (int) ($currentBest['project_id'] ?? 0);
            };

            $matchPurchaseOrderToProjectAtDate = function (string $purchaseOrder, $expenseDate) use ($projectCandidates, $projectStatusAtDate, $projectStatusPriority, $isBetterProjectCandidate): ?array {
                $po = $this->normalizeText($purchaseOrder);
                if ($po === '' || $projectCandidates === []) {
                    return null;
                }

                $poStreetToken = $this->hasHouseNumberPrefix($po) ? $this->extractStreetToken($po) : '';

                $expenseDateString = null;
                if (is_string($expenseDate) && $expenseDate !== '') {
                    $expenseDateString = substr($expenseDate, 0, 10);
                } elseif ($expenseDate && method_exists($expenseDate, 'format')) {
                    $expenseDateString = $expenseDate->format('Y-m-d');
                }

                if (! $expenseDateString) {
                    return null;
                }

                $expenseDateObj = \DateTimeImmutable::createFromFormat('Y-m-d', $expenseDateString);
                if (! $expenseDateObj) {
                    return null;
                }

                $windowStart = $expenseDateObj->modify('-2 months')->format('Y-m-d');
                $windowEnd = $expenseDateObj->modify('+2 months')->format('Y-m-d');

                $isActiveWithinWindow = static function (array $statuses, string $startDate, string $endDate): bool {
                    if ($statuses === []) {
                        return false;
                    }

                    $count = count($statuses);
                    for ($i = 0; $i < $count; $i++) {
                        $status = $statuses[$i];
                        $code = (int) ($status['code'] ?? 0);
                        $segmentStart = (string) ($status['start_date'] ?? '');

                        if ($segmentStart === '') {
                            continue;
                        }

                        $segmentEnd = '9999-12-31';
                        if ($i + 1 < $count) {
                            $nextStart = (string) ($statuses[$i + 1]['start_date'] ?? '');
                            if ($nextStart !== '') {
                                $nextStartObj = \DateTimeImmutable::createFromFormat('Y-m-d', $nextStart);
                                if ($nextStartObj) {
                                    $segmentEnd = $nextStartObj->modify('-1 day')->format('Y-m-d');
                                }
                            }
                        }

                        // Treat any status except Cancelled (10) and VIEW ONLY (11) as
                        // "work-in-progress" for window qualification.  Projects may go through
                        // Estimate → Prep → Scheduled without ever being marked Active (6) yet
                        // still have legitimate expenses placed before or during those phases.
                        // The score threshold (≥0.70) is the real guard against false matches.
                        if (in_array($code, [10, 11], true)) {
                            continue;
                        }

                        // Cap terminal statuses (7=Complete, 8=Service Call) so a long-finished
                        // project doesn't keep matching expenses years after it ended. Allow a
                        // 1-year tail past start_date for legitimate trailing receipts/refunds,
                        // then expire the segment.
                        if (in_array($code, [7, 8], true)) {
                            $segStartObj = \DateTimeImmutable::createFromFormat('Y-m-d', $segmentStart);
                            if ($segStartObj) {
                                $cap = $segStartObj->modify('+1 year')->format('Y-m-d');
                                if ($cap < $segmentEnd) {
                                    $segmentEnd = $cap;
                                }
                            }
                        }

                        // Overlap check: [segmentStart, segmentEnd] overlaps [startDate, endDate].
                        if ($segmentStart <= $endDate && $segmentEnd >= $startDate) {
                            return true;
                        }
                    }

                    return false;
                };

                $bestActive = null;
                $secondActive = null;
                $bestAny = null;
                $secondAny = null;

                $updateBestSecond = function (&$best, &$second, float $score, int $priority, ?string $statusStart, int $createdAt, int $projectId) use ($isBetterProjectCandidate): void {
                    if ($best === null || $isBetterProjectCandidate($score, $priority, $statusStart, $createdAt, $projectId, $best)) {
                        $second = $best;
                        $best = [
                            'project_id' => $projectId,
                            'score' => $score,
                            'priority' => $priority,
                            'status_start_date' => $statusStart,
                            'created_at' => $createdAt,
                        ];
                        return;
                    }

                    if ($second === null || $isBetterProjectCandidate($score, $priority, $statusStart, $createdAt, $projectId, $second)) {
                        $second = [
                            'project_id' => $projectId,
                            'score' => $score,
                            'priority' => $priority,
                            'status_start_date' => $statusStart,
                            'created_at' => $createdAt,
                        ];
                    }
                };

                foreach ($projectCandidates as $candidate) {
                    // Hard requirement: project must be active near the expense date.
                    if (! $isActiveWithinWindow($candidate['statuses'] ?? [], $windowStart, $windowEnd)) {
                        continue;
                    }

                    $statusAtDate = $projectStatusAtDate($candidate['statuses'] ?? [], $expenseDateString);
                    $statusCode = $statusAtDate['code'] ?? null;

                    // Exclude Cancelled / View-only (per existing business rules).
                    if (in_array($statusCode, [10, 11], true)) {
                        continue;
                    }

                    $priority = $projectStatusPriority((int) ($statusCode ?? 0));
                    $statusStart = $statusAtDate['start_date'] ?? null;
                    $isActiveAtDate = ((int) ($statusCode ?? 0)) === 6;

                    $score = 0.0;
                    foreach (($candidate['variants'] ?? []) as $variant) {
                        $variant = (string) $variant;

                        $score = max($score, $this->similarityScore($po, $variant));

                        $score = max($score, $this->houseNumberOcrBoostScore($po, $variant, $poStreetToken));

                        if ($poStreetToken !== '') {
                            $variantStreetToken = $this->extractStreetToken($variant);
                            if ($variantStreetToken !== '') {
                                $score = max($score, $this->similarityScoreWithOcrFixes($poStreetToken, $variantStreetToken));
                            }
                        }

                        // Also compare individual significant PO tokens against variant's street token.
                        // This handles cases like "M MARCELA" matching "17 N Marcella Rd" where
                        // "marcela" should match "marcella" even without a house number prefix.
                        $variantStreetToken = $this->extractStreetToken($variant);
                        if ($variantStreetToken !== '') {
                            $poTokens = array_filter(explode(' ', $po), fn ($t) => mb_strlen(trim($t)) >= 3);
                            foreach ($poTokens as $poToken) {
                                $score = max($score, $this->similarityScoreWithOcrFixes(trim($poToken), $variantStreetToken));
                            }
                        }
                    }

                    $candidateProjectId = (int) ($candidate['id'] ?? 0);
                    $candidateCreatedAt = (int) ($candidate['created_at'] ?? 0);

                    if ($candidateProjectId <= 0) {
                        continue;
                    }

                    if ($isActiveAtDate) {
                        $updateBestSecond($bestActive, $secondActive, $score, $priority, $statusStart, $candidateCreatedAt, $candidateProjectId);
                    }

                    $updateBestSecond($bestAny, $secondAny, $score, $priority, $statusStart, $candidateCreatedAt, $candidateProjectId);
                }

                $minScore = 0.70;

                $choose = static function (?array $best, ?array $second) use ($minScore): ?array {
                    if (! $best || ((float) ($best['score'] ?? 0)) < $minScore) {
                        return null;
                    }

                    $ambiguous = false;
                    if ($second && (((float) ($best['score'] ?? 0)) - ((float) ($second['score'] ?? 0))) < 0.06) {
                        if (($best['priority'] ?? null) === ($second['priority'] ?? null) && ($best['status_start_date'] ?? null) === ($second['status_start_date'] ?? null)) {
                            $ambiguous = true;
                        }
                    }

                    return [
                        'project_id' => (int) ($best['project_id'] ?? 0),
                        'score' => (float) ($best['score'] ?? 0),
                        'ambiguous' => $ambiguous,
                    ];
                };

                return $choose($bestActive, $secondActive) ?? $choose($bestAny, $secondAny);
            };

            $statsByExpenseVendorId = [];

            $ensureStats = static function (int $expenseVendorId) use (&$statsByExpenseVendorId): void {
                if ($expenseVendorId <= 0) {
                    return;
                }

                if (isset($statsByExpenseVendorId[$expenseVendorId])) {
                    return;
                }

                $statsByExpenseVendorId[$expenseVendorId] = [
                    'candidates_seen' => 0,
                    'candidates_considered' => 0,
                    'valid_po_seen' => 0,
                    'matched_distribution' => 0,
                    'matched_project' => 0,
                    'skipped_no_po' => 0,
                    'skipped_no_match' => 0,
                    'skipped_ambiguous' => 0,
                ];
            };

            $emitDecision = static function (?callable $onDecision, array $payload): void {
                if (! $onDecision) {
                    return;
                }

                $onDecision($payload);
            };

            $filterConditions = [
                '(project_id = 0 OR project_id IS NULL)',
                'distribution_id IS NULL',
                $includeNullSplits ? '(has_splits = false OR has_splits IS NULL)' : 'has_splits = false',
                $includeNullStatus
                    ? "(expense_status = 'No Project' OR expense_status = 'Missing Info' OR expense_status IS NULL)"
                    : "(expense_status = 'No Project' OR expense_status = 'Missing Info')",
            ];

            $updatedExpenseIds = [];

            $perPage = 1000;
            $page = 1;

            while (true) {
                $paginator = Expense::scopedSearchForVendors(
                    $matchableVendorIds,
                    '',
                    $filterConditions,
                    'date',
                    'desc'
                )->paginate($perPage, 'page', $page);

                $pageItems = $paginator->items();
                if ($pageItems === []) {
                    break;
                }

                $candidateIds = [];

                foreach ($pageItems as $item) {
                    $expenseId = (int) (is_array($item) ? ($item['id'] ?? 0) : ($item->id ?? 0));
                    if ($expenseId <= 0) {
                        continue;
                    }

                    $expenseVendorId = (int) (is_array($item) ? ($item['vendor_id'] ?? 0) : ($item->vendor_id ?? 0));
                    $ensureStats($expenseVendorId);

                    if ($expenseVendorId > 0) {
                        $statsByExpenseVendorId[$expenseVendorId]['candidates_seen']++;
                    }

                    $candidateIds[] = $expenseId;
                }

                $candidateIds = array_values(array_unique($candidateIds));

                if ($candidateIds === []) {
                    $page++;
                    continue;
                }

                $expenses = Expense::withoutGlobalScopes()
                    ->whereIn('belongs_to_vendor_id', $matchableVendorIds)
                    ->whereIn('id', $candidateIds)
                    ->with(['receipts:id,expense_id,receipt_items,created_at'])
                    ->get(['id', 'belongs_to_vendor_id', 'vendor_id', 'date', 'distribution_id', 'project_id']);

                foreach ($expenses as $expense) {
                    $expenseVendorId = (int) ($expense->vendor_id ?? 0);
                    $ensureStats($expenseVendorId);

                    if ($expenseVendorId > 0) {
                        $statsByExpenseVendorId[$expenseVendorId]['candidates_considered']++;
                    }

                    if (! is_null($expense->distribution_id)) {
                        continue;
                    }

                    $purchaseOrderCandidates = $this->extractPurchaseOrderCandidates($expense);

                    if ($purchaseOrderCandidates === []) {
                        $emitDecision($onDecision, [
                            'belongs_to_vendor_id' => (int) $hiveVendor->id,
                            'expense_vendor_id' => (int) $expenseVendorId,
                            'expense_id' => (int) $expense->id,
                            'purchase_order' => '',
                            'result' => 'skipped',
                            'reason' => 'no_po',
                        ]);

                        if ($expenseVendorId > 0) {
                            $statsByExpenseVendorId[$expenseVendorId]['skipped_no_po']++;
                        }
                        continue;
                    }

                    if ($expenseVendorId > 0) {
                        $statsByExpenseVendorId[$expenseVendorId]['valid_po_seen']++;
                    }

                    if ($distributionIndex === null) {
                        $distributions = Distribution::withoutGlobalScopes()
                            ->where('vendor_id', $hiveVendor->id)
                            ->select(['id', 'name'])
                            ->get();

                        $distributionNameById = $distributions
                            ->mapWithKeys(fn (Distribution $d) => [(int) $d->id => (string) ($d->name ?? '')])
                            ->all();

                        $distributionIndex = $distributions
                            ->map(function (Distribution $distribution): ?array {
                                $normalized = $this->normalizeText((string) ($distribution->name ?? ''));
                                if ($normalized === '') {
                                    return null;
                                }

                                return [
                                    'id' => (int) $distribution->id,
                                    'normalized' => $normalized,
                                    'name' => (string) ($distribution->name ?? ''),
                                ];
                            })
                            ->filter()
                            ->values()
                            ->all();
                    }

                    $best = null;
                    $bestPurchaseOrder = null;
                    $ambiguousBest = null;
                    $ambiguousPurchaseOrder = null;

                    foreach ($purchaseOrderCandidates as $purchaseOrder) {
                        $distributionMatch = $distributionIndex !== []
                            ? $this->matchPurchaseOrderToDistribution($purchaseOrder, $distributionIndex)
                            : null;
                        $projectMatch = $matchPurchaseOrderToProjectAtDate($purchaseOrder, $expense->date);

                        $candidateBest = $this->pickBestMatch($distributionMatch, $projectMatch);

                        if (! $candidateBest) {
                            continue;
                        }

                        if (($candidateBest['ambiguous'] ?? false) === true) {
                            if ($ambiguousBest === null) {
                                $ambiguousBest = $candidateBest;
                                $ambiguousPurchaseOrder = $purchaseOrder;
                            }
                            continue;
                        }

                        $best = $candidateBest;
                        $bestPurchaseOrder = $purchaseOrder;
                        break;
                    }

                    if (! $best && $ambiguousBest) {
                        $best = $ambiguousBest;
                        $bestPurchaseOrder = $ambiguousPurchaseOrder;
                    }

                    if (! $best) {
                        $emitDecision($onDecision, [
                            'belongs_to_vendor_id' => (int) $hiveVendor->id,
                            'expense_vendor_id' => (int) $expenseVendorId,
                            'expense_id' => (int) $expense->id,
                            'purchase_order' => implode(' | ', $purchaseOrderCandidates),
                            'result' => 'skipped',
                            'reason' => 'no_match',
                        ]);

                        if ($expenseVendorId > 0) {
                            $statsByExpenseVendorId[$expenseVendorId]['skipped_no_match']++;
                        }
                        continue;
                    }

                    if ($best['ambiguous']) {
                        $matchedName = '';
                        if ($best['type'] === 'distribution') {
                            $matchedName = (string) ($distributionNameById[(int) $best['id']] ?? '');
                        } elseif ($best['type'] === 'project') {
                            $matchedName = (string) ($projectDisplayById[(int) $best['id']] ?? '');
                        }

                        $emitDecision($onDecision, [
                            'belongs_to_vendor_id' => (int) $hiveVendor->id,
                            'expense_vendor_id' => (int) $expenseVendorId,
                            'expense_id' => (int) $expense->id,
                            'purchase_order' => (string) ($bestPurchaseOrder ?? ''),
                            'result' => 'skipped',
                            'reason' => 'ambiguous',
                            'matched_type' => (string) ($best['type'] ?? ''),
                            'matched_id' => (int) ($best['id'] ?? 0),
                            'matched_name' => $matchedName,
                            'score' => (float) ($best['score'] ?? 0),
                        ]);

                        if ($expenseVendorId > 0) {
                            $statsByExpenseVendorId[$expenseVendorId]['skipped_ambiguous']++;
                        }
                        continue;
                    }

                    if ($best['type'] === 'distribution') {
                        $matchedName = (string) ($distributionNameById[(int) $best['id']] ?? '');
                        $emitDecision($onDecision, [
                            'belongs_to_vendor_id' => (int) $hiveVendor->id,
                            'expense_vendor_id' => (int) $expenseVendorId,
                            'expense_id' => (int) $expense->id,
                            'purchase_order' => (string) ($bestPurchaseOrder ?? ''),
                            'result' => 'matched',
                            'reason' => null,
                            'matched_type' => 'distribution',
                            'matched_id' => (int) $best['id'],
                            'matched_name' => $matchedName,
                            'score' => (float) ($best['score'] ?? 0),
                        ]);

                        $affected = Expense::withoutGlobalScopes()
                            ->whereIn('belongs_to_vendor_id', $matchableVendorIds)
                            ->where('id', $expense->id)
                            ->where(function ($query) {
                                $query->whereNull('distribution_id')->orWhere('distribution_id', 0);
                            })
                            ->update(['distribution_id' => $best['id']]);

                        if ($affected > 0) {
                            $updatedExpenseIds[] = (int) $expense->id;
                        }

                        if ($expenseVendorId > 0) {
                            $statsByExpenseVendorId[$expenseVendorId]['matched_distribution']++;
                        }

                        continue;
                    }

                    if ($best['type'] === 'project') {
                        $matchedName = (string) ($projectDisplayById[(int) $best['id']] ?? '');
                        $emitDecision($onDecision, [
                            'belongs_to_vendor_id' => (int) $hiveVendor->id,
                            'expense_vendor_id' => (int) $expenseVendorId,
                            'expense_id' => (int) $expense->id,
                            'purchase_order' => (string) ($bestPurchaseOrder ?? ''),
                            'result' => 'matched',
                            'reason' => null,
                            'matched_type' => 'project',
                            'matched_id' => (int) $best['id'],
                            'matched_name' => $matchedName,
                            'score' => (float) ($best['score'] ?? 0),
                        ]);

                        $affected = Expense::withoutGlobalScopes()
                            ->whereIn('belongs_to_vendor_id', $matchableVendorIds)
                            ->where('id', $expense->id)
                            ->where(function ($query) {
                                $query->whereNull('project_id')->orWhere('project_id', 0);
                            })
                            ->update(['project_id' => $best['id']]);

                        if ($affected > 0) {
                            $updatedExpenseIds[] = (int) $expense->id;
                        }

                        if ($expenseVendorId > 0) {
                            $statsByExpenseVendorId[$expenseVendorId]['matched_project']++;
                        }
                    }
                }

                $page++;
            }

            foreach ($statsByExpenseVendorId as $expenseVendorId => $stats) {
                // Default: only report vendors that actually had at least one valid PO candidate.
                // When $summaryAll=true: include all vendors that had any candidate expenses.
                if (! $summaryAll && (($stats['valid_po_seen'] ?? 0) <= 0)) {
                    continue;
                }

                if ($summaryAll && (($stats['candidates_seen'] ?? 0) <= 0)) {
                    continue;
                }

                if (is_callable($onSummary)) {
                    $onSummary([
                        'belongs_to_vendor_id' => (int) $hiveVendor->id,
                        'expense_vendor_id' => (int) $expenseVendorId,
                        'candidates_seen' => (int) ($stats['candidates_seen'] ?? 0),
                        'candidates_considered' => (int) ($stats['candidates_considered'] ?? 0),
                        'valid_po_seen' => (int) ($stats['valid_po_seen'] ?? 0),
                        'matched_distribution' => (int) ($stats['matched_distribution'] ?? 0),
                        'matched_project' => (int) ($stats['matched_project'] ?? 0),
                        'skipped_no_po' => (int) ($stats['skipped_no_po'] ?? 0),
                        'skipped_no_match' => (int) ($stats['skipped_no_match'] ?? 0),
                        'skipped_ambiguous' => (int) ($stats['skipped_ambiguous'] ?? 0),
                    ]);
                }
            }

            if ($updatedExpenseIds !== []) {
                try {
                    Expense::withoutGlobalScopes()
                        ->whereIn('belongs_to_vendor_id', $matchableVendorIds)
                        ->whereIn('id', array_values(array_unique($updatedExpenseIds)))
                        ->get(['id', 'belongs_to_vendor_id'])
                        ->searchable();
                } catch (\Throwable $e) {
                    Log::warning('PO auto-match: failed to sync Scout index for updated expenses', [
                        'belongs_to_vendor_id' => $hiveVendor->id,
                        'updated_count' => count(array_unique($updatedExpenseIds)),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

        }
    }

    /**
     * @param array{distribution_id:int,score:float,ambiguous:bool}|null $distributionMatch
     * @param array{project_id:int,score:float,ambiguous:bool}|null $projectMatch
     * @return array{type:'distribution'|'project',id:int,score:float,ambiguous:bool}|null
     */
    protected function pickBestMatch(?array $distributionMatch, ?array $projectMatch): ?array
    {
        if (! $distributionMatch && ! $projectMatch) {
            return null;
        }

        if ($distributionMatch && ! $projectMatch) {
            return [
                'type' => 'distribution',
                'id' => (int) $distributionMatch['distribution_id'],
                'score' => (float) $distributionMatch['score'],
                'ambiguous' => (bool) ($distributionMatch['ambiguous'] ?? false),
            ];
        }

        if ($projectMatch && ! $distributionMatch) {
            return [
                'type' => 'project',
                'id' => (int) $projectMatch['project_id'],
                'score' => (float) $projectMatch['score'],
                'ambiguous' => (bool) ($projectMatch['ambiguous'] ?? false),
            ];
        }

        $distScore = (float) $distributionMatch['score'];
        $projScore = (float) $projectMatch['score'];

        $distAmbiguous = (bool) ($distributionMatch['ambiguous'] ?? false);
        $projAmbiguous = (bool) ($projectMatch['ambiguous'] ?? false);

        // Treat distribution/project equally:
        // - Don't discard an ambiguous match just because the other type exists.
        // - Only allow a non-ambiguous candidate to win over an ambiguous one if it's clearly better.
        if ($distAmbiguous || $projAmbiguous) {
            // If both are ambiguous, we can't safely auto-match.
            if ($distAmbiguous && $projAmbiguous) {
                return [
                    'type' => $distScore >= $projScore ? 'distribution' : 'project',
                    'id' => $distScore >= $projScore ? (int) $distributionMatch['distribution_id'] : (int) $projectMatch['project_id'],
                    'score' => max($distScore, $projScore),
                    'ambiguous' => true,
                ];
            }

            // If only one side is ambiguous, require a meaningful score lead to trust the other.
            $clearWinDelta = 0.06;

            if ($distAmbiguous && ! $projAmbiguous) {
                if (($projScore - $distScore) >= $clearWinDelta) {
                    return [
                        'type' => 'project',
                        'id' => (int) $projectMatch['project_id'],
                        'score' => $projScore,
                        'ambiguous' => false,
                    ];
                }

                return [
                    'type' => 'distribution',
                    'id' => (int) $distributionMatch['distribution_id'],
                    'score' => $distScore,
                    'ambiguous' => true,
                ];
            }

            if ($projAmbiguous && ! $distAmbiguous) {
                if (($distScore - $projScore) >= $clearWinDelta) {
                    return [
                        'type' => 'distribution',
                        'id' => (int) $distributionMatch['distribution_id'],
                        'score' => $distScore,
                        'ambiguous' => false,
                    ];
                }

                return [
                    'type' => 'project',
                    'id' => (int) $projectMatch['project_id'],
                    'score' => $projScore,
                    'ambiguous' => true,
                ];
            }
        }

        if (abs($distScore - $projScore) < 0.02) {
            return [
                'type' => $distScore >= $projScore ? 'distribution' : 'project',
                'id' => $distScore >= $projScore ? (int) $distributionMatch['distribution_id'] : (int) $projectMatch['project_id'],
                'score' => max($distScore, $projScore),
                'ambiguous' => true,
            ];
        }

        if ($distScore > $projScore) {
            return [
                'type' => 'distribution',
                'id' => (int) $distributionMatch['distribution_id'],
                'score' => $distScore,
                'ambiguous' => false,
            ];
        }

        return [
            'type' => 'project',
            'id' => (int) $projectMatch['project_id'],
            'score' => $projScore,
            'ambiguous' => false,
        ];
    }

    protected function extractPurchaseOrder(Expense $expense): ?string
    {
        return $this->extractPurchaseOrderCandidates($expense)[0] ?? null;
    }

    /**
     * Return ordered PO candidates for matching.
     *
     * Priority:
     * 1) Structured receipt_items.purchase_order
     * 2) receipt_items.handwritten_notes
     *
     * @return array<int, string>
     */
    protected function extractPurchaseOrderCandidates(Expense $expense): array
    {
        if (! $expense->relationLoaded('receipts')) {
            $expense->load('receipts');
        }

        $receipts = $expense->receipts
            ->sortByDesc(fn ($r) => $r->created_at ?? $r->id)
            ->values();

        $candidates = [];
        $seen = [];

        $pushCandidate = function (string $value) use (&$candidates, &$seen): void {
            $key = mb_strtolower(trim($value));
            if ($key === '' || isset($seen[$key])) {
                return;
            }

            $seen[$key] = true;
            $candidates[] = $value;
        };

        $extractValues = function (mixed $raw): array {
            if ($raw === null) {
                return [];
            }

            return is_array($raw) ? $raw : [$raw];
        };

        $collect = function (string $field) use ($receipts, $extractValues, $pushCandidate): void {
            foreach ($receipts as $receipt) {
                $items = $receipt->receipt_items ?? null;
                if (! is_array($items)) {
                    continue;
                }

                $raw = array_key_exists($field, $items)
                    ? $items[$field]
                    : null;

                foreach ($extractValues($raw) as $value) {
                    $candidate = $this->normalizePurchaseOrderCandidate($value);
                    if ($candidate !== null) {
                        $pushCandidate($candidate);
                    }
                }
            }
        };

        // Global priority across all receipts: purchase_order first, then handwritten_notes.
        $collect('purchase_order');
        $collect('handwritten_notes');

        // Extract site/job addresses from receipt content as fallback candidates.
        foreach ($receipts as $receipt) {
            $content = $receipt->receipt_items['raw_content']
                ?? $receipt->receipt_html
                ?? '';

            if ($content === '') {
                continue;
            }

            foreach ($this->extractSiteAddresses($content) as $address) {
                $pushCandidate($address);
            }
        }

        return $candidates;
    }

    /**
     * Extract site/job addresses from receipt text content.
     *
     * @return array<int, string>
     */
    protected function extractSiteAddresses(string $content): array
    {
        $addresses = [];

        // Pattern 1: "SiteAddress: <street>" + optional "Site City: <city>" + "Site State: <state>"
        if (preg_match('/SiteAddress:\s*(.+?)$/mi', $content, $m)) {
            $street = trim($m[1]);
            $city = '';
            $state = '';
            if (preg_match('/Site\s*City:\s*(.+?)$/mi', $content, $cm)) {
                $city = trim($cm[1]);
            }
            if (preg_match('/Site\s*State:\s*(\w{2})/mi', $content, $sm)) {
                $state = trim($sm[1]);
            }
            $full = $street;
            if ($city !== '') {
                $full .= ', ' . $city;
            }
            if ($state !== '') {
                $full .= ', ' . $state;
            }
            if ($street !== '') {
                $addresses[] = $full;
                // Also add just the street for matching flexibility
                if ($full !== $street) {
                    $addresses[] = $street;
                }
            }
        }

        // Pattern 2: "Job Address: <street>"
        if (preg_match('/Job\s*Address:\s*(.+?)$/mi', $content, $m)) {
            $street = trim($m[1]);
            if ($street !== '' && ! in_array($street, $addresses, true)) {
                $addresses[] = $street;
            }
        }

        // Pattern 3: "SHIP TO:" or "SOLD TO:" followed by optional name line then street address.
        // Handles multi-page receipts (e.g. Studio 41/Kohler Store) where the customer delivery
        // address appears under a "SOLD TO:" / "SHIP TO:" label with no explicit "SiteAddress:" key.
        // We look for a line starting with a house number directly after the label (with one
        // optional intermediate name line and any number of blank lines in between).
        if (preg_match_all(
            '/(?:SHIP|SOLD)\s+TO\s*:[ \t]*\n(?:[ \t]*\n)*(?:[^\d\n][^\n]*\n+)?(\d+\s+\S[^\n]+)/im',
            $content,
            $deliveryMatches
        )) {
            foreach ($deliveryMatches[1] as $street) {
                $street = trim($street);
                if ($street !== '' && ! in_array($street, $addresses, true)) {
                    $addresses[] = $street;
                }
            }
        }

        return $addresses;
    }

    protected function normalizePurchaseOrderCandidate(mixed $value): ?string
    {
        if (is_null($value)) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        $text = str_replace('|', ',', $text);
        $first = trim(explode(',', $text)[0] ?? '');

        if ($first === '') {
            return null;
        }

        $firstWithoutSpaces = preg_replace('/\s+/', '', $first);
        $moneyCandidate = $firstWithoutSpaces === null ? '' : $firstWithoutSpaces;
        $moneyCandidate = str_replace(',', '', $moneyCandidate);

        // Reject obvious currency/amount values like "$44.60" or "44.60".
        if (preg_match('/^\$?\d{1,6}(?:\.\d{2})$/', $moneyCandidate)) {
            return null;
        }

        $normalizedFirst = $this->normalizeText($first);

        $length = mb_strlen($normalizedFirst);

        // Too-short POs (e.g. "A", "PP") are almost always noise.
        // Keep 2- or 3-digit numeric POs (e.g. "17", "901"), which are common in this dataset.
        if ($length <= 1) {
            return null;
        }

        if ($length === 2 && ! preg_match('/^\d{2}$/', $normalizedFirst)) {
            return null;
        }

        if ($length === 3 && ! preg_match('/^\d{3}$/', $normalizedFirst)) {
            return null;
        }

        if (in_array($normalizedFirst, ['missing po', 'missing purchase order'], true)) {
            return null;
        }

        // Reject generic single-word POs that frequently appear on receipts but
        // carry no project-identifying signal. These tokens cause false positives
        // because they often happen to be substrings of legitimate project names
        // or addresses (e.g. PO="stock" matching project address "308 Comstock Dr").
        $genericSingleWordPos = [
            'stock', 'instock', 'in stock',
            'will call', 'willcall', 'pickup', 'pick up', 'pick-up',
            'cash', 'cashsale', 'cash sale', 'walk in', 'walkin', 'walk-in',
            'counter', 'counter sale', 'countersale',
            'none', 'na', 'nan', 'null', 'tba', 'tbd', 'unknown',
            'office', 'shop', 'warehouse', 'inventory', 'stocking',
            'sample', 'samples', 'test', 'demo',
        ];
        if (in_array($normalizedFirst, $genericSingleWordPos, true)) {
            return null;
        }

        return $first;
    }

    /**
     * @param array<int, array{id:int,normalized:string}> $distributionIndex
     * @return array{distribution_id:int,score:float,ambiguous:bool}|null
     */
    protected function matchPurchaseOrderToDistribution(string $purchaseOrder, array $distributionIndex): ?array
    {
        $po = $this->normalizeText($purchaseOrder);

        if ($po === '') {
            return null;
        }

        $poVariants = [$po];
        if (str_starts_with($po, 'not ')) {
            $stripped = trim(substr($po, 4));
            if ($stripped !== '') {
                $poVariants[] = $stripped;
            }
        }

        $best = null;
        $second = null;

        foreach ($distributionIndex as $distribution) {
            $score = 0.0;
            foreach ($poVariants as $candidatePo) {
                $score = max($score, $this->similarityScore($candidatePo, $distribution['normalized']));
            }

            // Also compare individual significant PO tokens against distribution name tokens.
            // This handles cases like "Grey" matching "Greg Home" where
            // "grey" should match "greg" even though the full strings differ.
            $distTokens = array_filter(explode(' ', $distribution['normalized']), fn ($t) => mb_strlen(trim($t)) >= 3);
            $poTokens = array_filter(explode(' ', $po), fn ($t) => mb_strlen(trim($t)) >= 3);
            foreach ($poTokens as $poToken) {
                foreach ($distTokens as $distToken) {
                    $score = max($score, $this->tokenSimilarityScore(trim($poToken), trim($distToken)));
                }
            }

            if ($best === null || $score > $best['score']) {
                $second = $best;
                $best = [
                    'distribution_id' => $distribution['id'],
                    'score' => $score,
                ];
                continue;
            }

            if ($second === null || $score > $second['score']) {
                $second = [
                    'distribution_id' => $distribution['id'],
                    'score' => $score,
                ];
            }
        }

        if (! $best) {
            return null;
        }

        $minScore = 0.70;
        if ($best['score'] < $minScore) {
            return null;
        }

        $ambiguous = false;
        if ($second && ($best['score'] - $second['score']) < 0.06) {
            $ambiguous = true;
        }

        return [
            'distribution_id' => (int) $best['distribution_id'],
            'score' => (float) $best['score'],
            'ambiguous' => $ambiguous,
        ];
    }

    /**
     * @param array<int, array{id:int,variants:array<int, string>}> $projectIndex
     * @return array{project_id:int,score:float,ambiguous:bool}|null
     */
    protected function matchPurchaseOrderToProject(string $purchaseOrder, array $projectIndex): ?array
    {
        $po = $this->normalizeText($purchaseOrder);

        if ($po === '' || $projectIndex === []) {
            return null;
        }

        $best = null;
        $second = null;

        foreach ($projectIndex as $project) {
            $score = 0.0;
            foreach (($project['variants'] ?? []) as $variant) {
                $score = max($score, $this->similarityScore($po, (string) $variant));
            }

            if ($best === null || $score > $best['score']) {
                $second = $best;
                $best = [
                    'project_id' => (int) $project['id'],
                    'score' => $score,
                ];
                continue;
            }

            if ($second === null || $score > $second['score']) {
                $second = [
                    'project_id' => (int) $project['id'],
                    'score' => $score,
                ];
            }
        }

        if (! $best) {
            return null;
        }

        $minScore = 0.70;
        if (($best['score'] ?? 0) < $minScore) {
            return null;
        }

        $ambiguous = false;
        if ($second && (($best['score'] - $second['score']) < 0.06)) {
            $ambiguous = true;
        }

        return [
            'project_id' => (int) $best['project_id'],
            'score' => (float) $best['score'],
            'ambiguous' => $ambiguous,
        ];
    }

    protected function similarityScore(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }

        return max(0.0, min(1.0, $this->rawSimilarityScore($a, $b)));
    }

    protected function similarityScoreWithOcrFixes(string $a, string $b): float
    {
        $score = $this->similarityScore($a, $b);

        // Only apply OCR-specific fixes to single-token comparisons.
        if (str_contains($a, ' ') || str_contains($b, ' ')) {
            return $score;
        }

        $aCollapsed = $this->collapseRepeatedLetters($a);
        $bCollapsed = $this->collapseRepeatedLetters($b);

        if ($aCollapsed !== $a || $bCollapsed !== $b) {
            $score = max(
                $score,
                $this->similarityScore($aCollapsed, $b),
                $this->similarityScore($a, $bCollapsed),
                $this->similarityScore($aCollapsed, $bCollapsed),
            );
        }

        return $score;
    }

    protected function tokenSimilarityScore(string $a, string $b): float
    {
        $a = trim($a);
        $b = trim($b);

        if ($a === '' || $b === '') {
            return 0.0;
        }

        $score = $this->similarityScoreWithOcrFixes($a, $b);

        // OCR often mangles the tail of a token while keeping the prefix.
        // If the first 3+ characters match and edit distance is small, boost above threshold.
        if ($score < 0.70) {
            $minPrefix = 3;
            if (strlen($a) >= 5 && strlen($b) >= 5 && substr($a, 0, $minPrefix) === substr($b, 0, $minPrefix)) {
                $distance = levenshtein($a, $b);
                $maxLen = max(strlen($a), strlen($b));

                if ($maxLen > 0 && $distance <= 2) {
                    $score = max($score, 0.80);
                }
            }
        }

        return $score;
    }

    protected function houseNumberOcrBoostScore(string $normalizedPo, string $normalizedVariant, string $poStreetToken): float
    {
        if (! $this->hasHouseNumberPrefix($normalizedPo)) {
            return 0.0;
        }

        $poNumber = $this->extractLeadingNumber($normalizedPo);
        $variantNumber = $this->extractLeadingNumber($normalizedVariant);

        if ($poNumber === null || $variantNumber === null) {
            return 0.0;
        }

        if (! $this->numbersAreOcrClose($poNumber, $variantNumber)) {
            return 0.0;
        }

        $variantStreetToken = $this->extractStreetToken($normalizedVariant);

        // When OCR is messy, require at least some street-name signal.
        // Use a cheap character overlap heuristic as a backstop.
        $overlap = ($poStreetToken !== '' && $variantStreetToken !== '')
            ? $this->letterOverlapScore($poStreetToken, $variantStreetToken)
            : 0.0;

        if ($overlap >= 0.70) {
            return 0.92;
        }

        if ($overlap >= 0.55) {
            return 0.86;
        }

        return 0.0;
    }

    protected function extractLeadingNumber(string $normalizedText): ?string
    {
        if ($normalizedText === '') {
            return null;
        }

        if (! preg_match('/^(\d+)/', $normalizedText, $m)) {
            return null;
        }

        $num = $m[1] ?? null;

        return is_string($num) && $num !== '' ? $num : null;
    }

    protected function numbersAreOcrClose(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        // Only consider short house numbers.
        if (strlen($a) < 2 || strlen($a) > 5 || strlen($b) < 2 || strlen($b) > 5) {
            return false;
        }

        $confusable = [
            '0' => ['8'],
            '1' => ['7'],
            '2' => ['4'],
            '4' => ['2'],
            '5' => ['6'],
            '6' => ['5'],
            '7' => ['1'],
            '8' => ['0'],
        ];

        // Handle OCR dropping leading digit(s): "46" matching "126" or "146"
        // Check if the shorter number is a suffix of the longer after allowing
        // confusable substitutions on the overlapping portion.
        if (strlen($a) !== strlen($b)) {
            $short = strlen($a) < strlen($b) ? $a : $b;
            $long = strlen($a) < strlen($b) ? $b : $a;

            // Only allow 1-digit drop (difference of 1 in length).
            if (strlen($long) - strlen($short) !== 1) {
                return false;
            }

            // Check if suffix of long matches short with confusables.
            $suffix = substr($long, 1);
            if ($this->numbersMatchWithConfusables($short, $suffix, $confusable)) {
                return true;
            }

            return false;
        }

        return $this->numbersMatchWithConfusables($a, $b, $confusable);
    }

    /**
     * Check if two same-length digit strings match allowing OCR confusables.
     *
     * @param array<string, list<string>> $confusable
     */
    protected function numbersMatchWithConfusables(string $a, string $b, array $confusable): bool
    {
        if (strlen($a) !== strlen($b)) {
            return false;
        }

        $len = strlen($a);
        for ($i = 0; $i < $len; $i++) {
            $da = $a[$i];
            $db = $b[$i];

            if ($da === $db) {
                continue;
            }

            if (! isset($confusable[$da]) || ! in_array($db, $confusable[$da], true)) {
                return false;
            }
        }

        return true;
    }

    protected function letterOverlapScore(string $a, string $b): float
    {
        $a = preg_replace('/[^a-z]/', '', strtolower($a)) ?? '';
        $b = preg_replace('/[^a-z]/', '', strtolower($b)) ?? '';

        if ($a === '' || $b === '') {
            return 0.0;
        }

        $aSet = array_values(array_unique(str_split($a)));
        $bSet = array_values(array_unique(str_split($b)));

        $intersection = array_values(array_intersect($aSet, $bSet));

        $denom = max(count($aSet), count($bSet));
        if ($denom <= 0) {
            return 0.0;
        }

        return count($intersection) / $denom;
    }

    protected function collapseRepeatedLetters(string $value): string
    {
        if ($value === '') {
            return '';
        }

        // Example: "marcella" -> "marcela" (helps with OCR variations).
        return preg_replace('/([a-z])\\1+/i', '$1', $value) ?? $value;
    }

    protected function rawSimilarityScore(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }

        $aNumber = null;
        $bNumber = null;

        if (preg_match('/^\d+/', $a, $mA)) {
            $aNumber = $mA[0];
        }

        if (preg_match('/^\d+/', $b, $mB)) {
            $bNumber = $mB[0];
        }

        if ($aNumber !== null && $bNumber !== null) {
            if ($aNumber === $bNumber) {
                return 0.92;
            }

            if (str_starts_with($aNumber, $bNumber) || str_starts_with($bNumber, $aNumber)) {
                return 0.30;
            }
        }

        if ($a === $b) {
            return 1.0;
        }

        if (str_contains($a, $b) || str_contains($b, $a)) {
            $lenA = strlen($a);
            $lenB = strlen($b);
            $max = max($lenA, $lenB);
            $min = min($lenA, $lenB);

            if ($max === 0) {
                return 0.0;
            }

            $ratio = $min / $max;
            $score = 0.86 + (0.14 * $ratio);

            return max(0.0, min(1.0, $score));
        }

        $distance = levenshtein($a, $b);
        $maxLen = max(strlen($a), strlen($b));

        if ($maxLen === 0) {
            return 0.0;
        }

        $score = 1.0 - ($distance / $maxLen);

        return max(0.0, min(1.0, $score));
    }

    protected function normalizeText(string $value): string
    {
        $value = strtolower(trim($value));

        if ($value === '') {
            return '';
        }

        $value = str_replace('&', ' and ', $value);
        $value = preg_replace('/[^a-z0-9\s]/', ' ', $value) ?? '';
        $value = preg_replace('/\s+/', ' ', $value) ?? '';

        return trim($value);
    }

    protected function hasHouseNumberPrefix(string $normalizedText): bool
    {
        $value = trim($normalizedText);
        if ($value === '') {
            return false;
        }

        $parts = array_values(array_filter(explode(' ', $value), fn (string $p) => trim($p) !== ''));
        if (count($parts) < 2) {
            return false;
        }

        $first = (string) ($parts[0] ?? '');
        if ($first === '') {
            return false;
        }

        if (ctype_digit($first)) {
            return true;
        }

        // OCR sometimes turns numbers into letters (e.g. "17" -> "iz" / "l7" / "o7").
        // Treat short, digit-like tokens as a house-number prefix.
        if (strlen($first) <= 4 && preg_match('/^[0-9ilozt]+$/', $first)) {
            return true;
        }

        return false;
    }

    protected function extractStreetToken(string $normalizedText): string
    {
        $value = trim($normalizedText);
        if ($value === '') {
            return '';
        }

        $ignore = [
            'n', 's', 'e', 'w', 'ne', 'nw', 'se', 'sw',
            'rd', 'road', 'st', 'street', 'ave', 'avenue', 'dr', 'drive',
            'ln', 'lane', 'blvd', 'boulevard', 'ct', 'court', 'cir', 'circle',
            'pl', 'place', 'way',
        ];

        $tokens = array_values(array_filter(explode(' ', $value), fn (string $p) => trim($p) !== ''));

        foreach ($tokens as $i => $token) {
            $token = trim($token);

            if ($token === '') {
                continue;
            }

            // Skip leading house number tokens (including OCR'd variants like "iz").
            if ($i === 0) {
                if (ctype_digit($token)) {
                    continue;
                }

                if (strlen($token) <= 4 && preg_match('/^[0-9ilozt]+$/', $token)) {
                    continue;
                }
            }

            if (ctype_digit($token) || in_array($token, $ignore, true)) {
                continue;
            }

            return $token;
        }

        return '';
    }
}
