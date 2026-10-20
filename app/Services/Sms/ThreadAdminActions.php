<?php

namespace App\Services\Sms;

use App\Models\Client;
use App\Models\SmsGroupThread;
use Illuminate\Validation\ValidationException;

/**
 * Delete / assign-client-or-vendor for a thread — shared by the web
 * conversation (App\Livewire\Sms\SmsConversation::deleteThread()/
 * assignClient()) and the app's thread endpoints.
 */
class ThreadAdminActions
{
    public function delete(SmsGroupThread $thread): void
    {
        $thread->messages()->delete();
        $thread->reads()->delete();
        $thread->threadParticipants()->delete();
        $thread->delete();
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
