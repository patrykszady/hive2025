<?php

namespace App\Jobs;

use App\Services\NylasService;
use App\Support\Ewccv\SignInLink;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Obtain a fresh EWCCV sign-in link for the extension (the mail round trip
 * takes a minute or two, so not inside a request).
 */
class RequestEwccvSignInLink implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 420;

    public function __construct()
    {
        $this->onQueue('long-running');
    }

    public function uniqueFor(): int
    {
        return 300;
    }

    public function handle(NylasService $nylas): void
    {
        if (SignInLink::cached()) {
            return;
        }
        SignInLink::request($nylas);
    }
}
