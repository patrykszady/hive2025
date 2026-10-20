<?php

namespace App\Services\Sms;

use App\Models\SmsGroupThread;
use App\Models\SmsThreadParticipant;
use App\Services\GroupSmsService;

/**
 * Consent (opt-in) actions shared by the web conversation
 * (App\Livewire\Sms\SmsConversation::resendOptInPrompt()/manualOptIn()) and
 * the app's thread opt-in endpoints — both call this so a resend or a
 * manual opt-in behaves identically on either side.
 */
class ThreadOptInManager
{
    public function __construct(private GroupSmsService $smsService)
    {
    }

    /**
     * Resend the START consent prompt. Returns false (no-op) when every
     * participant has already opted in — GroupSmsService::resendConsentPrompt()
     * already guards this, but the caller needs to know whether anything
     * actually went out.
     */
    public function resend(SmsGroupThread $thread): bool
    {
        return $this->smsService->resendConsentPrompt($thread);
    }

    /**
     * Manually mark one participant opted in (e.g. consent captured over the
     * phone), recording who did it and why, then send the welcome message
     * if every participant is now opted in.
     */
    public function manualOptIn(SmsGroupThread $thread, int $participantId, string $reason, int $actorId): SmsThreadParticipant
    {
        $participant = SmsThreadParticipant::findOrFail($participantId);

        if ((int) $participant->thread_id !== (int) $thread->id) {
            abort(403);
        }

        $participant->update([
            'opted_in_at' => now(),
            'manual_opt_in_reason' => $reason,
            'manual_opt_in_by' => $actorId,
        ]);

        $this->smsService->markParticipantOptedInAndSendWelcomeIfReady(
            $thread,
            $participant->phone_number,
            $actorId,
        );

        return $participant->refresh();
    }
}
