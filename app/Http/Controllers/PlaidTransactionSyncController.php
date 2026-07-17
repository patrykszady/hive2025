<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Services\PlaidService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use App\Support\ApiErrorFormatter;

class PlaidTransactionSyncController extends Controller
{
    public function __construct(private PlaidService $plaidService) {}

    public function syncAllBanks(): void
    {
        $banks = Bank::withoutGlobalScopes()->whereNotNull('plaid_access_token')->get();
        foreach ($banks as $bank) {
            if (!$bank->vendor?->registration_date) {
                Log::channel('plaid_skips')->error('Bank sync skipped: missing vendor registration_date', [
                    'bank_id' => $bank->id,
                    'vendor_id' => $bank->vendor?->id,
                ]);
                continue;
            }
            if ($bank->plaid_options['error']['error_code'] ?? false) {
                continue;
            }
            $this->syncBank($bank);
        }
    }

    public function syncBank(Bank $bank): void
    {
        $savedCursor = $bank->plaid_options['next_cursor'] ?? null;
        $currentCursor = $savedCursor ?: null;
        $bankAccountIds = $bank->institution_accounts->pluck('id')->toArray();
        $lastBankTransaction = Transaction::whereIn('bank_account_id', $bankAccountIds)->latest('transaction_date')->first();

        $page = 0;
        $maxPages = 50;
        $restarted = false;
        $finalNextCursor = $savedCursor;
        $transactionsStartDate = null;
        $balancesUpdated = false;

        while (true) {
            $page++;
            if ($page > $maxPages) {
                Log::channel('plaid_skips')->error('SYNC pagination aborted: max pages exceeded', [
                    'bank_id' => $bank->id,
                    'start_cursor' => $savedCursor,
                    'request_id' => null,
                ]);
                break;
            }

            $result = $this->plaidService->syncTransactions($bank->plaid_access_token, $currentCursor, 200);

            if (array_key_exists('error_code', $result)) {
                $code = $result['error_code'];
                Log::channel('plaid_skips')->error('SYNC page error', [
                    'bank_id' => $bank->id,
                    'error' => $result,
                    'cursor_used' => $currentCursor,
                    'request_id' => $result['request_id'] ?? null,
                ]);
                if (!$restarted && in_array($code, ['TRANSACTIONS_SYNC_MUTATION_DURING_PAGINATION','INVALID_CURSOR'])) {
                    $restarted = true;
                    $currentCursor = $savedCursor ?: null;
                    $page = 0;
                    continue;
                }
                break;
            }

            $status = $result['transactions_update_status'];
            if (!$balancesUpdated && isset($result['accounts'])) {
                $this->updateBankAccountBalances($result['accounts']);
                $balancesUpdated = true;
            }

            if ($transactionsStartDate === null) {
                if ($status === 'HISTORICAL_UPDATE_COMPLETE' && $lastBankTransaction?->transaction_date) {
                    $transactionsStartDate = $lastBankTransaction->transaction_date->copy()->subWeeks(3)->toDateString();
                } else {
                    // Check if bank has a specific transactions_start_date set (for newly linked banks)
                    $configuredStartDate = $bank->plaid_options['transactions_start_date'] ?? null;
                    
                    if ($configuredStartDate) {
                        $transactionsStartDate = $configuredStartDate;
                        Log::channel('plaid_adds')->info('Using configured transactions_start_date from bank options', [
                            'bank_id' => $bank->id,
                            'bank_name' => $bank->name,
                            'transactions_start_date' => $transactionsStartDate,
                        ]);
                    } elseif ($bank->created_at->isToday()) {
                        // For newly created banks (created today), only fetch transactions from today
                        $transactionsStartDate = Carbon::today()->toDateString();
                        Log::channel('plaid_adds')->info('New bank detected - limiting transactions to today', [
                            'bank_id' => $bank->id,
                            'bank_name' => $bank->name,
                            'created_at' => $bank->created_at->toDateTimeString(),
                            'transactions_start_date' => $transactionsStartDate,
                        ]);
                    } else {
                        $regDate = $bank->vendor->registration_date;
                        $oneYearAgo = Carbon::now()->subYear();
                        $transactionsStartDate = $regDate->lt($oneYearAgo)
                            ? $oneYearAgo->toDateString()
                            : $regDate->toDateString();
                    }
                }
            }

            if ($transactionsStartDate) {
                $result['added'] = $this->filterTransactionsByStartDate($result['added'], $transactionsStartDate);
                $result['modified'] = $this->filterTransactionsByStartDate($result['modified'], $transactionsStartDate);
            }

            if (!empty($result['added']) || !empty($result['modified']) || !empty($result['removed'])) {
                Log::channel('plaid_adds')->info('SYNC bank_id ' . $bank->id, [
                    'bank_id' => $bank->id,
                    'page' => $page,
                    'cursor_used' => $currentCursor,
                    'start_date' => $transactionsStartDate,
                    'has_more' => $result['has_more'] ?? null,
                    'update_status' => $status,
                    'request_id' => $result['request_id'] ?? null,
                    'added_after_date' => count($result['added']),
                    'modified_after_date' => count($result['modified']),
                    'removed' => count($result['removed']),
                    'result' => $result
                ]);
            }

            $accountSubtypeMap = [];
            foreach (($result['accounts'] ?? []) as $accInfo) {
                if (isset($accInfo['account_id'])) {
                    $accountSubtypeMap[$accInfo['account_id']] = $accInfo['subtype'];
                }
            }

            $addedDebug = $this->collectPageGroup(
                label: 'added',
                bank: $bank,
                page: $page,
                cursor: $currentCursor,
                requestId: $result['request_id'],
                items: $result['added'],
                accountSubtypeMap: $accountSubtypeMap,
                handler: fn($tx, $subtype) => $this->processAddedTransaction($bank, $tx, $subtype, $result['request_id'])
            );

            $modifiedDebug = $this->collectPageGroup(
                label: 'modified',
                bank: $bank,
                page: $page,
                cursor: $currentCursor,
                requestId: $result['request_id'],
                items: $result['modified'],
                accountSubtypeMap: $accountSubtypeMap,
                handler: fn($tx, $subtype) => $this->processModifiedTransaction($bank, $tx, $subtype, $result['request_id'])
            );

            $removedDebug = $this->collectPageGroup(
                label: 'removed',
                bank: $bank,
                page: $page,
                cursor: $currentCursor,
                requestId: $result['request_id'],
                items: $result['removed'],
                accountSubtypeMap: $accountSubtypeMap,
                handler: fn($tx, $subtype) => $this->processRemovedTransaction($bank, $tx, $subtype, $result['request_id'])
            );

            if (!empty($addedDebug) || !empty($modifiedDebug) || !empty($removedDebug)) {
                Log::channel('plaid_adds')->info('SYNC page aggregates bank_id ' . $bank->id, [
                    'bank_id' => $bank->id,
                    'page' => $page,
                    'cursor_used' => $currentCursor,
                    'request_id' => $result['request_id'],
                    'added_debug' => $addedDebug ?: null,
                    'modified_debug' => $modifiedDebug ?: null,
                    'removed_debug' => $removedDebug ?: null,
                ]);
            }

            $finalNextCursor = $result['next_cursor'] ?? $currentCursor;
            $currentCursor = $finalNextCursor;

            if (($result['has_more'] ?? false) !== true) {
                break;
            }
        }

        $plaidOptions = $bank->plaid_options;
        $plaidOptions['next_cursor'] = $finalNextCursor;
        $bank->plaid_options = $plaidOptions;
        $bank->save();
    }

