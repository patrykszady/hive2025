<?php

use App\Models\Expense;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

// Merge expense 26298 into 26284 (manual richer data)
DB::transaction(function () {
    $keep = Expense::findOrFail(26284); // manual, has project/invoice/note
    $dup  = Expense::findOrFail(26298); // auto, txn-linked, has distribution_id

    // Copy over distribution_id if missing
    if (!$keep->distribution_id && $dup->distribution_id) {
        $keep->distribution_id = $dup->distribution_id;
        $keep->save();
    }

    // Move all transactions from dup to keep
    $txns = Transaction::where('expense_id', $dup->id)->get();
    foreach ($txns as $txn) {
        $txn->expense_id = $keep->id;
        $txn->save();
    }

    // Optionally, merge any other fields you want here

    // Soft-delete the duplicate
    $dup->delete();

    echo "Merged expense 26298 into 26284. Transactions moved, duplicate soft-deleted.\n";
});
