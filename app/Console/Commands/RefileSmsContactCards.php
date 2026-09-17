<?php

namespace App\Console\Commands;

use App\Models\SmsMessage;
use App\Support\VCard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Shared contacts that arrived before 2026-09-17 were stored as .bin
 * (StoreSmsMedia knew no vCard type) and the thread showed "Image
 * unavailable". Rename every .bin that is really a vCard to .vcf so the
 * thread shows the card. Re-runnable; touches nothing it has already fixed.
 */
class RefileSmsContactCards extends Command
{
    protected $signature = 'sms:refile-contact-cards {--dry-run : Report without renaming}';

    protected $description = 'Rename MMS attachments stored as .bin that are really vCards to .vcf so threads show them as contact cards';

    public function handle(): int
    {
        $disk = Storage::disk('files');
        $dry = (bool) $this->option('dry-run');
        $fixed = 0;
        $skipped = 0;

        SmsMessage::query()
            ->whereNotNull('media_urls')
            ->where('media_urls', 'like', '%.bin%')
            ->orderBy('id')
            ->chunkById(200, function ($messages) use ($disk, $dry, &$fixed, &$skipped): void {
                foreach ($messages as $message) {
                    $urls = $message->media_urls ?? [];
                    $changed = false;
                    foreach ($urls as $i => $url) {
                        if (! is_string($url) || ! str_ends_with(strtolower($url), '.bin') || str_starts_with($url, 'http') || ! $disk->exists($url)) {
                            continue;
                        }
                        $head = (string) $disk->get($url);
                        if (! VCard::looksLikeVCard(substr($head, 0, 64))) {
                            $skipped++;

                            continue;
                        }
                        $new = substr($url, 0, -4).'.vcf';
                        $this->line(($dry ? '[dry-run] ' : '')."#{$message->id}: {$url} → {$new}");
                        if (! $dry) {
                            $disk->move($url, $new);
                        }
                        $urls[$i] = $new;
                        $changed = true;
                        $fixed++;
                    }
                    if ($changed && ! $dry) {
                        $message->update(['media_urls' => array_values($urls)]);
                    }
                }
            });

        $this->info("Done. contact cards refiled={$fixed}, other .bin files left alone={$skipped}".($dry ? ' (dry-run)' : ''));

        return self::SUCCESS;
    }
}
