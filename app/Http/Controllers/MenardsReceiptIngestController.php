<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Receives Menards receipts fetched by the browser extension and hands them to
 * the existing importer.
 *
 * The extension runs inside a signed-in Chromium and calls Menards' own
 * receipt-lookup JSON endpoints, so what arrives here is already decoded
 * transaction data plus a base64 PDF per receipt. This endpoint's whole job is
 * to lay that out on disk in the shape `menards:scrape-receipts --skip-scrape`
 * already reads — a manifest.json plus PDF files — and then run it. Nothing
 * downstream had to change: OCR, expense matching and de-duplication are the
 * same code paths the browser-driven scraper fed.
 *
 * Called by an MV3 service worker, which carries no session and no CSRF token:
 * auth is a bearer token compared in constant time.
 */
class MenardsReceiptIngestController extends Controller
{
    /** A receipt PDF is ~10 KB; a year of history across nine cards is still small. */
    protected const MAX_BYTES = 64 * 1024 * 1024;

    /** Refuse absurd batches before decoding any base64. */
    protected const MAX_RECEIPTS = 500;

    public function __invoke(Request $request): JsonResponse
    {
        // Via config(), never env() — `php artisan config:cache` runs on deploy.
        $expected = (string) config('services.menards.bridge_token');

        if ($expected === '') {
            return response()->json(['ok' => false, 'error' => 'Menards ingest is not configured on this server.'], 503);
        }

        if (! hash_equals($expected, (string) $request->bearerToken())) {
            Log::channel('menards')->warning('Menards ingest: bad token', ['ip' => $request->ip()]);

            return response()->json(['ok' => false, 'error' => 'Unauthorized.'], 401);
        }

        if (strlen((string) $request->getContent()) > self::MAX_BYTES) {
            return response()->json(['ok' => false, 'error' => 'Payload too large.'], 413);
        }

        $data = $request->validate([
            'since' => ['nullable', 'string', 'max:32'],
            'scrapedAt' => ['nullable', 'string', 'max:64'],
            'receipts' => ['required', 'array', 'min:1', 'max:' . self::MAX_RECEIPTS],
            'receipts.*.date' => ['required', 'string', 'max:32'],
            'receipts.*.amount' => ['required', 'numeric'],
            'receipts.*.store' => ['nullable', 'string', 'max:120'],
            'receipts.*.card' => ['nullable', 'string', 'max:120'],
            'receipts.*.transactionId' => ['required', 'string', 'max:120'],
            'receipts.*.pdfBase64' => ['required', 'string'],
        ]);

        // A fresh directory per batch. The scheduled scraper overwrites
        // _temp_menards/manifest.json, so writing there would race it.
        $dir = storage_path('files/_menards_ingest/' . now()->format('Ymd_His') . '_' . Str::random(6));

        if (! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return response()->json(['ok' => false, 'error' => 'Could not create the import directory.'], 500);
        }

        $manifestReceipts = [];
        $rejected = [];

        foreach ($data['receipts'] as $row) {
            $pdf = base64_decode($row['pdfBase64'], true);

            // Only real PDFs reach the importer — a truncated or error payload
            // would otherwise be stored and OCR'd as if it were a receipt.
            if ($pdf === false || ! str_starts_with($pdf, '%PDF')) {
                $rejected[] = $row['transactionId'];

                continue;
            }

            // Transaction ids contain characters that are not filename-safe
            // ("3254-12-7316-2026-08-21 11:23:01").
            $file = 'menards-' . $row['date'] . '-' . Str::slug($row['transactionId']) . '.pdf';
            file_put_contents($dir . '/' . $file, $pdf);

            $manifestReceipts[] = [
                'date' => $row['date'],
                'amount' => (string) $row['amount'],
                'store' => $row['store'] ?? '',
                'card' => $row['card'] ?? '',
                'file' => $file,
                'transactionId' => $row['transactionId'],
            ];
        }

        if ($manifestReceipts === []) {
            return response()->json([
                'ok' => false,
                'error' => 'No decodable PDFs in the payload.',
                'rejected' => count($rejected),
            ], 422);
        }

        file_put_contents($dir . '/manifest.json', json_encode([
            'scrapedAt' => $data['scrapedAt'] ?? now()->toIso8601String(),
            'since' => $data['since'] ?? null,
            'source' => 'browser-extension',
            'totalReceipts' => count($manifestReceipts),
            'receipts' => $manifestReceipts,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        Log::channel('menards')->info('Menards ingest: batch received', [
            'dir' => $dir,
            'receipts' => count($manifestReceipts),
            'rejected' => count($rejected),
        ]);

        // Import synchronously so the extension learns the outcome. Deliberately
        // no --force: already-imported receipts hit the exists-branch and cost
        // nothing, which is what makes a wide lookback window free.
        $exit = Artisan::call('menards:scrape-receipts', [
            '--skip-scrape' => true,
            '--match-expenses' => true,
            '--output-dir' => $dir,
        ]);

        $output = Artisan::output();

        Log::channel('menards')->info('Menards ingest: import finished', [
            'exit_code' => $exit,
            'tail' => substr($output, -800),
        ]);

        return response()->json([
            'ok' => $exit === 0,
            'received' => count($manifestReceipts),
            'rejected' => count($rejected),
            'imported' => count($manifestReceipts),
            'exit_code' => $exit,
            'dir' => basename($dir),
        ], $exit === 0 ? 200 : 500);
    }
}