    /**
     * Collect aggregate records for a group (added|modified|removed) via handler returning array|null.
     */
    private function collectPageGroup(string $label, Bank $bank, int $page, ?string $cursor, string $requestId, array $items, array $accountSubtypeMap, callable $handler): array
    {
        if (empty($items)) { return []; }
        $records = [];
        foreach ($items as $payload) {
            $subtype = $accountSubtypeMap[$payload['account_id']] ?? 'unknown';
            try {
                $res = $handler($payload, $subtype);
                if ($res) { $records[] = $res; }
            } catch (\Throwable $e) {
                Log::channel('plaid_skips')->error('Group handler exception', ApiErrorFormatter::format($e, [
                    'label' => $label,
                    'bank_id' => $bank->id,
                    'request_id' => $requestId,
                ]));
            }
        }
        return $records;
    }

    private function processAddedTransaction(Bank $bank, array $newTransaction, string $accountType, string $requestId): ?array
    {
        try {
            $plaidTransactionId = $newTransaction['transaction_id'];
            $pendingPlaidTransactionId = $newTransaction['pending_transaction_id'] ?? null;
            $amount = $newTransaction['amount'];
            // Prefer precise account match by Plaid account_id
            $targetAccountId = BankAccount::where('plaid_account_id', $newTransaction['account_id'])->value('id');
            if ($targetAccountId) {
                $institutionAccountIds = collect([$targetAccountId]);
            } else {
                $institutionAccountIds = $bank->institution_accounts()->ofSubtype($accountType)->pluck('bank_accounts.id');
                if ($institutionAccountIds->isEmpty()) {
                    // Fallback to any institution account for this bank
                    Log::channel('plaid_skips')->info('Subtype account match empty; falling back to all institution accounts', [
                        'bank_id' => $bank->id,
                        'account_type' => $accountType,
                        'request_id' => $requestId,
                    ]);
                    $institutionAccountIds = $bank->institution_accounts()->pluck('bank_accounts.id');
                }
            }
            [$candidates] = $this->resolveCandidateTransactions($institutionAccountIds, $plaidTransactionId, $pendingPlaidTransactionId);
            if ($candidates->count() === 0) {
                $heuristic = $this->chooseBestHeuristicCandidate(
                    $this->findHeuristicCandidates($institutionAccountIds, $newTransaction),
                    $newTransaction
                );
                if ($heuristic) { $candidates = collect([$heuristic]); }
            }
            $match = $this->evaluateDeterministicMatch($candidates, $amount, $plaidTransactionId, $pendingPlaidTransactionId, $bank->id, 'ADD', $requestId);
            // If ambiguous, fall through to insert as a new transaction (previous behavior)
            if ($match === 'AMBIGUOUS' || $match === 'AMOUNT_MISMATCH') { /* fall-through to insert */ }
            if ($match instanceof Transaction) {
                if (method_exists($match, 'trashed') && $match->trashed()) {
                    // Bring back the soft-deleted pending record to upgrade it
                    $match->restore();
                }
                $matchedTransaction = $match;
                $matchedVia = $this->determineMatchVia($matchedTransaction, $plaidTransactionId, $pendingPlaidTransactionId);
                $upgradeMeta = $this->determineUpgradeDates($matchedTransaction, $newTransaction);
                $this->persistUpgrade($matchedTransaction, $upgradeMeta, $newTransaction, $plaidTransactionId);
                return $this->buildAggregate(
                    status: 'matched_upgrade',
                    transactionId: $matchedTransaction->id,
                    plaidTransactionId: $plaidTransactionId,
                    pendingPlaidTransactionId: $pendingPlaidTransactionId,
                    amount: $amount,
                    payload: $newTransaction,
                    upgradeMeta: $upgradeMeta,
                    matchVia: $matchedVia
                );
            }
            Log::channel('plaid_skips')->info('No match found; inserting new transaction', [
                'bank_id' => $bank->id,
                'request_id' => $requestId,
                'plaid_transaction_id' => $plaidTransactionId,
                'pending_transaction_id' => $pendingPlaidTransactionId,
                'account_id' => $newTransaction['account_id'] ?? null,
                'candidate_ids' => [$plaidTransactionId, $pendingPlaidTransactionId],
                'amount' => $amount,
                'date' => $newTransaction['date'] ?? null,
                'merchant_name' => $newTransaction['merchant_name'] ?? $newTransaction['name'] ?? null,
            ]);
            $transaction = $this->insertNewTransaction($bank, $newTransaction, $amount, $accountType, $institutionAccountIds, $plaidTransactionId);
            return $this->buildAggregate(
                status: 'inserted',
                transactionId: $transaction->id,
                plaidTransactionId: $plaidTransactionId,
                pendingPlaidTransactionId: $pendingPlaidTransactionId,
                amount: $amount,
                payload: $newTransaction
            );
        } catch (\Throwable $e) {
            Log::channel('plaid_skips')->error('ADD Failed processing transaction', ApiErrorFormatter::format($e, [
                'bank_id' => $bank->id,
                'plaid_transaction_id' => $newTransaction['transaction_id'] ?? null,
                'request_id' => $requestId,
            ]));
            return null;
        }
    }

