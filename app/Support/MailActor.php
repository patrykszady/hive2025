<?php

namespace App\Support;

use App\Models\User;

/**
 * Who is sending the mail being built right now.
 *
 * In a web request that is auth()->user(). In a queued job there is no
 * session, so the job names the person it acts for. Cleared after every
 * queue job (AppServiceProvider), so one job's sender never leaks into the
 * next job the same worker runs.
 */
final class MailActor
{
    private static ?User $user = null;

    public static function as(?User $user): void
    {
        self::$user = $user;
    }

    public static function forget(): void
    {
        self::$user = null;
    }

    public static function current(): ?User
    {
        return self::$user ?? auth()->user();
    }
}
