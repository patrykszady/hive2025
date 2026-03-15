<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\ReceiptAccount;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DownloadAmazonReceipts extends Command
{
    protected $signature = 'amazon:download-receipts {--days=30 : Number of days to look back} {--limit=0 : Max receipts to download (0 = all)} {--dry-run : Show which receipts would be downloaded}';

    protected $description = 'Download OrderSummary PDFs from Amazon Document API for expenses missing receipt files';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $limit = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');

        $receipts = ExpenseReceipts::whereHas('expense', function ($q) use ($days) {
            $q->withoutGlobalScopes()
                ->where('vendor_id', 54)
                ->where('date', '>=', now()->subDays($days)->format('Y-m-d'))
                ->whereNull('deleted_at');
        })->whereNull('receipt_filename')->with(['expense' => function ($q) {
            $q->withoutGlobalScopes();
        }])->get();

        if ($limit > 0) {
            $receipts = $receipts->take($limit);
        }

        $this->info("Found {$receipts->count()} Amazon expenses missing receipt PDFs (last {$days} days)");

        if ($dryRun) {
            foreach ($receipts as $receipt) {
                $this->line("  Expense #{$receipt->expense->id} — Order: {$receipt->expense->invoice} — \${$receipt->expense->amount} — {$receipt->expense->date}");
            }
            return self::SUCCESS;
        }

        if ($receipts->isEmpty()) {
            return self::SUCCESS;
        }

        // Group by belongs_to_vendor_id to handle per-account auth
        $grouped = $receipts->groupBy(fn ($r) => $r->expense->belongs_to_vendor_id);

        $credentials = new \Aws\Credentials\Credentials(env('AMAZON_AWS_ACCESS_TOKEN'), env('AMAZON_AWS_SECRET_TOKEN'));

        $downloaded = 0;
        $failed = 0;

        foreach ($grouped as $vendorId => $vendorReceipts) {
            $receiptAccount = ReceiptAccount::withoutGlobalScopes()
                ->where('vendor_id', 54)
                ->where('belongs_to_vendor_id', $vendorId)
                ->whereNotNull('options->refresh_token')
                ->first();

            if (! $receiptAccount) {
                $this->warn("No receipt account found for vendor_id {$vendorId}, skipping {$vendorReceipts->count()} receipts");
                $failed += $vendorReceipts->count();
                continue;
            }

            // Refresh token if expired
            if (Carbon::now()->gt(Carbon::parse($receiptAccount->options['expires_in']))) {
                try {
                    $guzzle = new Client;
                    $tokenResponse = json_decode($guzzle->post('https://api.amazon.com/auth/O2/token', [
                        'form_params' => [
                            'client_id' => env('AMAZON_CLIENT_ID'),
                            'client_secret' => env('AMAZON_CLIENT_SECRET'),
                            'refresh_token' => $receiptAccount->options['refresh_token'],
                            'access_token' => $receiptAccount->options['access_token'],
                            'grant_type' => 'refresh_token',
                        ],
                    ])->getBody()->getContents());

                    $receiptAccount->update([
                        'options->expires_in' => Carbon::now()->addMinutes(55)->toIso8601String(),
                        'options->access_token' => $tokenResponse->access_token,
                    ]);
                    $receiptAccount->fresh();
                } catch (RequestException $e) {
                    $this->error("Failed to refresh token for vendor_id {$vendorId}: " . $e->getMessage());
                    $failed += $vendorReceipts->count();
                    continue;
                }
            }

            $client = new Client([
                'headers' => [
                    'host' => 'api.business.amazon.com',
                    'x-amz-access-token' => $receiptAccount->options['access_token'],
                    'x-amz-date' => Carbon::now()->toIso8601String(),
                    'user-agent' => 'Hive Production/0.2 (Language=PHP;Platform=Linux)',
                ],
            ]);

            $s4 = new \Aws\Signature\SignatureV4('execute-api', 'us-east-1');
            $baseUrl = 'https://na.business-api.amazon.com';

            foreach ($vendorReceipts as $receipt) {
                $expense = $receipt->expense;
                $orderId = $expense->invoice;

                $this->line("Downloading receipt for Expense #{$expense->id} — Order: {$orderId}...");

                try {
                    // Step 1: createReport
                    $createBody = json_encode([
                        'reportOptions' => [
                            'orderId' => $orderId,
                            'documentType' => 'OrderSummary',
                        ],
                        'reportType' => 'GET_AB_INVOICE_PDF',
                        'marketplaceIds' => ['ATVPDKIKX0DER'],
                    ]);

                    $createRequest = new \GuzzleHttp\Psr7\Request(
                        'POST',
                        $baseUrl . '/reports/2021-09-30/reports',
                        ['Content-Type' => 'application/json'],
                        $createBody
                    );
                    $signedRequest = $s4->signRequest($createRequest, $credentials);
                    $response = $client->send($signedRequest);
                    $createData = json_decode($response->getBody()->getContents(), true);
                    $reportId = $createData['reportId'] ?? null;

                    if (! $reportId) {
                        $this->warn("  No reportId returned for {$orderId}");
                        $failed++;
                        continue;
                    }

                    $this->line("  Report requested: {$reportId}. Polling...");

                    // Step 2: Poll getReport
                    $reportDocumentId = null;
                    $maxAttempts = 20;
                    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                        sleep(15);

                        $getReportRequest = new \GuzzleHttp\Psr7\Request(
                            'GET',
                            $baseUrl . '/reports/2021-09-30/reports/' . $reportId
                        );
                        $signedRequest = $s4->signRequest($getReportRequest, $credentials);
                        $response = $client->send($signedRequest);
                        $reportData = json_decode($response->getBody()->getContents(), true);
                        $status = $reportData['processingStatus'] ?? 'UNKNOWN';

                        $this->line("  Poll {$attempt}/{$maxAttempts}: {$status}");

                        if ($status === 'DONE') {
                            $reportDocumentId = $reportData['reportDocumentId'] ?? null;
                            break;
                        }

                        if (in_array($status, ['CANCELLED', 'FATAL'])) {
                            $this->warn("  Report failed with status: {$status}");
                            break;
                        }
                    }

                    if (! $reportDocumentId) {
                        $this->warn("  No reportDocumentId for {$orderId}");
                        $failed++;
                        continue;
                    }

                    // Step 3: Get presigned URL and download
                    $getDocRequest = new \GuzzleHttp\Psr7\Request(
                        'GET',
                        $baseUrl . '/reports/2021-09-30/documents/' . $reportDocumentId
                    );
                    $signedRequest = $s4->signRequest($getDocRequest, $credentials);
                    $response = $client->send($signedRequest);
                    $docData = json_decode($response->getBody()->getContents(), true);
                    $presignedUrl = $docData['url'] ?? null;
                    $compressionAlgorithm = $docData['compressionAlgorithm'] ?? null;

                    if (! $presignedUrl) {
                        $this->warn("  No presigned URL for {$orderId}");
                        $failed++;
                        continue;
                    }

                    // Download
                    $downloadClient = new Client();
                    $downloadResponse = $downloadClient->get($presignedUrl);
                    $fileContents = $downloadResponse->getBody()->getContents();

                    // Decompress gzip
                    if ($compressionAlgorithm === 'GZIP') {
                        $fileContents = gzdecode($fileContents);
                    }

                    // Try unzip (double-compressed: gzip then zip)
                    $tempZipPath = tempnam(sys_get_temp_dir(), 'amz_doc_');
                    file_put_contents($tempZipPath, $fileContents);

                    $zip = new \ZipArchive();
                    if ($zip->open($tempZipPath) === true) {
                        $pdfContents = $zip->getFromIndex(0);
                        $zip->close();
                        unlink($tempZipPath);

                        if ($pdfContents === false) {
                            $this->warn("  Zip archive empty for {$orderId}");
                            $failed++;
                            continue;
                        }
                    } else {
                        $pdfContents = $fileContents;
                        unlink($tempZipPath);
                    }

                    // Save
                    $filename = $expense->id . '-amazon-' . $orderId . '.pdf';
                    Storage::disk('files')->put('receipts/' . $filename, $pdfContents);

                    $receipt->receipt_filename = $filename;
                    $receipt->save();

                    $downloaded++;
                    $this->info("  Saved: {$filename}");

                    // Rate limit: Document API is 0.1 req/sec for createReport
                    sleep(2);
                } catch (\Exception $e) {
                    $this->error("  Failed: " . $e->getMessage());
                    Log::channel('amazon_orders')->error('Download receipt failed', [
                        'order_id' => $orderId,
                        'expense_id' => $expense->id,
                        'error' => $e->getMessage(),
                    ]);
                    $failed++;
                }
            }
        }

        $this->newLine();
        $this->info("Done! Downloaded: {$downloaded}, Failed: {$failed}");

        return self::SUCCESS;
    }
}
