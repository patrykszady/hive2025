<?php

namespace App\Http\Controllers\Api\App\V1\Concerns;

use App\Models\SmsGroupThread;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

trait ResolvesAppThread
{
    /**
     * The thread for this id, if the acting user may see it.
     *
     * 404 when the thread does not exist at all; 403 when it exists but
     * isn't visible to this user — the same visibility
     * SmsGroupThread::scopeAccessibleTo() enforces for the web Messages
     * page, so another company's user (or a GS team member asking about a
     * thread outside their own vendor context) gets a clear 403 rather than
     * a 404 that reads as "no such thread."
     */
    protected function resolveAppThread(int $threadId, User $user): SmsGroupThread
    {
        $thread = SmsGroupThread::find($threadId);

        if (! $thread) {
            throw new NotFoundHttpException('Thread not found.');
        }

        if (! SmsGroupThread::query()->accessibleTo($user)->whereKey($threadId)->exists()) {
            abort(403, 'You do not have access to this conversation.');
        }

        return $thread;
    }
}
