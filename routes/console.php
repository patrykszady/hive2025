<?php

use App\Jobs\DispatchIncompleteReceiptImageScrapesJob;
use App\Jobs\PruneFailedJobsJob;
use App\Jobs\PurgeOldCallRecordings;
use App\Jobs\RunScheduledTask;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Artisan;

Schedule::timezone('America/Chicago');

Schedule::job(new PurgeOldCallRecordings())
    ->dailyAt('03:15')
    ->name('purge-old-call-recordings')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer();

// Each village's adopted building codes (permit cover letters) are re-read
// from the village's own page on the first of the month, and towns with no
// page on file are looked up again. Code cycles change every few years and
// are announced on that page months ahead (Rolling Meadows: 1/1/2026).
Schedule::command('permits:refresh-village-codes --find')
    ->monthlyOn(1, '05:30')
    ->timezone('America/Chicago')
    ->name('refresh-village-codes')
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

// Email with project stakeholders (clients, vendors on the job) from every
// connected mailbox, inbox and sent, into the project timeline next to calls
// and texts. The Nylas webhook stores inbound mail sooner; this catches sent
// mail and anything the webhook missed.
Schedule::command('emails:sync')
    ->everyFiveMinutes()
    ->name('sync-stakeholder-emails')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer();

// The AI read for any stored email still without one: the first sync's
// backfill, and anything a queue outage dropped. SummarizeEmailMessage is
// unique per email, so an email already queued is not queued twice.
// The app's sheet exports and bank statements live under storage/app/exports
// behind 10-minute signed links; nothing needs them after that.
Schedule::command('exports:prune')
    ->hourly()
    ->name('prune-app-exports')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('emails:summarize --missing')
    ->everyFifteenMinutes()
    ->name('summarize-stakeholder-emails')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer();

// Keeps the Nylas message.created webhook converged without any deploy step:
// registers it if missing (the challenge handshake needs the LIVE endpoint,
// so deploy-time registration would race opcache), and rotates a lost signing
// secret back into the cache. Until it first succeeds, the endpoint 503s and
// the five-minute sweeps carry reply capture alone — nothing breaks.
// What the estimators changed after AI drafts, turned into proposed estimating rules.
Schedule::command('estimates:ai-propose-rules')
    ->dailyAt('04:10')
    ->withoutOverlapping();

Schedule::command('nylas:webhooks --ensure')
    ->hourly()
    ->name('ensure-nylas-webhook')
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

// A won lead's project waits in Consult until the meeting passes, then
// starts estimating on its own — see AdvancePastConsultProjects.
Schedule::command('projects:advance-past-consults')
    ->hourly()
    ->name('advance-past-consults')
    ->withoutOverlapping()
    ->onOneServer();

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

// Daily Plaid sync. Webhooks are the only other trigger, and they skip a bank
// in error — on 2026-10-06 a reconnected Citibank sat showing ITEM_LOGIN_REQUIRED
// with a week of charges unsynced. Every healthy bank is synced in case a
// webhook was missed; every bank in error is re-checked with Plaid and synced
// the moment Plaid reports it healthy. A reconnect on the banks page syncs at
// once; this is the daily try for everything else.
Schedule::command('plaid:sync-transactions --all')
    ->dailyAt('04:00')
    ->timezone('America/Chicago')
    ->name('plaid-transactions-sync-fallback')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer();

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

// An invoice entered as its own expense folds into the expense that paid it
// (National's invoices + payment confirmations were counting twice).
Schedule::command('expenses:merge-paid-invoices')
    ->hourly()
    ->name('merge-paid-invoices')
    ->environments(['production'])
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

// Amazon "Not <name> home" orders, nightly: business-paid ones go to that
// person's Home distribution, the rest are deleted once a week has passed
// with no charge linked — see RemoveNotOursExpenses.
Schedule::command('expenses:remove-not-ours')
    ->timezone('America/Chicago')
    ->dailyAt('02:00')
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

