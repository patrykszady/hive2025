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
        {--force : Re-transcribe and re-summarize even if already completed}';

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
}
