<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Api\App\V1\Concerns\ResolvesAppThread;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\SendThreadMessageRequest;
use App\Http\Requests\Api\App\UpdateScheduledMessageRequest;
use App\Http\Resources\Api\App\MessageResource;
use App\Livewire\Sms\SmsNewThread;
use App\Models\SmsMessage;
use App\Models\SmsThreadRead;
use App\Services\GroupSmsService;
use App\Services\Sms\ScheduledMessageActions;
use App\Services\SmsTranslationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The app's native texting screens over the existing SMS system: messages
 * are sent through the same GroupSmsService::sendToThread() the web
 * composer calls (same from-number, same consent gate, same crew
 * signature, same MMS media handling, same thread linking) — nothing here
 * re-sends anything on its own.
 */
class ThreadMessageController extends Controller
{
    use ResolvesAppThread;

    /** Messages per page, newest page first; matches SmsConversation's own loadMoreMessages() step. */
    private const PER_PAGE = 30;

    public function index(Request $request, int $thread): JsonResponse
    {
        $user = $request->user();
        $this->resolveAppThread($thread, $user);

        $before = $request->query('before');

        // Scheduled messages stay in the list (status 'scheduled', with
        // scheduled_at): the app shows them in place, marked with their send
        // time, where the web tracks them in a separate panel.
        $messages = SmsMessage::query()
            ->where('thread_id', $thread)
            ->with('sentByUser:id,first_name,last_name,nickname')
            ->when(filled($before), fn ($q) => $q->where('id', '<', (int) $before))
            ->orderByDesc('id')
            ->limit(self::PER_PAGE)
            ->get()
            // The page is cut by id (the cursor), then read in the order the
            // thread shows: a scheduled text sits where it went out, not where
            // it was typed (SmsMessage::shown_at).
            ->sortBy(fn (SmsMessage $m) => [$m->shown_at?->getTimestamp() ?? 0, $m->id])
            ->values();

        return response()->json([
            'data' => MessageResource::collection($messages),
            // Pass back as ?before= to load the next (older) page.
            'next_before' => $messages->min('id'),
            'has_more' => $messages->count() === self::PER_PAGE,
        ]);
    }

    public function store(SendThreadMessageRequest $request, int $thread, GroupSmsService $smsService): JsonResponse
    {
        $user = $request->user();
        $smsThread = $this->resolveAppThread($thread, $user);

        // Same consent/opt-in handling as the web composer's sendMessage():
        // nothing project-related goes out until every participant has
        // replied START.
        if ($smsThread->hasPendingOptIn()) {
            return response()->json([
                'message' => 'Each recipient must reply START before sending project messages.',
            ], 422);
        }

        $text = trim((string) $request->input('body', ''));
        $messageWithSig = $text !== ''
            ? $text . "\n" . SmsNewThread::getSignature($user->id)
            : SmsNewThread::getSignature($user->id);

        $mediaUrls = [];
        foreach ($request->file('media', []) as $file) {
            // Same storage convention as the web composer's attachment
            // upload: a relative /storage/... path; SendGroupMms turns it
            // into whatever Telnyx needs (public URL or compressed copy).
            $path = $file->store('sms-attachments', 'public');
            $mediaUrls[] = '/storage/' . $path;
        }

        // "Schedule send": the same presets and clock as the web composer.
        // 'schedule_only' is the web's "Schedule Only (manual send)" — saved
        // as a draft with no send time, sent later by hand (sendNow()).
        $now = now('America/Chicago');
        $sendAt = $request->validated('send_at');
        $scheduleOnly = $sendAt === 'schedule_only';
        $scheduledAt = match ($sendAt) {
            '1hr' => $now->copy()->addHour()->utc(),
            '2hr' => $now->copy()->addHours(2)->utc(),
            'tomorrow_8am' => $now->copy()->addDay()->setTime(8, 0)->utc(),
            'tomorrow_12pm' => $now->copy()->addDay()->setTime(12, 0)->utc(),
            default => null,
        };

        $message = $smsService->sendToThread(
            $smsThread,
            $messageWithSig,
            $mediaUrls,
            $user->id,
            $scheduledAt,
            ($scheduledAt || $scheduleOnly) ? ['original_text' => $text, 'source' => 'app_schedule_menu', 'schedule_only' => $scheduleOnly] : null,
            $scheduleOnly,
        );

        // sendToThread() dispatches the send job, which (on the sync queue
        // tests use, and sometimes in production before this returns) can
        // already have updated the row — reload so the response reports the
        // real status rather than the pre-send "sending" snapshot.
        return response()->json([
            'data' => new MessageResource($message->fresh('sentByUser')),
        ], 201);
    }

