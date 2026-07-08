<?php

namespace App\Console\Commands;

use App\Models\CallTranscript;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class PostDeployBackfill extends Command
{
    protected $signature = 'app:post-deploy-backfill
        {--skip-horizon : Do not restart Horizon workers}
        {--skip-scout : Do not reindex the SMS threads search index}';

    protected $description = 'One-shot post-deploy backfill: restart workers, reindex thread search flags, backfill agent names in call speaker maps, and regenerate AI summaries that say "Agent". Idempotent — safe to re-run.';

    public function handle(): int
    {
        // ── 1. Restart queue workers so they load the new job code ────────
        if (! $this->option('skip-horizon')) {
            $this->info('1/4 Restarting Horizon workers…');
            Artisan::call('horizon:terminate');
            $this->line('    horizon:terminate sent (supervisor restarts it automatically).');
        } else {
            $this->line('1/4 Skipped Horizon restart.');
        }

        // ── 2. Reindex threads (new has_client / has_subject_vendor flags) ─
        if (! $this->option('skip-scout')) {
            $this->info('2/4 Reindexing SMS threads search index…');
            Artisan::call('scout:reindex', ['--models' => ['App\Models\SmsGroupThread']], $this->getOutput());
        } else {
            $this->line('2/4 Skipped Scout reindex.');
        }

        // ── 3. Backfill agent names into historical speaker maps ──────────
        $this->info('3/4 Backfilling speaker maps (agent names) from stored segments…');
        Artisan::call('calls:process-recordings', ['--reidentify-speakers' => true, '--all' => true], $this->getOutput());

        // ── 4. Regenerate AI summaries that reference a generic "Agent" ───
        $this->info('4/4 Regenerating AI summaries that mention "Agent"…');

        $cleared = 0;
        CallTranscript::query()
            ->where('status', CallTranscript::STATUS_READY)
            ->whereNotNull('summary')
            ->chunkById(100, function ($transcripts) use (&$cleared): void {
                foreach ($transcripts as $t) {
                    $blob = strtolower(
                        (string) $t->summary
                        . json_encode($t->action_items ?? [])
                        . json_encode($t->topics ?? [])
                        . json_encode($t->next_steps ?? [])
                        . (string) $t->caller_intent
                    );

                    if (str_contains($blob, 'agent')) {
                        $t->update([
                            'summary' => null,
                            'action_items' => null,
                            'topics' => null,
                            'next_steps' => null,
                            'sentiment' => null,
                            'caller_intent' => null,
                            'summarized_at' => null,
                        ]);
                        $cleared++;
                    }
                }
            });

        $this->line("    Cleared {$cleared} summaries for regeneration.");

        if ($cleared > 0) {
            Artisan::call('calls:process-recordings', ['--all' => true], $this->getOutput());
        }

        $this->newLine();
        $this->info('Post-deploy backfill complete.');
        $this->line('Stuck/missing transcripts self-heal via the every-5-minutes scheduler — no action needed.');

        return self::SUCCESS;
    }
}
