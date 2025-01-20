<?php

use App\Http\Controllers\LeadController;
use App\Http\Controllers\MoveController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\VendorDocsController;
use App\Http\Controllers\WebhookController;
use App\Livewire\Banks\BankIndex;
use App\Livewire\Banks\BankShow;
use App\Livewire\BulkMatch\BulkMatchIndex;
use App\Livewire\Categories\CategoriesIndex;
use App\Livewire\Checks\CheckShow;
// use App\Livewire\CompanyEmails\CompanyEmailsForm;

// use App\Livewire\Users\UsersShow;
use App\Livewire\Checks\ChecksIndex;
use App\Livewire\Clients\ClientsIndex;
use App\Livewire\Clients\ClientsShow;
use App\Livewire\CompanyEmails\CompanyEmailsIndex;
use App\Livewire\Dashboard\DashboardShow;
use App\Livewire\Distributions\DistributionsIndex;
use App\Livewire\Distributions\DistributionsShow;
use App\Livewire\Entry\Registration;
use App\Livewire\Entry\VendorRegistration;
use App\Livewire\Entry\VendorSelection;
use App\Livewire\Estimates\EstimateCreate;
use App\Livewire\Estimates\EstimateShow;
use App\Livewire\Estimates\EstimatesIndex;
// use App\Http\Livewire\Distributions\DistributionsForm;
use App\Livewire\Expenses\ExpenseIndex;
use App\Livewire\Expenses\ExpenseShow;
use App\Livewire\Hours\HourCreate;
use App\Livewire\Leads\LeadsIndex;
use App\Livewire\LineItems\LineItemsIndex;
use App\Livewire\Payments\PaymentCreate;
use App\Livewire\Payments\PaymentsIndex;
use App\Livewire\Planner\PlannerIndex;
use App\Livewire\Projects\ProjectShow;
use App\Livewire\Projects\ProjectsIndex;
use App\Livewire\Sheets\SheetShow;
use App\Livewire\Sheets\SheetsIndex;
use App\Livewire\Tasks\Planner;
use App\Livewire\Tasks\PlannerList;
use App\Livewire\Test\Playground;
use App\Livewire\Test\Sorting;
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
use Illuminate\Support\Facades\Route;

// use App\Models\Expense;
// use Illuminate\Http\Request;

// Route::get('/search_test', function (Request $request) {
//     return Expense::search($request->search)->get();
// });

//if guests go to '/', if logged in go to dashboard (or to /vendor_selection if not set and User has multiple)
Route::middleware('guest')->group(function () {
    Route::get('/', function () {
        return view('welcome');
    })->name('welcome');

    Route::get('/login', function () {
        return view('auth.login');
    });

    Route::get('/registration', Registration::class)->middleware('guest')->name('registration');
    // Route::get('/registration-not-ready', Registration::class)->middleware('guest')->name('registration-not-ready');

});

Route::get('/move', [MoveController::class, 'move'])->name('move');

//3-29-2022 :it passes auth BUT FAILS user.vendor middleware, send to /vendor_selection if passes both..send to /dashboard
Route::get('/vendor_selection', VendorSelection::class)->middleware('auth')->name('vendor_selection');
Route::get('/vendor_registration/{vendor}', VendorRegistration::class)->middleware('auth')->name('vendor_registration');

//1-18-2023 combine the next 3 functions into one. Pass type = original or temp

