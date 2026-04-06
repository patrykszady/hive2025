<?php

namespace App\Console\Commands;

use App\Models\CallLog;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\SmsThreadParticipant;
use App\Models\SmsThreadRead;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupTestCalls extends Command
{
    protected $signature = 'cleanup:test-phone
        {phone : Phone number to clean up (e.g. 8472123894 or +18472123894)}
        {--execute : Actually delete records (default is dry-run)}
    ';

    protected $description = 'Remove all call logs, SMS threads, and messages associated with a test phone number.';

    public function handle(): int
    {
        $raw = preg_replace('/\D/', '', $this->argument('phone'));
        if (strlen($raw) === 10) {
            $raw = '1' . $raw;
        }
        $e164 = '+' . $raw;
        $execute = (bool) $this->option('execute');

        $this->info(($execute ? 'EXECUTING' : 'DRY-RUN') . " cleanup for {$e164}");
        $this->newLine();

        // ── Call Logs ──
        $callLogs = CallLog::query()
            ->where('from_number', $e164)
            ->orWhere('to_number', $e164)
            ->get();

        $this->line("Call logs found: {$callLogs->count()}");

        $recordingFiles = [];
        foreach ($callLogs as $log) {
            $this->line("  - Call #{$log->id} | {$log->direction} | {$log->from_number} → {$log->to_number} | {$log->status} | {$log->created_at}");
            if ($log->recording_url) {
                // recording_url is like /storage/call-recordings/2026/04/uuid.mp3
                $diskPath = 'public/' . str_replace('/storage/', '', $log->recording_url);
                $recordingFiles[] = $diskPath;
            }
        }

        // ── SMS Threads (from_number or participant matches) ──
        $threadsByFrom = SmsGroupThread::where('from_number', $e164)->pluck('id');

        $threadsByParticipant = SmsThreadParticipant::where('phone_number', $e164)->pluck('thread_id');

        $threadIds = $threadsByFrom->merge($threadsByParticipant)->unique();

        $threads = SmsGroupThread::whereIn('id', $threadIds)->get();
        $this->newLine();
        $this->line("SMS threads found: {$threads->count()}");
        foreach ($threads as $thread) {
            $msgCount = SmsMessage::where('thread_id', $thread->id)->count();
            $this->line("  - Thread #{$thread->id} | from: {$thread->from_number} | messages: {$msgCount} | {$thread->created_at}");
        }

        // ── Orphan SMS messages (from this number but not in a matching thread) ──
        $orphanMessages = SmsMessage::where('from_number', $e164)
            ->whereNotIn('thread_id', $threadIds)
            ->get();
        if ($orphanMessages->isNotEmpty()) {
            $this->newLine();
            $this->line("Orphan SMS messages (from {$e164} in other threads): {$orphanMessages->count()}");
        }

        // ── Recording files ──
        if (count($recordingFiles) > 0) {
            $this->newLine();
            $this->line('Recording files: ' . count($recordingFiles));
            foreach ($recordingFiles as $f) {
                $exists = Storage::disk('local')->exists($f) ? '✓' : '✗ missing';
                $this->line("  - {$f} ({$exists})");
            }
        }

        // ── Summary ──
        $this->newLine();
        $totalMessages = SmsMessage::whereIn('thread_id', $threadIds)->count() + $orphanMessages->count();
        $this->table(
            ['Type', 'Count'],
            [
                ['Call logs', $callLogs->count()],
                ['Recording files', count($recordingFiles)],
                ['SMS threads', $threads->count()],
                ['SMS messages', $totalMessages],
                ['Thread participants', SmsThreadParticipant::whereIn('thread_id', $threadIds)->count()],
                ['Thread reads', SmsThreadRead::whereIn('thread_id', $threadIds)->count()],
            ]
        );

        if (! $execute) {
            $this->warn('This was a dry-run. Re-run with --execute to delete.');

            return self::SUCCESS;
        }

        if (! $this->confirm("Delete all of the above for {$e164}?")) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        // ── Delete in correct order (foreign keys) ──
        $deletedMessages = SmsMessage::whereIn('thread_id', $threadIds)->delete();
        $orphanMessages->each->delete();
        SmsThreadRead::whereIn('thread_id', $threadIds)->delete();
        SmsThreadParticipant::whereIn('thread_id', $threadIds)->delete();
        SmsGroupThread::whereIn('id', $threadIds)->delete();

        foreach ($recordingFiles as $f) {
            if (Storage::disk('local')->exists($f)) {
                Storage::disk('local')->delete($f);
            }
        }

        $callLogs->each->delete();

        $this->info("Deleted {$callLogs->count()} call logs, {$threads->count()} threads, {$deletedMessages} messages, " . count($recordingFiles) . ' recordings.');

        return self::SUCCESS;
    }
}
