<?php

namespace App\Http\Controllers;

use App\Models\CallLog;
use App\Models\User;
use App\Services\Voice\InternalCalls;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The Decline button on an internal call's push notification: the service
 * worker hits this signed link with Hive closed, so there is no session to
 * rely on — the signature names the call and the callee.
 */
class InternalCallController extends Controller
{
    public function decline(Request $request, CallLog $call, InternalCalls $calls): Response
    {
        $callee = User::withoutGlobalScopes()->find((int) $request->query('user'));

        if ($callee) {
            $calls->decline($call, $callee);
        }

        return response()->noContent();
    }
}
