<?php

namespace App\Http\Controllers\Sms;

use App\Http\Controllers\Controller;
use App\Models\SmsGroupThread;
use App\Support\Sms\ConversationPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET endpoints backing the offline /messages experience.
 *
 * Thread switches on /messages are Livewire POSTs, which the Cache API cannot
 * store. These endpoints convert the same server-rendered content into a
 * cacheable GET surface: a manifest of the user's most recent threads (with
 * change fingerprints) and a per-thread rendered HTML fragment.
 *
 * resources/js/sms-offline.js owns fetching + storing these responses in the
 * 'hive-sms-data' Cache API bucket (the service worker never touches them).
 */
class SmsOfflineController extends Controller
{
    /**
     * Threads cached for offline use per user. Also the eviction bound the
     * page module applies after each warm cycle.
     */
    public const MANIFEST_THREAD_LIMIT = 30;

    /** Messages rendered into each offline fragment. */
    public const FRAGMENT_MESSAGE_LIMIT = 30;

    /**
     * Top threads (by last activity) with change fingerprints, so the page
     * module can diff cheaply and only re-fetch fragments that changed.
     */
    public function manifest(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->assertUserKey($request);

        $threadIds = SmsGroupThread::query()
            ->accessibleTo($user)
            ->orderByDesc('last_activity_at')
            ->limit(self::MANIFEST_THREAD_LIMIT)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $fingerprints = ConversationPresenter::fingerprintsForThreads($threadIds);

        return response()
            ->json([
                'ownerKey' => $this->userKey($request),
                'threads' => collect($threadIds)
                    ->map(fn (int $id) => [
                        'id' => $id,
                        'fingerprint' => $fingerprints[$id] ?? '0:0:',
                    ])
                    ->values(),
            ])
            // The Cache API stores this explicitly — keep the HTTP cache out of it.
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Server-rendered offline fragment for one thread: header + the shared
     * message-bubbles partial ($interactive = false) + disabled composer.
     */
    public function thread(Request $request, SmsGroupThread $thread): Response
    {
        $user = $request->user();
        $this->assertUserKey($request);

        abort_unless(
            SmsGroupThread::query()->accessibleTo($user)->whereKey($thread->id)->exists(),
            403
        );

        $loaded = ConversationPresenter::loadThreadForDisplay($thread->id);
        abort_unless($loaded !== null, 404);

        $presenter = new ConversationPresenter($loaded, $user, self::FRAGMENT_MESSAGE_LIMIT);
        $processed = $presenter->processedMessages();

        $tz = browser_timezone();
        $now = now($tz);

        $html = view('sms.offline-conversation', [
            'headerTitle' => $presenter->headerTitle(),
            'visibleMessages' => $processed['visible'],
            'scheduledMessages' => $processed['scheduled'],
            'reactionsMap' => $processed['reactions'],
            'phoneNameMap' => $presenter->phoneNameMap(),
            'tz' => $tz,
            'now' => $now,
            'todayDate' => $now->toDateString(),
            'yesterdayDate' => $now->copy()->subDay()->toDateString(),
            'isClientUser' => (bool) $user->is_browsing_as_client,
            'interactive' => false,
            'threadHasMixedNumbers' => $presenter->threadHasMixedNumbers(),
            'hasMoreMessages' => false,
            'resolveMediaUrl' => fn (string $url): string => ConversationPresenter::mediaUrl($url),
            // Only evaluated when $interactive — inert here by contract.
            'allowsTaskCreation' => fn ($m): bool => false,
        ])->render();

        return response($html)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store')
            // Read back by the page module for the "updated X ago" banner and
            // for manifest diffing without re-parsing the body.
            ->header('X-Fetched-At', now()->toIso8601String())
            ->header('X-Sms-Fingerprint', ConversationPresenter::fingerprintsForThreads([$thread->id])[$thread->id] ?? '0:0:');
    }

    /**
     * Fragment URLs carry ?u={userId}-{c|v} so cache keys are honestly
     * per-user; the server refuses a key that isn't the session's own.
     */
    private function assertUserKey(Request $request): void
    {
        abort_unless($request->query('u') === $this->userKey($request), 403);
    }

    private function userKey(Request $request): string
    {
        $user = $request->user();

        return $user->id . '-' . ($user->is_browsing_as_client ? 'c' : 'v');
    }
}
