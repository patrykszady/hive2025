<?php

use Illuminate\Support\Facades\Schedule;

// Email processing tasks
Schedule::call(function () {
    app(\App\Http\Controllers\CompanyEmailController::class)->fetchMessagesForGrantId();
})->everyTenMinutes()
  ->name('fetch-messages-for-grant-id')
  ->withoutOverlapping()
  ->onOneServer();

Schedule::call(function () {
    app(\App\Http\Controllers\LeadController::class)->leads_in_email();
})->everyTenMinutes()
  ->name('leads-in-email')
  ->withoutOverlapping()
  ->onOneServer();

Schedule::call(function () {
    app(\App\Http\Controllers\CompanyEmailController::class)->fetchAutoReceipts();
})->everyTenMinutes()
  ->name('fetch-auto-receipts')
  ->withoutOverlapping()
  ->onOneServer();

Schedule::call(function () {
    app(\App\Http\Controllers\VendorDocsController::class)->fetchMessagesFromInsuranceMailbox();
})->hourly()
  ->name('fetch-insurance-mailbox')
  ->withoutOverlapping()
  ->onOneServer();

// Plaid/Transaction tasks
Schedule::call(function () {
    app(\App\Http\Controllers\TransactionController::class)->plaid_item_status();
})->hourly()
  ->name('plaid-item-status')
  ->withoutOverlapping()
  ->onOneServer();

Schedule::call(function () {
    app(\App\Http\Controllers\TransactionController::class)->plaid_transactions_sync();
})->hourly()
  ->name('plaid-transactions-sync')
  ->withoutOverlapping()
  ->onOneServer();

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
})->hourly()
  ->name('amazon-orders-api')
  ->withoutOverlapping()
  ->onOneServer();

// Daily tasks
Schedule::call(function () {
    app(\App\Http\Controllers\TaskReminderController::class)->sendTomorrowReminders();
})->dailyAt('19:00')
  ->name('send-tomorrow-reminders')
  ->withoutOverlapping()
  ->onOneServer();

// System maintenance
Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->name('horizon-snapshot')
    ->withoutOverlapping();
