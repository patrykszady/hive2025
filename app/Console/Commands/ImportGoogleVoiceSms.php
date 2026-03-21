<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\SmsThreadParticipant;
use App\Models\SmsThreadRead;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportGoogleVoiceSms extends Command
{
    protected $signature = 'sms:import-google-voice
                            {path : Path to the Google Voice Takeout Calls directory}
                            {--gv-number=+18474304439 : The Google Voice number (E.164)}
                            {--dry-run : Preview matches without importing}
                            {--force : Skip confirmation prompt}
                            {--clean : Delete all existing GV imports before importing}
                            {--thread= : Only import for a specific thread ID}
                            {--participant= : Only import for a specific participant phone (E.164)}
                            {--create-threads : Create new threads for unmatched phones that match a client}';

    protected $description = 'Import Google Voice Takeout text messages (1:1 and group) into existing SMS threads.';

    /** @var array<string, int> */
    private array $stats = [
        'html_files' => 0,
        'group_files' => 0,
        'messages_parsed' => 0,
        'threads_matched' => 0,
        'threads_unmatched' => 0,
        'messages_imported' => 0,
        'media_imported' => 0,
        'skipped_empty' => 0,
        'skipped_duplicate' => 0,
        'threads_created' => 0,
    ];

    public function handle(): int
    {
        $path = $this->argument('path');
        $gvNumber = $this->option('gv-number');
        $isDryRun = $this->option('dry-run');
        $filterThread = $this->option('thread');
        $filterParticipant = $this->option('participant');

        if (! is_dir($path)) {
            $this->error("Directory not found: {$path}");

            return self::FAILURE;
        }

        // Collect all Text HTML files
        $htmlFiles = collect(scandir($path))
            ->filter(fn (string $f) => str_contains($f, ' - Text - ') && str_ends_with($f, '.html'))
            ->values();

        $this->stats['html_files'] = $htmlFiles->count();
        $this->info("Found {$htmlFiles->count()} text conversation files.");

        // Group HTML files by participant phone number.
        // Filenames can be phone numbers (+12034962791 - Text - ...) or contact
        // names (Karen - Text - ...). For named contacts we extract the real
        // phone from inside the HTML so thread matching works correctly.
        $rawGroups = $htmlFiles->groupBy(fn (string $f) => Str::before($f, ' - Text - '));

        $grouped = collect();
        $nameResolved = 0;
        foreach ($rawGroups as $prefix => $files) {
            if (str_starts_with($prefix, '+')) {
                $phone = $this->normalizeE164($prefix);
            } else {
                $phone = $this->extractPhoneFromHtml($path . '/' . $files->first(), $gvNumber);
                if ($phone) {
                    $nameResolved++;
                }
            }

            if (! $phone) {
                continue;
            }

            $grouped[$phone] = isset($grouped[$phone])
                ? $grouped[$phone]->merge($files)
                : $files;
        }

        $this->info("Unique phone numbers: {$grouped->count()}" . ($nameResolved ? " ({$nameResolved} resolved from contact names)" : ''));

        // Pre-load all threads with participants for matching
        $threads = SmsGroupThread::all();

        // Build a phone → thread lookup
        $phoneToThread = [];
        foreach ($threads as $thread) {
            foreach ($thread->participants as $phone) {
                $normalized = $this->normalizeE164($phone);
                // If multiple threads share a participant, keep the one with most recent activity
                if (! isset($phoneToThread[$normalized]) || $thread->last_activity_at > $phoneToThread[$normalized]->last_activity_at) {
                    $phoneToThread[$normalized] = $thread;
                }
            }
        }

        $matchedPhones = [];
        $unmatchedPhones = [];
        $createThreadPhones = [];

        foreach ($grouped as $phone => $files) {
            $normalized = $this->normalizeE164($phone);

            if ($filterParticipant && $normalized !== $this->normalizeE164($filterParticipant)) {
                continue;
            }

            $thread = $phoneToThread[$normalized] ?? null;

            if ($filterThread && (! $thread || $thread->id !== (int) $filterThread)) {
                continue;
            }

            if ($thread) {
                $matchedPhones[$normalized] = [
                    'thread' => $thread,
                    'files' => $files,
                ];
                $this->stats['threads_matched']++;
            } else {
                $unmatchedPhones[$normalized] = $files;
                $this->stats['threads_unmatched']++;
            }
        }

        // === Phase 1b: Create new threads for unmatched phones that match a client ===
        if ($this->option('create-threads') && ! empty($unmatchedPhones)) {
            $this->newLine();
            $this->info('Resolving unmatched phones to clients...');

            $stillUnmatched = [];
            foreach ($unmatchedPhones as $phone => $files) {
                $clientId = $this->resolveClientIdByPhone($phone);
                if ($clientId) {
                    $createThreadPhones[$phone] = [
                        'client_id' => $clientId,
                        'files' => $files,
                    ];
                } else {
                    $stillUnmatched[$phone] = $files->count();
                }
            }

            $unmatchedPhones = $stillUnmatched;
            $this->stats['threads_unmatched'] = count($unmatchedPhones);

            if (! empty($createThreadPhones)) {
                $this->info('Found ' . count($createThreadPhones) . ' unmatched phones that match a client.');
            }
        } else {
            // Convert files collections to counts for display
            $unmatchedPhones = collect($unmatchedPhones)->map(fn ($files) => $files->count())->all();
        }

        // === Phase 2: Group Conversation files ===
        $groupFiles = collect(scandir($path))
            ->filter(fn (string $f) => str_starts_with($f, 'Group Conversation - ') && str_ends_with($f, '.html'))
            ->values();

        $this->stats['group_files'] = $groupFiles->count();
        $this->newLine();
        $this->info("Found {$groupFiles->count()} group conversation files.");

        $groupsByParticipants = [];
        foreach ($groupFiles as $filename) {
            $participants = $this->parseGroupParticipants($path . '/' . $filename, $gvNumber);
            if (empty($participants)) {
                continue;
            }
            sort($participants);
            $key = implode('|', $participants);
            $groupsByParticipants[$key] ??= ['participants' => $participants, 'files' => collect()];
            $groupsByParticipants[$key]['files']->push($filename);
        }

        $this->info('Unique group participant sets: ' . count($groupsByParticipants));

        $matchedGroups = [];
        $unmatchedGroups = [];

        foreach ($groupsByParticipants as $key => $data) {
            if ($filterParticipant) {
                $normalizedFilter = $this->normalizeE164($filterParticipant);
                if (! in_array($normalizedFilter, $data['participants'])) {
                    continue;
                }
            }

            $thread = $this->matchGroupThread($data['participants'], $threads);

            if ($filterThread && (! $thread || $thread->id !== (int) $filterThread)) {
                continue;
            }

            if ($thread) {
                $matchedGroups[$key] = [
                    'thread' => $thread,
                    'files' => $data['files'],
                    'participants' => $data['participants'],
                ];
                $this->stats['threads_matched']++;
            } else {
                $unmatchedGroups[$key] = $data['files']->count();
                $this->stats['threads_unmatched']++;
            }
        }

        // === Match Summary ===
        $this->newLine();
        $this->info('Matched ' . count($matchedPhones) . ' 1:1 phones to threads, ' . count($matchedGroups) . ' group conversations to threads.');

        if ($createThreadPhones) {
            $this->info('Will create new threads (' . count($createThreadPhones) . '):');
            foreach ($createThreadPhones as $phone => $data) {
                $client = Client::find($data['client_id']);
                $this->line("  {$phone} → Client #{$data['client_id']} ({$client?->business_name}) ({$data['files']->count()} files)");
            }
        }

        if ($unmatchedPhones) {
            $this->warn('Unmatched 1:1 phones (' . count($unmatchedPhones) . '):');
            foreach ($unmatchedPhones as $phone => $fileCount) {
                $this->line("  {$phone} ({$fileCount} files)");
            }
        }

        if ($unmatchedGroups) {
            $this->warn('Unmatched groups (' . count($unmatchedGroups) . '):');
            foreach ($unmatchedGroups as $key => $fileCount) {
                $this->line("  [{$key}] ({$fileCount} files)");
            }
        }

        if ($isDryRun) {
            $this->newLine();
            $this->info('[DRY RUN] Matched 1:1 threads:');
            foreach ($matchedPhones as $phone => $data) {
                $msgCount = 0;
                foreach ($data['files'] as $file) {
                    $msgCount += $this->countMessagesInHtml($path . '/' . $file);
                }
                $this->line("  Thread #{$data['thread']->id} ← {$phone} ({$data['files']->count()} files, ~{$msgCount} messages)");
            }
            $this->newLine();
            $this->info('[DRY RUN] Matched group conversations:');
            foreach ($matchedGroups as $key => $data) {
                $msgCount = 0;
                foreach ($data['files'] as $file) {
                    $msgCount += $this->countMessagesInHtml($path . '/' . $file);
                }
                $phones = implode(', ', $data['participants']);
                $this->line("  Thread #{$data['thread']->id} ← [{$phones}] ({$data['files']->count()} files, ~{$msgCount} messages)");
            }
            if ($createThreadPhones) {
                $this->newLine();
                $this->info('[DRY RUN] New threads to create:');
                foreach ($createThreadPhones as $phone => $data) {
                    $client = Client::find($data['client_id']);
                    $msgCount = 0;
                    foreach ($data['files'] as $file) {
                        $msgCount += $this->countMessagesInHtml($path . '/' . $file);
                    }
                    $this->line("  NEW → {$phone} → Client #{$data['client_id']} ({$client?->business_name}) ({$data['files']->count()} files, ~{$msgCount} messages)");
                }
            }
            $this->newLine();
            $this->info('Run without --dry-run to import.');

            return self::SUCCESS;
        }

        $totalMatched = count($matchedPhones) + count($matchedGroups) + count($createThreadPhones);

        if ($totalMatched === 0) {
            $this->warn('No matched threads to import into.');

            return self::SUCCESS;
        }

        $confirmMsg = 'Import messages into ' . $totalMatched . ' threads (' . count($matchedPhones) . ' 1:1, ' . count($matchedGroups) . ' groups';
        if ($createThreadPhones) {
            $confirmMsg .= ', ' . count($createThreadPhones) . ' new threads';
        }
        $confirmMsg .= ')?';

        if (! $this->option('force') && ! $this->confirm($confirmMsg)) {
            return self::SUCCESS;
        }

        if ($this->option('clean')) {
            $deleted = SmsMessage::where('provider', 'google-voice-import')->delete();
            $this->info("Cleaned {$deleted} existing GV import messages.");
        }

        // Create new threads for client-matched unmatched phones
        if (! empty($createThreadPhones)) {
            $this->info('Creating ' . count($createThreadPhones) . ' new threads...');

            foreach ($createThreadPhones as $phone => $data) {
                $thread = SmsGroupThread::create([
                    'from_number' => $gvNumber,
                    'participants' => [$phone],
                    'client_id' => $data['client_id'],
                    'last_activity_at' => now(),
                ]);

                SmsThreadParticipant::create([
                    'thread_id' => $thread->id,
                    'phone_number' => $phone,
                ]);

                $matchedPhones[$phone] = [
                    'thread' => $thread,
                    'files' => $data['files'],
                ];
                $this->stats['threads_created']++;
            }
        }

        // Import 1:1
        if (! empty($matchedPhones)) {
            $this->info('Importing 1:1 messages...');
            $bar = $this->output->createProgressBar(count($matchedPhones));
            $bar->start();

            foreach ($matchedPhones as $phone => $data) {
                $this->importForThread($path, $data['thread'], $data['files'], $gvNumber, $phone);
                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);
        }

        // Import groups
        if (! empty($matchedGroups)) {
            $this->info('Importing group messages...');
            $bar = $this->output->createProgressBar(count($matchedGroups));
            $bar->start();

            foreach ($matchedGroups as $key => $data) {
                $this->importForThread($path, $data['thread'], $data['files'], $gvNumber, null, $data['participants']);
                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);
        }

        // Summary
        $this->table(
            ['Metric', 'Count'],
            collect($this->stats)->map(fn ($v, $k) => [str_replace('_', ' ', ucfirst($k)), number_format($v)])->values()->toArray()
        );

        return self::SUCCESS;
    }

    /**
     * Import all HTML files for a single thread.
     *
     * @param  \Illuminate\Support\Collection<int, string>  $files
     */
    private function importForThread(string $basePath, SmsGroupThread $thread, $files, string $gvNumber, ?string $participantPhone = null, ?array $groupParticipants = null): void
    {
        $latestImported = null;

        foreach ($files as $filename) {
            $filePath = $basePath . '/' . $filename;
            $messages = $this->parseHtml($filePath, $gvNumber);

            foreach ($messages as $msg) {
                if (empty($msg['text']) && empty($msg['images'])) {
                    $this->stats['skipped_empty']++;

                    continue;
                }

                $isOutbound = $msg['from'] === $this->normalizeE164($gvNumber);
                $timestamp = $msg['timestamp'];

                // Store any media
                $mediaUrls = [];
                foreach ($msg['images'] as $imgSrc) {
                    $imgPath = $this->resolveMediaPath($basePath, $imgSrc);

                    if ($imgPath) {
                        $ext = strtolower(pathinfo($imgPath, PATHINFO_EXTENSION)) ?: 'jpg';
                        $storageName = 'sms-media/gv-import/' . Str::uuid() . '.' . $ext;

                        Storage::disk('local')->put('public/' . $storageName, File::get($imgPath));

                        $mediaUrls[] = '/storage/' . $storageName;
                        $this->stats['media_imported']++;
                    }
                }

                $cleanText = trim($msg['text']);
                if (in_array($cleanText, ['MMS Received', 'MMS Sent'], true) && ! empty($mediaUrls)) {
                    $cleanText = '';
                }

                $providerMessageId = 'gv-' . md5($filename . $timestamp->toIso8601String() . $msg['text']);

                if (SmsMessage::where('provider_message_id', $providerMessageId)->exists()) {
                    $this->stats['skipped_duplicate']++;

                    continue;
                }

                $message = new SmsMessage;
                $message->thread_id = $thread->id;
                $message->provider = 'google-voice-import';
                $message->provider_message_id = $providerMessageId;
                $message->direction = $isOutbound ? SmsMessage::DIRECTION_OUTBOUND : SmsMessage::DIRECTION_INBOUND;
                if ($groupParticipants) {
                    $message->from_number = $isOutbound ? $gvNumber : $msg['from'];
                    $message->to_numbers = $isOutbound
                        ? $groupParticipants
                        : array_values(array_filter($groupParticipants, fn ($p) => $p !== $msg['from']));
                } else {
                    $message->from_number = $isOutbound ? $gvNumber : $participantPhone;
                    $message->to_numbers = $isOutbound ? [$participantPhone] : [$gvNumber];
                }
                $message->text = $cleanText ?: null;
                $message->media_urls = $mediaUrls ?: null;
                $message->raw_payload = null;
                $message->status = 'delivered';
                $message->sent_by_user_id = null;
                $message->created_at = $timestamp;
                $message->updated_at = $timestamp;
                $message->timestamps = false;
                $message->save();

                $this->stats['messages_imported']++;

                if (! $latestImported || $timestamp->gt($latestImported)) {
                    $latestImported = $timestamp;
                }
            }
        }

        // Mark all GV imports as read so they don't trigger unread dots
        $maxId = SmsMessage::where('thread_id', $thread->id)->max('id');

        if ($maxId) {
            $userIds = \App\Models\User::pluck('id');
            foreach ($userIds as $userId) {
                SmsThreadRead::updateOrCreate(
                    ['thread_id' => $thread->id, 'user_id' => $userId],
                    ['last_read_message_id' => $maxId],
                );
            }
        }

        // Update thread last_activity_at to the latest imported message time
        if ($latestImported && (! $thread->last_activity_at || $latestImported->gt($thread->last_activity_at))) {
            $thread->update(['last_activity_at' => $latestImported]);
        }
    }

    /**
     * Parse a Google Voice Takeout HTML file into an array of message objects.
     *
     * @return array<int, array{from: string, text: string, timestamp: \Carbon\Carbon, images: array<string>}>
     */
    private function parseHtml(string $filePath, string $gvNumber): array
    {
        $html = file_get_contents($filePath);
        if (! $html) {
            return [];
        }

        $messages = [];

        // Use DOMDocument to parse the HTML
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        // Each message is a <div class="message">
        $messageNodes = $xpath->query('//div[contains(@class, "message")]');

        foreach ($messageNodes as $node) {
            // Timestamp from <abbr class="dt" title="...">
            $abbrNodes = $xpath->query('.//abbr[@class="dt"]', $node);
            $timestamp = null;
            if ($abbrNodes->length > 0) {
                $isoDate = $abbrNodes->item(0)->getAttribute('title');
                $timestamp = Carbon::parse($isoDate)->utc();
            }

            // Sender phone from <a class="tel" href="tel:+1xxx">
            $telNodes = $xpath->query('.//cite[contains(@class, "sender")]//a[@class="tel"]', $node);
            $fromPhone = null;
            if ($telNodes->length > 0) {
                $href = $telNodes->item(0)->getAttribute('href');
                $fromPhone = str_replace('tel:', '', $href);
            }

            // Message text from <q>
            $qNodes = $xpath->query('.//q', $node);
            $text = '';
            if ($qNodes->length > 0) {
                $text = trim($qNodes->item(0)->textContent);
            }

            // Images from <img> tags
            $imgNodes = $xpath->query('.//img', $node);
            $images = [];
            foreach ($imgNodes as $img) {
                $src = $img->getAttribute('src');
                if ($src) {
                    $images[] = $src;
                }
            }

            if ($timestamp && $fromPhone) {
                $messages[] = [
                    'from' => $this->normalizeE164($fromPhone),
                    'text' => $text,
                    'timestamp' => $timestamp,
                    'images' => $images,
                ];
                $this->stats['messages_parsed']++;
            }
        }

        return $messages;
    }

    /**
     * Count messages in an HTML file (for dry-run summary).
     */
    private function countMessagesInHtml(string $filePath): int
    {
        $html = file_get_contents($filePath);

        return $html ? substr_count($html, 'class="message"') : 0;
    }

    /**
     * Parse participant phone numbers from a group conversation HTML file.
     *
     * @return array<string> Normalized E.164 phone numbers (excluding GV number)
     */
    private function parseGroupParticipants(string $filePath, string $gvNumber): array
    {
        $html = file_get_contents($filePath);
        if (! $html) {
            return [];
        }

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $participantNodes = $xpath->query('//div[contains(@class, "participants")]//a[@class="tel"]');

        $participants = [];
        $normalizedGv = $this->normalizeE164($gvNumber);

        foreach ($participantNodes as $node) {
            $href = $node->getAttribute('href');
            $phone = $this->normalizeE164(str_replace('tel:', '', $href));
            if ($phone !== '+' && $phone !== $normalizedGv && ! in_array($phone, $participants)) {
                $participants[] = $phone;
            }
        }

        return $participants;
    }

    /**
     * Find a thread whose participants match the given set of phone numbers.
     *
     * @param  array<string>  $participants  Normalized E.164 phone numbers
     * @param  \Illuminate\Database\Eloquent\Collection<int, SmsGroupThread>  $threads
     */
    private function matchGroupThread(array $participants, $threads): ?SmsGroupThread
    {
        $participantSet = collect($participants)->map(fn ($p) => $this->normalizeE164($p))->sort()->values()->all();

        $bestMatch = null;
        $bestScore = -1;

        foreach ($threads as $thread) {
            $threadPhones = collect($thread->participants)->map(fn ($p) => $this->normalizeE164($p))->sort()->values()->all();

            // All group participants must exist in thread
            if (count(array_diff($participantSet, $threadPhones)) > 0) {
                continue;
            }

            // Prefer exact participant count match
            $score = count($participantSet) === count($threadPhones) ? 2 : 1;

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $thread;
            }
        }

        return $bestMatch;
    }

    /**
     * Extract the participant phone number from a text conversation HTML file.
     * Used when the filename contains a contact name instead of a phone number.
     */
    private function extractPhoneFromHtml(string $filePath, string $gvNumber): ?string
    {
        $html = file_get_contents($filePath);
        if (! $html) {
            return null;
        }

        preg_match_all('/href="tel:(\+?\d+)"/', $html, $matches);
        $normalizedGv = $this->normalizeE164($gvNumber);

        foreach ($matches[1] as $phone) {
            $normalized = $this->normalizeE164($phone);
            if ($normalized !== $normalizedGv) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * Resolve a media file path, trying common extensions when the HTML src lacks one.
     */
    private function resolveMediaPath(string $basePath, string $src): ?string
    {
        $fullPath = $basePath . '/' . $src;
        if (file_exists($fullPath)) {
            return $fullPath;
        }

        foreach (['jpg', 'jpeg', 'png', 'gif', 'mp4', '3gp', 'amr', 'vcf'] as $ext) {
            $try = $fullPath . '.' . $ext;
            if (file_exists($try)) {
                return $try;
            }
        }

        return null;
    }

    /**
     * Resolve a client ID from an E.164 phone number.
     * Checks Client.home_phone and User.cell_phone → client pivot.
     */
    private function resolveClientIdByPhone(string $e164Phone): ?int
    {
        $digits = preg_replace('/[^0-9]/', '', $e164Phone);

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        $client = Client::whereRaw("REPLACE(home_phone, '-', '') = ?", [$digits])->first();
        if ($client) {
            return $client->id;
        }

        $user = User::where('cell_phone', $digits)->first();
        if ($user) {
            $client = $user->clients()->first();
            if ($client) {
                return $client->id;
            }
        }

        return null;
    }

    /**
     * Normalize a phone number to E.164 format.
     */
    private function normalizeE164(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);

        if (strlen($digits) === 10) {
            return '+1' . $digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+' . $digits;
        }

        return '+' . $digits;
    }
}
