<?php

namespace App\Console\Commands;

use App\Models\EmailTemplate;
use App\Models\EmailTracking;
use App\Models\Estimate;
use Illuminate\Console\Command;

class BackfillEmailTrackingSubjects extends Command
{
    protected $signature = 'email-tracking:backfill-template-names {--dry-run : Show what would be updated without making changes}';

    protected $description = 'Backfill email_template_name for existing email tracking records';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        
        if ($dryRun) {
            $this->info('Running in DRY RUN mode - no changes will be made');
        }

        // Step 1: Backfill email_template_name from metadata
        $this->info('Step 1: Backfilling email_template_name from metadata...');
        $this->backfillTemplateNameFromMetadata($dryRun);

        // Step 2: Try to infer template from estimate emails
        $this->info('Step 2: Inferring template names for estimate emails...');
        $this->inferTemplateNamesForEstimates($dryRun);

        // Step 3: Copy email_template_name from sent records to other events
        $this->info('Step 3: Copying email_template_name from sent records to related events...');
        $this->copyFromSentRecords($dryRun);

        $this->info('Backfill complete!');
        
        return self::SUCCESS;
    }

    protected function backfillTemplateNameFromMetadata(bool $dryRun): void
    {
        $records = EmailTracking::whereNull('email_template_name')
            ->whereNotNull('metadata')
            ->get();

        $updated = 0;

        foreach ($records as $record) {
            $metadata = $record->metadata;
            $templateName = $metadata['email_template_name'] ?? null;

            if ($templateName) {
                if ($dryRun) {
                    $this->line("Would update record {$record->id}: email_template_name = {$templateName}");
                } else {
                    // Update all records with this message ID
                    EmailTracking::where('nylas_message_id', $record->nylas_message_id)
                        ->update(['email_template_name' => $templateName]);
                }
                $updated++;
            }
        }

        $this->info("Template name updated for {$updated} records from metadata");
    }

    protected function inferTemplateNamesForEstimates(bool $dryRun): void
    {
        // For estimate emails without template name, we can't reliably determine
        // which template was used, so we'll just mark them as "Unknown Estimate"
        $records = EmailTracking::whereNull('email_template_name')
            ->whereNotNull('metadata')
            ->where('event_type', 'sent')
            ->get();

        $updated = 0;

        foreach ($records as $record) {
            $metadata = $record->metadata;
            $estimateId = $metadata['estimate_id'] ?? null;

            if (!$estimateId) {
                continue;
            }

            // Default to "Unknown Estimate" for old records
            $templateName = 'Unknown Estimate';

            if ($dryRun) {
                $this->line("Would update record {$record->id}: email_template_name = {$templateName}");
            } else {
                // Update all records with this message ID
                EmailTracking::where('nylas_message_id', $record->nylas_message_id)
                    ->update(['email_template_name' => $templateName]);
            }
            $updated++;
        }

        $this->info("Inferred template names for {$updated} estimate emails");
    }

    protected function copyFromSentRecords(bool $dryRun): void
    {
        // Get all unique message IDs that have sent records with email_template_name
        $messageIds = EmailTracking::where('event_type', 'sent')
            ->whereNotNull('email_template_name')
            ->pluck('nylas_message_id')
            ->unique();

        $updated = 0;

        foreach ($messageIds as $messageId) {
            // Get the sent record with email_template_name
            $sentRecord = EmailTracking::where('nylas_message_id', $messageId)
                ->where('event_type', 'sent')
                ->first();

            if (!$sentRecord || !$sentRecord->email_template_name) {
                continue;
            }

            // Update related records without email_template_name
            $relatedRecords = EmailTracking::where('nylas_message_id', $messageId)
                ->where('event_type', '!=', 'sent')
                ->whereNull('email_template_name')
                ->get();

            foreach ($relatedRecords as $record) {
                if ($dryRun) {
                    $this->line("Would update record {$record->id}: email_template_name = {$sentRecord->email_template_name}");
                } else {
                    $record->update(['email_template_name' => $sentRecord->email_template_name]);
                }
                $updated++;
            }
        }

        $this->info("Copied email_template_name to {$updated} records from sent records");
    }
}
