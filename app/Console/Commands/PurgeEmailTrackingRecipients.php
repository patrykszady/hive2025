<?php

namespace App\Console\Commands;

use App\Models\EmailTracking;
use Illuminate\Support\Facades\DB;
use Illuminate\Console\Command;

class PurgeEmailTrackingRecipients extends Command
{
    protected $signature = 'email-tracking:purge-recipients
        {--email=* : Recipient email(s) to purge; repeat option or use comma-separated values}
        {--event-type=* : Restrict to specific event types}
        {--limit=50000 : Max matching rows to scan}
        {--execute : Apply updates/deletes (default is dry-run)}';

    protected $description = 'Backfill email_tracking by removing excluded recipient emails from recipient_emails arrays (dry-run by default).';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $limit = (int) $this->option('limit');

        if ($limit <= 0) {
            $this->error('Option --limit must be greater than 0.');

            return self::FAILURE;
        }

        $targetEmails = $this->resolveTargetEmails();

        if (empty($targetEmails)) {
            $this->error('No target recipients provided. Use --email or config(email_tracking.excluded_recipients).');

            return self::FAILURE;
        }

        $eventTypes = $this->resolveEventTypes();

        $this->info($execute ? 'Executing recipient purge...' : 'Dry-run (no data changes).');
        $this->line('Target recipients: '.implode(', ', $targetEmails));
        if (! empty($eventTypes)) {
            $this->line('Event types: '.implode(', ', $eventTypes));
        }

        $rows = EmailTracking::withoutGlobalScopes()
            ->whereNotNull('recipient_emails')
            ->when(! empty($eventTypes), fn ($query) => $query->whereIn('event_type', $eventTypes))
            ->where(function ($query) use ($targetEmails) {
                foreach ($targetEmails as $email) {
                    $query->orWhereJsonContains('recipient_emails', $email);
                }
            })
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'event_type', 'recipient_emails']);

        if ($rows->isEmpty()) {
            $this->info('No matching email_tracking rows found.');

            return self::SUCCESS;
        }

        $deleteIds = [];
        $updates = [];
        $matchedByType = [];
        $deleteByType = [];
        $updateByType = [];

        foreach ($rows as $row) {
            $type = (string) $row->event_type;
            $matchedByType[$type] = ($matchedByType[$type] ?? 0) + 1;

            $existingRecipients = collect(is_array($row->recipient_emails) ? $row->recipient_emails : [])
                ->filter(fn ($email) => is_string($email) && trim($email) !== '')
                ->map(fn (string $email): string => strtolower(trim($email)))
                ->unique()
                ->values()
                ->all();

            if (empty($existingRecipients)) {
                $deleteIds[] = (int) $row->id;
                $deleteByType[$type] = ($deleteByType[$type] ?? 0) + 1;
                continue;
            }

            $cleanedRecipients = array_values(array_filter(
                $existingRecipients,
                fn (string $email): bool => ! in_array($email, $targetEmails, true)
            ));

            if ($cleanedRecipients === $existingRecipients) {
                continue;
            }

            if (empty($cleanedRecipients)) {
                $deleteIds[] = (int) $row->id;
                $deleteByType[$type] = ($deleteByType[$type] ?? 0) + 1;
                continue;
            }

            $updates[] = [
                'id' => (int) $row->id,
                'recipient_emails' => $cleanedRecipients,
            ];
            $updateByType[$type] = ($updateByType[$type] ?? 0) + 1;
        }

        $this->line('Rows scanned: '.$rows->count());
        $this->line('Rows to update: '.count($updates));
        $this->line('Rows to delete: '.count($deleteIds));

        $this->table(
            ['event_type', 'matched', 'update', 'delete'],
            collect(array_keys($matchedByType))
                ->sort()
                ->map(fn (string $type) => [
                    $type,
                    (int) ($matchedByType[$type] ?? 0),
                    (int) ($updateByType[$type] ?? 0),
                    (int) ($deleteByType[$type] ?? 0),
                ])
                ->values()
                ->all()
        );

        $sampleDeleteIds = array_slice($deleteIds, 0, 25);
        if (! empty($sampleDeleteIds)) {
            $this->line('Sample delete IDs: '.implode(', ', $sampleDeleteIds));
        }

        if (! $execute) {
            $this->warn('Dry-run complete. Re-run with --execute to apply changes.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($updates, $deleteIds): void {
            foreach ($updates as $update) {
                EmailTracking::withoutGlobalScopes()
                    ->whereKey($update['id'])
                    ->update([
                        'recipient_emails' => $update['recipient_emails'],
                        'updated_at' => now(),
                    ]);
            }

            if (! empty($deleteIds)) {
                EmailTracking::withoutGlobalScopes()
                    ->whereIn('id', $deleteIds)
                    ->delete();
            }
        });

        $this->info('Purge complete. Updated '.count($updates).' rows and deleted '.count($deleteIds).' rows.');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    protected function resolveTargetEmails(): array
    {
        $emails = collect($this->option('email'))
            ->flatMap(function ($value) {
                if (! is_string($value)) {
                    return [];
                }

                return explode(',', $value);
            })
            ->merge((array) config('email_tracking.excluded_recipients', []))
            ->filter(fn ($email) => is_string($email) && trim($email) !== '')
            ->map(fn (string $email): string => strtolower(trim($email)))
            ->unique()
            ->values()
            ->all();

        return $emails;
    }

    /**
     * @return list<string>
     */
    protected function resolveEventTypes(): array
    {
        return collect($this->option('event-type'))
            ->flatMap(function ($value) {
                if (! is_string($value)) {
                    return [];
                }

                return explode(',', $value);
            })
            ->filter(fn ($eventType) => is_string($eventType) && trim($eventType) !== '')
            ->map(fn (string $eventType): string => strtolower(trim($eventType)))
            ->unique()
            ->values()
            ->all();
    }
}
