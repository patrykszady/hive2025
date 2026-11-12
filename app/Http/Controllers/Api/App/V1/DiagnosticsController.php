<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StoreAppCallingDiagnosticsRequest;
use App\Http\Requests\Api\App\StoreAppErrorReportsRequest;
use App\Services\Calls\AppCallingDiagnostics;
use App\Services\Diagnostics\AppErrorReports;
use Illuminate\Http\JsonResponse;

/**
 * The app's on-device calling log, uploaded once it can next reach the
 * server — see App\Services\Calls\AppCallingDiagnostics.
 */
class DiagnosticsController extends Controller
{
    public function calling(StoreAppCallingDiagnosticsRequest $request, AppCallingDiagnostics $diagnostics): JsonResponse
    {
        $stored = $diagnostics->ingest($request->user(), $request->validated('events'));

        return response()->json(['stored' => $stored], 201);
    }

    /** JavaScript errors the app caught on the phone — see App\Services\Diagnostics\AppErrorReports. */
    public function errors(StoreAppErrorReportsRequest $request, AppErrorReports $reports): JsonResponse
    {
        $stored = $reports->ingest($request->user(), $request->validated('errors'), (string) $request->ip(), (string) $request->userAgent());

        return response()->json(['stored' => $stored], 201);
    }
}
