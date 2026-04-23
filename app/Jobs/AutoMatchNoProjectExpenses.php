<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AutoMatchNoProjectExpenses implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Prevent the same auto-match sweep from queuing multiple times concurrently
     * (e.g. when several receipt batches finish back-to-back).
     */
    public int $uniqueFor = 600;

    public function uniqueId(): string
    {
        return 'auto-match-no-project-expenses';
    }

    public function handle(): void
    {
        app(\App\Http\Controllers\ExpenseAutoMatchController::class)->runNoProjectExpenseAutoMatch(
            null,
            null,
            null,
            false,
            true,
            true,
        );
    }
}
