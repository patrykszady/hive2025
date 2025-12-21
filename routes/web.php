<?php

use App\Http\Controllers\CompanyEmailController;
use App\Http\Controllers\ExpenseAutoMatchController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\PlaidTransactionSyncController;
use App\Http\Controllers\Api\PlaidWebhookController;
use App\Http\Controllers\VendorDocsController;
use App\Http\Controllers\Api\EmailTrackingController;
use App\Http\Controllers\Api\MailtrapWebhookController;

use App\Livewire\Auth\CantLogin;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\Auth\VerifyResetCode;
use App\Livewire\Banks\BankIndex;
use App\Livewire\Banks\BankShow;
use App\Livewire\Categories\CategoriesIndex;
use App\Livewire\Checks\CheckShow;
use App\Livewire\Checks\ChecksIndex;
use App\Livewire\Clients\ClientsIndex;
use App\Livewire\Clients\ClientsShow;
use App\Livewire\CompanyEmails\CompanyEmailsIndex;
use App\Livewire\Dashboard\DashboardShow;
use App\Livewire\Distributions\DistributionsIndex;
use App\Livewire\Distributions\DistributionsShow;
use App\Livewire\EmailTemplates\EmailTemplateIndex;
use App\Livewire\Entry\Registration;
use App\Livewire\Entry\VendorRegistration;
use App\Livewire\Entry\VendorSelection;
use App\Livewire\Estimates\EstimateCreate;
use App\Livewire\Estimates\EstimateShow;
use App\Livewire\Estimates\EstimatesIndex;
use App\Livewire\Expenses\ExpenseIndex;
use App\Livewire\Expenses\ExpenseShow;
use App\Livewire\Hours\HourCreate;
use App\Livewire\Leads\LeadsIndex;
use App\Livewire\LineItems\LineItemsIndex;
use App\Livewire\Payments\PaymentCreate;
use App\Livewire\Payments\PaymentShow;
use App\Livewire\Payments\PaymentsIndex;
use App\Livewire\Planner\CardsIndex;
use App\Livewire\Planner\GanttIndex;
use App\Livewire\Projects\ProjectShow;
use App\Livewire\Projects\ProjectsIndex;
use App\Livewire\Sheets\SheetShow;
use App\Livewire\Sheets\SheetsIndex;
use App\Livewire\Timesheets\TimesheetCreate;
use App\Livewire\Timesheets\TimesheetPaymentCreate;
use App\Livewire\Timesheets\TimesheetPaymentIndex;
use App\Livewire\Timesheets\TimesheetShow;
use App\Livewire\Timesheets\TimesheetsIndex;
use App\Livewire\Transactions\MatchVendor;
use App\Livewire\Users\AdminLoginAsUser;
use App\Livewire\Users\UserShow;
use App\Livewire\VendorDocs\AuditShow;
use App\Livewire\VendorDocs\VendorDocsIndex;
use App\Livewire\Vendors\VendorPaymentCreate;
use App\Livewire\Vendors\VendorSheetsTypeIndex;

use App\Livewire\Vendors\VendorShow;
use App\Livewire\Vendors\VendorsIndex;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

//if guests go to '/', if logged in go to dashboard (or to /vendor_selection if not set and User has multiple)
Route::middleware('guest')->group(function () {
    Route::get('/', function () {
        return view('welcome');
    })->name('welcome');

    Route::get('login', Login::class)->name('login');
    Route::get('register', Register::class)->name('register');
    Route::get('cant-login', CantLogin::class)->name('cant.login');
    Route::get('verify-reset-code/{token}', VerifyResetCode::class)->name('verify.reset.code');

    Route::get('registration', Registration::class)->name('registration');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', function () {
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();
        return redirect('/');
    })->name('logout');

    Route::get('/vendor_selection', VendorSelection::class)->name('vendor_selection');
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

Route::get('/company-email/login', [CompanyEmailController::class, 'nylasLogin'])->name('company-email.login');
Route::get('/company-email/auth-response', [CompanyEmailController::class, 'nylasAuthResponse'])->name('company-email.auth-response');

//1-18-2023 combine the next 3 functions into one. Pass type = original or temp
// Route::get('/leads/leads_in_email', [LeadController::class, 'leads_in_email'])->name('leads.leads_in_email');

Route::get('vendor_docs/verifyWorkersComp', [ReceiptController::class, 'verifyWorkersComp'])->name('vendor_docs.verifyWorkersComp');
Route::get('receipts/home-depot-messages', [ReceiptController::class, 'getHomeDepotMessages'])->name('receipts.home-depot-messages');
Route::get('files/{folder}/{filename}', [ReceiptController::class, 'original_receipt'])->name('expenses.original_receipt');