    private function processModifiedTransaction(Bank $bank, array $modTransaction, string $accountType, string $requestId): ?array
    {
        try {
            $plaidTransactionId = $modTransaction['transaction_id'];
            $pendingPlaidTransactionId = $modTransaction['pending_transaction_id'] ?? null;
            $amount = $modTransaction['amount'];
            $targetAccountId = BankAccount::where('plaid_account_id', $modTransaction['account_id'])->value('id');
            if ($targetAccountId) {
                $institutionAccountIds = collect([$targetAccountId]);
            } else {
                $institutionAccountIds = $bank->institution_accounts()->ofSubtype($accountType)->pluck('bank_accounts.id');
                if ($institutionAccountIds->isEmpty()) {
                    Log::channel('plaid_skips')->info('Subtype account match empty (modified); falling back to all institution accounts', [
                        'bank_id' => $bank->id,
                        'account_type' => $accountType,
                        'request_id' => $requestId,
                    ]);
                    $institutionAccountIds = $bank->institution_accounts()->pluck('bank_accounts.id');
                }
            }
            [$candidates] = $this->resolveCandidateTransactions($institutionAccountIds, $plaidTransactionId, $pendingPlaidTransactionId);
            if ($candidates->count() === 0) {
                $heuristic = $this->chooseBestHeuristicCandidate(
                    $this->findHeuristicCandidates($institutionAccountIds, $modTransaction),
                    $modTransaction
                );
                if ($heuristic) { $candidates = collect([$heuristic]); }
            }
            $match = $this->evaluateDeterministicMatch($candidates, $amount, $plaidTransactionId, $pendingPlaidTransactionId, $bank->id, 'MODIFIED', $requestId, false);
            if (!($match instanceof Transaction)) { return null; }
            if (method_exists($match, 'trashed') && $match->trashed()) {
                $match->restore();
            }
            $matchedTransaction = $match;
            $matchedVia = $this->determineMatchVia($matchedTransaction, $plaidTransactionId, $pendingPlaidTransactionId);
            $upgradeMeta = $this->determineUpgradeDates($matchedTransaction, $modTransaction);
            $this->persistUpgrade($matchedTransaction, $upgradeMeta, $modTransaction, $plaidTransactionId);
            return $this->buildAggregate(
                status: 'modified_upgrade',
                transactionId: $matchedTransaction->id,
                plaidTransactionId: $plaidTransactionId,
                pendingPlaidTransactionId: $pendingPlaidTransactionId,
                amount: $amount,
                payload: $modTransaction,
                upgradeMeta: $upgradeMeta,
                matchVia: $matchedVia
            );
        } catch (\Throwable $e) {
            Log::channel('plaid_skips')->error('Failed processing modified transaction', ApiErrorFormatter::format($e, [
                'bank_id' => $bank->id,
                'plaid_transaction_id' => $modTransaction['transaction_id'] ?? null,
                'pending_transaction_id' => $modTransaction['pending_transaction_id'] ?? null,
                'request_id' => $requestId,
            ]));
            return null;
        }
    }

