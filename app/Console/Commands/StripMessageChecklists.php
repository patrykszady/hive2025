<?php

namespace App\Console\Commands;

use App\Models\SmsMessage;
use App\Models\Task;
use Illuminate\Console\Command;

/**
 * Take copied sentences of a client's text off task checklists.
 *
 * Until 2026-10-15 a task made from a text got the text's own sentences as
 * its checklist whenever the extractor named no to-dos, so a sub's page
 * listed "Hey Patryk, hope things have been going well" as a task (RG Tile's
 * grout job, twice over). An item six words or longer (the extractor's
 * to-dos are two to five), not ticked, and found word for word in the
 * task's notes or in a text received in the SOURCE_DAYS before the task was
 * made, is such a sentence: notes can hold only part of the text, as the
 * grout task's did. Repeated items are folded into one. Re-runnable.
 */
class StripMessageChecklists extends Command
{
    protected $signature = 'tasks:strip-message-checklists
        {--task=* : Only these task ids}
        {--dry-run : Show what would be removed, change nothing}';

    protected $description = 'Remove copied message sentences and duplicates from task checklists';

    /** The extractor's to-dos are two to five words; a copied sentence is longer. */
    public const MIN_SENTENCE_WORDS = 6;

    /** How far back from the task's creation a text it was made from can be. */
    public const SOURCE_DAYS = 60;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $tasks = Task::withoutGlobalScopes()
            ->whereNotNull('options')
            ->whereNotNull('notes')
            ->when($this->option('task'), fn ($query, $ids) => $query->whereIn('id', array_map('intval', (array) $ids)))
            ->get();

        $changed = 0;
        $removed = 0;

        foreach ($tasks as $task) {
            $options = (array) json_decode((string) json_encode($task->options), true);
            $checklist = array_values((array) ($options['checklist'] ?? []));
            if ($checklist === []) {
                continue;
            }

            $notes = self::normalize((string) $task->notes);
            $kept = [];
            $seen = [];
            $dropped = [];

            foreach ($checklist as $item) {
                $item = is_array($item) ? $item : ['text' => (string) $item];
                $text = self::normalize((string) ($item['text'] ?? ''));
                $completed = ! empty($item['completed']);

                $isSentence = ! $completed
                    && str_word_count($text) >= self::MIN_SENTENCE_WORDS
                    && (($notes !== '' && str_contains($notes, $text)) || $this->inRecentText($task, (string) ($item['text'] ?? '')));
                $isRepeat = $text !== '' && isset($seen[$text]) && ! $completed;

                if ($isSentence || $isRepeat) {
                    $dropped[] = (string) ($item['text'] ?? '');

                    continue;
                }
                $seen[$text] = true;
                $kept[] = $item;
            }

            if ($dropped === []) {
                continue;
            }

            $changed++;
            $removed += count($dropped);
            $this->line(sprintf('  task %d "%s": remove %d of %d', $task->id, $task->title, count($dropped), count($checklist)));
            foreach ($dropped as $text) {
                $this->line('      - '.mb_strimwidth($text, 0, 90, '…'));
            }

            if (! $dryRun) {
                $options['checklist'] = $kept;
                $task->options = $options;
                $task->saveQuietly();
            }
        }

        $this->info(($dryRun ? '[dry run] ' : '')."{$removed} item(s) removed from {$changed} task(s).");

        return self::SUCCESS;
    }

    /** The item appears word for word in a text received shortly before the task was made. */
    protected function inRecentText(Task $task, string $text): bool
    {
        $text = rtrim(trim($text), '.!?');
        if ($text === '' || ! $task->created_at) {
            return false;
        }

        return SmsMessage::query()
            ->whereBetween('created_at', [$task->created_at->copy()->subDays(self::SOURCE_DAYS), $task->created_at->copy()->addDay()])
            ->where('text', 'like', '%'.addcslashes($text, '%_\\').'%')
            ->exists();
    }

    protected static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', mb_strtolower(rtrim(trim($text), '.!?'))));
    }
}
