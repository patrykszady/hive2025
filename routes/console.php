<?php

use App\Jobs\DispatchIncompleteReceiptImageScrapesJob;
use App\Jobs\PruneFailedJobsJob;
use App\Jobs\PurgeOldCallRecordings;
use App\Jobs\RunScheduledTask;
use Illuminate\Support\Facades\Schedule;

Schedule::timezone('America/Chicago');

Schedule::job(new PurgeOldCallRecordings())
    ->dailyAt('03:15')
    ->name('purge-old-call-recordings')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('calls:process-recordings --retry-failed')
    ->everyFiveMinutes()
    ->name('process-call-recordings')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer();

// Schedule::call(function () {
//     app(\App\Http\Controllers\LeadController::class)->leads_in_email();
// })->everyTenMinutes()
//   ->name('leads-in-email')
//   ->withoutOverlapping()
//   ->onOneServer();

Schedule::job(new RunScheduledTask(\App\Http\Controllers\CompanyEmailController::class, 'dispatchAutoReceiptMailboxJobs'))
    ->everyTenMinutes()
    // ->between('7:00', '22:00')
    ->name('fetch-auto-receipts')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new RunScheduledTask(\App\Http\Controllers\CompanyEmailController::class, 'forwardRecentReceiptEmailsToCentral'))
    ->everyTenMinutes()
    // ->between('7:00', '22:00')
    ->name('forward-recent-receipts-to-central')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new RunScheduledTask(\App\Http\Controllers\CompanyEmailController::class, 'fetchReceiptMessages'))
    ->everyTenMinutes()
    // ->between('7:00', '22:00')
    ->name('fetch-receipt-messages')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new RunScheduledTask(\App\Http\Controllers\VendorDocsController::class, 'fetchMessagesFromInsuranceMailbox'))
    ->hourly()
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

Schedule::command('projects:activate-scheduled')
    ->dailyAt('04:00')
    ->timezone('America/Chicago')
    ->name('activate-scheduled-projects')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('sms:send-scheduled')
    ->everyTenMinutes()
    ->between('05:00', '22:00')
    ->name('send-scheduled-sms')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new PruneFailedJobsJob)
    ->weeklyOn(0, '03:00')
    ->name('prune-failed-jobs')
    ->withoutOverlapping()
    ->onOneServer();

// ─── Unified Task Notifications ─────────────────────────
// Shared notification schedule config
$notifyTimezone = config('sms.business_hours.timezone', 'America/Chicago');
$notifyStartTime = sprintf('%02d:%02d', config('sms.business_hours.start_hour', 7), config('sms.business_hours.start_minute', 0));
$notifyEndTime = sprintf('%02d:%02d', config('sms.business_hours.end_hour', 20) - 1, 0); // 1 hour before end (7 PM default)

// Morning digest: sends today's tasks via SMS, Email, and Push to all users
Schedule::job(new \App\Jobs\SendDigestNotifications('morning'))
  ->dailyAt($notifyStartTime)
  ->timezone($notifyTimezone)
  ->name('digest-notifications-morning')
  ->environments(['production'])
  ->withoutOverlapping()
  ->onOneServer();

// Evening digest: sends tomorrow's tasks via SMS, Email, and Push to all users
Schedule::job(new \App\Jobs\SendDigestNotifications('evening'))
  ->dailyAt($notifyEndTime)
  ->timezone($notifyTimezone)
  ->name('digest-notifications-evening')
  ->environments(['production'])
  ->withoutOverlapping()
  ->onOneServer();

// Plaid/Transaction tasks
// Fallback status check in case webhook delivery fails or is delayed
Schedule::job(new RunScheduledTask(\App\Http\Controllers\TransactionController::class, 'plaid_item_status'))
    ->hourly()
    ->name('plaid-item-status')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer();

// Daily fallback for Plaid transaction sync (in case webhooks miss updates)
// Schedule::command('plaid:sync-transactions --all')
//   ->dailyAt('04:00')
//   ->name('plaid-transactions-sync-fallback')
//   ->environments(['production'])
//   ->withoutOverlapping()
//   ->onOneServer();

