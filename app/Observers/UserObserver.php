<?php

namespace App\Observers;

use App\Models\User;
use App\Services\NylasContactSyncService;

class UserObserver
{
    protected NylasContactSyncService $contactSyncService;

    public function __construct(NylasContactSyncService $contactSyncService)
    {
        $this->contactSyncService = $contactSyncService;
    }

    /**
     * Handle the User "updated" event.
     * Update Nylas contacts when user information changes
     */
    public function updated(User $user): void
    {
        // Only sync if relevant fields changed
        if ($user->wasChanged(['first_name', 'last_name', 'email', 'cell_phone'])) {
            $this->contactSyncService->updateContactsForUser($user);
        }
    }
}