Route::get('expenses/temp_receipt/{receipt}', [ReceiptController::class, 'temp_receipt'])->name('receipts.temp_receipt');

Route::get('receipts/azure_receipts', [ReceiptController::class, 'azure_receipts'])->name('azure_receipts');
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

// Mailtrap webhooks (no auth required - token is validated in the URL)
Route::post('webhooks/mailtrap/{token}', [MailtrapWebhookController::class, 'handle'])->name('webhooks.mailtrap');

// Email tracking pixel (no auth required - loaded by email clients)
Route::get('t/o', [EmailTrackingController::class, 'trackOpen'])->name('email.track.open');

Route::middleware(['auth', 'vendor.access'])->group(function () {
    // Registration route
    Route::get('vendor/registration/{vendor}', VendorRegistration::class)
        ->name('vendor_registration');
    
    // All protected routes
    Route::get('/dashboard', DashboardShow::class)->name('dashboard');

    // Stream vendor docs with case-insensitive lookup and proper headers
    Route::get('files/vendor_docs/{filename}', [VendorDocsController::class, 'document'])->name('vendor_docs.show');
    //USERS
    //Log In As User for Admins (User id # 1 right now only)
    //Only User #1 / Patryk can access this route / middleware
    Route::get('/users/admin_login_as_user', AdminLoginAsUser::class)->name('admin_login_as_user');

    //EXPENSES
    Route::get('/expenses', ExpenseIndex::class)->name('expenses.index');
    Route::get('/expenses/{expense}', ExpenseShow::class)->name('expenses.show');
    // Route::resource('expenses', ExpenseController::class);


    //DISTRIBUTIONS
    Route::get('/distributions', DistributionsIndex::class)->name('distributions.index');
    // Route::get('/distributions/create', DistributionsForm::class)->name('distributions.create');
    Route::get('/distributions/{distribution}', DistributionsShow::class)->name('distributions.show');

    //VENDORS
    Route::get('/vendors', VendorsIndex::class)->name('vendors.index');
    Route::get('/vendors/sheet_types', VendorSheetsTypeIndex::class)->name('vendors.sheets_type');
    Route::get('/vendors/{vendor}', VendorShow::class)
        // Redirect to dashboard if requesting own primary vendor (separate from authorization)
        ->middleware(['vendor.own-redirect', 'can:view,vendor'])
        ->name('vendors.show');
    Route::get('/vendors/{vendor}/payment', VendorPaymentCreate::class)->name('vendors.payment');

    //CATEGORIES
    Route::get('/categories', CategoriesIndex::class)->name('categories.index');

    //ESTIMATES
    Route::get('/estimates', EstimatesIndex::class)->name('estimates.index');
    Route::get('/estimates/create/{project}', EstimateCreate::class)->name('estimates.create');
    Route::get('/estimates/{estimate}', EstimateShow::class)->name('estimates.show');

    //VENDOR DOCS
    Route::get('/audit', AuditShow::class)->name('vendor_docs.audit');
    Route::get('/vendor_docs', VendorDocsIndex::class)->name('vendor_docs.index');

    //LEADS
    Route::get('/leads', LeadsIndex::class)->name('leads.index');

    //BANKS
    Route::get('/banks', BankIndex::class)->name('banks.index');
    Route::get('/banks/{bank}', BankShow::class)->name('banks.show');

    //CHECKS
    Route::get('/checks', ChecksIndex::class)->name('checks.index');
    Route::get('/checks/{check}', CheckShow::class)->name('checks.show');

    //COMPANY EMAILS
    Route::get('/company_emails', CompanyEmailsIndex::class)->name('company_emails.index');
    Route::get('/forward-receipt-emails', [CompanyEmailController::class, 'forwardRecentReceiptEmailsToCentral'])->name('forward.receipt.emails');

    //CLIENTS
    Route::get('/clients', ClientsIndex::class)->name('clients.index');
    Route::get('/clients/{client}', ClientsShow::class)->name('clients.show');

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
    Route::get('/planner/gantt', GanttIndex::class)->name('planner.gantt');
    Route::get('/planner/cards', CardsIndex::class)->name('planner.cards');
    
    //EMAIL TEMPLATES
    Route::get('/email_templates', EmailTemplateIndex::class)->name('email_templates.index');
});