    private function processRemovedTransaction(Bank $bank, array $removedTransaction, ?string $accountType = null, string $requestId): ?array
    {
        try {
            $plaidTransactionId = $removedTransaction['transaction_id'];
            $institutionAccountIds = $bank->institution_accounts()->pluck('bank_accounts.id');
            // Allow matching both active and recently soft-deleted records to avoid double-deletes/races
            $matches = Transaction::withTrashed()
                ->whereIn('bank_account_id', $institutionAccountIds)
                ->where('plaid_transaction_id', $plaidTransactionId)
                ->limit(2)
                ->get();
            if ($matches->isEmpty()) {
                Log::channel('plaid_skips')->info('Removed transaction not found', [
                    'plaid_transaction_id' => $plaidTransactionId,
                    'bank_id' => $bank->id,
                    'request_id' => $requestId,
                    'amount' => $removedTransaction['amount'] ?? null,
                    'date' => $removedTransaction['date'] ?? null,
                    'merchant_name' => $removedTransaction['merchant_name'] ?? $removedTransaction['name'] ?? null,
                ]);
                return null;
            }
            if ($matches->count() > 1) {
                Log::channel('plaid_skips')->error('Removed duplicate plaid_transaction_id matched multiple active transactions - deletion aborted', [
                    'plaid_transaction_id' => $plaidTransactionId,
                    'bank_id' => $bank->id,
                    'transaction_ids' => $matches->pluck('id')->all(),
                    'count' => $matches->count(),
                    'request_id' => $requestId,
                ]);
                return null;
            }
            $t = $matches->first();
            // If any active transaction references this removed transaction as its pending_transaction_id,
            // then this is a legitimate pending->posted upgrade: do NOT delete the pending record.
            $hasUpgradeReference = Transaction::query()
                ->whereIn('bank_account_id', $institutionAccountIds)
                ->whereNull('deleted_at')
                ->whereJsonContains('details->pending_transaction_id', $plaidTransactionId)
                ->exists();
            if ($hasUpgradeReference) {
                Log::channel('plaid_skips')->info('Removed event skipped due to existing upgraded posted transaction referencing pending id', [
                    'plaid_transaction_id' => $plaidTransactionId,
                    'pending_transaction_id' => $plaidTransactionId,
                    'bank_id' => $bank->id,
                    'request_id' => $requestId,
                    'referenced_by_transaction' => true,
                ]);
                return $this->buildAggregate(
                    status: 'removed_skipped_due_to_upgrade',
                    transactionId: $t->id,
                    plaidTransactionId: $plaidTransactionId,
                    pendingPlaidTransactionId: null,
                    amount: (float) $t->amount,
                    payload: [
                        'date' => optional($t->transaction_date)->toDateString(),
                        'authorized_date' => null,
                        'pending' => false,
                    ]
                );
            }
            // If already soft-deleted, do not delete again
            if ($t->deleted_at) {
                Log::channel('plaid_skips')->info('Removed transaction already soft-deleted (skipping)', [
                    'transaction_id' => $t->id,
                    'plaid_transaction_id' => $plaidTransactionId,
                    'bank_id' => $bank->id,
                    'request_id' => $requestId,
                ]);
                return null;
            }
            try {
                $snapshot = [
                    'id' => $t->id,
                    'amount' => $t->amount,
                    'transaction_date' => optional($t->transaction_date)->toDateString(),
                    'posted_date' => optional($t->posted_date)->toDateString(),
                ];
                $t->delete();
                return $this->buildAggregate(
                    status: 'removed_deleted',
                    transactionId: $snapshot['id'],
                    plaidTransactionId: $plaidTransactionId,
                    pendingPlaidTransactionId: null,
                    amount: $snapshot['amount'],
                    payload: [
                        'date' => $snapshot['transaction_date'],
                        'authorized_date' => null,
                        'pending' => false,
                    ],
                    removedMeta: [
                        'transaction_date' => $snapshot['transaction_date'],
                        'posted_date' => $snapshot['posted_date'],
                        'bank_id' => $bank->id,
                    ]
                );
            } catch (\Throwable $inner) {
                Log::channel('plaid_skips')->warning('Removed transaction delete failed', ApiErrorFormatter::format($inner, [
                    'transaction_id' => $t->id,
                    'plaid_transaction_id' => $plaidTransactionId,
                    'bank_id' => $bank->id,
                    'request_id' => $requestId,
                ]));
            }
        } catch (\Throwable $e) {
            Log::channel('plaid_skips')->error('Removed transaction exception', ApiErrorFormatter::format($e, [
                'plaid_transaction_id' => $removedTransaction['transaction_id'] ?? null,
                'bank_id' => $bank->id,
                'request_id' => $requestId,
            ]));
            return null;
        }
    }

