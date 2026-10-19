<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Services\Notifications\AppNotificationFeed;
use App\Services\Notifications\AppNotificationTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The app's Notifications tab — the same list, unread count and read/unread
 * behaviour as the web notification bell (App\Livewire\Notifications\
 * {NotificationIndex,NotificationSidebarBadge}), via the shared
 * App\Services\Notifications\AppNotificationFeed. Each entry also carries a
 * `target` (App\Services\Notifications\AppNotificationTarget) so the app can
 * deep-link into its own screens instead of always falling back to the web
 * view the web side simply redirects to.
 */
class NotificationController extends Controller
{
    private const PER_PAGE = 25;

    /** Independent of browser_today()'s cookie, same reasoning as AppPlannerAgenda/AppHomeAgenda. */
    private const TIMEZONE = 'America/Chicago';

    public function index(Request $request, AppNotificationFeed $feed): JsonResponse
    {
        $userId = (int) $request->user()->id;

        $page = AppNotification::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(self::PER_PAGE);

        // Collapsing happens within this one page, exactly like the web's
        // NotificationIndex::grouped() operates on its own paginated 25.
        $entries = $feed->collapse($page->getCollection());

        return response()->json([
            'data' => array_map(fn (array $entry) => $this->entryPayload($entry), $entries),
            'next_cursor' => $page->nextCursor()?->encode(),
            'unread_count' => $feed->unreadCount($userId),
        ]);
    }

    public function unreadCount(Request $request, AppNotificationFeed $feed): JsonResponse
    {
        return response()->json([
            'data' => ['count' => $feed->unreadCount((int) $request->user()->id)],
        ]);
    }

    /** Marks $notification and every sibling it would collapse with (see AppNotificationFeed::markGroupRead()) read. */
    public function markRead(Request $request, int $notification, AppNotificationFeed $feed): JsonResponse
    {
        $found = $feed->find((int) $request->user()->id, $notification);
        abort_unless($found, 404);

        $feed->markGroupRead($found);

        return response()->json([
            'data' => ['marked' => true, 'unread_count' => $feed->unreadCount((int) $request->user()->id)],
        ]);
    }

    public function markAllRead(Request $request, AppNotificationFeed $feed): JsonResponse
    {
        $feed->markAllRead((int) $request->user()->id);

        return response()->json([
            'data' => ['marked' => true, 'unread_count' => 0],
        ]);
    }

    /** @param  array{notification: AppNotification, count: int, ids: list<int>, unread: bool, date: string}  $entry */
    private function entryPayload(array $entry): array
    {
        $notification = $entry['notification'];

        return [
            'id' => $notification->id,
            'ids' => $entry['ids'],
            'type' => $notification->type,
            'title' => $notification->title,
            'body' => $notification->body,
            'created_at' => $notification->created_at->toIso8601String(),
            'date_label' => $this->dateLabel($entry['date']),
            'count' => $entry['count'],
            'unread' => $entry['unread'],
            'target' => AppNotificationTarget::for($notification->action_url),
        ];
    }

    /** "Today"/"Yesterday"/"Mon, Oct 12" — mirrors notification-index.blade.php's $label. */
    private function dateLabel(string $date): string
    {
        $today = Carbon::now(self::TIMEZONE)->toDateString();
        $yesterday = Carbon::parse($today)->subDay()->toDateString();

        return match ($date) {
            $today => 'Today',
            $yesterday => 'Yesterday',
            default => Carbon::parse($date)->format('D, M j'),
        };
    }
}
