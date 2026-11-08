<?php

namespace App\Services\Notifications;

use App\Models\PushToken;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The Expo Push API half of the app's notifications — the mobile analogue
 * of App\Services\WebPushService for the browser. No official PHP SDK
 * exists for Expo, so this is a thin Illuminate\Http wrapper, same choice
 * WebPushService made for minishlink/web-push.
 *
 * Expo caps a single /send request at 100 messages and recommends batching
 * /getReceipts ids at up to 1000, so both methods below chunk accordingly.
 * Token pruning happens in two places, mirroring WebPushService::
 * flushAndCleanup()'s precedent: immediately here when /send's own response
 * already reports DeviceNotRegistered, and again, asynchronously, in
 * checkReceipts() for failures the immediate response didn't catch yet
 * (Expo's delivery is "at-least-once handoff," not guaranteed final
 * delivery — a ticket can still fail after /send accepted it).
 */
class ExpoPushClient
{
    private const BASE_URL = 'https://exp.host/--/api/v2/push';

    /**
     * Sends $message to every token in $tokens (each token gets its own
     * "to", everything else in $message is shared). Returns the tickets
     * worth following up on later — i.e. every one /send didn't already
     * resolve as DeviceNotRegistered — as a list of
     * {ticket_id, token} pairs for CheckExpoPushReceipts.
     *
     * @param  list<string>  $tokens
     * @return list<array{ticket_id: string, token: string}>
     */
    public function send(array $tokens, array $message): array
    {
        $tickets = [];

        foreach (array_chunk($tokens, 100) as $batch) {
            $response = $this->post('send', array_map(
                fn (string $token): array => array_merge($message, ['to' => $token]),
                $batch,
            ));

            foreach ($response->json('data', []) as $index => $ticket) {
                $token = $batch[$index] ?? null;

                if ($token === null) {
                    continue;
                }

                if (($ticket['details']['error'] ?? null) === 'DeviceNotRegistered') {
                    PushToken::where('token', $token)->delete();

                    continue;
                }

                if (isset($ticket['id'])) {
                    $tickets[] = ['ticket_id' => $ticket['id'], 'token' => $token];
                }
            }
        }

        return $tickets;
    }

    /**
     * A silent badge-count-only push: no title/body/sound, just the OS
     * badge and a data flag the app can recognise and ignore for display
     * purposes. Best-effort — not worth tracking receipts for a badge
     * number the next real notification (or the app's own poll) will
     * correct anyway.
     *
     * @param  list<string>  $tokens
     */
    public function sendBadgeOnly(array $tokens, int $badge): void
    {
        foreach (array_chunk($tokens, 100) as $batch) {
            $this->post('send', array_map(fn (string $token): array => [
                'to' => $token,
                'badge' => $badge,
                'sound' => null,
                'data' => ['badge_only' => true],
            ], $batch));
        }
    }

    /**
     * The asynchronous half of pruning: looks each ticket id up against
     * Expo's /getReceipts, and deletes the PushToken for any that came
     * back DeviceNotRegistered. $tickets is send()'s own return value.
     *
     * @param  list<array{ticket_id: string, token: string}>  $tickets
     */
    public function checkReceipts(array $tickets): void
    {
        if ($tickets === []) {
            return;
        }

        $tokenByTicketId = collect($tickets)->pluck('token', 'ticket_id')->all();

        foreach (array_chunk(array_keys($tokenByTicketId), 1000) as $idBatch) {
            $response = $this->post('getReceipts', ['ids' => $idBatch]);
            $receipts = $response->json('data', []);

            foreach ($idBatch as $ticketId) {
                $error = $receipts[$ticketId]['details']['error'] ?? null;

                if ($error === 'DeviceNotRegistered') {
                    PushToken::where('token', $tokenByTicketId[$ticketId])->delete();
                }
            }
        }
    }

    private function post(string $endpoint, array $payload): Response
    {
        $request = Http::withHeaders([
            'accept' => 'application/json',
            'accept-encoding' => 'gzip, deflate',
            'content-type' => 'application/json',
        ]);

        if ($accessToken = config('services.expo.access_token')) {
            $request = $request->withToken($accessToken);
        }

        return $request->post(self::BASE_URL.'/'.$endpoint, $payload);
    }
}