    private function resolveCandidateTransactions($institutionAccountIds, string $plaidTransactionId, ?string $pendingPlaidTransactionId)
    {
        $candidateIds = collect([$plaidTransactionId, $pendingPlaidTransactionId])->filter()->unique()->values();
        $candidates = collect();
        if ($candidateIds->isNotEmpty() && $institutionAccountIds->isNotEmpty()) {
            $query = Transaction::withTrashed()
                ->whereIn('bank_account_id', $institutionAccountIds)
                ->where(function ($q) use ($candidateIds) {
                    $q->whereIn('plaid_transaction_id', $candidateIds->all());
                    foreach ($candidateIds as $cid) {
                        $q->orWhereJsonContains('details->pending_transaction_id', $cid);
                    }
                });
            $candidates = $query->get()->unique('id')->values();
        }
        return [$candidates, $candidateIds];
    }

    private function evaluateDeterministicMatch($candidates, float $incomingAmount, string $plaidTransactionId, ?string $pendingPlaidTransactionId, int $bankId, string $type, string $requestId, bool $allowInsertOnNoMatch = true)
    {
        if ($candidates->count() === 1) {
            $candidate = $candidates->first();
            if ((float) $candidate->amount === (float) $incomingAmount) {
                return $candidate;
            }
            // Pending->Posted upgrades can legitimately change amount (tips, finalization, fees).
            // Because candidates were pre-filtered by plaid ids chain (posted id / pending id / details->pending_transaction_id),
            // we accept the upgrade even if the amount changed. We'll update amount during persistUpgrade.
            Log::channel('plaid_adds')->warning(($type === 'ADD' ? 'ADD upgrading with amount change' : 'MODIFIED upgrading with amount change'), [
                'bank_id' => $bankId,
                'candidate_id' => $candidate->id,
                'candidate_amount' => $candidate->amount,
                'new_amount' => $incomingAmount,
                'plaid_transaction_id' => $plaidTransactionId,
                'pending_transaction_id' => $pendingPlaidTransactionId,
                'request_id' => $requestId,
            ]);
            return $candidate;
        } elseif ($candidates->count() > 1) {
            Log::channel('plaid_skips')->warning(($type === 'ADD' ? 'ADDAmbiguous chain skipped' : 'Modified ambiguous chain skipped'), [
                'bank_id' => $bankId,
                'plaid_transaction_id' => $plaidTransactionId,
                'pending_transaction_id' => $pendingPlaidTransactionId,
                'candidate_transaction_ids' => $candidates->pluck('id')->all(),
                'amount' => $incomingAmount,
                'request_id' => $requestId,
            ]);
            return 'AMBIGUOUS';
        }
        if ($type === 'MODIFIED') {
            Log::channel('plaid_skips')->warning('Modified transaction no match (ignored)', [
                'plaid_transaction_id' => $plaidTransactionId,
                'pending_transaction_id' => $pendingPlaidTransactionId,
                'amount' => $incomingAmount,
                'request_id' => $requestId,
            ]);
            return 'NO_MATCH';
        }
        return null;
    }

