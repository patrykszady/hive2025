<?php

use Illuminate\Support\Facades\Schedule;

// Schedule::call(function () {
//     app(\App\Http\Controllers\LeadController::class)->leads_in_email();
// })->everyTenMinutes()
//   ->name('leads-in-email')
//   ->withoutOverlapping()
//   ->onOneServer();

Schedule::call(function () {
    app(\App\Http\Controllers\CompanyEmailController::class)->fetchAutoReceipts();
    })
    ->everyTenMinutes()
    // ->between('7:00', '22:00')
    ->name('fetch-auto-receipts')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer();

Schedule::call(function () {
    app(\App\Http\Controllers\CompanyEmailController::class)->forwardRecentReceiptEmailsToCentral();
    })
    ->everyTenMinutes()
    // ->between('7:00', '22:00')
    ->name('forward-recent-receipts-to-central')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer();

Schedule::call(function () {
  app(\App\Http\Controllers\CompanyEmailController::class)->fetchReceiptMessages();
  })
  ->everyTenMinutes()
  // ->between('7:00', '22:00')
  ->name('fetch-receipt-messages')
  ->environments(['production'])
  ->withoutOverlapping()
  ->onOneServer();

Schedule::call(function () {
    app(\App\Http\Controllers\VendorDocsController::class)->fetchMessagesFromInsuranceMailbox();
    })->hourly()
    // ->between('7:00', '20:00')
    ->name('fetch-insurance-mailbox')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('nylas:sync-contacts')
    ->dailyAt('02:00')
    ->name('sync-nylas-contacts')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer();

//Plaid/Transaction tasks
// Disabled - using ITEM webhooks (ERROR, PENDING_EXPIRATION, USER_PERMISSION_REVOKED) instead
// Schedule::call(function () {
//     app(\App\Http\Controllers\TransactionController::class)->plaid_item_status();
// })->hourly()
//   ->name('plaid-item-status')
//   ->environments(['production'])
//   ->withoutOverlapping()
//   ->onOneServer();

// Daily fallback for Plaid transaction sync (in case webhooks miss updates)
// Schedule::command('plaid:sync-transactions --all')
//   ->dailyAt('04:00')
//   ->name('plaid-transactions-sync-fallback')
//   ->environments(['production'])
//   ->withoutOverlapping()
//   ->onOneServer();

Schedule::call(function () {
    app(\App\Http\Controllers\TransactionController::class)->add_check_deposit_to_transactions();
})->everyTenMinutes()
  ->name('add-check-deposits')
  ->withoutOverlapping()
  ->onOneServer();

Schedule::call(function () {
    app(\App\Http\Controllers\TransactionController::class)->add_vendor_to_transactions();
})->everyTenMinutes()
  ->name('add-vendor-to-transactions')
  ->withoutOverlapping()
  ->onOneServer();

Schedule::call(function () {
    app(\App\Http\Controllers\TransactionController::class)->add_expense_to_transactions();
})->everyTenMinutes()
  ->name('add-expense-to-transactions')
  ->withoutOverlapping()
  ->onOneServer();

Schedule::call(function () {
    app(\App\Http\Controllers\TransactionController::class)->add_check_id_to_transactions();
})->everyTenMinutes()
  ->name('add-check-id-to-transactions')
  ->withoutOverlapping()
  ->onOneServer();

Schedule::call(function () {
    app(\App\Http\Controllers\TransactionController::class)->add_payments_to_transaction();
})->everyTenMinutes()
  ->name('add-payments-to-transactions')
  ->withoutOverlapping()
  ->onOneServer();

Schedule::call(function () {
    app(\App\Http\Controllers\TransactionController::class)->add_transaction_to_expenses_sin_vendor();
})->everyTenMinutes()
  ->name('add-transactions-to-expenses')
  ->withoutOverlapping()
  ->onOneServer();

Schedule::call(function () {
    app(\App\Http\Controllers\TransactionController::class)->find_credit_payments_on_debit();
})->everyTenMinutes()
  ->name('find-credit-payments')
  ->withoutOverlapping()
  ->onOneServer();

Schedule::call(function () {
    app(\App\Http\Controllers\TransactionController::class)->match_associated_expenses();
})->everyTenMinutes()
  ->name('match-associated-expenses')
  ->withoutOverlapping()
  ->onOneServer();

Schedule::call(function () {
    app(\App\Http\Controllers\TransactionController::class)->add_category_to_expense();
})->hourly()
  ->name('add-category-to-expense')
  ->withoutOverlapping()
  ->onOneServer();

Schedule::call(function () {
    app(\App\Http\Controllers\TransactionController::class)->transaction_vendor_bulk_match();
})->everyTenMinutes()
  ->name('transaction-vendor-bulk-match')
  ->withoutOverlapping()
  ->onOneServer();

// External API tasks
Schedule::call(function () {
    app(\App\Http\Controllers\ReceiptController::class)->amazon_orders_api();
    })
    ->everyTwoHours()
    ->between('6:00', '22:00')
    ->name('amazon-orders-api')
    ->withoutOverlapping()
    ->onOneServer();

// Nightly full sync for Amazon orders (catches cancellations/returns)
Schedule::call(function () {
    app(\App\Http\Controllers\ReceiptController::class)->amazon_orders_api();
    })
    ->dailyAt('2:00')
    ->name('amazon-orders-api-full-sync')
    ->withoutOverlapping()
    ->onOneServer();

// Daily user/team member task reminders for next day
Schedule::command('tasks:send-tomorrow-reminders')
    ->dailyAt('19:00')
    ->name('send-tomorrow-reminders')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer();

// Process all task notifications
Schedule::command('tasks:process-notifications')
    ->everyMinute()
    ->name('process-task-notifications')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer();
    
// Search index maintenance
Schedule::command('vendors:update-search-index')
    ->dailyAt('02:00')
    ->name('update-vendor-search-index')
    ->withoutOverlapping()
    ->onOneServer();

// System maintenance
Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->name('horizon-snapshot')
    ->withoutOverlapping();


