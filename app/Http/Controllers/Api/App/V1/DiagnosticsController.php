<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StoreAppCallingDiagnosticsRequest;
use App\Services\Calls\AppCallingDiagnostics;
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
}
