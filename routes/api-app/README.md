One file per section of the Hive Contractors app's API. Each file is
`require`d inside the authenticated `app/v1` group in routes/api.php
(prefix `app/v1`, names `api.app.v1.*`, middleware auth:sanctum +
abilities:app + app.access), so a file only declares its own routes:

    Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses.index');
