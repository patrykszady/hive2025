<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// only in Production not in Development enviroment ... EVERYTHING EMAIL RELATED GOES HERE
// if(env('APP_ENV') == 'production'){
//->timezone('America/Chicago')->between('6:00', '20:00')
Schedule::call('\App\Http\Controllers\ReceiptController@ms_graph_email_api')->everyTenMinutes()->sendOutputTo(storage_path('logs/schedule.log'));
Schedule::call('\App\Http\Controllers\LeadController@leads_in_email')->everyTenMinutes()->sendOutputTo(storage_path('logs/schedule.log'));
Schedule::call('\App\Http\Controllers\TransactionController@plaid_item_status')->hourly()->sendOutputTo(storage_path('logs/schedule.log'));
Schedule::call('\App\Http\Controllers\TransactionController@plaid_transactions_sync')->hourly()->sendOutputTo(storage_path('logs/schedule.log'));
Schedule::call('\App\Http\Controllers\ReceiptController@amazon_orders_api')->hourly()->sendOutputTo(storage_path('logs/schedule.log'));
Schedule::call('\App\Http\Controllers\TransactionController@add_check_deposit_to_transactions')->everyTenMinutes()->sendOutputTo(storage_path('logs/schedule.log'));
Schedule::call('\App\Http\Controllers\TransactionController@add_vendor_to_transactions')->everyTenMinutes()->sendOutputTo(storage_path('logs/schedule.log'));
Schedule::call('\App\Http\Controllers\TransactionController@add_expense_to_transactions')->everyTenMinutes()->sendOutputTo(storage_path('logs/schedule.log'));
Schedule::call('\App\Http\Controllers\TransactionController@add_check_id_to_transactions')->everyTenMinutes()->sendOutputTo(storage_path('logs/schedule.log'));
Schedule::call('\App\Http\Controllers\TransactionController@add_payments_to_transaction')->everyTenMinutes()->sendOutputTo(storage_path('logs/schedule.log'));
Schedule::call('\App\Http\Controllers\TransactionController@add_transaction_to_expenses_sin_vendor')->everyTenMinutes()->sendOutputTo(storage_path('logs/schedule.log'));
Schedule::call('\App\Http\Controllers\TransactionController@find_credit_payments_on_debit')->everyTenMinutes()->sendOutputTo(storage_path('logs/schedule.log'));

//->timezone('America/Chicago')->between('6:00', '20:00')
Schedule::call('\App\Http\Controllers\ReceiptController@auto_receipt')->everyTenMinutes()->sendOutputTo(storage_path('logs/schedule.log'));
// Schedule::call('\App\Http\Controllers\TransactionController@add_transaction_to_multi_expenses')->everyTenMinutes();
Schedule::call('\App\Http\Controllers\TransactionController@add_category_to_expense')->hourly()->sendOutputTo(storage_path('logs/schedule.log'));

Schedule::call('\App\Http\Controllers\TransactionController@transaction_vendor_bulk_match')->everyTenMinutes()->sendOutputTo(storage_path('logs/schedule.log'));
// }

//everyMinute();
//Laravel 10+ requires this
//https://laravel.com/docs/10.x/upgrade#redis-cache-tags
Schedule::command('cache:prune-stale-tags')->hourly();

Schedule::command('horizon:snapshot')->everyFiveMinutes();
// dd(Schedule::events());

// Schedule::call('\App\Http\Controllers\TransactionController@plaid_item_error_update')->hourly();
// Schedule::command('inspire')->hourly();
