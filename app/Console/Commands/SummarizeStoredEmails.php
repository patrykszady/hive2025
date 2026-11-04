<?php

namespace App\Console\Commands;

use App\Jobs\SummarizeEmailMessage;
use App\Models\EmailMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Queue the AI read (SummarizeEmailMessage) for stored stakeholder email —
 * used once after a mailbox's first sync, when every email it brought in
 * still has summarized_at null, or to catch up after an outage.
 */
class SummarizeStoredEmails extends Command
{
    protected $signature = 'emails:summarize
        {--missing : Only emails never summarized}
        {--unplaced : Read again the emails waiting in the unplaced inbox (an email not about any job leaves it)}
        {--since= : Only email sent on or after this date (YYYY-MM-DD)}
        {--limit= : At most this many emails}';

    protected $description = "Queue SummarizeEmailMessage for stored stakeholder email.";

    public function handle(): int
    {
        $query = EmailMessage::query();

        if ($this->option('missing')) {
            $query->whereNull('summarized_at');
        }

        $reread = (bool) $this->option('unplaced');
        if ($reread) {
            $query->unplaced();
        }

        if ($since = $this->option('since')) {
            $query->where('sent_at', '>=', Carbon::parse($since)->startOfDay());
        }

        $query->orderBy('id');

        if ($limit = $this->option('limit')) {
            $query->limit((int) $limit);
        }

        $ids = $query->pluck('id');

        foreach ($ids as $id) {
            SummarizeEmailMessage::dispatch($id, $reread);
        }

        $this->info(sprintf('Queued %d email(s) for summarization.', $ids->count()));

        return self::SUCCESS;
    }
}
