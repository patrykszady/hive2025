<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

// CompanyEmailController@fetchMessagesForGrantId
Schedule::call(function () {
    $output = app(\App\Http\Controllers\CompanyEmailController::class)
        ->fetchMessagesForGrantId();
    Log::channel('schedule')->info('fetchMessagesForGrantId executed', ['output' => $output]);
})->everyTenMinutes();

// LeadController@leads_in_email
Schedule::call(function () {
    $output = app(\App\Http\Controllers\LeadController::class)
        ->leads_in_email();
    Log::channel('schedule')->info('leads_in_email executed', ['output' => $output]);
})->everyTenMinutes();

// CompanyEmailController@fetchAutoReceipts
Schedule::call(function () {
    $output = app(\App\Http\Controllers\CompanyEmailController::class)
        ->fetchAutoReceipts();
    Log::channel('schedule')->info('fetchAutoReceipts executed', ['output' => $output]);
})->everyTenMinutes();

// TransactionController@plaid_item_status
Schedule::call(function () {
    $output = app(\App\Http\Controllers\TransactionController::class)
        ->plaid_item_status();
    Log::channel('schedule')->info('plaid_item_status executed', ['output' => $output]);
})->hourly();

// TransactionController@plaid_transactions_sync
Schedule::call(function () {
    $output = app(\App\Http\Controllers\TransactionController::class)
        ->plaid_transactions_sync();
    Log::channel('schedule')->info('plaid_transactions_sync executed', ['output' => $output]);
})->hourly();

// ReceiptController@amazon_orders_api
Schedule::call(function () {
    $output = app(\App\Http\Controllers\ReceiptController::class)
        ->amazon_orders_api();
    Log::channel('schedule')->info('amazon_orders_api executed', ['output' => $output]);
})->hourly();

// TransactionController@add_check_deposit_to_transactions
Schedule::call(function () {
    $output = app(\App\Http\Controllers\TransactionController::class)
        ->add_check_deposit_to_transactions();
    Log::channel('schedule')->info('add_check_deposit_to_transactions executed', ['output' => $output]);
})->everyTenMinutes();

// TransactionController@add_vendor_to_transactions
Schedule::call(function () {
    $output = app(\App\Http\Controllers\TransactionController::class)
        ->add_vendor_to_transactions();
    Log::channel('schedule')->info('add_vendor_to_transactions executed', ['output' => $output]);
})->everyTenMinutes();

// TransactionController@add_expense_to_transactions
Schedule::call(function () {
    $output = app(\App\Http\Controllers\TransactionController::class)
        ->add_expense_to_transactions();
    Log::channel('schedule')->info('add_expense_to_transactions executed', ['output' => $output]);
})->everyTenMinutes();

// TransactionController@add_check_id_to_transactions
Schedule::call(function () {
    $output = app(\App\Http\Controllers\TransactionController::class)
        ->add_check_id_to_transactions();
    Log::channel('schedule')->info('add_check_id_to_transactions executed', ['output' => $output]);
})->everyTenMinutes();

// TransactionController@add_payments_to_transaction
Schedule::call(function () {
    $output = app(\App\Http\Controllers\TransactionController::class)
        ->add_payments_to_transaction();
    Log::channel('schedule')->info('add_payments_to_transaction executed', ['output' => $output]);
})->everyTenMinutes();

// TransactionController@add_transaction_to_expenses_sin_vendor
Schedule::call(function () {
    $output = app(\App\Http\Controllers\TransactionController::class)
        ->add_transaction_to_expenses_sin_vendor();
    Log::channel('schedule')->info('add_transaction_to_expenses_sin_vendor executed', ['output' => $output]);
})->everyTenMinutes();

// TransactionController@find_credit_payments_on_debit
Schedule::call(function () {
    $output = app(\App\Http\Controllers\TransactionController::class)
        ->find_credit_payments_on_debit();
    Log::channel('schedule')->info('find_credit_payments_on_debit executed', ['output' => $output]);
})->everyTenMinutes();

// TransactionController@add_category_to_expense
Schedule::call(function () {
    $output = app(\App\Http\Controllers\TransactionController::class)
        ->add_category_to_expense();
    Log::channel('schedule')->info('add_category_to_expense executed', ['output' => $output]);
})->hourly();

// TransactionController@transaction_vendor_bulk_match
Schedule::call(function () {
    $output = app(\App\Http\Controllers\TransactionController::class)
        ->transaction_vendor_bulk_match();
    Log::channel('schedule')->info('transaction_vendor_bulk_match executed', ['output' => $output]);
})->everyTenMinutes();

// VendorDocsController@fetchMessagesFromInsuranceMailbox
Schedule::call(function () {
    $output = app(\App\Http\Controllers\VendorDocsController::class)
        ->fetchMessagesFromInsuranceMailbox();
    Log::channel('schedule')->info('fetchMessagesFromInsuranceMailbox executed', ['output' => $output]);
})->hourly();

// horizon:snapshot command
Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->appendOutputTo(storage_path('logs/horizon_snapshot-' . date('Y-m-d') . '.log'));

// Example: uncomment if needed
// Schedule::command('cache:prune-stale-tags')->hourly();
