<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Api\App\V1\Concerns\ResolvesAppThread;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\ManualOptInRequest;
use App\Services\Sms\ThreadOptInManager;
use App\Support\Sms\ConversationPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Consent (START opt-in) actions for a thread's composer banner — the app
 * equivalent of the web conversation's "Resend Opt-in" / "Manual Opt-in"
 * thread-menu items (App\Livewire\Sms\SmsConversation). See
 * App\Services\Sms\ThreadOptInManager, which both sides call.
 */
class ThreadOptInController extends Controller
{
    use ResolvesAppThread;

    public function resend(Request $request, int $thread, ThreadOptInManager $optInManager): JsonResponse
    {
        $smsThread = $this->resolveAppThread($thread, $request->user());

        if (! $smsThread->hasPendingOptIn()) {
            return response()->json(['message' => 'All recipients have already replied START.'], 422);
        }

        $optInManager->resend($smsThread);

        return response()->json(['sent' => true]);
    }

    public function manual(ManualOptInRequest $request, int $thread, ThreadOptInManager $optInManager): JsonResponse
    {
        $user = $request->user();
        $smsThread = $this->resolveAppThread($thread, $user);

        try {
            $participant = $optInManager->manualOptIn(
                $smsThread,
                (int) $request->validated('participant_id'),
                (string) $request->validated('reason'),
                (int) $user->id,
            );
        } catch (\Throwable $e) {
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface && $e->getStatusCode() === 403) {
                throw ValidationException::withMessages(['participant_id' => 'That participant does not belong to this conversation.']);
            }

            throw $e;
        }

        return response()->json([
            'opted_in' => true,
            'name' => ConversationPresenter::resolvePhoneDisplay($participant->phone_number),
        ]);
    }
}
