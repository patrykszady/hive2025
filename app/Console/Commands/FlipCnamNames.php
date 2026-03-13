<?php

namespace App\Console\Commands;

use App\Models\CallLog;
use Illuminate\Console\Command;

class FlipCnamNames extends Command
{
    protected $signature = 'app:flip-cnam-names';

    protected $description = 'Flip CNAM caller names from "LAST FIRST" to "FIRST LAST" format';

    public function handle(): int
    {
        // Match ALL-CAPS "WORD WORD" patterns that haven't been flipped yet
        $records = CallLog::whereNotNull('caller_name')
            ->whereNull('user_id')
            ->whereNull('metadata->cnam_flipped')
            ->get()
            ->filter(fn (CallLog $c) => preg_match('/^[A-Z]+\s[A-Z]+$/', $c->caller_name));

        if ($records->isEmpty()) {
            $this->info('No CNAM names to flip.');

            return self::SUCCESS;
        }

        $this->info("Found {$records->count()} record(s) to flip:");

        $records->each(function (CallLog $callLog): void {
            $parts = preg_split('/\s+/', trim($callLog->caller_name));
            $flipped = $parts[1] . ' ' . $parts[0];

            $this->line("  #{$callLog->id}: {$callLog->caller_name} → {$flipped}");

            $metadata = $callLog->metadata ?? [];
            $metadata['cnam_flipped'] = true;
            $callLog->update(['caller_name' => $flipped, 'metadata' => $metadata]);
        });

        $this->info('Done.');

        return self::SUCCESS;
    }
}