Schedule::job(new RunScheduledTask(\App\Http\Controllers\TransactionController::class, 'add_check_deposit_to_transactions'))
    ->everyTenMinutes()
    ->name('add-check-deposits')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new RunScheduledTask(\App\Http\Controllers\TransactionController::class, 'add_vendor_to_transactions'))
    ->everyTenMinutes()
    ->name('add-vendor-to-transactions')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new RunScheduledTask(\App\Http\Controllers\TransactionController::class, 'add_expense_to_transactions'))
    ->everyTenMinutes()
    ->name('add-expense-to-transactions')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new RunScheduledTask(\App\Http\Controllers\TransactionController::class, 'add_transaction_to_multi_expenses'))
    ->everyTenMinutes()
    ->name('add-transaction-to-multi-expenses')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new RunScheduledTask(\App\Http\Controllers\TransactionController::class, 'add_check_id_to_transactions'))
    ->everyTenMinutes()
    ->name('add-check-id-to-transactions')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new RunScheduledTask(\App\Http\Controllers\TransactionController::class, 'add_payments_to_transaction'))
    ->everyTenMinutes()
    ->name('add-payments-to-transactions')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new RunScheduledTask(\App\Http\Controllers\TransactionController::class, 'add_transaction_to_expenses_sin_vendor'))
    ->everyTenMinutes()
    ->name('add-transactions-to-expenses')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new RunScheduledTask(\App\Http\Controllers\TransactionController::class, 'find_credit_payments_on_debit'))
    ->everyTenMinutes()
    ->name('find-credit-payments')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new RunScheduledTask(\App\Http\Controllers\TransactionController::class, 'match_associated_expenses'))
    ->everyTenMinutes()
    ->name('match-associated-expenses')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new RunScheduledTask(\App\Http\Controllers\TransactionController::class, 'add_category_to_expense'))
    ->hourly()
    ->name('add-category-to-expense')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new RunScheduledTask(\App\Http\Controllers\TransactionController::class, 'transaction_vendor_bulk_match'))
    ->everyTenMinutes()
    ->name('transaction-vendor-bulk-match')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new RunScheduledTask(
    \App\Http\Controllers\ExpenseAutoMatchController::class,
    'runNoProjectExpenseAutoMatch',
    [null, null, null, false, true, true],
))
    ->everyTenMinutes()
    ->name('match-expense-po-to-project')
    ->withoutOverlapping()
    ->onOneServer();

// External API tasks
Schedule::job(new RunScheduledTask(\App\Http\Controllers\ReceiptController::class, 'amazon_orders_api', [], null, 1, 5400, 7200))
    ->everyTwoHours()
    ->between('6:00', '22:00')
    ->name('amazon-orders-api')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer();

// Nightly full sync for Amazon orders (catches cancellations/returns)
Schedule::job(new RunScheduledTask(\App\Http\Controllers\ReceiptController::class, 'amazon_orders_api', [], null, 1, 5400, 7200))
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

// Menards receipt scraping — 4× daily (2-day lookback to catch delayed postings)
$menardsLogPath = storage_path('logs/menards-scraper.log');
$menardsSince = now()->subDays(2)->format('Y-m-d');
foreach (['08:05', '12:05', '16:05', '20:05'] as $time) {
    Schedule::command("menards:scrape-receipts --match-expenses --force --since={$menardsSince}")->runInBackground()
        ->dailyAt($time)
        ->timezone('America/Chicago')
        ->name("menards-scrape-{$time}")
        ->environments(['production'])
        ->withoutOverlapping()
        ->onOneServer()
        ->appendOutputTo($menardsLogPath);
}

// Retry scraping product images for material-order receipt items that are missing them
// Covers items where the initial scrape failed (API timeout, bad search query, etc.)
Schedule::job(new DispatchIncompleteReceiptImageScrapesJob)
    ->twiceDaily(10, 18)
    ->timezone('America/Chicago')
    ->name('retry-incomplete-receipt-images')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer();

// System maintenance
Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->name('horizon-snapshot')
    ->withoutOverlapping();