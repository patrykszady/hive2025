<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => 'required|string|max:500',
            'keys.p256dh' => 'required|string',
            'keys.auth' => 'required|string',
        ]);

        $user = $request->user();

        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        PushSubscription::updateOrCreate(
            [
                'user_id' => $user->id,
                'endpoint' => $validated['endpoint'],
            ],
            [
                'p256dh' => $validated['keys']['p256dh'],
                'auth' => $validated['keys']['auth'],
            ]
        );

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => 'required|string',
        ]);

        $user = $request->user();

        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        PushSubscription::where('user_id', $user->id)
            ->where('endpoint', $validated['endpoint'])
            ->delete();

        return response()->json(['success' => true]);
    }

    public function vapidPublicKey(): JsonResponse
    {
        return response()->json([
            'publicKey' => config('services.vapid.public_key'),
        ]);
    }
}
