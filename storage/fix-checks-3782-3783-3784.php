<?php

/**
 * One-time production fix for checks 3782, 3783, 3784.
 *
 * Root cause:
 *   In Carbon 3 (Laravel 12) `diffInDays($other)` returns a SIGNED float, so
 *   transactions dated AFTER the check produced the most-negative value and were
 *   incorrectly selected as "closest" by the ascending sort in
 *   TransactionController::add_check_id_to_transactions(). The subset-sum matcher
 *   also picked the first sum-matching combo it found, ignoring date proximity.
 *
 * Result on production:
 *   3784 (3/21 $200) -> 28346 (3/16) + 28379 (3/20)   [should be 28378 + 28380, both 3/21]
 *   3783 (3/20 $100) -> 28414 (3/25)                  [should be 28379, 3/20]
 *   3782 (3/16 $100) -> 28380 (3/21)                  [should be 28346, 3/16]
 *
 * Run:
 *   php artisan tinker --execute="require 'storage/fix-checks-3782-3783-3784.php';"
 */

use App\Models\Check;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

$desired = [
    3784 => [28378, 28380],
    3783 => [28379],
    3782 => [28346],
];

DB::transaction(function () use ($desired) {
    $allTransactionIds = collect($desired)->flatten()->all();
    $allCheckIds       = array_keys($desired);

    // 1) Verify the checks exist.
    $checks = Check::withoutGlobalScopes()
        ->whereIn('id', $allCheckIds)
        ->get()
        ->keyBy('id');

    foreach ($allCheckIds as $checkId) {
        if (!$checks->has($checkId)) {
            throw new RuntimeException("Check {$checkId} not found.");
        }
    }

    // 2) Verify each desired transaction exists and the amounts reconcile.
    $transactions = Transaction::withoutGlobalScopes()
        ->whereIn('id', $allTransactionIds)
        ->get()
        ->keyBy('id');

    foreach ($desired as $checkId => $txIds) {
        $sum = 0;
        foreach ($txIds as $txId) {
            if (!$transactions->has($txId)) {
                throw new RuntimeException("Transaction {$txId} not found.");
            }
            $sum += (float) $transactions[$txId]->amount;
        }
        $checkAmount = (float) $checks[$checkId]->amount;
        if (round($sum, 2) !== round($checkAmount, 2)) {
            throw new RuntimeException(sprintf(
                'Sum mismatch for check %d: transactions sum to %.2f but check is %.2f',
                $checkId,
                $sum,
                $checkAmount
            ));
        }
    }

    // 3) Detach any incorrect existing links on these checks (do NOT touch other checks).
    $detached = Transaction::withoutGlobalScopes()
        ->whereIn('check_id', $allCheckIds)
        ->whereNotIn('id', $allTransactionIds)
        ->get();

    foreach ($detached as $t) {
        echo "Detaching transaction {$t->id} from check {$t->check_id}\n";
        $t->check_id = null;
        $t->save();
    }

    // 4) Re-link transactions to the correct checks (using save so model events fire
    //    and Scout/observers stay consistent).
    foreach ($desired as $checkId => $txIds) {
        foreach ($txIds as $txId) {
            $tx = $transactions[$txId];
            if ((int) $tx->check_id === (int) $checkId) {
                echo "Transaction {$txId} already linked to check {$checkId}, skipping\n";
                continue;
            }
            echo "Linking transaction {$txId} (was check_id=" . ($tx->check_id ?? 'null') . ") to check {$checkId}\n";
            $tx->check_id = $checkId;
            $tx->save();
        }
    }
});

echo "Done.\n";

// Verification readout.
foreach ([3782, 3783, 3784] as $checkId) {
    $check = Check::withoutGlobalScopes()->with(['transactions' => fn ($q) => $q->withoutGlobalScopes()])->find($checkId);
    $txIds = $check->transactions->pluck('id')->sort()->values()->all();
    $sum   = $check->transactions->sum('amount');
    echo "Check {$checkId} ({$check->date->toDateString()}, \${$check->amount}) -> [" . implode(', ', $txIds) . "] sum=\${$sum}\n";
}
