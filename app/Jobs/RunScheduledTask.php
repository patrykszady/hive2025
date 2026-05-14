<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Generic wrapper for scheduled controller method calls.
 *
 * Replaces `Schedule::call(fn() => app(X)->method())` with a queued job so
 * heavy work runs on a Horizon worker (and ultimately on a dedicated worker
 * droplet) instead of inside the `php artisan schedule:run` process where it
 * would block other scheduled work and compete with PHP-FPM for resources.
 *
 * Implements ShouldBeUnique keyed by class+method so two scheduled runs of
 * the same method cannot pile up on the queue if a previous run is still
 * in flight.
 */
class RunScheduledTask implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public int $uniqueFor = 1800;

    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __construct(
        public string $controllerClass,
        public string $method,
        public array $arguments = [],
        public ?string $label = null,
    ) {
        $this->onQueue('background');
    }

    public function handle(): void
    {
        $instance = app($this->controllerClass);
        $instance->{$this->method}(...$this->arguments);
    }

    public function uniqueId(): string
    {
        return $this->controllerClass.'@'.$this->method;
    }

    public function displayName(): string
    {
        return $this->label ?? class_basename($this->controllerClass).'@'.$this->method;
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['scheduled', class_basename($this->controllerClass), $this->method];
    }
}
