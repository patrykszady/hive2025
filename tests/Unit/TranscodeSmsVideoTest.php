<?php

use App\Jobs\TranscodeSmsVideo;

it('picks the smallest-area crop to strip baked-in letterbox bars', function (): void {
    $output = implode("\n", [
        // Some frames see partial bars (wider crop)
        '[Parsed_cropdetect_0 @ 0x123] crop=720:1280:0:0',
        '[Parsed_cropdetect_0 @ 0x123] crop=720:1164:0:58',
        // Other frames see the full bars (narrower crop = the real content area)
        '[Parsed_cropdetect_0 @ 0x123] crop=400:1280:160:0',
        '[Parsed_cropdetect_0 @ 0x123] crop=400:1280:160:0',
    ]);

    expect(TranscodeSmsVideo::extractCropFromOutput($output))->toBe('crop=400:1280:160:0');
});

it('returns null when ffmpeg output contains no valid crop', function (): void {
    expect(TranscodeSmsVideo::extractCropFromOutput('no crop lines here'))->toBeNull();
    expect(TranscodeSmsVideo::extractCropFromOutput('crop=10:10:0:0'))->toBeNull();
});
