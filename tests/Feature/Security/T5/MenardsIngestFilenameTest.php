<?php

use App\Jobs\ImportMenardsReceiptBatch;
use Illuminate\Support\Facades\Queue;

it('sanitizes a receipt date containing path-traversal characters when building the pdf filename', function () {
    Queue::fake();
    config(['services.menards.bridge_token' => 'test-ingest-token']);

    $minimalPdf = "%PDF-1.4\n%fake";

    $response = $this->withToken('test-ingest-token')
        ->postJson('/api/menards/receipts', [
            'receipts' => [[
                'date' => '../../../../etc/passwd',
                'amount' => 12.34,
                'transactionId' => 'txn-1',
                'pdfBase64' => base64_encode($minimalPdf),
            ]],
        ]);

    $response->assertStatus(202);

    $capturedDir = null;
    Queue::assertPushed(ImportMenardsReceiptBatch::class, function ($job) use (&$capturedDir) {
        $capturedDir = $job->directory;

        return true;
    });

    expect($capturedDir)->not->toBeNull();

    try {
        $manifest = json_decode(file_get_contents($capturedDir.'/manifest.json'), true);
        $file = $manifest['receipts'][0]['file'];

        // No traversal segment and no path separator reached the filesystem call.
        expect($file)->not->toContain('/')
            ->and($file)->not->toContain('..')
            ->and(basename($file))->toBe($file);

        // The date business logic still sees the untouched original value.
        expect($manifest['receipts'][0]['date'])->toBe('../../../../etc/passwd');

        // The PDF landed inside the batch directory, not escaped out of it.
        expect(file_exists($capturedDir.'/'.$file))->toBeTrue();
    } finally {
        if ($capturedDir && is_dir($capturedDir)) {
            array_map('unlink', glob($capturedDir.'/*') ?: []);
            @rmdir($capturedDir);
        }
    }
});
