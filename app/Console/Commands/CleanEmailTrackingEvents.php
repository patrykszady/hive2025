<?php

namespace App\Console\Commands;

use App\Models\EmailTracking;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanEmailTrackingEvents extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email-tracking:clean 
                            {--dry-run : Show what would be cleaned without making changes}
                            {--backfill : Extract legitimate opens from recents arrays}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean email tracking events: remove automated/sender opens and backfill missing legitimate opens';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $backfill = $this->option('backfill');

        if ($dryRun) {
            $this->info('🔍 DRY RUN MODE - No changes will be made');
        }

        $this->info('Starting email tracking cleanup...');
        $this->newLine();

        // Step 1: Remove automated opens (GoogleImageProxy, etc.)
        $automatedCount = $this->cleanAutomatedOpens($dryRun);

        // Step 2: Remove sender opens (OneOutlook, etc.)
        $senderCount = $this->cleanSenderOpens($dryRun);

        // Step 3: Remove replied_outgoing events
        $repliedOutgoingCount = $this->cleanRepliedOutgoing($dryRun);

        // Step 4: Backfill missing legitimate opens from recents arrays
        $backfilledCount = 0;
        if ($backfill) {
            $backfilledCount = $this->backfillLegitimateOpens($dryRun);
        }

        $this->newLine();
        $this->info('✅ Cleanup complete!');
        $this->table(
            ['Category', 'Count'],
            [
                ['Automated opens removed', $automatedCount],
                ['Sender opens removed', $senderCount],
                ['Replied outgoing removed', $repliedOutgoingCount],
                ['Legitimate opens backfilled', $backfilledCount],
            ]
        );

        if ($dryRun) {
            $this->warn('This was a dry run. Run without --dry-run to apply changes.');
        }

        return Command::SUCCESS;
    }

    /**
     * Remove automated opens (GoogleImageProxy, prefetch, bots, etc.)
     */
    protected function cleanAutomatedOpens(bool $dryRun): int
    {
        $this->info('🤖 Cleaning automated opens...');

        $automatedPatterns = [
            'googleimageproxy',
            'ggpht.com',
        ];

        // Get all opened events and filter in PHP to be precise
        $allOpens = EmailTracking::where('event_type', 'opened')->get();
        $toDelete = collect();

        foreach ($allOpens as $open) {
            $ua = $open->user_agent ?? '';
            
            foreach ($automatedPatterns as $pattern) {
                if (stripos($ua, $pattern) !== false) {
                    $toDelete->push($open->id);
                    break;
                }
            }
        }

        $count = $toDelete->count();

        if ($count > 0) {
            if (!$dryRun) {
                EmailTracking::whereIn('id', $toDelete->toArray())->delete();
                Log::info('Deleted automated email opens', ['count' => $count]);
            }
            $this->line("  Found {$count} automated opens");
        }

        return $count;
    }

    /**
     * Remove sender opens (when sender views their own sent email)
     */
    protected function cleanSenderOpens(bool $dryRun): int
    {
        $this->info('👤 Cleaning sender opens...');

        $query = EmailTracking::where('event_type', 'opened')
            ->where('user_agent', 'LIKE', '%OneOutlook%');

        $count = $query->count();

        if ($count > 0) {
            if (!$dryRun) {
                $query->delete();
                Log::info('Deleted sender email opens', ['count' => $count]);
            }
            $this->line("  Found {$count} sender opens");
        }

        return $count;
    }

    /**
     * Remove replied_outgoing events (duplicate sent tracking)
     */
    protected function cleanRepliedOutgoing(bool $dryRun): int
    {
        $this->info('📤 Cleaning replied_outgoing events...');

        $query = EmailTracking::where('event_type', 'replied_outgoing');

        $count = $query->count();

        if ($count > 0) {
            if (!$dryRun) {
                $query->delete();
                Log::info('Deleted replied_outgoing events', ['count' => $count]);
            }
            $this->line("  Found {$count} replied_outgoing events");
        }

        return $count;
    }

    /**
     * Backfill legitimate opens from recents arrays in existing webhook payloads
     */
    protected function backfillLegitimateOpens(bool $dryRun): int
    {
        $this->info('📥 Backfilling legitimate opens from recents arrays...');

        // Get all opened events that have metadata with recents arrays
        $events = EmailTracking::where('event_type', 'opened')
            ->whereNotNull('metadata')
            ->get();

        $backfilledCount = 0;

        foreach ($events as $event) {
            $metadata = is_array($event->metadata) ? $event->metadata : json_decode($event->metadata, true);
            $recents = $metadata['object']['recents'] ?? [];

            if (empty($recents)) {
                continue;
            }

            // Process each recent open
            foreach ($recents as $recent) {
                $ua = $recent['user_agent'] ?? '';
                $timestamp = $recent['timestamp'] ?? null;
                $openedId = $recent['opened_id'] ?? null;

                if (!$timestamp) {
                    continue;
                }

                // Skip automated opens
                if ($this->isAutomated($ua)) {
                    continue;
                }

                // Skip sender opens
                if (stripos($ua, 'OneOutlook') !== false) {
                    continue;
                }

                $openTime = Carbon::createFromTimestamp($timestamp)->utc();

                // Check if this open is already tracked
                $exists = EmailTracking::where('nylas_message_id', $event->nylas_message_id)
                    ->where('event_type', 'opened')
                    ->where('event_at', $openTime)
                    ->exists();

                if ($exists) {
                    continue;
                }

                // Extract primary IP
                $ip = $recent['ip'] ?? '';
                $ipArray = array_map('trim', explode(',', $ip));
                $primaryIp = $ipArray[0] ?? null;

                // Create tracking event for this legitimate open
                if (!$dryRun) {
                    EmailTracking::create([
                        'project_id' => $event->project_id,
                        'nylas_message_id' => $event->nylas_message_id,
                        'nylas_thread_id' => $event->nylas_thread_id,
                        'event_type' => 'opened',
                        'recipient_emails' => $event->recipient_emails,
                        'event_at' => $openTime,
                        'user_agent' => $ua,
                        'ip_address' => $primaryIp,
                        'metadata' => [
                            'object' => $metadata['object'] ?? [],
                            'grant_id' => $metadata['grant_id'] ?? null,
                            'application_id' => $metadata['application_id'] ?? null,
                            'nylas_thread_id' => $event->nylas_thread_id,
                            'resolved_event_details' => [
                                'opened_id' => $openedId,
                                'ip_addresses' => $ipArray,
                            ],
                            'note' => 'Backfilled from recents array',
                        ],
                    ]);
                }

                $backfilledCount++;
            }
        }

        if ($backfilledCount > 0) {
            if (!$dryRun) {
                Log::info('Backfilled legitimate email opens', ['count' => $backfilledCount]);
            }
            $this->line("  Backfilled {$backfilledCount} legitimate opens");
        }

        return $backfilledCount;
    }

    /**
     * Check if user agent indicates automated open
     */
    protected function isAutomated(string $userAgent): bool
    {
        $patterns = [
            'googleimageproxy',
            'ggpht\.com',
            'safe.*link',
            'security.*scanner',
            'barracuda',
            'proofpoint',
            'mimecast',
            'bot',
            'crawler',
            'spider',
            'headless',
            'phantom',
            'selenium',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match("/{$pattern}/i", $userAgent)) {
                return true;
            }
        }

        return false;
    }
}