    private function determineMatchVia(Transaction $matchedTransaction, string $plaidTransactionId, ?string $pendingPlaidTransactionId): string
    {
        if ($matchedTransaction->plaid_transaction_id === $plaidTransactionId) { return 'posted_id_match'; }
        if ($pendingPlaidTransactionId && $matchedTransaction->plaid_transaction_id === $pendingPlaidTransactionId) { return 'pending_id_match'; }
        return 'previous_pending_id';
    }

    private function persistUpgrade(Transaction $matchedTransaction, array $upgradeMeta, array $payload, string $newPlaidTransactionId): void
    {
        if ($upgradeMeta['after_transaction_date'] !== $upgradeMeta['before_transaction_date']) {
            $matchedTransaction->transaction_date = $upgradeMeta['after_transaction_date'];
        }
        if ($upgradeMeta['after_posted_date'] !== $upgradeMeta['before_posted_date']) {
            $matchedTransaction->posted_date = $upgradeMeta['after_posted_date'];
        }
        // Always update amount to incoming amount on upgrade (pending -> posted can change amount)
        if (isset($payload['amount']) && (float) $matchedTransaction->amount !== (float) $payload['amount']) {
            $matchedTransaction->amount = $payload['amount'];
        }
        $matchedTransaction->plaid_transaction_id = $newPlaidTransactionId;
        $matchedTransaction->details = $payload;
        $matchedTransaction->owner = $payload['account_owner'] ?? $matchedTransaction->owner;
        $matchedTransaction->check_number = $payload['check_number'] ?? $matchedTransaction->check_number;
        
        // Handle merchant_name carefully. When a pending transaction upgrades to
        // posted and Plaid re-sends a merchant name, trust it. When it doesn't,
        // we normally keep the pending row's name — EXCEPT when this posted row
        // is a transfer or a bank-generated fee, which never have a real
        // merchant. Those are exactly the descriptors a mis-matched pending
        // transaction leaves a stale name on (e.g. a $12 "Laravel Forge" pending
        // inherited onto a $12 "FEE-DEP CK RTN" returned-check fee).
        $incomingDescription = $payload['name'] ?? $payload['original_description'] ?? '';
        $isTransfer = preg_match('/\b(ZELLE|WIRE|ACH|TRANSFER|PAYROLL)\b/i', $incomingDescription);
        $isBankFee = (($payload['personal_finance_category']['primary'] ?? null) === 'BANK_FEES')
            || preg_match('/\bFEE\b|RETURNED|SERVICE CHARGE|ACCT ANALYSIS|OVERDRAFT|\bNSF\b/i', $incomingDescription);

        if (isset($payload['merchant_name']) && $payload['merchant_name'] !== null) {
            $matchedTransaction->plaid_merchant_name = $payload['merchant_name'];
        } elseif ($isTransfer || $isBankFee) {
            // Don't inherit a stale merchant name from a mis-matched pending transaction.
            $matchedTransaction->plaid_merchant_name = null;
        }
        // Otherwise keep existing merchant name (for non-transfer upgrades where Plaid doesn't send merchant_name)
        
        $matchedTransaction->plaid_merchant_description = $payload['name'] ?? $payload['original_description'] ?? $matchedTransaction->plaid_merchant_description;
        $matchedTransaction->save();
    }

    private function insertNewTransaction(Bank $bank, array $payload, float $amount, string $accountType, $institutionAccountIds, string $plaidTransactionId): Transaction
    {
        $insertMeta = $this->determineInsertDates($payload);
        $transaction = new Transaction();
        $transaction->transaction_date = $insertMeta['transaction_date'];
        $transaction->posted_date = $insertMeta['posted_date'];
        $transaction->amount = $amount;
        $transaction->bank_account_id = BankAccount::where('plaid_account_id', $payload['account_id'])
            ->whereIn('id', $institutionAccountIds)
            ->value('id');
        $transaction->owner = $payload['account_owner'] ?? null;
        $transaction->check_number = $payload['check_number'] ?? null;
        $transaction->plaid_merchant_name = $payload['merchant_name'] ?? null;
        $transaction->plaid_merchant_description = $payload['name'] ?? $payload['original_description'] ?? null;
        $transaction->plaid_transaction_id = $plaidTransactionId;
        $transaction->details = $payload;
        $transaction->save();
        return $transaction;
    }

