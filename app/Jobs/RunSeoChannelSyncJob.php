<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Runs a long artisan command on the queue with a realistic timeout.
 * Ported from dawnsellshomes' identical job. A default QueuedCommand
 * inherits the worker's short timeout and default retry count — a full
 * paginated GSC pull or a full-sitemap inspection sweep takes minutes, so
 * it would be killed and retried into MaxAttemptsExceeded (the same
 * failure App\Jobs\RunGscInspectBulkJob exists to avoid).
 */
class RunSeoChannelSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public string $command,
        public array $options = [],
    ) {}

    public function handle(): void
    {
        // Whether or not a queue worker is actually running (this app runs
        // QUEUE_CONNECTION=redis in dev, but production's worker is not
        // guaranteed either), a job dispatched with no worker running would
        // sit forever. Running the command as a detached process means an
        // admin's manual "Refresh" click works the same whether a worker is
        // present or not, with its output kept in storage/logs/{command}.log.
        if (config('queue.default') === 'sync' && ! app()->runningUnitTests()) {
            $this->runDetached();

            return;
        }

        $exit = Artisan::call($this->command, $this->options);
        if ($exit !== 0) {
            Log::warning('RunSeoChannelSyncJob command exited non-zero', [
                'command' => $this->command,
                'exit' => $exit,
            ]);
        }
    }

    /** `nohup <php> artisan {command} {options} >> storage/logs/{slug}.log &` */
    protected function runDetached(): void
    {
        $log = $this->detachedLogPath();

        file_put_contents($log, '['.now()->toDateTimeString().'] starting '.$this->command."\n", FILE_APPEND);
        Log::info('RunSeoChannelSyncJob started detached', ['command' => $this->command, 'log' => $log]);
        exec($this->detachedShellCommand());
    }

    public function detachedLogPath(): string
    {
        return storage_path('logs/'.preg_replace('/[^a-z0-9]+/', '-', $this->command).'.log');
    }

    /**
     * The shell line the detached run uses. The PHP binary is found the
     * way Symfony does, never PHP_BINARY: under PHP-FPM that constant is
     * php-fpm itself, which only prints its usage text when handed
     * `artisan`.
     */
    public function detachedShellCommand(): string
    {
        $args = [];
        foreach ($this->options as $name => $value) {
            if ($value === false || $value === null) {
                continue;
            }
            $args[] = $value === true ? $name : $name.'='.escapeshellarg((string) $value);
        }

        $php = (new PhpExecutableFinder)->find(false) ?: 'php';

        return sprintf(
            'nohup %s %s %s %s >> %s 2>&1 &',
            escapeshellarg($php),
            escapeshellarg(base_path('artisan')),
            escapeshellarg($this->command),
            implode(' ', $args),
            escapeshellarg($this->detachedLogPath()),
        );
    }
}
