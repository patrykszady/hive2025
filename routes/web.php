<?php

use App\Http\Controllers\CompanyEmailController;
use App\Http\Controllers\ExpenseAutoMatchController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\ShortLinkController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\PlaidTransactionSyncController;
use App\Http\Controllers\Api\PlaidWebhookController;
use App\Http\Controllers\Api\TelnyxWebhookController;
use App\Http\Controllers\VendorDocsController;
use App\Http\Controllers\Api\EmailTrackingController;
use App\Http\Controllers\Api\MailtrapWebhookController;

use App\Livewire\Auth\CantLogin;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\OneTimeCodeLogin;
use App\Livewire\Auth\VerifyResetCode;
use App\Livewire\Banks\BankIndex;
use App\Livewire\Banks\BankShow;
use App\Livewire\Categories\CategoriesIndex;
use App\Livewire\Checks\CheckShow;
use App\Livewire\Checks\ChecksIndex;
use App\Livewire\Client\ScheduleIndex as ClientScheduleIndex;
use App\Livewire\Clients\ClientsIndex;
use App\Livewire\Clients\ClientsShow;
use App\Livewire\CompanyEmails\CompanyEmailsIndex;
use App\Livewire\Dashboard\DashboardShow;
use App\Livewire\ReceiptAccounts\ReceiptAccountsIndex;
use App\Livewire\Distributions\DistributionsIndex;
use App\Livewire\Distributions\DistributionsShow;
use App\Livewire\EmailTemplates\EmailTemplateIndex;
use App\Livewire\Entry\Registration;
use App\Livewire\Entry\VendorRegistration;
use App\Livewire\Entry\VendorSelection;
use App\Livewire\Estimates\EstimateCreate;
use App\Livewire\Estimates\EstimateShow;
use App\Livewire\Estimates\EstimateSign;
use App\Models\Estimate;
use App\Support\EstimateDocumentGenerator;
use App\Livewire\Estimates\EstimatesIndex;
use App\Livewire\Expenses\AutoReceipts as ExpensesAutoReceipts;
use App\Livewire\Expenses\ExpenseIndex;
use App\Livewire\Expenses\ExpenseShow;
use App\Livewire\Hours\HourCreate;
use App\Livewire\Leads\LeadsIndex;
use App\Livewire\LineItems\LineItemsIndex;
use App\Livewire\Payments\PaymentCreate;
use App\Livewire\Payments\PaymentShow;
use App\Livewire\Payments\PaymentsIndex;
use App\Livewire\Planner\CardsIndex;
use App\Livewire\Receipts\ReceiptsIndex;
use App\Livewire\Projects\ProjectShow;
use App\Livewire\Projects\ProjectsIndex;
use App\Livewire\Sheets\SheetShow;
use App\Livewire\Sheets\SheetsIndex;
use App\Livewire\Sms\SmsIndex;
use App\Livewire\Timesheets\TimesheetCreate;
use App\Livewire\Timesheets\TimesheetPaymentCreate;
use App\Livewire\Timesheets\TimesheetPaymentIndex;
use App\Livewire\Timesheets\TimesheetShow;
use App\Livewire\Timesheets\TimesheetsIndex;
use App\Livewire\Transactions\MatchVendor;
use App\Livewire\Users\AdminLoginAsUser;
use App\Livewire\Users\UserShow;
use App\Livewire\Agents\AgentsIndex;
use App\Livewire\VendorDocs\AuditShow;
use App\Livewire\VendorDocs\VendorDocsIndex;
use App\Livewire\Vendors\VendorPaymentCreate;
use App\Livewire\Vendors\VendorSheetsTypeIndex;
use App\Livewire\Vendors\VendorOptions;

use App\Livewire\Vendor\AvailabilityIndex as VendorAvailabilityIndex;
use App\Livewire\Vendors\VendorShow;
use App\Livewire\Vendors\VendorsIndex;
use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Laragear\WebAuthn\Http\Routes as WebAuthnRoutes;
use App\Livewire\Auth\PasskeySetup;
use App\Livewire\Notifications\NotificationIndex;
use Illuminate\Support\Facades\Log;

Route::get('robots.txt', function () {
    $content = "User-agent: *\nDisallow: /\nAllow: /welcome\nAllow: /welcome/\n";

    return response($content, 200, ['Content-Type' => 'text/plain']);
})->name('robots');

