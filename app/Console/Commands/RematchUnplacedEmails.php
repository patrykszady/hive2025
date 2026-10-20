<?php

namespace App\Console\Commands;

use App\Jobs\SummarizeEmailMessage;
use App\Models\EmailMessage;
use App\Services\Emails\EmailStakeholders;
use App\Services\Emails\StakeholderEmailStore;
use Illuminate\Console\Command;

/**
 * Give the unplaced inbox a second look with what placement knows now: a
 * reply goes to the project the rest of its thread is on, and a client the
 * subject names by last name ("Abbey-plumbing delivery") becomes a candidate
 * — those emails are read again so the AI can pick the project. Safe to run
 * again: placed emails are skipped and an email is only re-read when it
 * gained a candidate.
 */
class RematchUnplacedEmails extends Command
{
    protected $signature = 'emails:rematch-unplaced
        {--dry-run : List what would change without changing anything}';

    protected $description = 'Place unplaced stakeholder emails by thread, and re-read those whose subject names a client.';

    public function handle(EmailStakeholders $stakeholders, StakeholderEmailStore $store): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $byThread = 0;
        $reread = 0;

        foreach (EmailMessage::query()->unplaced()->orderBy('id')->get() as $email) {
            $threadProjectId = $store->threadProjectId((int) $email->belongs_to_vendor_id, $email->thread_id);

            if ($threadProjectId) {
                $this->line("#{$email->id} \"{$email->subject}\" → project {$threadProjectId} (same thread)");
                $byThread++;

                if (! $dryRun) {
                    $email->forceFill(['project_id' => $threadProjectId])->save();
                }

                continue;
            }

            $current = array_map('intval', (array) ($email->candidate_project_ids ?? []));
            $named = $stakeholders->namedInSubject($email->subject, (int) $email->belongs_to_vendor_id)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->reject(fn (int $id) => in_array($id, $current, true))
                ->values()
                ->all();

            if ($named === []) {
                continue;
            }

            $this->line("#{$email->id} \"{$email->subject}\" → read again with project(s) ".implode(', ', $named));
            $reread++;

            if (! $dryRun) {
                $email->forceFill([
                    'candidate_project_ids' => array_values(array_unique([...$named, ...$current])),
                    'summarized_at' => null,
                ])->save();
                SummarizeEmailMessage::dispatch($email->id);
            }
        }

        $this->info(sprintf('%s %d by thread, %d queued to read again.', $dryRun ? 'Would place' : 'Placed', $byThread, $reread));

        return self::SUCCESS;
    }
}