// ── Menards receipts ─────────────────────────────────────────────────────────
//
// Fetching happens inside the signed-in browser on the server: the receipt
// extension calls Menards' own JSON endpoints for every card on the account and
// POSTs the results to /api/menards/receipts, which queues the import.
//
// `menards:browser sync` is what starts a run. It opens the extension's own
// options page with ?sync=1 — a chrome-extension:// URL, so it never navigates
// menards.com and cannot draw the Imperva challenge that a page load can. The
// extension reuses the receipt tab that is already open and makes only XHR
// calls, which is why four runs a day costs no navigations at all.
//
// Four windows: 08:00, 12:00, 16:00 and 20:00 Central. Receipts appear in
// Menards' lookup within a few hours of a purchase, so this covers a working
// day without the volume that got this server challenged on 2026-08-23 — that
// was two walled NAVIGATIONS an hour, which is a different thing entirely.
foreach (['08:00', '12:00', '16:00', '20:00'] as $menardsSyncTime) {
    Schedule::command('menards:browser sync')
        ->dailyAt($menardsSyncTime)
        ->timezone('America/Chicago')
        ->name("menards-sync-{$menardsSyncTime}")
        ->environments(['production'])
        ->withoutOverlapping(15)
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/menards-ensure.log'));
}

// Health, once a day, half an hour before the first sync window — the browser
// only has to be alive and signed in by the time the extension fetches.
//
// Hourly was never justified (the extension owns the fetching; this only keeps
// the browser up), and on 2026-08-23 it was actively harmful: every run met
// Imperva's hCaptcha, so the schedule was making two walled navigations an hour
// at a site that scores exactly that. Retrying into a standing block feeds it
// rather than clearing it. ensure now also skips the browser entirely when a
// receipt batch arrived recently, so on a healthy day this touches nothing.
//
// When a pass finds something only a person can fix — the "I am human" wall
// above all — it sends a push notification to admins, throttled to once per
// reason per 12 hours. That is the part that matters: the original scraper
// failed into a log nobody read and stayed broken for two weeks.
// --signin: the daily pass may sign in (a plain ensure, the deploy's, never
// does); an uncleared security check pauses that for 12 hours.
Schedule::command('menards:browser ensure --signin')
    ->dailyAt('07:30')
    ->timezone('America/Chicago')
    ->environments(['production'])
    // 15 minutes, not the 24h default: this mutex lives in the file cache and
    // survives a reboot, so a crash mid-run would otherwise silently skip the
    // next day's pass too. The command holds its own lock as well, which is
    // what serializes it against the deploy-time run.
    ->withoutOverlapping(15)
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/menards-ensure.log'));

// Chrome on the server is updated by a weekly root job in Forge (Sundays
// 00:00 UTC; forge has no sudo). This is the check on that job, a day later:
// an admin is told in the app when Chrome is a release or more behind. The
// daily pass above restarts the browser onto an updated Chrome by itself.
Schedule::command('menards:browser chrome-check')
    ->weeklyOn(1, '07:00')
    ->timezone('America/Chicago')
    ->environments(['production'])
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/menards-ensure.log'));

// Keep-alive: Menards drops an idle session within about an hour, and every
// new sign-in draws Imperva's hCaptcha, which needs a person. One receipt-API
// call from inside the parked tab every 15 minutes (a random 0-3 minutes late,
// so the calls do not land on the clock) — never a navigation, which is what
// draws the challenge. Logs only when the answer changes. MENARDS_KEEPALIVE=false
// turns it off.
Schedule::command('menards:browser keepalive --jitter=180')
    ->everyFifteenMinutes()
    ->environments(['production'])
    ->withoutOverlapping(10)
    ->onOneServer()
    ->runInBackground();

// Retry scraping product images for material-order receipt items that are missing them
// Covers items where the initial scrape failed (API timeout, bad search query, etc.)
Schedule::job(new DispatchIncompleteReceiptImageScrapesJob)
    ->twiceDaily(10, 18)
    ->timezone('America/Chicago')
    ->name('retry-incomplete-receipt-images')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer();

// // Weekly EWCCV refresh: re-verify every active workers comp policy and keep
// // coverage-cancellation tracking subscribed. New/changed policies are handled
// // immediately by VendorDocObserver → LookupEwccvForVendor; this catches
// // subscriptions EWCCV dropped and policies whose lookups previously failed.
// Schedule::command('ewccv:scrape-tracking --belongs-to-vendor-id=1')->runInBackground()
//     ->weeklyOn(1, '07:00')
//     ->timezone('America/Chicago')
//     ->name('ewccv-weekly-refresh')
//     ->environments(['production'])
//     ->withoutOverlapping()
//     ->onOneServer()
//     ->appendOutputTo(storage_path('logs/ewccv-scraper.log'));

