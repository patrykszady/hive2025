<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Api\App\V1\Concerns\ResolvesAppThread;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\SendThreadScheduleRequest;
use App\Livewire\Sms\SendScheduleModal;
use App\Services\ConsultScheduleLinkTexter;
use App\Services\GroupSmsService;
use App\Services\SmsTranslationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The web conversation's tools, for the app's composer: "Send schedule"
 * (SendScheduleModal's own preview and send) and "Draft consult scheduling
 * text" (ConsultScheduleLinkTexter, drafted into the message box, never sent
 * by itself).
 */
class ThreadToolsController extends Controller
{
    use ResolvesAppThread;

    /** The schedule text the web "Send schedule" dialog would draft for this thread. */
    public function schedulePreview(Request $request, int $thread): JsonResponse
    {
        $this->resolveAppThread($thread, $request->user());

        $modal = app(SendScheduleModal::class);
        $modal->open($thread);

        return response()->json(['message' => (string) $modal->editableMessage]);
    }

    /** Send the (possibly edited) schedule text, as the web dialog's Send does. */
    public function sendSchedule(SendThreadScheduleRequest $request, int $thread, GroupSmsService $smsService, SmsTranslationService $translator): JsonResponse
    {
        $this->resolveAppThread($thread, $request->user());

        $modal = app(SendScheduleModal::class);
        $modal->open($thread);
        $modal->editableMessage = (string) $request->validated('message');
        $modal->scheduleWithoutDate = (bool) $request->validated('without_date', false);

        $result = $modal->deliver($smsService, $translator);

        return response()->json(['heading' => $result['heading'], 'message' => $result['text']], $result['ok'] ? 201 : 422);
    }

    /** The consult scheduling text, for the composer (the sender reads it and presses send). */
    public function consultDraft(Request $request, int $thread, ConsultScheduleLinkTexter $texter): JsonResponse
    {
        $smsThread = $this->resolveAppThread($thread, $request->user());
        $result = $texter->composeForThread($smsThread, $request->user());

        return response()->json([
            'ok' => $result['ok'],
            'draft' => $result['ok'] ? $result['message'] : null,
            'heading' => $result['heading'],
            'message' => $result['text'],
        ], $result['ok'] ? 200 : 422);
    }
}
