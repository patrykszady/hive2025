<?php

namespace App\Http\Controllers;

use App\Mail\TestMail;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Bid;
use App\Models\Category;
use App\Models\Check;
use App\Models\Client;
use App\Models\CompanyEmail;
use App\Models\Distribution;
use App\Models\Estimate;
use App\Models\EstimateLineItem;
use App\Models\EstimateSection;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\ExpenseSplits;
use App\Models\Hour;
use App\Models\Payment;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Receipt;
use App\Models\ReceiptAccount;
use App\Models\Timesheet;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Task;
use App\Models\Vendor;

use Carbon\Carbon;
use Carbon\CarbonPeriod;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

ini_set('max_execution_time', 600900000);

class MoveController extends Controller
{
    public function move()
    {
        //get all expenses that have splits and a recepit but no receipt line items.
        // $expenses = Expense::whereHas('splits') // Has at least one split
        //     ->whereHas('receipts') // Has at least one receipt
        //     ->whereDoesntHave('receipts', function($query) {
        //         $query->whereNotNull('receipt_items')
        //             ->whereRaw("JSON_LENGTH(receipt_items->'$.items') > 0");
        //     }) // Doesn't have any receipts with line items
        //     ->orderBy('date', 'desc')->take(10)->get();

        // dd($expenses);
        
        // $transactions = Transaction::withoutGlobalScopes()->where('vendor_id', 317)->get();
        // foreach($transactions as $transaction){
        //     $transaction->vendor_id = 218;
        //     $transaction->save();
        // }

        // $expenses = Expense::where('vendor_id', 317)->get();
        // foreach($expenses as $expense){
        //     $expense->vendor_id = 218;
        //     $expense->save();
        // }

        dd('jere');

        $tasks = Task::withoutGlobalScopes()->get();

        foreach($tasks as $task){
            if($task->type === "Material"){
                $task->type = "Milestone";
                $task->save();
            }
            //  else {
            //     $task->start_date = null;
            //     $task->end_date = null;
            // }
        }

        dd('done with tasks');

        foreach ($tasks as $task) {
            if($task->user_id){
                $task->user_ids = [$task->user_id];
                $task->user_id = NULL;
                $task->save();
            }
        }

        dd('done with tasks');

        foreach ($tasks as $task) {
            $task->options = NULL;
            $task->save();
        }

        foreach ($tasks as $task) {
            if ($task->start_date && $task->end_date) {
                // Get the range of days the task spans
                $taskPeriod = CarbonPeriod::create(
                    $task->start_date->format('Y-m-d'),
                    $task->end_date->format('Y-m-d')
                );

                // Check options for including weekends
                $includeSaturday = $task->options->include_weekend_days->saturday ?? false;
                $includeSunday = $task->options->include_weekend_days->sunday ?? false;

                $dates = [];
                foreach ($taskPeriod as $date) {
                    $dayOfWeek = $date->dayOfWeek; // 6 = Saturday, 0 = Sunday

                    // Skip weekends if not included in options
                    if (($dayOfWeek === 6 && !$includeSaturday) || ($dayOfWeek === 0 && !$includeSunday)) {
                        continue;
                    }

                    $formattedDate = $date->format('Y-m-d');
                    $dates[] = $formattedDate;
                }
            } elseif ($task->start_date) {
                // If the task only has a start_date, assign it to that day
                $formattedDate = $task->start_date->format('Y-m-d');
                $dates = [$formattedDate];
            }else {
                // If the task has no start_date, add it to the "No Date" collection
                $dates = NULL;
            }

            $task->dates = $dates;
            $task->save();
        }

        dd('done with tasks');

        $receipts = ExpenseReceipts::whereNotNull('receipt_items') // Ensure receipt_items is not null
            ->get()
            ->filter(function ($receipt) {
                $totalTax = data_get($receipt->receipt_items, 'total_tax');
                return is_array($totalTax) || is_object($totalTax); // Check if total_tax is an array or object
            });

        foreach ($receipts as $receipt) {
            $receiptItems = $receipt->receipt_items;

            // Check if total_tax is an object or array and has a valueNumber
            if (isset($receiptItems->total_tax->valueNumber)) {
                $receiptItems->total_tax = $receiptItems->total_tax->valueNumber; // Update total_tax to valueNumber
            }elseif (isset($receiptItems->total_tax->valueCurrency)) {
                $receiptItems->total_tax = $receiptItems->total_tax->valueCurrency->amount; // Update total_tax to valueNumber
            }

            // Save the updated receipt_items back to the database
            $receipt->receipt_items = $receiptItems;
            $receipt->save();
        }

        $receipts = ExpenseReceipts::whereNotNull('receipt_items') // Ensure receipt_items is not null
            ->where('receipt_items->total', '!=', 0) // Ensure total is not 0
            ->where('receipt_items->subtotal', '=', 0) // Ensure subtotal is 0
            ->where('receipt_items->total_tax', '=', 0) // Ensure total_tax is 0
            ->get(); // Retrieve the matching receipts

        foreach ($receipts as $receipt) {
            $receiptItems = $receipt->receipt_items;

            // Update subtotal to the value of total
            if (isset($receiptItems->total)) {
                $receiptItems->subtotal = $receiptItems->total;
            }

            // Save the updated receipt_items back to the database
            $receipt->receipt_items = $receiptItems;
            $receipt->save();
        }

        dd('done');

        $receipts = ExpenseReceipts::whereNotNull('receipt_items') // Ensure receipt_items is not null
            ->whereRaw("JSON_TYPE(JSON_EXTRACT(receipt_items, '$.total')) = 'OBJECT'") // Check if total is an object
            ->get();

        dd($receipts);

        $expenses = Expense::whereHas('receipts', function ($query) {
            $query->whereNotNull('receipt_items') // Filter receipts where receipt_items is not null
                ->whereNull('receipt_items->total_tax')
                ->whereNotNull('receipt_items->total')
                ->whereNull('receipt_items->subtotal');
        })->pluck('id');

        dd($expenses);

        // Find all checks with 2 or more transactions
        $checksWithMultipleTransactions = Check::whereHas('transactions', function ($query) {
            $query->select('check_id')
                ->groupBy('check_id')
                ->havingRaw('COUNT(*) >= 2');
        })->get();

        dd($checksWithMultipleTransactions);
        $expenses = Expense::where('vendor_id', 10)
        ->whereHas('receipts', function ($query) {
            $query->where(function ($q) {
                $q->whereRaw("CAST(receipt_items->>'$.total' AS DECIMAL(10,2)) != amount");
            });
        })
        ->get();

        dd($expenses);
        $checks = Check::whereYear('date', 2024)
                    ->where('belongs_to_vendor_id', 1)
                    ->with('transactions')
                    // ->withWhereHas('expenses')
                    // ->withWhereHas('timesheets')
                    ->get();

        $wrong_checks = [];
        foreach ($checks as $check) {
            if ($check->transactions->sum('amount') == $check->amount ) {

            } else {
                $wrong_checks[] = $check;
            }
        }

        dd($wrong_checks);
        //expenses for past year where expense has transactions but transactions->amount is no equal to $expense->amount
        // $YTD = Carbon::now()->subYear();
        // $expenses = Expense::where('vendor_id', '!=', 8)->where('date', '>=', $YTD)->withWhereHas('transactions')->get();

        // $wrong_expenses = [];
        // foreach($expenses as $expense){
        //     if($expense->amount > $expense->transactions->sum('amount')){
        //         $wrong_expenses[] = $expense;
        //     }
        // }

        //->whereNotIn('id', [14720])
        $receipts = ExpenseReceipts::whereNotNull('receipt_items')->whereBetween('updated_at', ['2025-01-01', '2025-01-14'])->whereNull('receipt_html')->orderBy('updated_at', 'DESC')->get();

        foreach ($receipts as $receipt) {
            // dd($receipt->receipt_items);
            //TAX
            // $total_tax = $receipt->receipt_items->total_tax;
            // dd($receipt->receipt_items->total->valueNumber);
            $formatted_items = [];
            if ($receipt->receipt_items->items) {
                foreach ($receipt->receipt_items->items as $item_key => $item) {
                    if (isset($item->valueObject)) {
                        // dd($receipt->receipt_items, $item);
                        //$item->content
                        // $formatted_items[$item_key]['Description'] = isset($item->valueObject->Description->valueString) ? $item->valueObject->Description->valueString : NULL;
                        if (isset($item->valueObject->Description)) {
                            if (isset($item->valueObject->Description->valueString)) {
                                $formatted_items[$item_key]['Description'] = $item->valueObject->Description->valueString;
                            } else {
                                dd($receipt, $receipt->receipt_items, $item);
                            }
                        }

                        $formatted_items[$item_key]['ProductCode'] = isset($item->valueObject->ProductCode) ? $item->valueObject->ProductCode->valueString : null;

                        if (isset($item->valueObject->TotalPrice->valueNumber)) {
                            $formatted_items[$item_key]['TotalPrice'] = $item->valueObject->TotalPrice->valueNumber;
                        } elseif (isset($item->valueObject->TotalPrice->valueCurrency)) {
                            $formatted_items[$item_key]['TotalPrice'] = $item->valueObject->TotalPrice->valueCurrency->amount;
                        } elseif (isset($item->valueObject->Amount)) {
                            $formatted_items[$item_key]['TotalPrice'] = $item->valueObject->Amount->valueCurrency->amount;
                        } else {
                            // dd($receipt, $receipt->receipt_items, $item);
                            $formatted_items[$item_key]['TotalPrice'] = null;
                        }

                        //quantity
                        if (isset($line_item->valueObject->Quantity)) {
                            if (isset($line_item->valueObject->Quantity->valueNumber)) {
                                $formatted_items[$item_key]['Description'] = $item->valueObject->Description->valueString;
                            } else {
                                dd($receipt, $receipt->receipt_items, $item);
                            }
                            $formatted_items[$item_key]['Quantity'] = $item->valueObject->Quantity->valueNumber;
                        } else {
                            $formatted_items[$item_key]['Quantity'] = 1;
                        }

                        //price each
                        if (isset($item->valueObject->Price)) {
                            if (isset($line_item->valueObject->Price->valueNumber)) {
                                $formatted_items[$item_key]['Price'] = $item->valueObject->Price->valueNumber;
                            } elseif (isset($item->valueObject->Price->valueCurrency)) {
                                $formatted_items[$item_key]['Price'] = $item->valueObject->Price->valueCurrency->amount;
                            } else {
                                $formatted_items[$item_key]['Price'] = $formatted_items[$item_key]['TotalPrice'];
                            }
                        } else {
                            $formatted_items[$item_key]['Price'] = $formatted_items[$item_key]['TotalPrice'];
                        }
                    } else {
                        continue 2;
                    }
                }

                // dd($formatted_items);

                $total = $receipt->receipt_items->total ?? null;
                // $subtotal = $receipt->receipt_items->subtotal ?? NULL;
                //SUBTOTAL
                if (isset($receipt->receipt_items->subtotal)) {
                    if (isset($receipt->receipt_items->subtotal->valueCurrency)) {
                        $subtotal = $receipt->receipt_items->subtotal->valueCurrency->amount;
                    } elseif (isset($receipt->receipt_items->subtotal->valueNumber)) {
                        $subtotal = $receipt->receipt_items->subtotal->valueNumber;
                    } else {
                        $subtotal = $receipt->receipt_items->subtotal;
                    }
                } else {
                    // dd($receipt->receipt_items);
                    $subtotal = null;
                }

                $total_tax = $receipt->receipt_items->total_tax ?? null;
                $merchant_name = $receipt->receipt_items->merchant_name ?? null;
                // $transaction_date = $receipt->receipt_items->transaction_date ?? NULL;
                if (isset($receipt->receipt_items->transaction_date)) {
                    if (isset($receipt->receipt_items->transaction_date->valueDate)) {
                        $transaction_date = $receipt->receipt_items->transaction_date->valueDate;
                    } else {
                        $transaction_date = $receipt->receipt_items->transaction_date;
                    }
                } else {
                    $transaction_date = null;
                }

                $invoice_number = $receipt->receipt_items->invoice_number ?? null;
                $purchase_order = $receipt->receipt_items->purchase_order ?? null;
                $handwritten_notes = $receipt->receipt_items->handwritten_notes ?? null;

                $receipt->receipt_items = [
                    'items' => $formatted_items ?? $receipt->receipt_items->items,
                    'subtotal' => $subtotal,
                    'total' => $total,
                    'total_tax' => $total_tax,
                    'transaction_date' => $transaction_date,
                    'merchant_name' => $merchant_name,
                    'invoice_number' => $invoice_number,
                    'purchase_order' => $purchase_order,
                    'handwritten_notes' => $handwritten_notes,
                ];

                $receipt->save();
            } else {
                continue;
            }
        }

    }
}