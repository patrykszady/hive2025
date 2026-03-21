<?php

namespace App\Console\Commands;

use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\SmsThreadParticipant;
use App\Models\SmsThreadRead;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MergeDuplicateSmsThreads extends Command
{
    protected $signature = 'sms:merge-duplicate-threads
                            {--dry-run : Preview merges without executing}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Merge duplicate SMS threads for the same client into one thread per client.';

    public function handle(): int
    {
        $duplicates = SmsGroupThread::query()
            ->select('client_id', DB::raw('COUNT(*) as thread_count'))
            ->whereNotNull('client_id')
            ->groupBy('client_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('thread_count', 'client_id');

        if ($duplicates->isEmpty()) {
            $this->info('No duplicate threads found.');
            return self::SUCCESS;
        }

        $this->info("Found {$duplicates->count()} clients with duplicate threads.");

        foreach ($duplicates as $clientId => $count) {
            $threads = SmsGroupThread::where('client_id', $clientId)
                ->withCount('messages')
                ->orderByDesc('messages_count')
                ->get();

            $keep = $threads->first();
            $mergeInto = $threads->skip(1);

            $client = $keep->client;
            $clientLabel = $client ? ($client->business_name ?: "Client #{$clientId}") : "Client #{$clientId}";

            $this->newLine();
            $this->info("Client: {$clientLabel} (ID: {$clientId}) — {$count} threads");

            $this->line("  KEEP  Thread #{$keep->id}: {$keep->messages_count} msgs, participants: " . json_encode($keep->participants));

            foreach ($mergeInto as $thread) {
                $this->line("  MERGE Thread #{$thread->id}: {$thread->messages_count} msgs, participants: " . json_encode($thread->participants));
            }

            if ($this->option('dry-run')) {
                continue;
            }

            foreach ($mergeInto as $thread) {
                $this->mergeThread($thread, $keep);
            }
        }

        $this->newLine();
        if ($this->option('dry-run')) {
            $this->warn('Dry run — no changes made.');
        } else {
            $this->info('All duplicate threads merged successfully.');
        }

        return self::SUCCESS;
    }

    private function mergeThread(SmsGroupThread $source, SmsGroupThread $target): void
    {
        DB::transaction(function () use ($source, $target) {
            // 1. Move all messages to target thread
            $moved = SmsMessage::where('thread_id', $source->id)->update(['thread_id' => $target->id]);
            $this->line("    Moved {$moved} messages from thread #{$source->id} → #{$target->id}");

            // 2. Merge participants array (combine unique phone numbers)
            $sourceParticipants = $source->participants ?? [];
            $targetParticipants = $target->participants ?? [];
            $merged = array_values(array_unique(array_merge($targetParticipants, $sourceParticipants)));
            $target->participants = $merged;

            // 3. Update last_activity_at to the latest of both
            $latestMsg = SmsMessage::where('thread_id', $target->id)->max('created_at');
            if ($latestMsg) {
                $target->last_activity_at = $latestMsg;
            }

            $target->save();

            // 4. Move thread participants that don't already exist on target
            $existingPhones = SmsThreadParticipant::where('thread_id', $target->id)->pluck('phone_number')->all();
            SmsThreadParticipant::where('thread_id', $source->id)
                ->whereNotIn('phone_number', $existingPhones)
                ->update(['thread_id' => $target->id]);

            // Delete remaining source participants (duplicates)
            SmsThreadParticipant::where('thread_id', $source->id)->delete();

            // 5. Merge read markers — keep the highest last_read_message_id per user
            $sourceReads = SmsThreadRead::where('thread_id', $source->id)->get();
            foreach ($sourceReads as $read) {
                $existing = SmsThreadRead::where('thread_id', $target->id)
                    ->where('user_id', $read->user_id)
                    ->first();

                if ($existing) {
                    if ($read->last_read_message_id > $existing->last_read_message_id) {
                        $existing->update(['last_read_message_id' => $read->last_read_message_id]);
                    }
                } else {
                    $read->update(['thread_id' => $target->id]);
                }
            }

            // Delete any remaining source read markers
            SmsThreadRead::where('thread_id', $source->id)->delete();

            // 6. Delete the empty source thread
            $source->delete();
            $this->line("    Deleted thread #{$source->id}");
        });
    }
}
