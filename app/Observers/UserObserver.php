<?php

namespace App\Observers;

use App\Models\User;
use App\Services\NylasContactSyncService;
use App\Services\SmsThreadLinker;

class UserObserver
{
    public function __construct(
        protected NylasContactSyncService $contactSyncService,
        protected SmsThreadLinker $smsThreadLinker,
    ) {
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

        $this->smsThreadLinker->linkThreadsForUser($user);
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

        if ($user->wasChanged('cell_phone')) {
            $this->smsThreadLinker->linkThreadsForUser($user);
        }
    }
}
