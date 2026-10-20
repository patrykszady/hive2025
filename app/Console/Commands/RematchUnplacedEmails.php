<?php

namespace App\Console\Commands;

use App\Jobs\ExtractProjectActionItems;
use App\Jobs\SummarizeEmailMessage;
use App\Models\EmailMessage;
use App\Services\Emails\StakeholderEmailStore;
use Illuminate\Console\Command;

/**
 * Give the unplaced inbox a second look with what placement knows now:
 * everyone on the email (a homeowner in To counts — Jenn's "tile is on the
 * way" to the Jordans), a client the subject names ("Abbey-plumbing
 * delivery"), and the project the rest of its thread is on. An email with
 * one clear project is placed; one that gained candidates is read again so
 * the AI can pick. Safe to run again: placed emails are skipped and nothing
 * is re-read unless its candidates changed.
 */
class RematchUnplacedEmails extends Command
{
    protected $signature = 'emails:rematch-unplaced
        {--dry-run : List what would change without changing anything}';

    protected $description = 'Re-match unplaced stakeholder emails by who is on them, the subject and the thread.';

    public function handle(StakeholderEmailStore $store): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $placed = 0;
        $reread = 0;
        $projectsWithNewItems = [];

        foreach (EmailMessage::query()->unplaced()->orderBy('id')->get() as $email) {
            $placement = $store->placementFor(
                (string) $email->from_email,
                (array) $email->to,
                (array) $email->cc,
                (string) $email->subject,
                $email->thread_id,
                (int) $email->belongs_to_vendor_id,
            );

            if (! $placement) {
                continue;
            }

            $match = $placement['match'];
            $who = [
                'client_id' => $match['client_id'],
                'vendor_id' => $match['vendor_id'],
                'user_id' => $match['user']?->id,
                'candidate_project_ids' => $placement['candidate_ids'],
            ];

            if ($placement['project_id']) {
                $this->line("#{$email->id} \"{$email->subject}\" → project {$placement['project_id']}");
                $placed++;

                if (! $dryRun) {
                    $email->forceFill($who + ['project_id' => $placement['project_id']])->save();

                    if ((array) $email->action_items !== []) {
                        $projectsWithNewItems[$placement['project_id']] = true;
                    }
                }

                continue;
            }

            $current = array_map('intval', (array) ($email->candidate_project_ids ?? []));
            if (array_diff($placement['candidate_ids'], $current) === []) {
                continue;
            }

            $this->line("#{$email->id} \"{$email->subject}\" → read again with project(s) ".implode(', ', $placement['candidate_ids']));
            $reread++;

            if (! $dryRun) {
                $email->forceFill($who + ['summarized_at' => null])->save();
                SummarizeEmailMessage::dispatch($email->id);
            }
        }

        // Same as sorting by hand (EmailPlacement::place): a placed email's
        // action items join its project's list.
        foreach (array_keys($projectsWithNewItems) as $projectId) {
            ExtractProjectActionItems::dispatch($projectId)->delay(now()->addMinute());
        }

        $this->info(sprintf('%s %d, %d queued to read again.', $dryRun ? 'Would place' : 'Placed', $placed, $reread));

        return self::SUCCESS;
    }
}
