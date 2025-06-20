<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

// Updated reusable scheduling function to handle time-specific schedules
function scheduleTask(callable $task, string $taskName, string $frequency, string $time = null)
{
    $scheduled = Schedule::call(function () use ($task, $taskName) {
        try {
            $task();
        } catch (\Throwable $e) {
            Log::channel('schedule')->error("Error in $taskName", [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    });

    // Handle time-specific scheduling
    if ($time && $frequency === 'daily') {
        $scheduled->dailyAt($time);
    } else {
        $scheduled->$frequency();
    }
}

// Schedule tasks
scheduleTask(
    fn() => app(\App\Http\Controllers\CompanyEmailController::class)
        ->fetchMessagesForGrantId(),
    'fetchMessagesForGrantId',
    'everyTenMinutes'
);

scheduleTask(
    fn() => app(\App\Http\Controllers\LeadController::class)
        ->leads_in_email(),
    'leads_in_email',
    'everyTenMinutes'
);

scheduleTask(
    fn() => app(\App\Http\Controllers\CompanyEmailController::class)
        ->fetchAutoReceipts(),
    'fetchAutoReceipts',
    'everyTenMinutes'
);

scheduleTask(
    fn() => app(\App\Http\Controllers\TransactionController::class)
        ->plaid_item_status(),
    'plaid_item_status',
    'hourly'
);

scheduleTask(
    fn() => app(\App\Http\Controllers\TransactionController::class)
        ->plaid_transactions_sync(),
    'plaid_transactions_sync',
    'hourly'
);

scheduleTask(
    fn() => app(\App\Http\Controllers\ReceiptController::class)
        ->amazon_orders_api(),
    'amazon_orders_api',
    'hourly'
);

scheduleTask(
    fn() => app(\App\Http\Controllers\TransactionController::class)
        ->add_check_deposit_to_transactions(),
    'add_check_deposit_to_transactions',
    'everyTenMinutes'
);

scheduleTask(
    fn() => app(\App\Http\Controllers\TransactionController::class)
        ->add_vendor_to_transactions(),
    'add_vendor_to_transactions',
    'everyTenMinutes'
);

scheduleTask(
    fn() => app(\App\Http\Controllers\TransactionController::class)
        ->add_expense_to_transactions(),
    'add_expense_to_transactions',
    'everyTenMinutes'
);

scheduleTask(
    fn() => app(\App\Http\Controllers\TransactionController::class)
        ->add_check_id_to_transactions(),
    'add_check_id_to_transactions',
    'everyTenMinutes'
);

scheduleTask(
    fn() => app(\App\Http\Controllers\TransactionController::class)
        ->add_payments_to_transaction(),
    'add_payments_to_transaction',
    'everyTenMinutes'
);

scheduleTask(
    fn() => app(\App\Http\Controllers\TransactionController::class)
        ->add_transaction_to_expenses_sin_vendor(),
    'add_transaction_to_expenses_sin_vendor',
    'everyTenMinutes'
);

scheduleTask(
    fn() => app(\App\Http\Controllers\TransactionController::class)
        ->find_credit_payments_on_debit(),
    'find_credit_payments_on_debit',
    'everyTenMinutes'
);

scheduleTask(
    fn() => app(\App\Http\Controllers\TransactionController::class)
        ->add_category_to_expense(),
    'add_category_to_expense',
    'hourly'
);

scheduleTask(
    fn() => app(\App\Http\Controllers\TransactionController::class)
        ->transaction_vendor_bulk_match(),
    'transaction_vendor_bulk_match',
    'everyTenMinutes'
);

scheduleTask(
    fn() => app(\App\Http\Controllers\VendorDocsController::class)
        ->fetchMessagesFromInsuranceMailbox(),
    'fetchMessagesFromInsuranceMailbox',
    'hourly'
);

// Task reminders - send at 7 PM daily (now with proper time parameter)
scheduleTask(
    fn() => app(\App\Http\Controllers\TaskReminderController::class)
        ->sendTomorrowReminders(),
    'sendTomorrowReminders',
    'daily',
    '19:00'
);

// horizon:snapshot command
Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->appendOutputTo(storage_path('logs/horizon_snapshot-' . date('Y-m-d') . '.log'));

// Schedule::command('cache:prune-stale-tags')->hourly();
