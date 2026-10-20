<?php

use App\Http\Controllers\Api\App\V1\ThreadController as AppThreadController;
use App\Http\Controllers\Api\App\V1\ThreadMessageController as AppThreadMessageController;
use App\Http\Controllers\Api\App\V1\ThreadOptInController as AppThreadOptInController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Messages, Calls & Phone System — parity gaps closed 2026-10-20
|--------------------------------------------------------------------------
|
| The base thread/message/call/voice routes already live inline in
| routes/api.php's app/v1 group (a pre-existing layout, not moved here).
| Everything new for this round — the subject-filter tabs, consent
| (opt-in), scheduled-message management, and thread admin actions — is
| registered here instead, per this file's own section convention.
*/

// Consent (START opt-in) — App\Livewire\Sms\SmsConversation's
// "Resend Opt-in" / "Manual Opt-in" thread-menu items.
Route::post('threads/{thread}/opt-in/resend', [AppThreadOptInController::class, 'resend'])
    ->whereNumber('thread')
    ->name('threads.opt-in.resend');
Route::post('threads/{thread}/opt-in/manual', [AppThreadOptInController::class, 'manual'])
    ->whereNumber('thread')
    ->name('threads.opt-in.manual');

// Scheduled message management — cancel, send now, and (view-only) translate.
Route::delete('threads/{thread}/messages/{message}', [AppThreadMessageController::class, 'destroy'])
    ->whereNumber(['thread', 'message'])
    ->name('threads.messages.destroy');
Route::post('threads/{thread}/messages/{message}/send-now', [AppThreadMessageController::class, 'sendNow'])
    ->whereNumber(['thread', 'message'])
    ->name('threads.messages.send-now');
Route::post('threads/{thread}/messages/{message}/translate', [AppThreadMessageController::class, 'translate'])
    ->whereNumber(['thread', 'message'])
    ->name('threads.messages.translate');

// Thread admin actions — mark/unmark spam, delete, assign client/vendor.
Route::post('threads/{thread}/spam', [AppThreadController::class, 'markSpam'])
    ->whereNumber('thread')
    ->name('threads.spam.store');
Route::delete('threads/{thread}/spam', [AppThreadController::class, 'unmarkSpam'])
    ->whereNumber('thread')
    ->name('threads.spam.destroy');
Route::put('threads/{thread}/assign', [AppThreadController::class, 'assign'])
    ->whereNumber('thread')
    ->name('threads.assign');
Route::delete('threads/{thread}', [AppThreadController::class, 'destroy'])
    ->whereNumber('thread')
    ->name('threads.destroy');