// // Daily confirmation of what EWCCV is actually tracking for us — this is the
// // half of the integration that runs unattended. Searching EWCCV is gated by
// // reCAPTCHA v3 Enterprise (a score no captcha service can supply), but its
// // subscription API answers to the ordinary login JWT, so reading our own
// // tracked policies needs no human and no captcha. Daily because a subscription
// // silently disappearing is exactly the failure we must notice: while a policy
// // is tracked, EWCCV emails us if it is cancelled or not renewed.
// Live since 2026-10-12: the magic-link sign-in reads the certificates
// mailbox (live.com now forwards EWCCV's mail), and new policies are searched
// by RunPendingEwccvLookups the moment the bridge extension hands over a
// browser session — the only credential EWCCV's search accepts from here.
// Audits whose EWCCV answers are all in get their package built and emailed.
Schedule::job(new \App\Jobs\SendAuditWhenReady)
    ->everyFifteenMinutes()
    ->name('audits-send-ready')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer();

// Two days before a workers comp certificate expires, ask EWCCV whether the
// policy is still in force that day (the answer becomes the Verified date).
Schedule::command('ewccv:expiring-checks --belongs-to-vendor-id=1')->runInBackground()
    ->dailyAt('06:00')
    ->timezone('America/Chicago')
    ->name('ewccv-expiring-checks')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/ewccv-scraper.log'));

Schedule::command('ewccv:sync-subscriptions --belongs-to-vendor-id=1')->runInBackground()
    ->dailyAt('06:30')
    ->timezone('America/Chicago')
    ->name('ewccv-subscription-sync')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/ewccv-scraper.log'));

// EWCCV's own notices (cancellation, non-renewal, reinstatement of a tracked
// policy) arrive by email; pin them to the doc and text the admins.
Schedule::command('ewccv:notices --belongs-to-vendor-id=1')->runInBackground()
    ->hourlyAt(20)
    ->name('ewccv-notices')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/ewccv-scraper.log'));

// System maintenance
Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->name('horizon-snapshot')
    ->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| SEO capability — shared ss-systems/platform-kit syncs
|--------------------------------------------------------------------------
|
| Staggered, overnight. Each no-ops cleanly (SUCCESS, a plain message)
| until its credential is set: seo:bing-sync until BING_WMT_KEY (or the
| admin-saved key) is present, seo:gsc-sync and seo:gsc-inspect-bulk until
| GSC_CREDENTIALS + GSC_PROPERTY are both set AND the service account has
| been added as a user on the property, seo:psi-sync until a PageSpeed key
| is saved from the SEO screen's Connect Services modal (see that
| command's own docblock for why it waits rather than running keyless),
| seo:clarity-sync until a Clarity project id + API token are saved
| (App\Support\Seo\ClaritySettings).
*/
Schedule::command('seo:bing-sync')
    ->dailyAt('03:00')
    ->name('seo-bing-sync')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/seo-bing-sync.log'))
    // New numbers landed: refresh where visitors come from for these days.
    ->then(fn () => Artisan::call('traffic:collect', ['--days' => 5, '--provider' => ['bing']]));

// What Bing holds of the site (kit 0.22.0, 2026-11-10): crawl stats, page
// stats and per-URL fetch info for the hub's Bing card (App\Http\
// Controllers\Api\Admin\V1\BingCoverageController). 40 minutes after the
// sync above so both finish well before the admin's morning check.
Schedule::command('seo:bing-coverage --limit=300')
    ->dailyAt('03:40')
    ->name('seo-bing-coverage')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/seo-bing-coverage.log'));

