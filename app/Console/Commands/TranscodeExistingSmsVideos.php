<?php

namespace App\Console\Commands;

use App\Jobs\TranscodeSmsVideo;
use App\Models\SmsMessage;
use Illuminate\Console\Command;

class TranscodeExistingSmsVideos extends Command
{
    protected $signature = 'sms:transcode-videos {--queue : Dispatch via queue instead of running inline} {--dry-run} {--force : Overwrite existing mp4 output} {--include-mp4 : Reprocess already-transcoded mp4 files}';

    protected $description = 'Find existing SMS messages with .3gp/.mov videos and transcode to browser-friendly .mp4';

    public function handle(): int
    {
        $queued = 0;
        $extensions = ['3gp', '3gpp', 'mov', 'avi', 'mkv', 'amr'];

        if ($this->option('include-mp4')) {
            $extensions[] = 'mp4';
        }

        SmsMessage::whereNotNull('media_urls')->chunkById(200, function ($messages) use (&$queued, $extensions) {
            foreach ($messages as $msg) {
                foreach ((array) $msg->media_urls as $url) {
                    $rel = ltrim(preg_replace('#^/?storage/#', '', (string) $url) ?? '', '/');
                    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));

                    if (! in_array($ext, $extensions, true)) {
                        continue;
                    }

                    $this->line("msg {$msg->id}: {$rel}");
                    $queued++;

                    if ($this->option('dry-run')) {
                        continue;
                    }

                    if ($this->option('queue')) {
                        TranscodeSmsVideo::dispatch($msg->id, $rel, (bool) $this->option('force'));
                    } else {
                        (new TranscodeSmsVideo($msg->id, $rel, (bool) $this->option('force')))->handle();
                    }
                }
            }
        });

        $this->info("Processed {$queued} videos" . ($this->option('dry-run') ? ' (dry-run)' : ''));

        return self::SUCCESS;
    }
}
