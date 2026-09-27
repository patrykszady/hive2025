<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Throwable;

/**
 * Ported verbatim from dawnsellshomes' identical class. Runs one SEO
 * report's artisan command for the admin's "Run" button and writes the
 * whole attempt, start to finish, to the 'seo-reports' log channel
 * (config/logging.php), including the X-Admin-User/X-Admin-Screen headers
 * ss-systems' SiteApiConnection sends — see ss-systems/CLAUDE.md's "Every
 * Run and Refresh metrics is logged at both ends".
 */
class SeoReportRun
{
    /**
     * @param  array{label: string, command: string, description?: string}  $meta
     * @return array{status: string, ok: bool, message: string, started_at: string, finished_at: string, duration_ms: int, exit_code: ?int, output_tail: string, log_channel: string}
     */
    public static function run(string $key, array $meta, Request $request, int $trendDays): array
    {
        $label = $meta['label'];
        $command = $meta['command'];
        $commandName = strtok($command, ' ') ?: $command;

        $startedAt = Carbon::now();
        $start = microtime(true);

        Log::channel('seo-reports')->info('report run started', [
            'key' => $key,
            'label' => $label,
            'command' => $command,
            'trend_days' => $trendDays,
            'requested_by' => $request->header('X-Admin-User'),
            'screen' => $request->header('X-Admin-Screen'),
            'ip' => $request->ip(),
        ]);

        $status = 'ok';
        $exitCode = null;
        $output = '';
        $errorContext = [];
        $userMessage = null;

        try {
            $exitCode = Artisan::call($command);
            $output = (string) Artisan::output();
            if ($exitCode !== 0) {
                // A non-zero exit with the report freshly written is a health
                // signal, not a broken run: seo:health exits 1 when it finds
                // something wrong. The operator gets the report and the warning.
                $status = self::writtenSince($key, $startedAt) ? 'warning' : 'failed';
            }
        } catch (CommandNotFoundException $e) {
            $status = 'missing-command';
            $userMessage = "The command {$commandName} is not installed on this site";
            $errorContext = [
                'error' => $userMessage,
                'exception_message' => $e->getMessage(),
            ];
        } catch (Throwable $e) {
            $status = 'failed';
            $errorContext = [
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
                'stack' => self::stackFrames($e),
            ];
        }

        $finishedAt = Carbon::now();
        $durationMs = (int) round((microtime(true) - $start) * 1000);
        $file = self::fileState($key);

        $logContext = [
            'key' => $key,
            'status' => $status,
            'exit_code' => $exitCode,
            'duration_ms' => $durationMs,
            'output_tail' => self::tail($output, 4000),
            'file' => $file,
        ] + $errorContext;

        Log::channel('seo-reports')->{match ($status) { 'ok' => 'info', 'warning' => 'warning', default => 'error' }}('report run finished', $logContext);

        $message = match ($status) {
            'missing-command' => "Could not run {$label}: the command {$commandName} is not installed on this site.",
            'warning' => sprintf('%s regenerated in %s, but the command reported problems (exit %d). See what it printed.', $label, self::formatSeconds($durationMs), $exitCode),
            'failed' => "{$label} failed: ".($errorContext['exception_message'] ?? "exit code {$exitCode}").'.',
            default => sprintf('%s regenerated in %s.', $label, self::formatSeconds($durationMs)),
        };

        return [
            'status' => $status,
            'ok' => in_array($status, ['ok', 'warning'], true),
            'message' => $message,
            'started_at' => $startedAt->toIso8601String(),
            'finished_at' => $finishedAt->toIso8601String(),
            'duration_ms' => $durationMs,
            'exit_code' => $exitCode,
            'output_tail' => self::tail($output, 2000),
            'log_channel' => 'seo-reports',
        ];
    }

    public static function formatSeconds(int $ms): string
    {
        return number_format($ms / 1000, 1).' s';
    }

    private static function tail(string $text, int $chars): string
    {
        return mb_strlen($text) > $chars ? mb_substr($text, -$chars) : $text;
    }

    /** @return array<int, string> */
    private static function stackFrames(Throwable $e): array
    {
        return collect($e->getTrace())
            ->take(5)
            ->map(function (array $frame): string {
                $location = ($frame['file'] ?? '[internal]').':'.($frame['line'] ?? '?');
                $call = ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '');

                return trim($location.' '.$call);
            })
            ->all();
    }

    /** Whether the report file was (re)written during this run. */
    protected static function writtenSince(string $key, Carbon $startedAt): bool
    {
        $disk = Storage::disk('local');
        $path = SeoStorage::path("reports/{$key}.md");

        return $disk->exists($path) && $disk->lastModified($path) >= $startedAt->getTimestamp() - 1;
    }

    /**
     * @return array{path: string, exists: bool, size: ?int, modified_at: ?string}
     */
    private static function fileState(string $key): array
    {
        $disk = Storage::disk('local');
        $path = SeoStorage::path("reports/{$key}.md");
        $exists = $disk->exists($path);

        return [
            'path' => $path,
            'exists' => $exists,
            'size' => $exists ? $disk->size($path) : null,
            'modified_at' => $exists ? Carbon::createFromTimestamp($disk->lastModified($path))->toIso8601String() : null,
        ];
    }
}
