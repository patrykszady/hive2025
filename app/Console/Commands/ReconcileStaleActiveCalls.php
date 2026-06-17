<?php

namespace App\Console\Commands;

use App\Models\CallLog;
use Illuminate\Console\Command;

class ReconcileStaleActiveCalls extends Command
{
    protected $signature = 'calls:reconcile-stale
        {--execute : Actually update rows (default is a dry-run preview)}
    ';

    protected $description = 'Transition calls stuck in an active status (no hangup webhook received) to a terminal status so the "In a call" badge clears.';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');

        // A call is stale when it is still flagged active but either already
        // has a terminating timestamp (status was never transitioned) or was
        // started long enough ago that it cannot still be live.
        $stale = CallLog::query()
            ->whereIn('status', CallLog::ACTIVE_STATUSES)
            ->where(function ($query): void {
                $query->whereNotNull('ended_at')
                    ->orWhere('created_at', '<', now()->subMinutes(CallLog::STALE_ACTIVE_MINUTES));
            })
            ->orderByDesc('created_at')
            ->get();

        $this->info(($execute ? 'EXECUTING' : 'DRY-RUN') . " reconcile for {$stale->count()} stale call(s)");
        $this->newLine();

        $updated = 0;

        foreach ($stale as $call) {
            $terminal = $this->terminalStatusFor($call);

            $this->line("  #{$call->id}: {$call->direction} | {$call->status} → {$terminal} | created={$call->created_at}");

            if ($execute) {
                $call->status = $terminal;
                if ($call->ended_at === null) {
                    $call->ended_at = $call->answered_at ?? $call->created_at;
                }
                $call->save();
            }

            $updated++;
        }

        $this->newLine();
        $verb = $execute ? 'Reconciled' : 'Would reconcile';
        $this->info("{$verb}: {$updated}");

        if (! $execute && $updated > 0) {
            $this->newLine();
            $this->comment('Dry-run only. Re-run with --execute to apply.');
        }

        return self::SUCCESS;
    }

    /**
     * A call that ever connected is completed; otherwise it was missed
     * (inbound) or failed (outbound).
     */
    private function terminalStatusFor(CallLog $call): string
    {
        $connected = $call->answered_at !== null
            || in_array($call->status, [CallLog::STATUS_ANSWERED, CallLog::STATUS_TRANSFERRED], true);

        if ($connected) {
            return CallLog::STATUS_COMPLETED;
        }

        return $call->direction === 'incoming'
            ? CallLog::STATUS_MISSED
            : CallLog::STATUS_FAILED;
    }
}
