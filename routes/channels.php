<?php

use App\Models\SmsGroupThread;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('sms.thread.{threadId}', function ($user, $threadId) {
    // Any authenticated non-client user can listen to SMS threads
    return ! $user->is_client_user;
});

Broadcast::channel('sms.notifications', function ($user) {
    return (bool) $user;
});
