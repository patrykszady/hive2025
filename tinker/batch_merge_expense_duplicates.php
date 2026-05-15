<?php

use App\Models\Expense;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

// List of pairs to merge (receipt_exp, txn_exp)
$pairs = [
    // [receipt_exp, txn_exp]
    [25604, 25589],
    [25603, 25591],
    [25618, 25594],
    [25621, 25593],
    [25620, 25592],
    [25624, 25558],
];

DB::transaction(function () use ($pairs) {
    foreach ($pairs as [$receiptId, $txnId]) {
        $receipt = Expense::findOrFail($receiptId); // has receipt, usually no project
        $txn    = Expense::findOrFail($txnId);      // has transaction, usually has project

        // If txn has a project, copy it to receipt if receipt has no project
        if (!$receipt->project_id && $txn->project_id) {
            $receipt->project_id = $txn->project_id;
            $receipt->save();
        }

        // Move all transactions from txn to receipt
        $txns = Transaction::where('expense_id', $txn->id)->get();
        foreach ($txns as $t) {
            $t->expense_id = $receipt->id;
            $t->save();
        }

        // Optionally, copy distribution_id if needed
        if (!$receipt->distribution_id && $txn->distribution_id) {
            $receipt->distribution_id = $txn->distribution_id;
            $receipt->save();
        }

        // Soft-delete the txn-side duplicate
        $txn->delete();

        echo "Merged txn expense {$txn->id} into receipt expense {$receipt->id}\n";
    }
});

echo "Batch merge complete.\n";