// The FAQ bank (kit 0.24.0, 2026-11-10): seo:faq-bank derives the questions
// and answers behind /geo/answers.json and /llms-full.txt from this site's
// own marketing pages and blog, through the OpenAI key (Anthropic as the
// fallback) — see AppServiceProvider's FaqBankSource/TextGenerator/
// FaqBankStore bindings and App\Support\Seo\FaqBank\HiveFaqBankSource for
// which pages. 150 pages a night covers the whole site (home, the product
// areas, every feature page, the homeowner pages, the blog) in one run, and
// an unchanged page costs nothing on the next. 04:50 Chicago, after the
// 04:00 jobs; this app's own timezone is UTC, so the zone is said here.
Schedule::command('seo:faq-bank --limit=150')
    ->dailyAt('04:50')
    ->timezone('America/Chicago')
    ->name('seo-faq-bank')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/seo-faq-bank.log'))
    ->onFailure(fn () => logger()->warning('Scheduled seo:faq-bank failed'));

Schedule::command('seo:gsc-sync')
    ->dailyAt('03:30')
    ->name('seo-gsc-sync')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/seo-gsc-sync.log'))
    // New numbers landed: refresh where visitors come from for these days.
    ->then(fn () => Artisan::call('traffic:collect', ['--days' => 5, '--provider' => ['search_console']]));

// The admin's "Pages Google Is Having Trouble With" card otherwise reads
// zeros/"never checked" forever, since nothing else calls URL Inspection.
// The kit rotates stale-first under its own daily/per-minute quota
// (UrlInspectionQuota), so a --limit here only bounds one run's slice.
Schedule::command('seo:gsc-inspect-bulk --limit=600 --markdown')
    ->dailyAt('04:00')
    ->name('seo-gsc-inspect-bulk')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/seo-gsc-inspect-bulk.log'));

Schedule::command('seo:psi-sync')
    ->dailyAt('04:15')
    ->name('seo-psi-sync')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/seo-psi-sync.log'));

// Microsoft Clarity (kit 0.20.0, 2026-11-02): writes today's site-wide
// clarity_daily_metrics row (App\Support\Seo\ClaritySettings' admin-saved-
// or-env project id/API token) for the weekly seo:clarity-health report
// below to read. No clarity_page_metrics table on this site, so the sync
// spends one of Clarity's ten daily API requests, not two.
Schedule::command('seo:clarity-sync --days=3')
    ->dailyAt('04:20')
    ->name('seo-clarity-sync')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/seo-clarity-sync.log'));

// Bing gets every sitemap this site's own robots.txt lists, submitting only
// the ones it does not hold yet (kit 0.19.4) — idempotent, so a daily run is
// cheap even once every map is already there.
Schedule::command('seo:bing-submit-sitemaps')
    ->dailyAt('01:50')
    ->name('seo-bing-submit-sitemaps')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/seo-bing-submit-sitemaps.log'));

// IndexNow (Bing/Yandex/Seznam/Naver): tells them which pages are new or
// changed since the last tick, via App\Support\Seo\IndexNowSiteKey (kit
// 0.19.4) — no-ops cleanly until a key exists, though IndexNowSiteKey
// generates one on first use, so this is never actually unconfigured.
Schedule::command('seo:indexnow')
    ->hourlyAt(40)
    ->name('seo-indexnow')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/seo-indexnow.log'));

// Where visitors come from (kit 0.17): every provider's per-source numbers
// into traffic_source_days for the SEO screen. After the Bing/GSC/PSI/
// Clarity syncs above; the last five days are re-read as Search Console
// fills in late. Production only, same as this block's other live-API
// syncs — Clarity's quota (10 requests/day) is shared with every tenant,
// and this collector spends one of its own (ClarityCollector, Source
// dimension) on top of seo:clarity-sync's (OS dimension) above.
Schedule::command('traffic:collect --days=5')
    ->dailyAt('07:30')
    ->name('traffic-collect')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/traffic-collect.log'));