    /**
     * Unified aggregate record builder for inserted / upgraded / removed transactions.
     */
    private function buildAggregate(
        string $status,
        int $transactionId,
        string $plaidTransactionId,
        ?string $pendingPlaidTransactionId,
        float $amount,
        array $payload,
        ?array $upgradeMeta = null,
        ?string $matchVia = null,
        ?array $removedMeta = null
    ): array {
        $base = [
            'status' => $status,
            'transaction_id' => $transactionId,
            'plaid_transaction_id' => $plaidTransactionId,
            'pending_transaction_id' => $pendingPlaidTransactionId,
            'amount' => $amount,
        ];

        // Insert specific (derive dates)
        if ($status === 'inserted') {
            $insertMeta = $this->determineInsertDates($payload);
            $base += [
                'incoming_date' => $payload['date'] ?? null,
                'incoming_authorized_date' => $payload['authorized_date'] ?? null,
                'pending' => $payload['pending'] ?? null,
                'derived_transaction_date' => $insertMeta['transaction_date'],
                'derived_posted_date' => $insertMeta['posted_date'],
            ];
        }

        // Upgrade specific
        if ($upgradeMeta) {
            $base += [
                'existing_transaction_id' => $transactionId,
                'transaction_date_before' => $upgradeMeta['before_transaction_date'],
                'transaction_date_after_logic' => $upgradeMeta['after_transaction_date'],
                'posted_date_before' => $upgradeMeta['before_posted_date'],
                'posted_date_after_logic' => $upgradeMeta['after_posted_date'],
                'incoming_date' => $payload['date'] ?? null,
                'incoming_authorized_date' => $payload['authorized_date'] ?? null,
                'incoming_used_source' => $upgradeMeta['incoming_source'],
                'was_pending' => $upgradeMeta['was_pending'],
                'updated_field' => $upgradeMeta['updated_field'],
                'kept_existing_earlier_date' => $upgradeMeta['kept_existing_earlier_date'],
                'match_via' => $matchVia,
            ];
        }

        // Removed specific
        if ($status === 'removed_deleted' && $removedMeta) {
            $base += [
                'transaction_date' => $removedMeta['transaction_date'],
                'posted_date' => $removedMeta['posted_date'],
                'bank_id' => $removedMeta['bank_id'],
            ];
        }

        return $base;
    }

    private function determineInsertDates(array $tx): array
    {
        $pending = $tx['pending'] ?? false;
        $date = $tx['date'] ?? null;
        $auth = $tx['authorized_date'] ?? null;
        $transactionDate = null;
        $postedDate = null;
        if ($pending) {
            if ($date && $auth && $date !== $auth) {
                $transactionDate = min($date, $auth);
            } else {
                $transactionDate = $date ?? $auth;
            }
            $postedDate = null;
        } else {
            if ($date && $auth && $date !== $auth) {
                $earlier = min($date, $auth);
                $later = max($date, $auth);
                $transactionDate = $earlier;
                $postedDate = $later;
            } else {
                $val = $date ?? $auth;
                $transactionDate = $val;
                $postedDate = $val;
            }
        }
        return [
            'transaction_date' => $transactionDate,
            'posted_date' => $postedDate,
        ];
    }

    private function determineUpgradeDates($existing, array $tx): array
    {
        $pendingFlag = $tx['pending'] ?? false;
        $incomingDate = $tx['date'] ?? $tx['authorized_date'] ?? null;
        $incomingSource = isset($tx['date']) ? 'date' : (isset($tx['authorized_date']) ? 'authorized_date' : null);
        // Support either an Eloquent model or a simple array/object carrying the values
        $existingTxn = null;
        $existingPosted = null;
        if (is_array($existing)) {
            $existingTxn = $existing['transaction_date'] ?? null;
            $existingPosted = $existing['posted_date'] ?? null;
        } elseif (is_object($existing)) {
            $existingTxn = $existing->transaction_date ?? null;
            $existingPosted = $existing->posted_date ?? null;
        }
        $beforeTxn = $existingTxn instanceof \Carbon\CarbonInterface ? $existingTxn->toDateString() : ($existingTxn ? (string) $existingTxn : null);
        $beforePosted = $existingPosted instanceof \Carbon\CarbonInterface ? $existingPosted->toDateString() : ($existingPosted ? (string) $existingPosted : null);
        $afterTxn = $beforeTxn;
        $afterPosted = $beforePosted;
        $updatedField = null;
        $keptEarlier = null;
        if ($incomingDate && $beforeTxn) {
            if ($pendingFlag && !$beforePosted) {
                if ($beforeTxn === null) {
                    $afterTxn = $incomingDate;
                    $updatedField = 'transaction_date';
                } else {
                    if (\Carbon\Carbon::parse($beforeTxn)->gt(\Carbon\Carbon::parse($incomingDate))) {
                        $afterTxn = $incomingDate;
                        $updatedField = 'transaction_date';
                    } elseif (\Carbon\Carbon::parse($beforeTxn)->lt(\Carbon\Carbon::parse($incomingDate))) {
                        $keptEarlier = true;
                    }
                }
            } else {
                if ($beforePosted === null) {
                    $afterPosted = $incomingDate;
                    $updatedField = 'posted_date';
                } elseif ($beforePosted !== $incomingDate) {
                    $afterPosted = $incomingDate;
                    $updatedField = 'posted_date';
                }
            }
        }
        return [
            'before_transaction_date' => $beforeTxn,
            'after_transaction_date' => $afterTxn,
            'before_posted_date' => $beforePosted,
            'after_posted_date' => $afterPosted,
            'updated_field' => $updatedField,
            'kept_existing_earlier_date' => $keptEarlier,
            'incoming_source' => $incomingSource,
            'was_pending' => $pendingFlag,
        ];
    }

