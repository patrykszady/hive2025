<?php

use App\Http\Controllers\Api\App\V1\CallLogController as AppCallLogController;
use App\Http\Controllers\Api\App\V1\MessageProjectLinkController as AppMessageProjectLinkController;
use App\Http\Controllers\Api\App\V1\MessageTaskSuggestionController as AppMessageTaskSuggestionController;
use App\Http\Controllers\Api\App\V1\ThreadController as AppThreadController;
use App\Http\Controllers\Api\App\V1\ThreadForwardController as AppThreadForwardController;
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

// Scheduled message management — edit, cancel, send now, and (view-only) translate.
Route::put('threads/{thread}/messages/{message}', [AppThreadMessageController::class, 'update'])
    ->whereNumber(['thread', 'message'])
    ->name('threads.messages.update');
Route::delete('threads/{thread}/messages/{message}', [AppThreadMessageController::class, 'destroy'])
    ->whereNumber(['thread', 'message'])
    ->name('threads.messages.destroy');
Route::post('threads/{thread}/messages/{message}/send-now', [AppThreadMessageController::class, 'sendNow'])
    ->whereNumber(['thread', 'message'])
    ->name('threads.messages.send-now');
Route::post('threads/{thread}/messages/{message}/translate', [AppThreadMessageController::class, 'translate'])
    ->whereNumber(['thread', 'message'])
    ->name('threads.messages.translate');

// Forward one or more messages to another thread — App\Livewire\Sms\
// SmsConversation::forwardableThreads()/forwardSingleMessage()/forwardMessages().
Route::get('threads/{thread}/forward-targets', [AppThreadForwardController::class, 'targets'])
    ->whereNumber('thread')
    ->name('threads.forward-targets');
Route::post('threads/{thread}/forward', [AppThreadForwardController::class, 'store'])
    ->whereNumber('thread')
    ->name('threads.forward');

// Add a message's photos to a project — App\Livewire\Sms\SmsConversation::
// openAddToProjectModal()/addableProjects()/addImagesToProject().
Route::get('threads/{thread}/messages/{message}/project-candidates', [AppMessageProjectLinkController::class, 'candidates'])
    ->whereNumber(['thread', 'message'])
    ->name('threads.messages.project-candidates');
Route::post('threads/{thread}/messages/{message}/add-to-project', [AppMessageProjectLinkController::class, 'store'])
    ->whereNumber(['thread', 'message'])
    ->name('threads.messages.add-to-project');

// Create a task from a message — App\Livewire\Sms\SmsConversation::
// createTaskFromMessage(); the app opens its own task create screen with
// the returned suggestion instead of the web's in-page modal.
Route::post('threads/{thread}/messages/{message}/task-suggestion', [AppMessageTaskSuggestionController::class, 'store'])
    ->whereNumber(['thread', 'message'])
    ->name('threads.messages.task-suggestion');

// The web's finer per-person recipient picker for a new thread —
// App\Livewire\Sms\SmsNewThread's recipientPreset dropdown.
Route::get('recipient-presets', [AppThreadController::class, 'recipientPresets'])
    ->name('recipient-presets');

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

// New-call contact picker + the web's multi-admin ring recipient choice —
// App\Livewire\Sms\CallList::contactUsers()/callRecipients().
Route::get('calls/contacts', [AppCallLogController::class, 'contacts'])
    ->name('calls.contacts');
