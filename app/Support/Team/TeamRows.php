<?php

namespace App\Support\Team;

use App\Models\SmsGroupThread;
use App\Models\User;

/**
 * The rows of the Team tab: the whole team first, then each teammate, each
 * a Hive-only conversation (SmsGroupThread::KIND_TEAM) that is made when it
 * is first opened. The website's Team tab (App\Livewire\Sms\TeamList) and
 * the Hive Contractors app's (Api\App\V1\TeamController) list the same rows.
 */
class TeamRows
{
    /**
     * @return list<array{key: string, user_id: ?int, thread_id: ?int, title: string, initial: string, preview: ?string, at: ?\Carbon\CarbonInterface, unread: bool, is_group: bool}>
     */
    public static function for(?User $user): array
    {
        $teammates = TeamRoster::teammatesOf($user);
        if (! $user || ! $user->vendor || $teammates->isEmpty()) {
            return [];
        }

        $threads = SmsGroupThread::query()
            ->teamThreads()
            ->where('vendor_id', $user->vendor->id)
            ->forTeamMember((int) $user->id)
            ->with('latestMessage')
            ->get();
        $unreadIds = SmsGroupThread::query()
            ->teamThreads()
            ->forTeamMember((int) $user->id)
            ->unreadForUser((int) $user->id)
            ->pluck('id')
            ->all();

        $threadFor = function (array $memberIds) use ($threads): ?SmsGroupThread {
            $wanted = collect($memberIds)->sort()->values()->all();

            return $threads->first(fn (SmsGroupThread $t) => collect($t->memberIds())->sort()->values()->all() === $wanted);
        };
        $row = function (string $key, ?int $userId, string $title, bool $isGroup, ?SmsGroupThread $thread) use ($unreadIds): array {
            $latest = $thread?->latestMessage;

            return [
                'key' => $key,
                'user_id' => $userId,
                'thread_id' => $thread?->id,
                'title' => $title,
                'initial' => mb_strtoupper(mb_substr($title, 0, 1)),
                'preview' => $latest?->text,
                'at' => $latest?->shown_at,
                'unread' => $thread && in_array($thread->id, $unreadIds, true),
                'is_group' => $isGroup,
            ];
        };

        $rows = [$row('group', null, TeamRoster::groupName($user->vendor), true, $threadFor($teammates->pluck('id')->push((int) $user->id)->all()))];
        foreach ($teammates as $mate) {
            $rows[] = $row('user-'.$mate->id, (int) $mate->id, self::nameOf($mate), false, $threadFor([(int) $user->id, (int) $mate->id]));
        }

        return $rows;
    }

    /** How a teammate is called on the Team tab: their nickname, else their first name. */
    public static function nameOf(User $mate): string
    {
        return trim((string) ($mate->nickname ?: $mate->first_name));
    }
}