    public function markRead(Request $request, int $thread): JsonResponse
    {
        $user = $request->user();
        $this->resolveAppThread($thread, $user);

        $latestMessageId = SmsMessage::where('thread_id', $thread)->max('id');

        if ($latestMessageId) {
            SmsThreadRead::updateOrCreate(
                ['thread_id' => $thread, 'user_id' => $user->id],
                ['last_read_message_id' => $latestMessageId],
            );
        }

        return response()->json(['read' => true]);
    }

    /**
     * Edit a scheduled (not-yet-sent) message's text —
     * App\Livewire\Sms\SmsConversation::saveEditedScheduledMessage(). Only
     * the body can change; the send time is never editable, on the web either.
     */
    public function update(UpdateScheduledMessageRequest $request, int $thread, int $message, ScheduledMessageActions $scheduledActions, SmsTranslationService $translator): JsonResponse
    {
        $this->resolveAppThread($thread, $request->user());

        $smsMessage = SmsMessage::where('id', $message)
            ->where('thread_id', $thread)
            ->where('status', 'scheduled')
            ->firstOrFail();

        $scheduledActions->edit($smsMessage, (string) $request->validated('body'), $translator);

        return response()->json(['data' => new MessageResource($smsMessage->fresh('sentByUser'))]);
    }

    /**
     * Cancel a scheduled (not-yet-sent) message —
     * App\Livewire\Sms\SmsConversation::cancelScheduledMessage().
     */
    public function destroy(Request $request, int $thread, int $message, ScheduledMessageActions $scheduledActions): JsonResponse
    {
        $this->resolveAppThread($thread, $request->user());

        $smsMessage = SmsMessage::where('id', $message)
            ->where('thread_id', $thread)
            ->where('status', 'scheduled')
            ->firstOrFail();

        $scheduledActions->cancel($smsMessage);

        return response()->json(['deleted' => true]);
    }

    /**
     * Send a scheduled message right now instead of waiting —
     * App\Livewire\Sms\SmsConversation::sendScheduledNow().
     */
    public function sendNow(Request $request, int $thread, int $message, ScheduledMessageActions $scheduledActions): JsonResponse
    {
        $user = $request->user();
        $this->resolveAppThread($thread, $user);

        $smsMessage = SmsMessage::where('id', $message)
            ->where('thread_id', $thread)
            ->where('status', 'scheduled')
            ->firstOrFail();

        $scheduledActions->sendNow($smsMessage, (int) $user->id);

        return response()->json(['data' => new MessageResource($smsMessage->fresh('sentByUser'))]);
    }

    /**
     * Translate one message into the viewer's own preferred language, for
     * the per-message translate badge — App\Livewire\Sms\SmsConversation::toggleMessageTranslation().
     * Never persisted: the thread itself always renders in English: the app
     * re-requests this each time the badge is pressed and reverts to the
     * message's own (English) body itself.
     */
    public function translate(Request $request, int $thread, int $message, SmsTranslationService $translator): JsonResponse
    {
        $user = $request->user();
        $this->resolveAppThread($thread, $user);

        $smsMessage = SmsMessage::where('id', $message)
            ->where('thread_id', $thread)
            ->firstOrFail();

        $language = $translator->normalizeLanguage((string) ($user->preferred_language ?: 'English'));

        if (strcasecmp($language, 'English') === 0) {
            throw ValidationException::withMessages(['message' => 'You read messages in English — nothing to translate.']);
        }

        $text = trim((string) ($smsMessage->translated_display_text ?? $smsMessage->display_text ?? ''));

        if ($text === '') {
            throw ValidationException::withMessages(['message' => 'This message has no text to translate.']);
        }

        return response()->json([
            'translated' => $translator->translate($text, $language, 'English'),
            'language' => $language,
        ]);
    }
}
