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
            'contentEncoding' => 'nullable|string|in:aesgcm,aes128gcm',
            'preferences.realtime_enabled' => 'nullable|boolean',
            'preferences.morning_enabled' => 'nullable|boolean',
            'preferences.evening_enabled' => 'nullable|boolean',
            'preferences.sms_inbound_enabled' => 'nullable|boolean',
        ]);

        $user = $request->user();

        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $preferences = (array) ($validated['preferences'] ?? []);
        $defaultPreferences = [
            'realtime_enabled' => true,
            'morning_enabled' => true,
            'evening_enabled' => true,
            'sms_inbound_enabled' => false,
        ];

        $preferenceValues = array_merge(
            $defaultPreferences,
            array_intersect_key($preferences, $defaultPreferences)
        );

        $contentEncoding = $validated['contentEncoding'] ?? $this->defaultContentEncodingForEndpoint($validated['endpoint']);

        PushSubscription::updateOrCreate(
            [
                'endpoint' => $validated['endpoint'],
            ],
            [
                'user_id' => $user->id,
                'p256dh' => $validated['keys']['p256dh'],
                'auth' => $validated['keys']['auth'],
                'content_encoding' => $contentEncoding,
                'realtime_enabled' => (bool) $preferenceValues['realtime_enabled'],
                'morning_enabled' => (bool) $preferenceValues['morning_enabled'],
                'evening_enabled' => (bool) $preferenceValues['evening_enabled'],
                'sms_inbound_enabled' => (bool) $preferenceValues['sms_inbound_enabled'],
                'user_agent' => $request->userAgent(),
                'last_seen_at' => now(),
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

        PushSubscription::where('endpoint', $validated['endpoint'])->delete();

        return response()->json(['success' => true]);
    }

    public function status(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => 'required|string',
        ]);

        $user = $request->user();

        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $subscription = PushSubscription::where('endpoint', $validated['endpoint'])
            ->where('user_id', $user->id)
            ->first();

        if (! $subscription) {
            return response()->json(['enabled' => false]);
        }

        $subscription->update([
            'last_seen_at' => now(),
        ]);

        return response()->json([
            'enabled' => true,
            'preferences' => [
                'realtime_enabled' => (bool) $subscription->realtime_enabled,
                'morning_enabled' => (bool) $subscription->morning_enabled,
                'evening_enabled' => (bool) $subscription->evening_enabled,
                'sms_inbound_enabled' => (bool) $subscription->sms_inbound_enabled,
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $currentEndpoint = (string) $request->query('endpoint', '');

        $subscriptions = PushSubscription::where('user_id', $user->id)
            ->orderByDesc('last_seen_at')
            ->get()
            ->map(function (PushSubscription $subscription) use ($currentEndpoint) {
                $enabled = (bool) ($subscription->realtime_enabled
                    || $subscription->morning_enabled
                    || $subscription->evening_enabled);

                return [
                    'id' => $subscription->id,
                    'is_current' => $currentEndpoint !== '' && $subscription->endpoint === $currentEndpoint,
                    'enabled' => $enabled,
                    'label' => $this->formatSubscriptionLabel($subscription->user_agent),
                    'last_seen_at' => optional($subscription->last_seen_at)->toIso8601String(),
                    'preferences' => [
                        'realtime_enabled' => (bool) $subscription->realtime_enabled,
                        'morning_enabled' => (bool) $subscription->morning_enabled,
                        'evening_enabled' => (bool) $subscription->evening_enabled,
                        'sms_inbound_enabled' => (bool) $subscription->sms_inbound_enabled,
                    ],
                ];
            })
            ->values();

        return response()->json([
            'subscriptions' => $subscriptions,
        ]);
    }

    public function preferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => 'required|string',
            'preferences.realtime_enabled' => 'sometimes|boolean',
            'preferences.morning_enabled' => 'sometimes|boolean',
            'preferences.evening_enabled' => 'sometimes|boolean',
            'preferences.sms_inbound_enabled' => 'sometimes|boolean',
        ]);

        $user = $request->user();

        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $subscription = PushSubscription::where('endpoint', $validated['endpoint'])
            ->where('user_id', $user->id)
            ->first();

        if (! $subscription) {
            return response()->json(['error' => 'Subscription not found'], 404);
        }

        $providedPreferences = (array) ($validated['preferences'] ?? []);

        $updates = [];

        if (array_key_exists('realtime_enabled', $providedPreferences)) {
            $updates['realtime_enabled'] = (bool) $providedPreferences['realtime_enabled'];
        }

        if (array_key_exists('morning_enabled', $providedPreferences)) {
            $updates['morning_enabled'] = (bool) $providedPreferences['morning_enabled'];
        }

        if (array_key_exists('evening_enabled', $providedPreferences)) {
            $updates['evening_enabled'] = (bool) $providedPreferences['evening_enabled'];
        }

        if (array_key_exists('sms_inbound_enabled', $providedPreferences)) {
            $updates['sms_inbound_enabled'] = (bool) $providedPreferences['sms_inbound_enabled'];
        }

        if ($updates === []) {
            return response()->json(['error' => 'No preferences provided'], 422);
        }

        $subscription->update($updates);

        return response()->json(['success' => true]);
    }

    protected function formatSubscriptionLabel(?string $userAgent): string
    {
        if (! $userAgent) {
            return 'Unknown browser';
        }

        $ua = strtolower($userAgent);

        $browser = match (true) {
            str_contains($ua, 'edg') => 'Edge',
            str_contains($ua, 'chrome') && ! str_contains($ua, 'edg') => 'Chrome',
            str_contains($ua, 'safari') && ! str_contains($ua, 'chrome') => 'Safari',
            str_contains($ua, 'firefox') => 'Firefox',
            default => 'Browser',
        };

        $platform = match (true) {
            str_contains($ua, 'iphone') || str_contains($ua, 'ipad') => 'iOS',
            str_contains($ua, 'android') => 'Android',
            str_contains($ua, 'mac') => 'macOS',
            str_contains($ua, 'win') => 'Windows',
            str_contains($ua, 'linux') => 'Linux',
            default => 'Device',
        };

        return $browser . ' on ' . $platform;
    }

    public function vapidPublicKey(): JsonResponse
    {
        return response()->json([
            'publicKey' => config('services.vapid.public_key'),
        ]);
    }

    protected function defaultContentEncodingForEndpoint(string $endpoint): string
    {
        if (str_contains($endpoint, 'notify.windows.com')) {
            return 'aesgcm';
        }

        return 'aes128gcm';
    }
}
