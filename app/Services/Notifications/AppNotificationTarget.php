<?php

namespace App\Services\Notifications;

/**
 * Classifies an AppNotification's action_url into a deep link the Hive
 * Contractors app can act on natively, so a tap opens the matching screen
 * (a thread, a call, a project, the Planner tab) instead of always falling
 * back to the "open full page in Hive" WebView. The web side just redirects
 * the raw URL (NotificationIndex::goToGroup()); this classifier has no web
 * equivalent to stay in sync with — it only has to keep matching whatever
 * action_url shapes AppNotification::create() is actually called with (see
 * this class's test for the full list, enumerated from every call site).
 *
 * action_url is stored either as a route()-built absolute URL (e.g.
 * ".../projects/5") or as a hand-written relative path+query (e.g.
 * "/messages?threadId=5") — both are normalized to a relative path+query
 * before matching.
 */
class AppNotificationTarget
{
    /**
     * @return array{type: string, id: ?int, path: ?string, title: ?string}
     */
    public static function for(?string $actionUrl): array
    {
        if ($actionUrl === null || $actionUrl === '') {
            return self::none();
        }

        $parts = parse_url($actionUrl);
        $path = $parts['path'] ?? '/';
        $query = [];

        if (! empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        if ($path === '/messages') {
            if (isset($query['threadId']) && is_numeric($query['threadId'])) {
                return ['type' => 'thread', 'id' => (int) $query['threadId'], 'path' => null, 'title' => null];
            }

            if (isset($query['callId']) && is_numeric($query['callId'])) {
                return ['type' => 'call', 'id' => (int) $query['callId'], 'path' => null, 'title' => null];
            }

            return ['type' => 'web', 'id' => null, 'path' => '/messages', 'title' => 'Messages'];
        }

        if (preg_match('#^/projects/(\d+)$#', $path, $matches) === 1) {
            return ['type' => 'project', 'id' => (int) $matches[1], 'path' => null, 'title' => null];
        }

        if ($path === '/planner' || $path === '/planner/cards') {
            return ['type' => 'planner', 'id' => null, 'path' => null, 'title' => null];
        }

        return ['type' => 'web', 'id' => null, 'path' => self::relativePath($path, $parts['query'] ?? null), 'title' => self::webTitleFor($path)];
    }

    /** @return array{type: string, id: ?int, path: ?string, title: ?string} */
    private static function none(): array
    {
        return ['type' => 'none', 'id' => null, 'path' => null, 'title' => null];
    }

    private static function relativePath(string $path, ?string $query): string
    {
        return $query ? "{$path}?{$query}" : $path;
    }

    private static function webTitleFor(string $path): string
    {
        return match (true) {
            str_starts_with($path, '/leads') => 'Leads',
            str_starts_with($path, '/estimate') => 'Estimate',
            default => 'Hive',
        };
    }
}
