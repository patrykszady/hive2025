<?php

namespace App\Console\Commands;

use App\Models\SmsMessage;
use Illuminate\Console\Command;

class FixDoubleEncodedSms extends Command
{
    protected $signature = 'sms:fix-encoding {--dry-run : Show what would be fixed without saving}';

    protected $description = 'Fix double-encoded UTF-8 text in existing SMS messages';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('Dry-run mode — no changes will be saved.');
        }

        $fixed = 0;
        $skipped = 0;

        // Scan inbound messages with common mojibake byte signatures.
        // Ã (C3 83) and Â (C3 82) are the telltale signs of double-encoded UTF-8.
        SmsMessage::where('direction', SmsMessage::DIRECTION_INBOUND)
            ->where(function ($q) {
                $q->where('text', 'like', '%Ã%')
                  ->orWhere('text', 'like', '%Â%');
            })
            ->chunkById(200, function ($messages) use ($dryRun, &$fixed, &$skipped) {
                foreach ($messages as $msg) {
                    $original = $msg->text;

                    if (! preg_match('/\xC3[\x82\x83]\xC2[\x80-\xBF]/', $original)) {
                        $skipped++;
                        continue;
                    }

                    $decoded = @iconv('UTF-8', 'CP1252//IGNORE', $original);

                    if ($decoded === false || ! mb_check_encoding($decoded, 'UTF-8')) {
                        $skipped++;
                        continue;
                    }

                    // Guard: decoded text should be shorter (fewer bytes) than original
                    if (strlen($decoded) >= strlen($original)) {
                        $skipped++;
                        continue;
                    }

                    if ($dryRun) {
                        $this->line("#{$msg->id}: " . mb_substr($original, 0, 60) . ' → ' . mb_substr($decoded, 0, 60));
                    } else {
                        $msg->update(['text' => $decoded]);
                    }

                    $fixed++;
                }
            });

        $label = $dryRun ? 'Would fix' : 'Fixed';
        $this->info("{$label} {$fixed} message(s). Skipped {$skipped} false-positive(s).");

        return self::SUCCESS;
    }
}