// Public signed-URL stream of an SMS media file (used in outbound SMS so recipients
// can view full-quality video/audio/image without authentication, but the link
// itself is HMAC-signed and time-limited via signedRoute()).
Route::get('m/sms/{filename}', [VendorDocsController::class, 'smsMediaPublic'])
    ->where('filename', '.*')
    ->name('sms.media.public');

// Serve audio files with proper range request support (required for Telnyx streaming)
Route::get('telnyx-audio/{filename}', function (string $filename) {
    $path = public_path("audio/{$filename}");

    if (! file_exists($path) || ! preg_match('/\.(wav|mp3|ogg)$/i', $filename)) {
        abort(404);
    }

    $size = filesize($path);
    $mimeTypes = ['wav' => 'audio/wav', 'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg'];
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $mime = $mimeTypes[$ext] ?? 'application/octet-stream';

    $headers = [
        'Content-Type' => $mime,
        'Accept-Ranges' => 'bytes',
        'Cache-Control' => 'public, max-age=86400',
    ];

    // Handle range requests (required by Telnyx audio streaming)
    $range = request()->header('Range');
    if ($range && preg_match('/bytes=(\d+)-(\d*)/', $range, $matches)) {
        $start = (int) $matches[1];
        $end = $matches[2] !== '' ? (int) $matches[2] : $size - 1;
        $end = min($end, $size - 1);
        $length = $end - $start + 1;

        $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        $headers['Content-Length'] = $length;

        return response()->stream(function () use ($path, $start, $length) {
            $stream = fopen($path, 'rb');
            fseek($stream, $start);
            echo fread($stream, $length);
            fclose($stream);
        }, 206, $headers);
    }

    $headers['Content-Length'] = $size;

    return response()->file($path, $headers);
})->where('filename', '[a-zA-Z0-9_\-]+\.(wav|mp3|ogg)');

// Passkey debug logging endpoint (temporary for debugging)
Route::post('api/passkey-debug-log', function () {
    $data = request()->all();
    Log::channel('single')->info('PasskeyJS: ' . ($data['message'] ?? 'No message'), [
        'level' => $data['level'] ?? 'info',
        'data' => $data['data'] ?? [],
        'timestamp' => $data['timestamp'] ?? null,
        'url' => $data['url'] ?? null,
    ]);
    return response()->json(['ok' => true]);
})->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);

