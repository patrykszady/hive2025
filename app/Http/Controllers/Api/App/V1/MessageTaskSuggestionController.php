<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Api\App\V1\Concerns\ResolvesAppEmail;
use App\Http\Controllers\Api\App\V1\Concerns\ResolvesAppThread;
use App\Http\Controllers\Controller;
use App\Models\SmsMessage;
use App\Services\Sms\MessageTaskSuggestionBuilder;
use App\Services\SmsTaskExtractionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Create Task" from a message —
 * App\Livewire\Sms\SmsConversation::createTaskFromMessage(). Returns the
 * exact same AI-extracted suggestion the web computes (via
 * MessageTaskSuggestionBuilder, shared with the web component); the app then
 * opens its own task create screen prefilled with it instead of the web's
 * in-page modal. Emails get the same suggestion (email(), the project
 * communications timeline's "Create Task") through the same builder.
 */
class MessageTaskSuggestionController extends Controller
{
    use ResolvesAppEmail, ResolvesAppThread;

    public function store(Request $request, int $thread, int $message, MessageTaskSuggestionBuilder $builder, SmsTaskExtractionService $extractor): JsonResponse
    {
        $this->resolveAppThread($thread, $request->user());

        $smsMessage = SmsMessage::where('thread_id', $thread)->whereKey($message)->firstOrFail();

        $result = $builder->suggest($smsMessage, $thread, $extractor);

        if (! $result['ok']) {
            return response()->json(['reason' => $result['reason'], 'message' => $result['text']], 422);
        }

        return response()->json(['data' => $result['payload']]);
    }

    /** "Create Task" from a stakeholder email — the same suggestion the web's email detail opens. */
    public function email(Request $request, int $email, MessageTaskSuggestionBuilder $builder, SmsTaskExtractionService $extractor): JsonResponse
    {
        $found = $this->resolveAppEmail($email, $request->user());

        $result = $builder->suggestForEmail($found, $extractor);

        if (! $result['ok']) {
            return response()->json(['reason' => $result['reason'], 'message' => $result['text']], 422);
        }

        return response()->json(['data' => $result['payload']]);
    }
}
