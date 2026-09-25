<?php

use App\Models\SmsGroupThread;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('sms.notifications', function ($user) {
    // Was `(bool) $user` — any authenticated user, including a client
    // (homeowner) user with no reason to see SMS/call-log ids. Restricted to
    // users who belong to at least one vendor (the staff SMS inbox this
    // channel serves).
    return (bool) $user?->is_vendor_user;
});