/*
|--------------------------------------------------------------------------
| SEO capability — shared report library
|--------------------------------------------------------------------------
|
| Only the reports App\Support\Seo\Reports\ReportCapabilities marks
| available here. area-pages-audit alone needs a capability (area_catalog)
| this marketing site has no adapter for at all — scheduling it would only
| ever log a refusal, so it stays out. clarity-health and cwv-template ARE
| scheduled below (both now have real adapters — the kit's own
| Seo\Clarity\StoreClarityMetricsReader / PsiSnapshotStore), but each
| reads calmly as "not configured"/no rows until its credential is saved,
| same spirit as the syncs above.
|
| WEEKLY, not daily: these are deeper analyses than the daily sync/
| inspection jobs above. Sunday night, Chicago, staggered a comfortable
| distance after the 04:00 GSC inspection sweep — content-decay/
| content-gap read only gsc_query_metrics (seconds), gbp-parity fetches 3
| landing pages (seconds), the two self-crawl reports cap --limit well
| below their kit defaults so "nothing fetches hundreds of pages at once"
| holds even in the worst case, clarity-health/cwv-template are one API
| round trip each, and health reads only DB + the ledger file (seconds) so
| it runs last.
*/
// The report library counts a report as up to date for 24 hours; the weekly
// runs below left it reading "needs a refresh" six days in seven. Hourly, only
// the stale ones, one after another (a report it could not refresh waits six
// hours before the next try) — each report is rewritten about once a day.
Schedule::command('seo:reports-refresh --stale --automatic')
    ->hourlyAt(17)
    ->name('seo-reports-refresh')
    ->environments(['production'])
    ->withoutOverlapping(60)
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/seo-reports-refresh.log'));

Schedule::command('seo:content-decay --markdown')
    ->weeklyOn(0, '04:30')
    ->name('seo-content-decay')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/seo-content-decay.log'));

Schedule::command('seo:content-gap --markdown')
    ->weeklyOn(0, '04:35')
    ->name('seo-content-gap')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/seo-content-gap.log'));

Schedule::command('seo:gbp-parity --markdown')
    ->weeklyOn(0, '04:40')
    ->name('seo-gbp-parity')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/seo-gbp-parity.log'));

Schedule::command('seo:internal-link-suggest --limit=25 --markdown')
    ->weeklyOn(0, '04:45')
    ->name('seo-internal-link-suggest')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/seo-internal-link-suggest.log'));

Schedule::command('seo:schema-audit --limit=25 --markdown')
    ->weeklyOn(0, '05:00')
    ->name('seo-schema-audit')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/seo-schema-audit.log'));

Schedule::command('seo:health-check --limit=25 --min-score=0 --markdown')
    ->weeklyOn(0, '05:15')
    ->name('seo-health-check')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/seo-health-check.log'));

Schedule::command('seo:clarity-health --markdown')
    ->weeklyOn(0, '05:20')
    ->name('seo-clarity-health')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/seo-clarity-health.log'));

Schedule::command('seo:cwv-template --markdown')
    ->weeklyOn(0, '05:25')
    ->name('seo-cwv-template')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/seo-cwv-template.log'));

Schedule::command('seo:health --markdown')
    ->weeklyOn(0, '05:30')
    ->name('seo-health')
    ->environments(['production'])
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/seo-health.log'));

// Action items from calls and texts (ProjectActionItemExtractor): every
// planner project reads what came in since its last run, nightly.
Schedule::command('project:action-items --active')
    ->dailyAt('04:10')
    ->timezone('America/Chicago')
    ->onOneServer()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/action-items.log'));

// Our own visitors and contacts refresh every hour, today included; the
// platforms' numbers refresh right after each of their syncs above.
Schedule::command('traffic:collect --date=today --provider=beacon')
    ->hourly()->environments(['production'])
    ->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| The kit's SEO tools (0.19.1, 2026-11-01) — the cadence J. Peterson Design
| runs
|--------------------------------------------------------------------------
| Every paid run carries a --budget in USD and stops with a clear message
| when the DataForSEO balance cannot cover it. The weekly rank check posts
| standard-queue tasks; the daily --collect sweep picks up late answers.
| No seo:map-pack-grid/seo:map-pack-competitors lines here — this SaaS
| marketing site has no service-area coordinates (HiveSeoProfile::
| latitude()/longitude() are null), so there is no map-pack grid to scan;
| both commands are still registered (bootstrap/app.php) for anyone who
| wants to run them by hand.
*/
Schedule::command('seo:track-rankings')->weeklyOn(1, '04:00')
    ->name('seo-track-rankings')
    ->environments(['production'])
    ->withoutOverlapping()->onOneServer()
    ->appendOutputTo(storage_path('logs/seo-track-rankings.log'))
    ->onFailure(fn () => logger()->warning('Scheduled seo:track-rankings failed (balance?)'));
