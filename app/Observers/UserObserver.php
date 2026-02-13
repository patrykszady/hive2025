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
     * Handle the User "created" event.
     * Create default notification settings for every new user.
     */
    public function created(User $user): void
    {
        $user->notificationSetting()->create([
            'realtime_sms' => true,
        ]);
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
