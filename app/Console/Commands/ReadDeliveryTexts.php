<?php

namespace App\Console\Commands;

use App\Models\SmsMessage;
use App\Services\Deliveries\DeliveryTextIntake;
use Illuminate\Console\Command;

/**
 * Runs the delivery reader on texts already received — for a text that came
 * in before the reader existed, or to see what it would do (--dry-run).
 */
class ReadDeliveryTexts extends Command
{
    protected $signature = 'deliveries:read-texts {message* : SMS message ids} {--dry-run : Report the decision without writing a task, a row or an alert}';

    protected $description = 'Read inbound texts for supplier deliveries and schedule them on the project at the delivery address.';

    public function handle(DeliveryTextIntake $intake): int
    {
        foreach ($this->argument('message') as $id) {
            $message = SmsMessage::withoutGlobalScopes()->find((int) $id);

            if (! $message) {
                $this->warn("Message {$id}: not found.");

                continue;
            }

            $decision = $intake->consider($message, dryRun: (bool) $this->option('dry-run'));

            if (! $decision) {
                $this->line("Message {$id}: nothing to read (not inbound, a client's thread, no delivery words, intake off, or the model could not be asked).");

                continue;
            }

            $this->info(sprintf(
                'Message %d: %s — %s %s, project %s, task %s%s',
                $id,
                $decision->outcome,
                $decision->supplier ?: 'supplier?',
                $decision->delivery_date?->toDateString() ?? 'no date',
                $decision->project_id ?: '-',
                $decision->task_id ?: '-',
                $decision->reason ? ' ('.$decision->reason.')' : '',
            ));
        }

        return self::SUCCESS;
    }
}
