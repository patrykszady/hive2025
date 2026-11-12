<?php

namespace App\Services\Diagnostics;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use SsSystems\Platform\Pulse\Recorder;

/**
 * JavaScript errors the Hive Contractors app caught on the phone (a screen
 * that threw, a rejected promise, a render error), uploaded the next time
 * it reaches the server. Each one is written in full (stack, screen, app
 * build, who) to the app-errors log, and as a `jserr` Pulse event, so it
 * shows on the same JS Errors board as the website's errors — browser
 * "hive-app", source = the app screen.
 */
class AppErrorReports
{
    /** What the JS Errors board shows as the browser for an app error. */
    public const BROWSER = 'hive-app';

    public function __construct(protected Recorder $recorder) {}

    /**
     * @param  list<array{at: string, kind: string, fatal: bool, message: string, stack?: ?string, screen?: ?string, build?: ?string}>  $errors
     */
    public function ingest(User $user, array $errors, string $ip, string $userAgent): int
    {
        foreach ($errors as $error) {
            $screen = (string) ($error['screen'] ?? '');

            Log::channel('app-errors')->error($error['message'], [
                'user_id' => $user->id,
                'kind' => $error['kind'],
                'fatal' => (bool) $error['fatal'],
                'screen' => $screen,
                'build' => $error['build'] ?? null,
                'at' => Carbon::parse($error['at'])->toIso8601String(),
                'device' => $userAgent,
                'stack' => $error['stack'] ?? null,
            ]);

            $this->recorder->track(
                'jserr',
                [
                    'm' => mb_substr(($error['kind'] === 'promise' ? 'promise: ' : '').$error['message'], 0, 160),
                    's' => mb_substr('app '.($screen !== '' ? $screen : '?'), 0, 80),
                    'b' => self::BROWSER,
                    'mob' => 1,
                ],
                $screen !== '' ? $screen : '/',
                $ip,
                $userAgent,
                (string) config('app.key'),
            );
        }

        return count($errors);
    }
}
