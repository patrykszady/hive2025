<?php

namespace App\Services\Voice;

use App\Models\CallLog;
use App\Models\User;
use App\Services\GroupSmsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * An outbound call placed from Hive: the person's own phone rings first (their
 * cell, or their Hive Contractors app / browser), and once they answer the
 * webhook dials the target(s) from the business number and joins them in a
 * recorded conference (TelnyxWebhookController click-to-call handlers). One
 * implementation for "Call back", the Messages call button and the app.
 */
class ClickToCall
{
    public function __construct(protected AppVoiceCredentials $appVoice) {}

    /**
     * @param  array<int, string|null>  $targetPhones  numbers as typed or stored
     * @param  array<int, int>  $recipientIds  team members to ring in as well
     * @return array{ok: bool, error: ?string, call_log: ?CallLog, targets: array<int, string>}
     */
    public function start(User $user, array $targetPhones, array $recipientIds = [], bool $ringApp = false): array
    {
        $targets = collect($targetPhones)
            ->map(fn ($phone) => self::normalize($phone))
            ->filter()
            ->reject(fn (string $phone) => GroupSmsService::isOurNumber($phone))
            ->unique()
            ->values();

        if ($targets->isEmpty()) {
            return $this->failed(collect($targetPhones)->filter()->isNotEmpty() && collect($targetPhones)->every(fn ($phone) => GroupSmsService::isOurNumber((string) self::normalize($phone)))
                ? 'Cannot call the company phone number.'
                : 'No callable number.');
        }

        $userPhone = $user->routeNotificationForTelnyx();
        $appUri = $ringApp ? $this->appUriFor($user) : null;
        if ($ringApp && ! $appUri) {
            return $this->failed('Calling from the app is not set up yet.');
        }
        if (! $ringApp && ! $userPhone) {
            return $this->failed("You don't have a cell phone on file.");
        }

        $apiKey = config('services.telnyx.api_key');
        $connectionId = config('services.telnyx.connection_id');
        $from = config('services.telnyx.from');
        if (! $apiKey || ! $connectionId) {
            return $this->failed('Voice calling is not configured.');
        }

        $primary = $targets->first();
        $metadata = [
            'type' => $targets->count() > 1 ? 'click_to_call_multi' : 'click_to_call',
            'target_phone' => $primary,
            'target_phones' => $targets->all(),
            'target_count' => $targets->count(),
            // Still the person's cell: CallLog::agentUser() finds them by it.
            'user_phone' => $userPhone,
            'user_leg' => $ringApp ? 'app' : 'cell',
        ];
        $validRecipients = $recipientIds === [] ? [] : User::whereIn('id', $recipientIds)->whereNotNull('cell_phone')->where('cell_phone', '!=', '')->pluck('id')->all();
        if ($validRecipients !== []) {
            $metadata['click_to_call_recipient_ids'] = $validRecipients;
        }

        $callLog = CallLog::create([
            'direction' => 'outgoing',
            'from_number' => $from,
            'to_number' => $primary,
            'status' => CallLog::STATUS_INITIATED,
            'user_id' => $user->id,
            'vendor_id' => $user->vendor?->id,
            'metadata' => $metadata,
        ]);

        try {
            $response = Http::withToken($apiKey)->post('https://api.telnyx.com/v2/calls', array_filter([
                'connection_id' => $connectionId,
                'to' => $appUri ?? $userPhone,
                'from' => $from,
                'from_display_name' => 'GS Construction',
                'timeout_secs' => $ringApp ? 20 : (int) config('services.telnyx.voice_timeout', 30),
                'preferred_codecs' => config('services.telnyx.preferred_codecs'),
                // The app answers its own outgoing call by itself: this tells
                // it the ring is the call it just placed, not a new one.
                'custom_headers' => $ringApp ? [['name' => 'X-Hive-Outbound-Call', 'value' => (string) $callLog->id]] : null,
                'client_state' => base64_encode(json_encode([
                    'action' => 'click_to_call',
                    'target_phone' => $primary,
                    'target_phones' => $targets->all(),
                    'call_log_id' => $callLog->id,
                ])),
                'webhook_url' => self::webhookUrl(),
            ], fn ($value) => $value !== null));
        } catch (\Throwable $exception) {
            $callLog->update(['status' => CallLog::STATUS_FAILED]);
            Log::channel('telnyx')->error('Click-to-call exception', ['error' => $exception->getMessage()]);

            return $this->failed('Could not initiate the call.', $callLog);
        }

        if (! $response->successful()) {
            $callLog->update(['status' => CallLog::STATUS_FAILED]);
            Log::channel('telnyx')->error('Click-to-call API failed', ['status' => $response->status(), 'error' => $response->json()]);

            return $this->failed('Could not initiate the call.', $callLog);
        }

        $callLog->update([
            'call_control_id' => $response->json('data.call_control_id'),
            'call_session_id' => $response->json('data.call_session_id'),
            'call_leg_id' => $response->json('data.call_leg_id'),
        ]);

        Log::channel('telnyx')->info('Click-to-call initiated', [
            'call_log_id' => $callLog->id,
            'user_leg' => $metadata['user_leg'],
            'target_phone' => $primary,
            'target_count' => $targets->count(),
        ]);

        return ['ok' => true, 'error' => null, 'call_log' => $callLog, 'targets' => $targets->all()];
    }

    /** "(224) 555-1234", "2245551234" or "+12245551234" → "+12245551234". */
    public static function normalize(?string $phone): ?string
    {
        if (! is_string($phone) || trim($phone) === '') {
            return null;
        }

        $digits = (string) preg_replace('/\D/', '', $phone);
        if ($digits === '') {
            return null;
        }
        if (strlen($digits) === 10) {
            return '+1'.$digits;
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+'.$digits;
        }

        return '+'.$digits;
    }

    /** Telnyx must reach the webhook from outside: on a local dev box, through the public tunnel. */
    public static function webhookUrl(): string
    {
        $appUrl = (string) config('app.url');
        $publicUrl = config('services.telnyx.public_url');

        if ($publicUrl && (str_contains($appUrl, '127.0.0.1') || str_contains($appUrl, 'localhost'))) {
            return rtrim((string) $publicUrl, '/').'/webhooks/telnyx/voice';
        }

        return rtrim($appUrl, '/').'/webhooks/telnyx/voice';
    }

    protected function appUriFor(User $user): ?string
    {
        if (! $this->appVoice->enabled()) {
            return null;
        }

        return $this->appVoice->credentialFor($user)->sipUri();
    }

    /** @return array{ok: bool, error: ?string, call_log: ?CallLog, targets: array<int, string>} */
    protected function failed(string $error, ?CallLog $callLog = null): array
    {
        return ['ok' => false, 'error' => $error, 'call_log' => $callLog, 'targets' => []];
    }
}
