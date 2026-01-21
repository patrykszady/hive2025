<?php

use Illuminate\Support\Facades\Schedule;

Schedule::timezone('America/Chicago');

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
    ->timezone('America/Chicago')
    ->name('sync-nylas-contacts')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer();

// Schedule SMS Notifications
// Shared notification schedule config
$notifyTimezone = config('sms.business_hours.timezone', 'America/Chicago');
$notifyStartTime = sprintf('%02d:%02d', config('sms.business_hours.start_hour', 7), config('sms.business_hours.start_minute', 0));
$notifyEndTime = sprintf('%02d:%02d', config('sms.business_hours.end_hour', 20) - 1, 0); // 1 hour before end (7 PM default)

// Send "tomorrow" reminders at configured end time each night (client, vendor, team)
Schedule::command('schedule:send-sms client tomorrow')
  ->dailyAt($notifyEndTime)
  ->timezone($notifyTimezone)
  ->name('schedule-sms-client-tomorrow')
  ->environments(['production'])
  ->withoutOverlapping()
  ->onOneServer();

// Web Push notifications - "today" at configured start hour
Schedule::job(new \App\Jobs\SendTaskPushNotifications('today'))
  ->dailyAt($notifyStartTime)
  ->timezone($notifyTimezone)
  ->name('push-notifications-today')
  ->environments(['production'])
  ->withoutOverlapping()
  ->onOneServer();

// Web Push notifications - "tomorrow" at configured end time
Schedule::job(new \App\Jobs\SendTaskPushNotifications('tomorrow'))
  ->dailyAt($notifyEndTime)
  ->timezone($notifyTimezone)
  ->name('push-notifications-tomorrow')
  ->environments(['production'])
  ->withoutOverlapping()
  ->onOneServer();

// Web Push notifications - "update" every 15 minutes during the day
$notifyUpdateStart = sprintf('%02d:15', config('sms.business_hours.start_hour', 7));
Schedule::job(new \App\Jobs\SendTaskPushNotifications('update'))
  ->everyFifteenMinutes()
  ->between($notifyUpdateStart, $notifyEndTime)
  ->timezone($notifyTimezone)
  ->name('push-notifications-update')
  ->environments(['production'])
  ->withoutOverlapping()
  ->onOneServer();

Schedule::command('schedule:send-sms vendor tomorrow')
  ->dailyAt($notifyEndTime)
  ->timezone($notifyTimezone)
  ->name('schedule-sms-vendor-tomorrow')
  ->environments(['production'])
  ->withoutOverlapping()
  ->onOneServer();

Schedule::command('schedule:send-sms team tomorrow')
  ->dailyAt($notifyEndTime)
  ->timezone($notifyTimezone)
  ->name('schedule-sms-team-tomorrow')
  ->environments(['production'])
  ->withoutOverlapping()
  ->onOneServer();

// Send "today" reminders at configured start time each morning (client, vendor)
Schedule::command('schedule:send-sms client today')
  ->dailyAt($notifyStartTime)
  ->timezone($notifyTimezone)
  ->name('schedule-sms-client-today')
  ->environments(['production'])
  ->withoutOverlapping()
  ->onOneServer();

Schedule::command('schedule:send-sms vendor today')
  ->dailyAt($notifyStartTime)
  ->timezone($notifyTimezone)
  ->name('schedule-sms-vendor-today')
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
    app(\App\Http\Controllers\TransactionController::class)->add_transaction_to_multi_expenses();
})->everyTenMinutes()
  ->name('add-transaction-to-multi-expenses')
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

Schedule::call(function () {
  app(\App\Http\Controllers\ExpenseAutoMatchController::class)->runNoProjectExpenseAutoMatch();
})->everyTenMinutes()
  ->name('match-expense-po-to-project')
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
    ->timezone('America/Chicago')
    ->name('amazon-orders-api-full-sync')
    ->withoutOverlapping()
    ->onOneServer();

// Search index maintenance - sync settings and reimport vendor index
Schedule::command('scout:sync-index-settings')
    ->dailyAt('02:00')
    ->timezone('America/Chicago')
    ->name('sync-scout-index-settings')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer();

// System maintenance
Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->name('horizon-snapshot')
    ->withoutOverlapping();


