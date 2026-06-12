<?php

it('defaults recording format to wav for higher-fidelity transcription input', function () {
    $config = require __DIR__ . '/../../config/call_recording.php';

    expect($config['format'])->toBe('wav')
        ->and($config['channels'])->toBe('single');
});
