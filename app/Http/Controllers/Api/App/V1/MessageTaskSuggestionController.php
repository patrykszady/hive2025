<?php

namespace App\Http\Controllers\Api\App\V1;

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
 * in-page modal.
 */
class MessageTaskSuggestionController extends Controller
{
    use ResolvesAppThread;

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
}