Schedule::command('seo:track-rankings --collect')->dailyAt('05:10')
    ->name('seo-track-rankings-collect')
    ->environments(['production'])
    ->withoutOverlapping()->onOneServer()
    ->appendOutputTo(storage_path('logs/seo-track-rankings.log'));
Schedule::command('seo:keyword-research --budget=4 --ideas')->weeklyOn(0, '04:30')
    ->name('seo-keyword-research')
    ->environments(['production'])
    ->withoutOverlapping()->onOneServer()
    ->appendOutputTo(storage_path('logs/seo-keyword-research.log'))
    ->onFailure(fn () => logger()->warning('Scheduled seo:keyword-research failed (balance?)'));
Schedule::command('seo:domain-overview --budget=2')->weeklyOn(0, '05:00')
    ->name('seo-domain-overview')
    ->environments(['production'])
    ->withoutOverlapping()->onOneServer()
    ->appendOutputTo(storage_path('logs/seo-domain-overview.log'))
    ->onFailure(fn () => logger()->warning('Scheduled seo:domain-overview failed (balance?)'));
Schedule::command('seo:backlink-gap --budget=1')->monthlyOn(2, '05:15')
    ->name('seo-backlink-gap')
    ->environments(['production'])
    ->withoutOverlapping()->onOneServer()
    ->appendOutputTo(storage_path('logs/seo-backlink-gap.log'))
    ->onFailure(fn () => logger()->warning('Scheduled seo:backlink-gap failed (balance?)'));
Schedule::command('seo:ai-mentions --budget=2')->weeklyOn(1, '05:30')
    ->name('seo-ai-mentions')
    ->environments(['production'])
    ->withoutOverlapping()->onOneServer()
    ->appendOutputTo(storage_path('logs/seo-ai-mentions.log'))
    ->onFailure(fn () => logger()->warning('Scheduled seo:ai-mentions failed (balance?)'));
Schedule::command('seo:competitor-brand-track --days=28 --markdown')->dailyAt('05:50')
    ->name('seo-competitor-brand-track')
    ->environments(['production'])
    ->withoutOverlapping()->onOneServer()
    ->appendOutputTo(storage_path('logs/seo-competitor-brand.log'))
    ->onFailure(fn () => logger()->error('Scheduled seo:competitor-brand-track failed'));

// Change intel: one DataForSEO family per slot, $1 cap each, the cadence
// gs.construction/J. Peterson Design run. The on-page crawl is posted on
// Monday and collected by the free pickup runs until it finishes.
foreach ([
    ['serp', 'weeklyOn', [3, '04:00']],
    ['trends', 'weeklyOn', [6, '05:30']],
    ['onpage', 'weeklyOn', [1, '03:30']],
    ['labs', 'weeklyOn', [1, '04:30']],
    ['backlinks', 'weeklyOn', [2, '04:00']],
    ['domain_analytics', 'monthlyOn', [4, '04:45']],
    ['content_analysis', 'weeklyOn', [4, '04:30']],
    ['ai_optimization', 'weeklyOn', [5, '04:30']],
] as [$family, $method, $at]) {
    Schedule::command("seo:intel {$family} --budget=1")->{$method}(...$at)
        ->name("seo-intel-{$family}")
        ->environments(['production'])
        ->withoutOverlapping(90)->onOneServer()
        ->appendOutputTo(storage_path('logs/seo-intel.log'))
        ->onFailure(fn () => logger()->warning("Scheduled seo:intel {$family} failed (balance?)"));
}
Schedule::command('seo:intel business_data --budget=1')->weekdays()->dailyAt('06:10')
    ->name('seo-intel-business_data')
    ->environments(['production'])
    ->withoutOverlapping(90)->onOneServer()
    ->appendOutputTo(storage_path('logs/seo-intel.log'))
    ->onFailure(fn () => logger()->warning('Scheduled seo:intel business_data failed (balance?)'));
Schedule::command('seo:intel onpage --budget=1')->cron('30 5-23/2 * * 1,2')
    ->name('seo-intel-onpage-collect')
    ->environments(['production'])
    ->withoutOverlapping(90)->onOneServer()
    ->appendOutputTo(storage_path('logs/seo-intel.log'));
