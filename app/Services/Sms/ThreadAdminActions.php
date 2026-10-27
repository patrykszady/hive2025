<?php

namespace App\Services\Sms;

use App\Models\Client;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\User;
use App\Support\Sms\MessageQuoteMatcher;
use Illuminate\Validation\ValidationException;

/**
 * Delete / assign-client-or-vendor for a thread, and delete (or restore)
 * one of its messages — shared by the web conversation (App\Livewire\Sms\
 * SmsConversation::deleteThread()/assignClient()/deleteMessage()) and the
 * app's thread endpoints.
 */
class ThreadAdminActions
{
    public function delete(SmsGroupThread $thread): void
    {
        // The whole conversation goes for good, as before messages could be soft-deleted.
        $thread->messages()->withTrashed()->forceDelete();
        $thread->reads()->delete();
        $thread->threadParticipants()->delete();
        $thread->delete();
    }

    /**
     * Remove one text from the conversation in Hive (soft delete, with who
     * did it), with its reactions and edit notices. The people it went to
     * still have it — an SMS cannot be unsent. It leaves the thread, its
     * preview, search and the planner's reading; the rows stay for the
     * record. A reaction that arrives later is dropped as it is saved
     * (MessageQuoteMatcher::dropIfAboutDeleted).
     */
    public function deleteMessage(SmsMessage $message, User $by): void
    {
        // Its reactions and iMessage edit notices go with it: each quotes the
        // text, and would otherwise keep it in search or show it as a stray
        // bubble (MessageQuoteMatcher — the rule the thread folds them by).
        $followUps = app(MessageQuoteMatcher::class)->followUpsOf($message);

        foreach ($followUps as $followUp) {
            $followUp->forceFill(['deleted_by_user_id' => $by->id, 'deleted_with_message_id' => $message->id])->saveQuietly();
            $followUp->delete();
        }

        $message->forceFill(['deleted_by_user_id' => $by->id])->saveQuietly();
        $message->delete();
    }

    /**
     * Bring a deleted text back, with every reaction and edit notice that went
     * because of it — deleted with it, or dropped as they arrived after it was
     * deleted (deleted_with_message_id). The thread folds them into it again.
     *
     * @return int how many messages came back (the text included)
     */
    public function restoreMessage(SmsMessage $message): int
    {
        $followUps = SmsMessage::onlyTrashed()
            ->where('thread_id', $message->thread_id)
            ->where('deleted_with_message_id', $message->id)
            ->get();

        foreach ($followUps->push($message) as $back) {
            $back->restore();
            $back->forceFill(['deleted_by_user_id' => null, 'deleted_with_message_id' => null])->saveQuietly();
        }

        return $followUps->count();
    }

    /**
     * @param  'client'|'vendor'  $subjectType
     */
    public function assign(SmsGroupThread $thread, string $subjectType, ?int $clientId, ?int $vendorId): void
    {
        if ($subjectType === 'client') {
            // A bare table check that bypasses Client's tenant-scoping global
            // scope — same reasoning as StartThreadRequest/SmsConversation's
            // identical closure: a client id from outside the acting
            // vendor's own visibility is still a valid thing to assign to.
            if ($clientId && ! Client::query()->withoutGlobalScopes()->whereKey($clientId)->exists()) {
                throw ValidationException::withMessages(['client_id' => 'The selected client is invalid.']);
            }

            $thread->update([
                'client_id' => $clientId ?: null,
                'subject_vendor_id' => null,
            ]);

            return;
        }

        $thread->update([
            'client_id' => null,
            'subject_vendor_id' => $vendorId ?: null,
        ]);
    }
}