$hubRoutes = function () {

//if guests go to '/', if logged in go to dashboard (or to /account/selection if not set and User has multiple)
Route::middleware('guest')->group(function () {
    Route::get('/', function () {
        return redirect()->route('welcome', ['locale' => config('locales.default', 'en')]);
    })->name('home');

    Route::get('login', Login::class)->name('login');
    Route::get('cant-login', CantLogin::class)->name('cant.login');
    Route::get('one-time-login', OneTimeCodeLogin::class)->name('one-time-login');
    Route::get('verify-reset-code/{token}', VerifyResetCode::class)->name('verify.reset.code');

    Route::get('registration', Registration::class)->name('registration');
});

// Public marketing site — one canonical URL per language under its locale
// code: /en/welcome, /pl/welcome, /es/welcome. A single required {locale}
// prefix keeps route names unique and works in every environment (tests,
// route:cache, Octane); SetLocale sets the app locale and pins
// URL::defaults('locale') so every route('welcome') link stays
// language-correct with no call-site changes. Bare /welcome paths 301 to the
// default locale below, preserving old links and SEO.
$localePattern = implode('|', array_keys(config('locales.supported', ['en' => []])));

Route::prefix('{locale}')
    ->where(['locale' => $localePattern])
    ->middleware(\App\Http\Middleware\SetLocale::class)
    ->group(function () {
        Route::view('welcome', 'welcome')->name('welcome');

        Route::prefix('welcome')->name('welcome.')->group(function () {
            Route::view('finances', 'welcome.finances')->name('finances');
            Route::view('estimates', 'welcome.estimates')->name('estimates');
            Route::view('clients', 'welcome.clients')->name('clients');
            Route::view('vendors', 'welcome.vendors')->name('vendors');
            Route::view('planning', 'welcome.planning')->name('planning');
            Route::view('team', 'welcome.team')->name('team');
            Route::view('communication', 'welcome.communication')->name('communication');
            Route::view('automation', 'welcome.automation')->name('automation');
            Route::view('homeowners', 'welcome.homeowners')->name('homeowners');

            Route::prefix('homeowners')->name('homeowners.')->group(function () {
                Route::view('status', 'welcome.homeowners.status')->name('status');
                Route::view('schedule', 'welcome.homeowners.schedule')->name('schedule');
                Route::view('messaging', 'welcome.homeowners.messaging')->name('messaging');
                Route::view('photos', 'welcome.homeowners.photos')->name('photos');
                Route::view('documents', 'welcome.homeowners.documents')->name('documents');
                Route::view('payments', 'welcome.homeowners.payments')->name('payments');
                Route::view('selections', 'welcome.homeowners.selections')->name('selections');
                Route::view('notifications', 'welcome.homeowners.notifications')->name('notifications');
                Route::view('access', 'welcome.homeowners.access')->name('access');
            });

            // Include $locale in the signature: with the {locale} prefix the
            // route now has three params, and closure args bind positionally —
            // omitting $locale would shift the locale value into $area.
            Route::get('{area}/{card}', function (string $locale, string $area, string $card) {
                $areaConfig = marketing("areas.$area");
                abort_unless($areaConfig && isset($areaConfig['cards'][$card]), 404);

                return view('welcome.feature', [
                    'areaKey' => $area,
                    'cardKey' => $card,
                    'area' => $areaConfig,
                    'card' => $areaConfig['cards'][$card],
                ]);
            })->whereIn('area', array_keys(config('marketing.areas')))->name('feature');
        });

        // Standalone FAQ page
        Route::view('welcome/faq', 'welcome.faq')->name('welcome.faq');
    });

// Legal pages (public, no auth required) — un-prefixed, registered before the
// bare-welcome catch-all below so /welcome/legal/* keeps matching here.
Route::prefix('welcome/legal')->name('legal.')->group(function () {
    Route::view('privacy', 'legal.privacy-policy')->name('privacy');
    Route::view('terms', 'legal.terms-of-service')->name('terms');
});

// Bare (un-prefixed) marketing paths 301 to the default locale so old links,
// bookmarks, and existing SEO for /welcome keep resolving.
$defaultLocale = config('locales.default', 'en');
Route::permanentRedirect('welcome', "/{$defaultLocale}/welcome");
Route::get('welcome/{path}', function (string $path) use ($defaultLocale) {
    return redirect("/{$defaultLocale}/welcome/{$path}", 301);
})->where('path', '.*')->name('welcome.legacy');

Route::permanentRedirect('legal', '/welcome/legal');
Route::permanentRedirect('legal/privacy', '/welcome/legal/privacy');
Route::permanentRedirect('legal/terms', '/welcome/legal/terms');

// Short URLs for SMS
Route::permanentRedirect('p', '/welcome/legal/privacy');
Route::permanentRedirect('t', '/welcome/legal/terms');
Route::get('l/{code}', ShortLinkController::class)->name('short-links.redirect');

// Passkey setup page (requires auth)
Route::middleware('auth')->group(function () {
    Route::get('passkey/setup', PasskeySetup::class)->name('passkey.setup');
});

WebAuthnRoutes::register()->withoutMiddleware(VerifyCsrfToken::class);

// Short URL for SMS (redirects to full availability page)
Route::get('v/{token}', VendorAvailabilityIndex::class)->name('vendor.availability.short');

// Short URL for client schedule SMS
Route::get('s/{token}', ClientScheduleIndex::class)->name('client.schedule.short');

// Public lien waiver signing (token-based, no auth)


// Public vendor availability response routes (no auth required)
Route::prefix('vendor/availability')->name('vendor.availability.')->group(function () {
    Route::get('{token}', VendorAvailabilityIndex::class)->name('index');
});

// Public client schedule routes (no auth required)
Route::prefix('client/schedule')->name('client.schedule.')->group(function () {
    Route::get('{token}', ClientScheduleIndex::class)->name('index');
});

// Estimate signing (requires auth — guests are redirected to login)
Route::middleware(['auth', 'registered'])->group(function () {
    Route::get('estimate/sign/{estimate}', EstimateSign::class)
        ->name('estimate.sign');

    // Inline PDF preview for the signing page
    Route::get('estimate/sign/{estimate}/pdf', function (Estimate $estimate) {
        $estimate = Estimate::withoutGlobalScopes()
            ->with(['vendor.users', 'project.client.users'])
            ->find($estimate->id);

        abort_unless($estimate && $estimate->vendor, 404);

        $user = auth()->user();
        $vendorAdminIds = $estimate->vendor->users
            ->filter(fn ($u) => $u->pivot->role_id == 1 && $u->pivot->is_employed)
            ->pluck('id');
        $clientUserIds = $estimate->project?->client?->users?->pluck('id') ?? collect();

        abort_unless($vendorAdminIds->contains($user->id) || $clientUserIds->contains($user->id), 403);

        $result = EstimateDocumentGenerator::generate($estimate);

        return response($result['binary'])
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="' . $result['filename'] . '"')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    })->name('estimate.sign.pdf');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', function () {
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();
        return redirect('/');
    })->name('logout');
});

Route::middleware(['auth', 'registered'])->group(function () {
    Route::get('/account/selection', VendorSelection::class)->name('account_selection');
    Route::get('/notifications', NotificationIndex::class)->name('notifications.index');
});

if(env('APP_ENV') === 'local') {
    Route::get('/fetch-auto-receipts', [CompanyEmailController::class, 'fetchAutoReceipts'])->name('fetch.auto.receipts');
    Route::get('/fetch-consolidated-orders', [CompanyEmailController::class, 'fetchConsolidatedOrders'])->name('fetch.consolidated.orders');
    Route::get('/fetch-receipt-messages', [CompanyEmailController::class, 'fetchReceiptMessages'])->name('fetch.receipt.messages');
    Route::get('transaction_vendor_bulk_match', [TransactionController::class, 'transaction_vendor_bulk_match'])->name('transaction_vendor_bulk_match');
    Route::get('/insurance-mailbox/messages', [VendorDocsController::class, 'fetchMessagesFromInsuranceMailbox']);

    Route::get('/match-expense-po-to-project', [ExpenseAutoMatchController::class, 'runNoProjectExpenseAutoMatchRoute'])
        ->name('match_expense_po_to_project');

}

Route::middleware(['auth', 'registered'])->group(function () {
    Route::get('/menards-scrape-receipts', function () {
        \Illuminate\Support\Facades\Artisan::queue('menards:scrape-receipts', [
            '--match-expenses' => true,
            '--force' => true,
        ])->onQueue('long-running');

        return redirect('/horizon/jobs/pending');
    })->name('menards.scrape');

    Route::get('/activate-scheduled-projects', function () {
        \Illuminate\Support\Facades\Artisan::call('projects:activate-scheduled');

        return back()->with('success', \Illuminate\Support\Facades\Artisan::output());
    })->name('projects.activate-scheduled');
});

Route::get('/company-email/login', [CompanyEmailController::class, 'nylasLogin'])->name('company-email.login');
Route::get('/company-email/auth-response', [CompanyEmailController::class, 'nylasAuthResponse'])->name('company-email.auth-response');

//1-18-2023 combine the next 3 functions into one. Pass type = original or temp
// Route::get('/leads/leads_in_email', [LeadController::class, 'leads_in_email'])->name('leads.leads_in_email');

Route::get('vendor_docs/verifyWorkersComp', [ReceiptController::class, 'verifyWorkersComp'])->name('vendor_docs.verifyWorkersComp');
Route::get('receipts/home-depot-messages', [ReceiptController::class, 'getHomeDepotMessages'])->name('receipts.home-depot-messages');
Route::get('files/{folder}/{filename}', [ReceiptController::class, 'original_receipt'])->name('expenses.original_receipt');
Route::get('files/checks/files/{filename}', fn (string $filename) => app(ReceiptController::class)->original_receipt('checks/files', $filename))->name('checks.statement_pdf');

Route::get('expenses/temp_receipt/{receipt}', [ReceiptController::class, 'temp_receipt'])->name('receipts.temp_receipt');

Route::get('receipts/goutte_crawl', [ReceiptController::class, 'goutte_crawl'])->name('goutte_crawl');
// Route::get('new_ocr_status', [ReceiptController::class, 'new_ocr_status'])->name('new_ocr_status');

Route::get('plaid_transactions_sync', [PlaidTransactionSyncController::class, 'syncAllBanks']);
Route::get('plaid_statements_list', [TransactionController::class, 'plaid_statements_list']);
Route::get('plaid_transactions_refresh', [TransactionController::class, 'plaid_transactions_refresh']);
Route::get('plaid_item_status', [TransactionController::class, 'plaid_item_status']);
Route::get('plaid_transactions_enrich', [TransactionController::class, 'plaid_transactions_enrich']);
Route::get('add_vendor_to_transactions', [TransactionController::class, 'add_vendor_to_transactions']);
Route::get('add_expense_to_transactions', [TransactionController::class, 'add_expense_to_transactions']);
Route::get('add_transaction_to_multi_expenses', [TransactionController::class, 'add_transaction_to_multi_expenses']);
Route::get('add_check_id_to_transactions', [TransactionController::class, 'add_check_id_to_transactions']);
Route::get('add_check_deposit_to_transactions', [TransactionController::class, 'add_check_deposit_to_transactions']);
Route::get('add_payments_to_transaction', [TransactionController::class, 'add_payments_to_transaction']);
Route::get('add_transaction_to_expenses_sin_vendor', [TransactionController::class, 'add_transaction_to_expenses_sin_vendor']);
Route::get('find_credit_payments_on_debit', [TransactionController::class, 'find_credit_payments_on_debit']);
Route::get('transactions_sum_not_expense_amount', [TransactionController::class, 'transactions_sum_not_expense_amount']);
Route::get('add_category_to_expense', [TransactionController::class, 'add_category_to_expense']);

Route::get('receipts/amazon_login', [ReceiptController::class, 'amazon_login'])->name('amazon_login');
Route::get('receipts/amazon_auth_response', [ReceiptController::class, 'amazon_auth_response']);
Route::get('receipts/amazon_orders_api', [ReceiptController::class, 'amazon_orders_api']);

// Plaid webhooks (no auth required - Plaid sends these directly)
Route::post('webhooks/plaid', [PlaidWebhookController::class, 'handle'])->name('webhooks.plaid');

// Telnyx webhooks (SMS delivery status, inbound messages)
Route::post('webhooks/telnyx/messaging', [TelnyxWebhookController::class, 'handle'])
    ->middleware('telnyx.signature')
    ->name('webhooks.telnyx.messaging');

// Telnyx voice webhooks (call control - incoming calls, transfers, hangups)
Route::post('webhooks/telnyx/voice', [TelnyxWebhookController::class, 'handleVoice'])
    ->middleware('telnyx.signature')
    ->name('webhooks.telnyx.voice');

// Telnyx webhook health check (for monitoring receiver availability)
Route::get('webhooks/telnyx/health', [TelnyxWebhookController::class, 'health'])
    ->name('webhooks.telnyx.health');

// Mailtrap webhooks (no auth required - token is validated in the URL)
Route::post('webhooks/mailtrap/{token}', [MailtrapWebhookController::class, 'handle'])->name('webhooks.mailtrap');

// Email tracking pixel (no auth required - loaded by email clients)
Route::get('t/o', [EmailTrackingController::class, 'trackOpen'])->name('email.track.open');

Route::middleware(['auth', 'registered', 'vendor.access'])->group(function () {
    // Registration route
    Route::get('vendor/registration/{vendor}', VendorRegistration::class)
        ->name('vendor_registration');
    
    Route::get('/dashboard', function () {
        return redirect('/hub');
    });

    // All protected routes
    Route::get('/hub', DashboardShow::class)->name('dashboard');

    // Push subscription routes
    Route::get('/push/vapid-public-key', [PushSubscriptionController::class, 'vapidPublicKey'])
        ->name('push.vapid-public-key');
    Route::post('/push/subscribe', [PushSubscriptionController::class, 'store'])
        ->name('push.subscribe');
    Route::post('/push/status', [PushSubscriptionController::class, 'status'])
        ->name('push.status');
    Route::get('/push/subscriptions', [PushSubscriptionController::class, 'index'])
        ->name('push.subscriptions');
    Route::post('/push/preferences', [PushSubscriptionController::class, 'preferences'])
        ->name('push.preferences');
    Route::post('/push/unsubscribe', [PushSubscriptionController::class, 'destroy'])
        ->name('push.unsubscribe');

    // Stream vendor docs with case-insensitive lookup and proper headers
    Route::get('files/vendor_docs/{filename}', [VendorDocsController::class, 'document'])->name('vendor_docs.show');

    // Stream SMS media (authenticated)
    Route::get('files/sms_media/{filename}', [VendorDocsController::class, 'smsMedia'])
        ->where('filename', '.*')
        ->name('sms.media');

    //USERS
    //Log In As User for Admins (User id # 1 right now only)
    //Only User #1 / Patryk can access this route / middleware
    Route::get('/users/admin_login_as_user', AdminLoginAsUser::class)->name('admin_login_as_user');

    //EXPENSES
    Route::get('/expenses', ExpenseIndex::class)->name('expenses.index');
    Route::get('/expenses/auto-receipts', ExpensesAutoReceipts::class)->name('expenses.auto-receipts');
    Route::get('/expenses/{expense}', ExpenseShow::class)->name('expenses.show');
    // Route::resource('expenses', ExpenseController::class);


    //DISTRIBUTIONS
    Route::get('/distributions', DistributionsIndex::class)->name('distributions.index');
    // Route::get('/distributions/create', DistributionsForm::class)->name('distributions.create');
    Route::get('/distributions/{distribution}', DistributionsShow::class)->name('distributions.show');

    //VENDORS
    Route::get('/vendors', VendorsIndex::class)->name('vendors.index');
    Route::get('/vendors/sheet_types', VendorSheetsTypeIndex::class)->name('vendors.sheets_type');
    Route::get('/vendors/categories', CategoriesIndex::class)->name('categories.index');
    Route::get('/vendors/{vendor}', VendorShow::class)
        // Redirect to dashboard if requesting own primary vendor (separate from authorization)
        ->middleware(['vendor.own-redirect', 'can:view,vendor'])
        ->name('vendors.show');
    Route::get('/vendors/{vendor}/payment', VendorPaymentCreate::class)->name('vendors.payment');

    //ESTIMATES
    Route::get('/estimates', EstimatesIndex::class)->name('estimates.index');
    Route::get('/estimates/create/{project}', EstimateCreate::class)->name('estimates.create');
    Route::get('/estimates/{estimate}', EstimateShow::class)->name('estimates.show');

    //VENDOR DOCS
    Route::get('/audit', AuditShow::class)->name('vendor_docs.audit');
    Route::get('/vendor_docs', VendorDocsIndex::class)->name('vendor_docs.index');

    //INSURANCE AGENTS
    Route::get('/agents', AgentsIndex::class)->name('agents.index');

    //LEADS
    Route::get('/leads', LeadsIndex::class)->name('leads.index');

    //BANKS
    Route::get('/banks', BankIndex::class)->name('banks.index');
    Route::get('/banks/{bank}', BankShow::class)->name('banks.show');

    //CHECKS
    Route::get('/checks', ChecksIndex::class)->name('checks.index');
    Route::get('/checks/{check}', CheckShow::class)->name('checks.show');

    //LIEN WAIVERS
    Route::get('/lien-waivers', \App\Livewire\LienWaivers\Index::class)->name('lien-waivers.index');
    Route::get('/lien-waivers/{lienWaiver}', \App\Livewire\LienWaivers\Show::class)->name('lien-waivers.show');
    Route::get('/lien-waivers/{lienWaiver}/download', [\App\Http\Controllers\LienWaiverController::class, 'download'])->name('lien-waivers.download');
    Route::get('/sworn-statements/{swornStatement}/download', [\App\Http\Controllers\LienWaiverController::class, 'downloadSwornStatement'])->name('sworn-statements.download');
    Route::get('/sworn-statements/{swornStatement}/download-package', [\App\Http\Controllers\LienWaiverController::class, 'downloadDrawPackage'])->name('sworn-statements.download-package');

    //COMPANY EMAILS
    Route::get('/company_emails', CompanyEmailsIndex::class)->name('company_emails.index');
    Route::get('/forward-receipt-emails', [CompanyEmailController::class, 'forwardRecentReceiptEmailsToCentral'])->name('forward.receipt.emails');

    //VENDOR MATCH
    Route::get('/vendor_match', ReceiptAccountsIndex::class)->name('vendor_match.index');

    //CLIENTS
    Route::get('/clients', ClientsIndex::class)->name('clients.index');
    Route::get('/clients/{client}', ClientsShow::class)->name('clients.show');

    //MESSAGES
    Route::get('/messages', SmsIndex::class)
        ->name('sms.index');

    // Exit-beacon: threads stay unread while open and are marked read on exit.
    // In-app exits (thread switch / close) go through SmsConversation; this
    // handles leaving the page entirely (wire:navigate away, tab close) via
    // navigator.sendBeacon from the messages index.
    Route::post('/messages/threads/{thread}/read', function (\App\Models\SmsGroupThread $thread) {
        $user = auth()->user();

        if ($user->is_browsing_as_client) {
            abort_unless($thread->client_id && $user->clients()->pluck('clients.id')->contains($thread->client_id), 403);
        } else {
            $vendorId = $user->vendor?->id;
            abort_unless($vendorId && \App\Models\SmsGroupThread::where('id', $thread->id)->visibleToVendor($vendorId)->exists(), 403);
        }

        $latestMessageId = $thread->messages()->max('id');

        if ($latestMessageId) {
            \App\Models\SmsThreadRead::updateOrCreate(
                ['thread_id' => $thread->id, 'user_id' => $user->id],
                ['last_read_message_id' => $latestMessageId],
            );
        }

        return response()->noContent();
    })->name('sms.threads.read');

    // Call recording stream — uses BinaryFileResponse which natively supports
    // HTTP Range requests so <audio> can show real duration and seek (the
    // built-in `php artisan serve` dev server does not support Range on
    // /storage/* static files, so the browser shows 0:00 there).
    Route::get('/calls/{call}/recording', function (\App\Models\CallLog $call) {
        abort_unless($call->recording_path && $call->recording_disk, 404);
        $disk = \Illuminate\Support\Facades\Storage::disk($call->recording_disk);
        abort_unless($disk->exists($call->recording_path), 404);

        return response()->file($disk->path($call->recording_path), [
            'Content-Type' => 'audio/mpeg',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    })->name('calls.recording');

    //LINE ITEMS
    Route::get('/line_items', LineItemsIndex::class)->name('line_items.index');

    //PROJECTS
    Route::get('/projects', ProjectsIndex::class)->name('projects.index');
    Route::get('/projects/{project}', ProjectShow::class)->name('projects.show');
    // Route::get('projects/reimbursments/print/{project}', [ReceiptController::class, 'printReimbursment'])->name('print_reimbursment');

    //TIMESHEETS
    Route::get('/timesheets', TimesheetsIndex::class)
        ->middleware('can:viewAny,'.App\Models\Timesheet::class)
        ->name('timesheets.index');
    Route::get('/timesheets/create/{hour}', TimesheetCreate::class)->name('timesheets.create');
    Route::get('/timesheets/payment/{user}', TimesheetPaymentCreate::class)->name('timesheets.payment');
    Route::get('/timesheets/payments', TimesheetPaymentIndex::class)->name('timesheets.payments');
    Route::get('/timesheets/{timesheet}', TimesheetShow::class)->name('timesheets.show');

    //TRANSACTIONS
    Route::get('/transactions/match_vendor', MatchVendor::class)->name('transactions.match_vendor');

    //RECEIPTS
    Route::get('/receipts', ReceiptsIndex::class)->name('receipts.index');

    //USERS
    Route::get('/users/{user}', UserShow::class)->name('users.show');

    //HOURS
    Route::get('/hours/create', HourCreate::class)->name('hours.create');

    //PAYMENTS
    Route::get('/payments', PaymentsIndex::class)->name('payments.index');
    Route::get('/payments/{payment}', PaymentShow::class)->name('payments.show');

    //SHEETS
    Route::get('/sheets', SheetsIndex::class)->name('sheets.index');
    Route::get('/sheet_show', SheetShow::class)->name('sheets.show');

    //PLANNER
    Route::get('/planner/cards', CardsIndex::class)->name('planner.cards');
    
    //TEMPLATES
    Route::get('/templates', EmailTemplateIndex::class)->name('templates.index');

    //VENDOR OPTIONS
    Route::get('/options', VendorOptions::class)->name('vendor_options.index');
});

};

$hubRoutes();
