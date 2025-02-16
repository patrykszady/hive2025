<?php

namespace App\Observers;

use App\Models\Check;

class CheckObserver
{
    /**
     * Handle the Check "created" event.
     */
    public function created(Check $check): void
    {
        //
    }

    /**
     * Handle the Check "updated" event.
     */
    public function updated(Check $check): void
    {
        //
    }

    /**
     * Handle the Check "deleted" event.
     */
    public function deleted(Check $check): void
    {
        $check->expenses()->delete();
        $check->timesheets()->update(['check_id' => null]);
    }

    /**
     * Handle the Check "restored" event.
     */
    public function restored(Check $check): void
    {
        //
    }

    /**
     * Handle the Check "force deleted" event.
     */
    public function forceDeleted(Check $check): void
    {
        //
    }
}
