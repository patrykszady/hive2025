<?php

namespace App\Console\Commands\Sms;

use App\Models\AppNotification;
use App\Models\SmsMessage;
use Illuminate\Console\Command;

class BackfillTapbacks extends Command
{
    /**
     * Scan inbound SMS messages for tapback reactions that the parser now
     * recognises (after multi-language / extra-emoji updates) and report
     * them. With --clean, also mark related AppNotifications as read so
     * stale "New Text from..." badges from un-detected reactions disappear.
     */
    protected $signature = 'sms:backfill-tapbacks
                            {--clean : Mark AppNotifications for detected tapbacks as read}
                            {--limit=0 : Limit number of messages scanned (0 = no limit)}
                            {--thread= : Only scan a specific thread id}';

    protected $description = 'Detect tapback reactions in existing SMS messages and optionally clean stale notifications';

    public function handle(): int
    {
        $query = SmsMessage::query()
            ->where('direction', SmsMessage::DIRECTION_INBOUND)
            ->whereNotNull('text')
            ->orderByDesc('id');

        if ($threadId = $this->option('thread')) {
            $query->where('thread_id', $threadId);
        }

        if ($limit = (int) $this->option('limit')) {
            $query->limit($limit);
        }

        $clean = (bool) $this->option('clean');
        $detected = 0;
        $cleaned = 0;
        $byEmoji = [];

        $this->info('Scanning inbound SMS messages for tapback reactions…');

        $query->chunkById(500, function ($messages) use (&$detected, &$cleaned, &$byEmoji, $clean): void {
            foreach ($messages as $msg) {
                $tapback = $msg->parseTapback();
                if (! $tapback || ! ($tapback['emoji'] ?? null)) {
                    continue;
                }

                $detected++;
                $emoji = $tapback['emoji'];
                $byEmoji[$emoji] = ($byEmoji[$emoji] ?? 0) + 1;

                $this->line(sprintf(
                    '  #%d  thread %s  %s  ← %s',
                    $msg->id,
                    $msg->thread_id ?? '—',
                    $emoji,
                    mb_strimwidth(trim(preg_replace('/\s+/', ' ', $msg->text)), 0, 80, '…')
                ));

                if ($clean) {
                    $cleaned += AppNotification::query()
                        ->whereNull('read_at')
                        ->where('type', 'sms_received')
                        ->where('data->sms_message_id', $msg->id)
                        ->update(['read_at' => now()]);
                }
            }
        });

        $this->newLine();
        $this->info("Detected tapbacks: {$detected}");
        if ($byEmoji) {
            foreach ($byEmoji as $emoji => $count) {
                $this->line("  {$emoji}  {$count}");
            }
        }

        if ($clean) {
            $this->info("Notifications marked read: {$cleaned}");
        } else {
            $this->comment('Re-run with --clean to mark related notifications as read.');
        }

        return self::SUCCESS;
    }
}
