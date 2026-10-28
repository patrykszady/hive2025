<?php

namespace App\Support\Team;

use App\Models\SmsGroupThread;
use App\Models\User;
use App\Models\Vendor;
use App\Services\GroupSmsService;
use Illuminate\Support\Collection;

/**
 * Who is on the company's Hive team: the people who see the Team tab in
 * Messages and can call and message each other inside Hive. Picked on
 * /phone-system (vendor option `team_member_user_ids`); until it is set,
 * everyone employed by the company counts. Employment still gates it: a
 * person who left is off the team whatever the option says.
 */
class TeamRoster
{
    public const OPTION = 'team_member_user_ids';

    /** @return list<int> */
    public static function memberIds(?Vendor $vendor): array
    {
        if (! $vendor) {
            return [];
        }

        $employed = $vendor->users()->wherePivot('is_employed', 1)->pluck('users.id')->map(fn ($id) => (int) $id);
        // Read from the row, not the instance: the signed-in user's cached
        // vendor can predate a save on /phone-system in the same process.
        $options = Vendor::query()->whereKey($vendor->id)->value('options');
        $configured = data_get(is_string($options) ? json_decode($options) : $options, self::OPTION);

        if (! is_array($configured)) {
            return $employed->sort()->values()->all();
        }

        $picked = collect($configured)->map(fn ($id) => (int) $id);

        return $employed->intersect($picked)->sort()->values()->all();
    }

    /** @return Collection<int, User> */
    public static function members(?Vendor $vendor): Collection
    {
        $ids = self::memberIds($vendor);
        if ($ids === []) {
            return collect();
        }

        return User::query()->whereIn('id', $ids)->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'nickname', 'cell_phone', 'primary_vendor_id']);
    }

    /** The team without the person looking. @return Collection<int, User> */
    public static function teammatesOf(?User $user): Collection
    {
        if (! $user || $user->is_browsing_as_client || ! $user->vendor) {
            return collect();
        }

        return self::members($user->vendor)->reject(fn (User $u) => (int) $u->id === (int) $user->id)->values();
    }

    public static function isMember(?User $user): bool
    {
        if (! $user || $user->is_browsing_as_client || ! $user->vendor) {
            return false;
        }

        return in_array((int) $user->id, self::memberIds($user->vendor), true);
    }

    public static function groupName(Vendor $vendor): string
    {
        return trim((string) ($vendor->short_name ?: $vendor->business_name)).' Crew';
    }

    /** The name the group thread carried before it was called the crew. */
    protected static function legacyGroupName(Vendor $vendor): string
    {
        return trim((string) ($vendor->short_name ?: $vendor->business_name)).' Team';
    }

    /**
     * The whole-team thread, made on first use and kept in step with the
     * roster: when the team changes on /phone-system the same thread (and
     * its history) carries on with the new members.
     */
    public static function groupThread(Vendor $vendor): SmsGroupThread
    {
        return self::syncGroupThread($vendor)
            ?? SmsGroupThread::teamThreadFor(self::memberIds($vendor), (int) $vendor->id, self::groupName($vendor));
    }

    /** Re-member the existing group thread to the current roster; null when there is none yet. */
    public static function syncGroupThread(Vendor $vendor): ?SmsGroupThread
    {
        $thread = SmsGroupThread::query()
            ->teamThreads()
            ->where('vendor_id', $vendor->id)
            ->whereIn('name', [self::groupName($vendor), self::legacyGroupName($vendor)])
            ->orderBy('id')
            ->first();

        if (! $thread) {
            return null;
        }

        $changes = [];
        if ($thread->name !== self::groupName($vendor)) {
            $changes['name'] = self::groupName($vendor);
        }
        $ids = self::memberIds($vendor);
        if (collect($thread->memberIds())->sort()->values()->all() !== $ids) {
            $changes['member_user_ids'] = $ids;
            $changes['participants'] = User::withoutGlobalScopes()->whereIn('id', $ids)->pluck('cell_phone')
                ->map(fn ($cell) => $cell ? GroupSmsService::formatE164((string) $cell) : null)
                ->filter()->unique()->values()->all();
        }
        if ($changes !== []) {
            $thread->update($changes);
        }

        return $thread;
    }
}
