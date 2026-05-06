<?php

use App\Jobs\SendGroupMms;
use Illuminate\Support\Facades\Storage;

it('builds a signed public media link when the attachment exists on the public disk', function (): void {
    Storage::fake('files');
    Storage::fake('public');

    Storage::disk('public')->put('sms-attachments/demo-video.mp4', 'video-bytes');

    $job = new class(1) extends SendGroupMms {
        public function buildLink(string $url): ?string
        {
            return $this->buildPublicSignedLink($url);
        }
    };

    $link = $job->buildLink('/storage/sms-attachments/demo-video.mp4');

    expect($link)->not->toBeNull();
    expect($link)->toContain('/m/sms/sms-attachments/demo-video.mp4');
});
