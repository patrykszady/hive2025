// Capital One account transition duplicate cleanup (BA 16 → BA 18, late April 2025)
// Account number changed from 8338 to 4060 causing Plaid to import overlapping transactions.
//
// This script:
// 1. Soft-deletes duplicate TXN 25978 (BA 18, 04/28, -$600) — duplicate of TXN 25840 (BA 16)
// 2. Soft-deletes Exp 24557 (-$600, 04/28) — was linked to the duplicate TXN 25978
// 3. Soft-deletes duplicate TXN 25977 (BA 18, 05/01, -$600) — duplicate of TXN 25871 (BA 16)
// 4. Creates missing -$600 expense (05/05) for TXN 25967 on BA 18 (no BA 16 duplicate exists)
// 5. Links TXN 25967 to the new expense
// 6. Updates Exp 24558 parent_expense_id to point to the new expense (was pointing to deleted 24557)

use App\Models\Transaction;
use App\Models\Expense;

$dryRun = true; // Set to false to execute changes
$prefix = $dryRun ? '[DRY RUN]' : '[APPLIED]';

echo "=== Capital One Duplicate Cleanup ===\n";
echo $dryRun ? "** DRY RUN MODE — no changes will be made **\n\n" : "** LIVE MODE — changes will be applied **\n\n";

// --- Step 1: Soft-delete duplicate TXN 25978 ---
$txn25978 = Transaction::withTrashed()->find(25978);
if (!$txn25978) {
    echo "ERROR: TXN 25978 not found\n";
    return;
}
if ($txn25978->trashed()) {
    echo "SKIP: TXN 25978 already soft-deleted\n";
} else {
    // Verify it's the expected duplicate
    if ($txn25978->bank_account_id != 18 || $txn25978->amount != -600 || $txn25978->transaction_date->format('Y-m-d') != '2025-04-28') {
        echo "ERROR: TXN 25978 doesn't match expected values (BA 18, -600, 2025-04-28). Got: BA {$txn25978->bank_account_id}, {$txn25978->amount}, {$txn25978->transaction_date->format('Y-m-d')}\n";
        return;
    }
    echo "{$prefix} Soft-delete TXN 25978 (BA 18, \${$txn25978->amount}, {$txn25978->transaction_date->format('Y-m-d')})\n";
    if (!$dryRun) {
        $txn25978->delete();
    }
}

// --- Step 2: Soft-delete Exp 24557 (linked to duplicate TXN 25978) ---
$exp24557 = Expense::withTrashed()->find(24557);
if (!$exp24557) {
    echo "ERROR: Exp 24557 not found\n";
    return;
}
if ($exp24557->trashed()) {
    echo "SKIP: Exp 24557 already soft-deleted\n";
} else {
    if ($exp24557->vendor_id != 77 || $exp24557->amount != -600) {
        echo "ERROR: Exp 24557 doesn't match expected values (vendor 77, -600). Got: vendor {$exp24557->vendor_id}, {$exp24557->amount}\n";
        return;
    }
    echo "{$prefix} Soft-delete Exp 24557 (\${$exp24557->amount}, {$exp24557->date->format('Y-m-d')})\n";
    if (!$dryRun) {
        $exp24557->delete();
    }
}

// --- Step 3: Soft-delete duplicate TXN 25977 ---
$txn25977 = Transaction::withTrashed()->find(25977);
if (!$txn25977) {
    echo "ERROR: TXN 25977 not found\n";
    return;
}
if ($txn25977->trashed()) {
    echo "SKIP: TXN 25977 already soft-deleted\n";
} else {
    if ($txn25977->bank_account_id != 18 || $txn25977->amount != -600 || $txn25977->transaction_date->format('Y-m-d') != '2025-05-01') {
        echo "ERROR: TXN 25977 doesn't match expected values (BA 18, -600, 2025-05-01). Got: BA {$txn25977->bank_account_id}, {$txn25977->amount}, {$txn25977->transaction_date->format('Y-m-d')}\n";
        return;
    }
    echo "{$prefix} Soft-delete TXN 25977 (BA 18, \${$txn25977->amount}, {$txn25977->transaction_date->format('Y-m-d')})\n";
    if (!$dryRun) {
        $txn25977->delete();
    }
}

// --- Step 4: Create missing expense for TXN 25967 (05/05, -$600) ---
$txn25967 = Transaction::find(25967);
if (!$txn25967) {
    echo "ERROR: TXN 25967 not found\n";
    return;
}
if ($txn25967->bank_account_id != 18 || $txn25967->amount != -600 || $txn25967->transaction_date->format('Y-m-d') != '2025-05-05') {
    echo "ERROR: TXN 25967 doesn't match expected values (BA 18, -600, 2025-05-05). Got: BA {$txn25967->bank_account_id}, {$txn25967->amount}, {$txn25967->transaction_date->format('Y-m-d')}\n";
    return;
}

$newExpense = null;
if ($txn25967->expense_id) {
    $existing = Expense::find($txn25967->expense_id);
    if ($existing && $existing->amount == -600 && $existing->vendor_id == 77) {
        echo "SKIP: TXN 25967 already linked to Exp {$existing->id} (\${$existing->amount})\n";
        $newExpense = $existing;
    } else {
        echo "WARNING: TXN 25967 linked to unexpected Exp {$txn25967->expense_id}\n";
    }
} else {
    echo "{$prefix} Create Expense: -\$600, 2025-05-05, vendor 77, dist 1, category 124\n";
    if (!$dryRun) {
        $newExpense = new Expense();
        $newExpense->amount = -600;
        $newExpense->date = '2025-05-05';
        $newExpense->vendor_id = 77;
        $newExpense->belongs_to_vendor_id = 1;
        $newExpense->created_by_user_id = 0;
        $newExpense->category_id = 124;
        $newExpense->distribution_id = 1;
        $newExpense->save();
        echo "  Created Exp {$newExpense->id}\n";

        // Link TXN 25967 to the new expense
        $txn25967->expense_id = $newExpense->id;
        $txn25967->save();
        echo "  Linked TXN 25967 -> Exp {$newExpense->id}\n";
    }
}

// --- Step 5: Update Exp 24558 parent_expense_id ---
$exp24558 = Expense::find(24558);
if (!$exp24558) {
    echo "ERROR: Exp 24558 not found\n";
    return;
}
if ($exp24558->amount != 600 || $exp24558->vendor_id != 77) {
    echo "ERROR: Exp 24558 doesn't match expected values (+600, vendor 77). Got: {$exp24558->amount}, vendor {$exp24558->vendor_id}\n";
    return;
}

if ($newExpense) {
    if ($exp24558->parent_expense_id == $newExpense->id) {
        echo "SKIP: Exp 24558 parent_expense_id already set to {$newExpense->id}\n";
    } else {
        echo "{$prefix} Update Exp 24558 parent_expense_id: {$exp24558->parent_expense_id} -> {$newExpense->id}\n";
        if (!$dryRun) {
            $exp24558->parent_expense_id = $newExpense->id;
            $exp24558->save();
        }
    }
} elseif ($dryRun) {
    echo "{$prefix} Update Exp 24558 parent_expense_id -> [new expense id]\n";
}

echo "\n=== Done ===\n";
