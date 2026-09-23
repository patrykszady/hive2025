<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessNylasInboundMessage;
use App\Jobs\SyncMeetTaskFromCalendar;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Nylas v3 webhook receiver — `message.created` on the personal mailbox
 * grants, which turns reply capture from a five-minute poll into a push, and
 * `event.updated`, which brings a Meet moved in Outlook or Google back to its
 * task. The reply sweeps stay scheduled as backfill: Nylas pauses webhooks
 * that fail repeatedly, and a paused webhook must not mean lost replies.
 * Meets have no sweep, deliberately: Hive reads a calendar event only when
 * Nylas says it changed.
 *
 * crew@ still cannot be pushed at all — it is a shared mailbox with no grant
 * of its own, so there is no synced object for Nylas to notify about (see
 * config/nylas.php). Its five-minute poll is a constraint, not a choice.
 *
 * The payload is treated as a DOORBELL, not as data: we take the grant and
 * message (or event) ids and re-fetch the object from the API before acting.
 * Webhook bodies are attacker-visible surface; the API is the source of truth.
 */
class NylasWebhookController extends Controller
{
    /**
     * Webhook creation handshake: Nylas GETs the callback with ?challenge=…
     * and expects the raw value echoed back.
     */
    public function verify(Request $request)
    {
        return response((string) $request->query('challenge', ''), 200)
            ->header('Content-Type', 'text/plain');
    }

    public function handle(Request $request)
    {
        // Env wins when set; otherwise the secret cached by
        // `nylas:webhooks --ensure` at registration/rotation time. The cached
        // path is what lets deploys be fully hands-free.
        $secret = (string) config('nylas.webhook_secret');
        if ($secret === '') {
            $secret = (string) cache()->get('nylas:webhook-secret', '');
        }

        if ($secret === '') {
            return response()->json(['ok' => false, 'error' => 'Nylas webhook is not configured.'], 503);
        }

        $signature = (string) $request->header('X-Nylas-Signature');
        $expected = hash_hmac('sha256', (string) $request->getContent(), $secret);

        if ($signature === '' || ! hash_equals($expected, $signature)) {
            Log::channel('nylas')->warning('Nylas webhook: bad signature', ['ip' => $request->ip()]);

            return response()->json(['ok' => false, 'error' => 'Unauthorized.'], 401);
        }

        $payload = $request->json()->all();
        $trigger = (string) ($payload['type'] ?? '');

        if ($trigger === 'event.updated') {
            return $this->queueMeetTaskSync((array) data_get($payload, 'data.object', []));
        }

        if ($trigger !== 'message.created') {
            // Subscribed triggers can outgrow the code; acknowledge so Nylas
            // doesn't retry, and leave a trace for whoever added the trigger.
            Log::channel('nylas')->info('Nylas webhook: ignoring trigger', ['type' => $trigger]);

            return response()->json(['ok' => true, 'ignored' => $trigger]);
        }

        $object = (array) data_get($payload, 'data.object', []);
        $grantId = trim((string) ($object['grant_id'] ?? ''));
        $messageId = trim((string) ($object['id'] ?? ''));

        if ($grantId === '' || $messageId === '') {
            return response()->json(['ok' => true, 'ignored' => 'no grant or message id']);
        }

        // Only the grants the reply pipeline reads. The webhook is
        // application-wide, so company-email grants used purely for sending
        // land here too — they are not reply mailboxes.
        $watched = array_map('trim', (array) config('nylas.crew_leads.grant_ids', []));
        if (! in_array($grantId, $watched, true)) {
            return response()->json(['ok' => true, 'ignored' => 'grant not watched']);
        }

        ProcessNylasInboundMessage::dispatch($grantId, $messageId);

        return response()->json(['ok' => true]);
    }

    /**
     * A calendar event changed somewhere in the application. Only a Meet's
     * own invite matters, matched on the event and grant ids stored when Hive
     * created it; the job re-reads the event from the API before moving the
     * task.
     *
     * @param  array<string, mixed>  $object
     */
    private function queueMeetTaskSync(array $object): JsonResponse
    {
        $eventId = trim((string) ($object['id'] ?? ''));
        $grantId = trim((string) ($object['grant_id'] ?? ''));

        $taskId = $eventId === '' || $grantId === '' ? null : Task::query()
            ->where('type', 'Meet')
            ->where('options->nylas_meet_event->event_id', $eventId)
            ->where('options->nylas_meet_event->grant_id', $grantId)
            ->value('id');

        if (! $taskId) {
            return response()->json(['ok' => true, 'ignored' => 'not a meet event']);
        }

        SyncMeetTaskFromCalendar::dispatch((int) $taskId);

        return response()->json(['ok' => true]);
    }
}