Route::post('/webhooks/angi', [WebhookController::class, 'angi_webhook'])->withoutMiddleware([Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);
Route::get('/leads/leads_in_email', [LeadController::class, 'leads_in_email'])->name('leads.leads_in_email');

Route::get('vendor_docs/verifyWorkersComp', [ReceiptController::class, 'verifyWorkersComp'])->name('vendor_docs.verifyWorkersComp');
Route::get('expenses/original_receipts/{receipt}', [ReceiptController::class, 'original_receipt'])->name('expenses.original_receipt');
Route::get('expenses/temp_receipt/{receipt}', [ReceiptController::class, 'temp_receipt'])->name('receipts.temp_receipt');
Route::get('vendor_docs/{document}', [VendorDocsController::class, 'document'])->name('vendor_docs.document');

// Route::get('receipts/ms_graph_login', [ReceiptController::class, 'ms_graph_login'])->name('ms_graph_login');
// Route::get('receipts/ms_graph_auth_response', [ReceiptController::class, 'ms_graph_auth_response'])->name('ms_graph_auth_response');
Route::get('receipts/ms_graph_email_api', [ReceiptController::class, 'ms_graph_email_api'])->name('ms_graph_email_api');

Route::get('receipts/nylas_login', [ReceiptController::class, 'nylas_login'])->name('nylas_login');
Route::get('receipts/nylas_auth_response', [ReceiptController::class, 'nylas_auth_response'])->name('nylas_auth_response');
Route::get('receipts/nylas_read_email_receipts', [ReceiptController::class, 'nylas_read_email_receipts'])->name('nylas_read_email_receipts');
// Route::get('receipts/google_cloud_login', [ReceiptController::class, 'google_cloud_login'])->name('google_cloud_login');
// Route::get('receipts/google_cloud_auth_response', [ReceiptController::class, 'google_cloud_auth_response'])->name('google_cloud_auth_response');

Route::get('receipts/auto_receipt', [ReceiptController::class, 'auto_receipt'])->name('auto_receipt');
Route::get('receipts/azure_receipts', [ReceiptController::class, 'azure_receipts'])->name('azure_receipts');
Route::get('receipts/goutte_crawl', [ReceiptController::class, 'goutte_crawl'])->name('goutte_crawl');
Route::get('receipts/receipt_email', [ReceiptController::class, 'receipt_email'])->name('receipt_email');
// Route::get('new_ocr_status', [ReceiptController::class, 'new_ocr_status'])->name('new_ocr_status');

Route::get('projects/reimbursments/print/{project}', [ReceiptController::class, 'printReimbursment'])->name('print_reimbursment');

// Route::middleware('can:admin')->group(function () {
//     Route::resource('admin/posts', AdminPostController::class)->except('show');
// });

// Route::get('plaid_transactions_scheduled', [TransactionController::class, 'plaid_transactions_scheduled']);
Route::get('transaction_vendor_bulk_match', [TransactionController::class, 'transaction_vendor_bulk_match'])->name('transaction_vendor_bulk_match');
Route::get('plaid_statements_list', [TransactionController::class, 'plaid_statements_list']);
Route::get('plaid_transactions_refresh', [TransactionController::class, 'plaid_transactions_refresh']);
Route::get('plaid_transactions_sync', [TransactionController::class, 'plaid_transactions_sync']);
Route::get('plaid_item_status', [TransactionController::class, 'plaid_item_status']);
Route::get('plaid_transactions_get', [TransactionController::class, 'plaid_transactions_get']);
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

Route::get('insurance/find_insurance_dates', [VendorDocsController::class, 'find_insurance_dates']);

Route::get('transactions/bulk_match', BulkMatchIndex::class)->name('transactions.bulk_match');
//plaid webhooks
// Route::post('plaid_webhooks', 'TransactionController@plaid_webhooks');
// Route::get('fire_webhook', 'TransactionController@fire_webhook');

Route::middleware(['auth', 'user.vendor'])->group(function () {
    //DASHBOARD/ PRIMARY VENDOR
    Route::get('/dashboard', DashboardShow::class)->name('dashboard');

    //TESTS
    Route::get('/test/playground', Playground::class)->name('test.playground');
    Route::get('/test/sorting', Sorting::class)->name('test.sorting');
    //USERS
    // Route::get('/users/{user}', UsersShow::class)->name('users.show');
    //Log In As User for Admins (User id # 1 right now only)
    //Only User #1 / Patryk can access this route / middleware
    Route::get('/users/admin_login_as_user', AdminLoginAsUser::class)->name('admin_login_as_user');

    //EXPENSES
    Route::get('/expenses', ExpenseIndex::class)->name('expenses.index');
    // Route::get('/expenses/new', ExpensesNewForm::class)->name('expenses.new');
    // Route::get('/expenses/find', ExpensesFind::class)->name('expenses.find');
    Route::get('/expenses/{expense}', ExpenseShow::class)->name('expenses.show');
    // Route::resource('expenses', ExpenseController::class);

    //DISTRIBUTIONS
    Route::get('/distributions', DistributionsIndex::class)->name('distributions.index');
    // Route::get('/distributions/create', DistributionsForm::class)->name('distributions.create');
    Route::get('/distributions/{distribution}', DistributionsShow::class)->name('distributions.show');

    //VENDORS
    Route::get('/vendors', VendorsIndex::class)->name('vendors.index');
    Route::get('/vendors/sheet_types', VendorSheetsTypeIndex::class)->name('vendors.sheets_type');
    Route::get('/vendors/{vendor}', VendorShow::class)->name('vendors.show');
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
    // Route::post('/webhook', [LeadsIndex::class, 'handle']);

    Route::get('/leads', LeadsIndex::class)->name('leads.index');

    //BANKS
    Route::get('/banks', BankIndex::class)->name('banks.index');
    Route::get('/banks/{bank}', BankShow::class)->name('banks.show');

    //CHECKS
    Route::get('/checks', ChecksIndex::class)->name('checks.index');
    Route::get('/checks/{check}', CheckShow::class)->name('checks.show');

    //COMPANY EMAILS
    Route::get('/company_emails', CompanyEmailsIndex::class)->name('company_emails.index');

    //CLIENTS
    Route::get('/clients', ClientsIndex::class)->name('clients.index');
    Route::get('/clients/{client}', ClientsShow::class)->name('clients.show');

    //LINE ITEMS
    Route::get('/line_items', LineItemsIndex::class)->name('line_items.index');

    //PROJECTS
    Route::get('/projects', ProjectsIndex::class)->name('projects.index');
    Route::get('/projects/{project}', ProjectShow::class)->name('projects.show');

    //TIMESHEETS
    Route::get('/timesheets', TimesheetsIndex::class)->name('timesheets.index');
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
    Route::get('/payments/create/{client}', PaymentCreate::class)->name('payments.create');

    //SHEETS
    Route::get('/sheets', SheetsIndex::class)->name('sheets.index');
    Route::get('/sheet_show', SheetShow::class)->name('sheets.show');

    //PLANNER
    Route::get('/planner', PlannerIndex::class)->name('planner.index');

    //TASKS
    // Route::get('/planner_gantt', Planner::class)->name('planner.index');
    // Route::get('/planner_schedule', PlannerList::class)->name('planner_list.index');
});

require __DIR__.'/auth.php';
