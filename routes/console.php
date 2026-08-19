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

// Prospect enquiries emailed to the crew@gs.construction shared mailbox.
// Polling rather than a webhook is forced, not chosen: crew@ is read through
// another grant's `shared_from`, and Nylas only raises message.created for a
// grant's OWN synced mailbox — a webhook could never fire for this one.
// Volume is tiny (281 messages lifetime), so five minutes is ample.
Schedule::command('crew:ingest-leads')
    ->everyFiveMinutes()
    ->name('ingest-crew-lead-emails')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer();

// The Azure secret behind every mailbox: check monthly, renew inside the
// 60-day window — its unwatched 2-year expiry took email down on 2026-08-11.
Schedule::command('nylas:rotate-microsoft-secret')
    ->monthlyOn(1, '11:00')
    ->name('rotate-nylas-ms-secret')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer();

// One nudge to leads we answered days ago that never booked or sent times.
// Mid-morning Central: business-hours mail, after the overnight ingests.
Schedule::command('leads:follow-up')
    ->dailyAt('15:00') // 10:00 America/Chicago
    ->name('lead-follow-ups')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('calls:reconcile-stale --execute')
    ->everyFiveMinutes()
    ->name('reconcile-stale-active-calls')
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
    ->everyTenMinutes()
    ->name('fetch-insurance-mailbox')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer();

// Returned wet-signed lien waiver / GCSS scans → match by footer barcode,
// store the scan, flip the record to Signed.
Schedule::job(new RunScheduledTask(\App\Services\WaiverScanIngest::class, 'processInbox'))
    ->everyTenMinutes()
    ->name('fetch-waiver-scans')
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

// Weekly EWCCV refresh: re-verify every active workers comp policy and keep
// coverage-cancellation tracking subscribed. New/changed policies are handled
// immediately by VendorDocObserver → LookupEwccvForVendor; this catches
// subscriptions EWCCV dropped and policies whose lookups previously failed.
Schedule::command('ewccv:scrape-tracking --belongs-to-vendor-id=1')->runInBackground()
    ->weeklyOn(1, '07:00')
    ->timezone('America/Chicago')
    ->name('ewccv-weekly-refresh')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/ewccv-scraper.log'));

// Daily confirmation of what EWCCV is actually tracking for us — this is the
// half of the integration that runs unattended. Searching EWCCV is gated by
// reCAPTCHA v3 Enterprise (a score no captcha service can supply), but its
// subscription API answers to the ordinary login JWT, so reading our own
// tracked policies needs no human and no captcha. Daily because a subscription
// silently disappearing is exactly the failure we must notice: while a policy
// is tracked, EWCCV emails us if it is cancelled or not renewed.
Schedule::command('ewccv:sync-subscriptions --belongs-to-vendor-id=1')->runInBackground()
    ->dailyAt('06:30')
    ->timezone('America/Chicago')
    ->name('ewccv-subscription-sync')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/ewccv-scraper.log'));

// System maintenance
Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->name('horizon-snapshot')
    ->withoutOverlapping();