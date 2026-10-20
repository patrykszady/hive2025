<?php

namespace App\Services\Calls;

use App\Http\Controllers\Api\TelnyxWebhookController;
use App\Models\CallLog;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Dial someone into a live conference — shared by the web's
 * App\Livewire\Sms\Concerns\HasCallActions::inviteParticipantToCall() and the
 * app's CallParticipantController, so an admin on a live call can pull in a
 * teammate, a client, a vendor, or a hand-typed number identically from
 * either surface.
 */
class ConferenceInviter
{
    /**
     * @return array{ok: bool, code: ?string, heading: string, message: string}
     */
    public function invite(User $user, int $callLogId, ?int $participantId, string $participantPhone, string $participantName): array
    {
        // Only someone actually ON this call may pull in another person — but
        // that's the outbound initiator (user_id) OR an admin who joined an
        // inbound conference (joined_admin_ids). callLog may not exist at all.
        $callLog = CallLog::find($callLogId);
        $joinedAdminIds = array_map('intval', ($callLog?->metadata['joined_admin_ids'] ?? []));
        $isOwner = $callLog && $callLog->user_id === $user->id;
        $isConferenceAdmin = in_array($user->id, $joinedAdminIds, true);
        if (! $callLog || (! $isOwner && ! $isConferenceAdmin)) {
            return $this->failed('not_on_call', 'Invalid Call', 'Call not found or you are not on this call.');
        }

        // Ensure call is in a conference (TRANSFERRED status with conference_id in metadata)
        $metadata = $callLog->metadata ?? [];
        $conferenceId = $metadata['conference_id'] ?? null;

        if (! $conferenceId) {
            return $this->failed('no_conference', 'No Conference', 'This call is not currently in a conference.');
        }

        $apiKey = config('services.telnyx.api_key');
        $connectionId = config('services.telnyx.connection_id');
        $from = config('services.telnyx.from');

        if (! $apiKey || ! $connectionId) {
            return $this->failed('not_configured', 'Not Configured', 'Voice calling is not configured.');
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Accept' => 'application/json',
            ])->post('https://api.telnyx.com/v2/calls', [
                'connection_id' => $connectionId,
                'to' => $participantPhone,
                'from' => $from,
                'from_display_name' => 'GS Construction',
                'timeout_secs' => 30,
                'preferred_codecs' => config('services.telnyx.preferred_codecs'),
                // Same detection the team legs use (ADMIN_LEG_AMD_CONFIG): an
                // invited client, vendor, or hand-typed number can just as
                // easily reach a voicemail box, and a voicemail box must
                // never join a live conference.
                'answering_machine_detection' => 'premium',
                'answering_machine_detection_config' => TelnyxWebhookController::ADMIN_LEG_AMD_CONFIG,
                'client_state' => base64_encode(json_encode([
                    'action' => 'conference_invite',
                    'call_log_id' => $callLogId,
                    'conference_name' => $metadata['conference_name'] ?? null,
                    'caller_name' => $user->first_name ?? 'Someone',
                    'invited_by_user_id' => $user->id,
                    // Carried through so the intro TTS and the AMD-machine
                    // toast can name this leg without a non-user phone
                    // number (a vendor line, a hand-typed number) having to
                    // be re-resolved from the Telnyx API after the fact.
                    'participant_name' => $participantName,
                ])),
                'webhook_url' => config('app.url').'/webhooks/telnyx/voice',
            ]);
        } catch (\Exception $e) {
            Log::channel('telnyx')->error('Conference invite: exception', [
                'call_log_id' => $callLogId,
                'participant_id' => $participantId,
                'error' => $e->getMessage(),
            ]);

            return $this->failed('exception', 'Error', 'Something went wrong inviting the participant.');
        }

        if (! $response->successful()) {
            Log::channel('telnyx')->error('Conference invite: API call failed', [
                'call_log_id' => $callLogId,
                'participant_id' => $participantId,
                'status' => $response->status(),
                'error' => $response->json(),
            ]);

            return $this->failed('api_failed', 'Failed', 'Unable to invite participant. Please try again.');
        }

        Log::channel('telnyx')->info('Conference invite: participant call initiated', [
            'call_log_id' => $callLogId,
            'participant_id' => $participantId,
            'participant_phone' => $participantPhone,
            'participant_name' => $participantName,
            'conference_id' => $conferenceId,
        ]);

        return ['ok' => true, 'code' => null, 'heading' => 'Invited', 'message' => "Calling {$participantName}..."];
    }

    /** Whether $user may invite someone into $callLog's conference right now — the same guard invite() enforces. */
    public function isOnCall(User $user, CallLog $callLog): bool
    {
        $joinedAdminIds = array_map('intval', (array) ($callLog->metadata['joined_admin_ids'] ?? []));

        return $callLog->user_id === $user->id || in_array($user->id, $joinedAdminIds, true);
    }

    /**
     * @return array{ok: bool, code: ?string, heading: string, message: string}
     */
    protected function failed(string $code, string $heading, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'heading' => $heading, 'message' => $message];
    }
}