    /**
     * Heuristic fallback when Plaid ID chain doesn't link pending->posted.
     */
    private function findHeuristicCandidates($institutionAccountIds, array $tx)
    {
        $amount = (float) ($tx['amount'] ?? 0);
        $date = $tx['date'] ?? $tx['authorized_date'] ?? null;
        if (!$date || !$amount || !$institutionAccountIds || $institutionAccountIds->isEmpty()) {
            return collect();
        }
        $start = Carbon::parse($date)->copy()->subDays(5)->toDateString();
        $end = Carbon::parse($date)->copy()->addDays(5)->toDateString();
        return Transaction::withTrashed()
            ->whereIn('bank_account_id', $institutionAccountIds)
            ->whereNull('posted_date')
            ->whereBetween('transaction_date', [$start, $end])
            ->where(function ($q) use ($amount) {
                $q->where('amount', (float) $amount)
                  ->orWhere('amount', (float) (-1 * $amount));
            })
            ->orderBy('transaction_date')
            ->get();
    }

    private function chooseBestHeuristicCandidate($candidates, array $incoming)
    {
        if (!$candidates || $candidates->isEmpty()) { return null; }
        $incomingName = $incoming['name'] ?? null;
        $incomingOrig = $incoming['original_description'] ?? null;
        $incomingMerchant = $incoming['merchant_name'] ?? null;
        $incomingDate = $incoming['date'] ?? $incoming['authorized_date'] ?? null;
        return $candidates->sortByDesc(function ($t) use ($incomingName, $incomingOrig, $incomingMerchant, $incomingDate) {
            $score = 0;
            $details = is_array($t->details ?? null) ? $t->details : (is_object($t->details ?? null) ? (array) $t->details : json_decode(json_encode($t->details ?? []), true));
            $tName = $details['name'] ?? null;
            $tOrig = $details['original_description'] ?? null;
            $tMerchant = $t->plaid_merchant_name ?? null;
            if ($incomingOrig && $tOrig && strcasecmp($incomingOrig, $tOrig) === 0) { $score += 10; }
            if ($incomingName && $tName && strcasecmp($incomingName, $tName) === 0) { $score += 8; }
            if ($incomingMerchant && $tMerchant && strcasecmp($incomingMerchant, $tMerchant) === 0) { $score += 5; }
            // Penalize soft-deleted slightly
            if ($t->deleted_at) { $score -= 1; }
            // Closer dates are better
            if ($incomingDate && $t->transaction_date) {
                try {
                    $diff = abs(\Carbon\Carbon::parse($incomingDate)->diffInDays(\Carbon\Carbon::parse($t->transaction_date)));
                    $score += max(0, 5 - min($diff, 5));
                } catch (\Throwable $e) {}
            }
            return $score;
        })->first();
    }

    private function updateBankAccountBalances(array $accountsData): void
    {
        if (empty($accountsData)) { return; }
        foreach ($accountsData as $accountData) {
            $bankAccount = BankAccount::where('plaid_account_id', $accountData['account_id'])->first();
            if (!$bankAccount) { continue; }
            $options = $bankAccount->options ?? new \stdClass();
            $balancesChanged = false;
            if (!isset($options->balances) ||
                $options->balances->available != $accountData['balances']['available'] ||
                $options->balances->current != $accountData['balances']['current']) { $balancesChanged = true; }
            $options->balances = json_decode(json_encode($accountData['balances']));
            if ($balancesChanged) { $options->last_balance_update = now()->toDateTimeString(); }
            $bankAccount->options = $options;
            $bankAccount->save();
        }
    }

    private function filterTransactionsByStartDate(array $transactions, string $startDate): array
    {
        if (empty($transactions)) { return []; }
        return array_values(array_filter($transactions, function ($tx) use ($startDate) {
            $d = $tx['date'] ?? $tx['authorized_date'] ?? null;
            return !$d || $d >= $startDate; }));
    }
}
