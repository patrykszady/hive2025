<?php

use App\Http\Controllers\Api\App\V1\VendorDocAuditController;
use App\Http\Controllers\Api\App\V1\VendorDocController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Vendor Documents: additions on top of the read/upload routes
|--------------------------------------------------------------------------
|
| vendor-docs(.show)/store and the read-only audit summary/banks routes
| already live in routes/api-app/settings.php (another track's file) —
| this adds the "Request Documents" action, and the audit actions that
| stayed web-only there (bank-statement gaps/"ask again", "check EWCCV
| now", the Excel export), so settings.php stays untouched. See
| App\Services\VendorDocs\{VendorDocRequester,AppAuditActions}.
*/
Route::post('vendor-docs/{vendor}/request', [VendorDocController::class, 'request'])
    ->whereNumber('vendor')
    ->name('vendor-docs.request');

Route::get('vendor-docs/audit/statement-gaps', [VendorDocAuditController::class, 'statementGaps'])
    ->name('vendor-docs.audit.statement-gaps');
Route::post('vendor-docs/audit/statements/{bank}/refresh', [VendorDocAuditController::class, 'refreshStatements'])
    ->whereNumber('bank')
    ->name('vendor-docs.audit.statements.refresh');
Route::post('vendor-docs/audit/ewccv/verify', [VendorDocAuditController::class, 'verifyEwccv'])
    ->name('vendor-docs.audit.ewccv.verify');
Route::get('vendor-docs/audit/export-xlsx-url', [VendorDocAuditController::class, 'exportXlsxUrl'])
    ->name('vendor-docs.audit.export-xlsx-url');
