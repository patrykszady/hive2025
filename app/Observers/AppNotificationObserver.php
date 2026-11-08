<?php

namespace App\Observers;

use App\Jobs\SendExpoPushForNotification;
use App\Models\AppNotification;

/**
 * The one choke point between every AppNotification::create() call site
 * (14 of them today) and a real device push — registered in
 * AppServiceProvider::bootEvent() alongside CallLogObserver/LeadObserver.
 * Push filtering (menards_browser, no registered device) lives inside the
 * job itself, not here, so this observer stays a pure dispatch.
 */
class AppNotificationObserver
{
    /**
     * Handle the AppNotification "created" event.
     */
    public function created(AppNotification $appNotification): void
    {
        SendExpoPushForNotification::dispatch($appNotification->id)->afterCommit();
    }
}
