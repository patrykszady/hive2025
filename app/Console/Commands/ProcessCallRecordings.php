<?php

namespace App\Console\Commands;

use App\Jobs\SummarizeCallTranscript;
use App\Jobs\TranscribeCallRecording;
use App\Models\CallLog;
use App\Models\CallTranscript;
use Illuminate\Console\Command;

class ProcessCallRecordings extends Command
{
    protected $signature = 'calls:process-recordings
        {--limit=25 : Max number of recordings to process per run}
        {--all : Process every matching call (ignores --limit)}
        {--queue : Dispatch jobs to the queue instead of running inline}
        {--call= : Process a specific call_log id only}
        {--retry-failed : Re-attempt transcripts previously marked failed}
        {--force : Re-transcribe and re-summarize even if already completed}
        {--reidentify-speakers : Recompute speaker maps for ready transcripts from stored segments (no re-transcription)}';

    protected $description = 'Transcribe and summarize stored call recordings (replaces inline auto-dispatch)';

    public function handle(): int
    {
        $transcriptionEnabled = (bool) config('call_recording.transcription.enabled');
        $summarizationEnabled = (bool) config('call_recording.summarization.enabled');

        if (! $transcriptionEnabled && ! $summarizationEnabled) {
            $this->warn('Both transcription and summarization are disabled in config/call_recording.php.');

            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        $all = (bool) $this->option('all');
        $useQueue = (bool) $this->option('queue');
        $specificId = $this->option('call');
        $retryFailed = (bool) $this->option('retry-failed');
        $force = (bool) $this->option('force');

        // Standalone mode: recompute speaker maps from stored segments and exit.
        if ($this->option('reidentify-speakers')) {
            return $this->reidentifySpeakers($specificId, $all ? null : $limit);
        }

        $transcribed = 0;
        $summarized = 0;

        if ($transcriptionEnabled) {
            $query = CallLog::query()
                ->whereNotNull('recording_path')
                ->whereNotNull('recording_disk');

            if (! $force) {
                $query->where(function ($q) use ($retryFailed) {
                    $q->whereDoesntHave('transcript');
                    if ($retryFailed) {
                        $q->orWhereHas('transcript', fn ($t) => $t->where('status', CallTranscript::STATUS_FAILED));
                        // Zombie recovery: a transcript stuck in `transcribing` for
                        // over 30 minutes means its job died without running the
                        // failure handler (worker killed, deploy restart) — retry it.
                        $q->orWhereHas('transcript', fn ($t) => $t
                            ->where('status', CallTranscript::STATUS_TRANSCRIBING)
                            ->where('updated_at', '<', now()->subMinutes(30)));
                    }
                });
            }

            if ($specificId) {
                $query->where('id', $specificId);
            }

            $query->orderBy('id', 'desc');
            if (! $all) {
                $query->limit($limit);
            }

            $callLogs = $query->get();

            $this->info('Found ' . $callLogs->count() . ' call(s) needing transcription.');

            foreach ($callLogs as $callLog) {
                if ($force && $callLog->transcript) {
                    $callLog->transcript->update([
                        'status' => CallTranscript::STATUS_TRANSCRIBING,
                        'text' => null,
                        'segments' => null,
                        'summary' => null,
                        'action_items' => null,
                        'topics' => null,
                        'next_steps' => null,
                        'sentiment' => null,
                        'caller_intent' => null,
                        'failure_reason' => null,
                    ]);
                }

                if ($useQueue) {
                    TranscribeCallRecording::dispatch($callLog->id);
                    $this->line("  queued transcribe for call {$callLog->id}");
                    $transcribed++;

                    continue;
                }

                $this->info("Transcribing call {$callLog->id}...");
                try {
                    (new TranscribeCallRecording($callLog->id))->handle();
                    $transcribed++;
                } catch (\Throwable $e) {
                    $this->error("Transcribe failed for call {$callLog->id}: {$e->getMessage()}");
                }
            }
        }

        if ($summarizationEnabled) {
            $query = CallTranscript::query()
                ->where('status', CallTranscript::STATUS_READY)
                ->whereNotNull('text');

            if (! $force) {
                $query->whereNull('summary');
            }

            if ($specificId) {
                $query->where('call_log_id', $specificId);
            }

            $query->orderBy('id', 'desc');
            if (! $all) {
                $query->limit($limit);
            }

            $transcripts = $query->get();

            $this->info('Found ' . $transcripts->count() . ' transcript(s) needing summarization.');

            foreach ($transcripts as $transcript) {
                if ($useQueue) {
                    SummarizeCallTranscript::dispatch($transcript->id);
                    $this->line("  queued summarize for transcript {$transcript->id}");
                    $summarized++;

                    continue;
                }

                $this->info("Summarizing transcript {$transcript->id} (call {$transcript->call_log_id})...");
                try {
                    (new SummarizeCallTranscript($transcript->id))->handle();
                    $summarized++;
                } catch (\Throwable $e) {
                    $this->error("Summarize failed for transcript {$transcript->id}: {$e->getMessage()}");
                }
            }
        }

        $verb = $useQueue ? 'Queued' : 'Done';
        $this->info("{$verb}. Transcribed: {$transcribed}, summarized: {$summarized}.");

        if ($useQueue && $force && $transcribed > 0) {
            $this->warn('Note: with --queue --force, transcripts were reset and re-queued. Re-run this command (without --force) after the queue drains to summarize them.');
        }

        return self::SUCCESS;
    }

    /**
     * Recompute speaker maps for ready transcripts from their stored segments
     * (LLM role classification only — no AssemblyAI re-transcription). Used to
     * backfill after agent-resolution improvements.
     */
    protected function reidentifySpeakers(?string $specificCallId, ?int $limit): int
    {
        $query = CallTranscript::query()
            ->where('status', CallTranscript::STATUS_READY)
            ->whereNotNull('segments')
            ->with('callLog');

        if ($specificCallId) {
            $query->where('call_log_id', $specificCallId);
        }

        $query->orderByDesc('id');
        if ($limit !== null) {
            $query->limit($limit);
        }

        $transcripts = $query->get()->filter(fn ($t) => $t->callLog !== null);
        $this->info('Re-identifying speakers for ' . $transcripts->count() . ' transcript(s)…');

        $updated = 0;
        foreach ($transcripts as $transcript) {
            // The AssemblyAI LLM Gateway rate-limits bursts — retry 429s with
            // backoff instead of losing the rest of the batch.
            for ($attempt = 0; $attempt <= 5; $attempt++) {
                try {
                    $map = (new \App\Jobs\TranscribeCallRecording($transcript->call_log_id))
                        ->reidentifySpeakers($transcript);

                    if ($map !== []) {
                        $updated++;
                        $this->line("  call {$transcript->call_log_id}: " . json_encode($map, JSON_UNESCAPED_UNICODE));
                    } else {
                        $this->line("  call {$transcript->call_log_id}: (no confident map — left unchanged)");
                    }
                    break;
                } catch (\Throwable $e) {
                    if (str_contains($e->getMessage(), '429') && $attempt < 5) {
                        $wait = 15 * ($attempt + 1);
                        $this->line("  call {$transcript->call_log_id}: rate limited — waiting {$wait}s (retry " . ($attempt + 1) . '/5)…');
                        sleep($wait);

                        continue;
                    }
                    $this->error("  call {$transcript->call_log_id}: {$e->getMessage()}");
                    break;
                }
            }

            // Gentle pacing between requests.
            usleep(500_000);
        }

        $this->info("Done. Updated speaker maps: {$updated}.");

        return self::SUCCESS;
    }
}
