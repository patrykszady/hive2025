<?php

namespace App\Support;

use App\Jobs\SendBrowserNotificationsToUsers;
use Illuminate\Support\Collection;

/**
 * The browser-push half of an admin alert.
 *
 * When a homeowner picks consultation times, a sub proposes dates for a
 * task, or a client shares service-call availability, the team already gets
 * an in-app notification row (what /notifications lists). Those rows sat
 * silent in a tab nobody was looking at; this sends the same alert to every
 * admin's subscribed browser as well.
 */
final class AdminAlerts
{
    /**
     * @param  Collection<int, int>|array<int, int>  $userIds
     */
    public static function push(Collection|array $userIds, string $type, string $title, string $body, string $url): void
    {
        $ids = collect($userIds)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($ids === []) {
            return;
        }

        SendBrowserNotificationsToUsers::dispatch($ids, [
            'title' => $title,
            'body' => mb_substr($body, 0, 180),
            // One tag per alert type and target, so a repeat replaces rather
            // than stacks — a homeowner editing their picks twice is one alert.
            'tag' => $type.'-'.substr(md5($url), 0, 10),
            'data' => ['url' => $url],
        ]);
    }
}
