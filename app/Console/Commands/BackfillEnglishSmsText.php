<?php

namespace App\Console\Commands;

use App\Models\SmsMessage;
use App\Services\SmsTranslationService;
use Illuminate\Console\Command;

/**
 * Store an English rendering of every SMS, once.
 *
 * Hive reads in English, but historical threads are full of Spanish and
 * Polish text with no sender_language recorded — thread 305 is entirely
 * Spanish with sender_language NULL on every row. The display path had to
 * guess the language from the text itself, and that guess is a keyword
 * heuristic: "Cuando nos vemos no hay prisa" carries no accents and none of
 * the hint words, so it was read as English and left untranslated.
 *
 * Guessing per render was the wrong shape anyway — same cost, same wrong
 * answer, every time anyone opened the thread. This translates each message
 * ONCE into raw_payload.english_text, and the presenter prefers that when it
 * is there. Nothing is overwritten: the original text column is untouched,
 * so a message can always be shown as it was actually sent.
 *
 *   php artisan sms:backfill-english --thread=305 --dry-run
 *   php artisan sms:backfill-english --thread=305
 *   php artisan sms:backfill-english --limit=500
 */
class BackfillEnglishSmsText extends Command
{
    protected $signature = 'sms:backfill-english
        {--thread= : Only this thread id}
        {--limit=200 : Maximum messages to process in this run}
        {--force : Re-translate messages that already have english_text}
        {--dry-run : Show what would change without calling the API or writing}';

    protected $description = 'Translate stored SMS text into English once and cache it on the message.';

    public function handle(SmsTranslationService $translator): int
    {
        $query = SmsMessage::query()
            ->when($this->option('thread'), fn ($q, $id) => $q->where('thread_id', $id))
            ->whereNotNull('text')
            ->where('text', '!=', '')
            ->orderBy('id');

        $messages = $query->limit((int) $this->option('limit'))->get();

        if ($messages->isEmpty()) {
            $this->info('  Nothing to do.');

            return self::SUCCESS;
        }

        $translated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($messages as $message) {
            $payload = is_array($message->raw_payload) ? $message->raw_payload : [];
            $text = trim((string) $message->text);

            if ($text === '') {
                $skipped++;

                continue;
            }

            if (! $this->option('force') && filled($payload['english_text'] ?? null)) {
                $skipped++;

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line(sprintf('    %-6d %s', $message->id, mb_strimwidth(str_replace("\n", ' ', $text), 0, 70, '…')));
                $translated++;

                continue;
            }

            try {
                $english = $translator->translate($text, 'English');
            } catch (\Throwable $e) {
                $this->warn("    {$message->id}: {$e->getMessage()}");
                $failed++;

                continue;
            }

            $english = trim($english);

            if ($english === '') {
                $failed++;

                continue;
            }

            $payload['english_text'] = $english;
            $message->raw_payload = $payload;
            // saveQuietly: this is a display cache, not a change to the
            // conversation — it must not bump the thread or fire observers
            // that would look like new activity to anyone watching.
            $message->saveQuietly();

            $translated++;

            if ($english !== $text) {
                $this->line(sprintf('    %-6d %s', $message->id, mb_strimwidth(str_replace("\n", ' ', $english), 0, 70, '…')));
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '  %s %d message(s); skipped %d; failed %d.',
            $this->option('dry-run') ? 'Would translate' : 'Translated',
            $translated,
            $skipped,
            $failed
        ));

        return self::SUCCESS;
    }
}
