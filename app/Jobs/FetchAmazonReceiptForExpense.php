<?php

namespace App\Jobs;

use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\ReceiptAccount;
use App\Support\ApiErrorFormatter;
use App\Support\AmazonOAuthPayload;
use App\Support\AmazonTokenRefreshRecovery;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FetchAmazonReceiptForExpense implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 2;

    public array $backoff = [10, 30];

    public function __construct(public Expense $expense) {}

    public function handle(): void
    {
        $expense = $this->expense;

        // Only for Amazon expenses (vendor_id=54) that have no receipts
        if ($expense->vendor_id !== 54 || $expense->receipts()->exists()) {
            return;
        }

        if (empty($expense->invoice)) {
            Log::channel('amazon_orders')->info('Skipping Amazon receipt fetch: no invoice/orderId', [
                'expense_id' => $expense->id,
            ]);
            return;
        }

        $receiptAccount = ReceiptAccount::withoutGlobalScopes()
            ->where('vendor_id', 54)
            ->where('belongs_to_vendor_id', $expense->belongs_to_vendor_id)
            ->whereNotNull('options->refresh_token')
            ->first();

        if (! $receiptAccount) {
            Log::channel('amazon_orders')->warning('No Amazon receipt account found for vendor', [
                'expense_id' => $expense->id,
                'belongs_to_vendor_id' => $expense->belongs_to_vendor_id,
            ]);
            return;
        }

        // Refresh OAuth token if expired
        if (Carbon::now()->gt(Carbon::parse($receiptAccount->options['expires_in']))) {
            try {
                $guzzle = new Client;
                $tokens = json_decode($guzzle->post('https://api.amazon.com/auth/O2/token', [
                    'form_params' => AmazonOAuthPayload::refreshToken((string) $receiptAccount->options['refresh_token']),
                ])->getBody()->getContents());
            } catch (RequestException $e) {
                Log::channel('amazon_orders')->error('Amazon token refresh failed during restore fetch', ApiErrorFormatter::format($e, [
                    'receipt_account_id' => $receiptAccount->id,
                    'expense_id' => $expense->id,
                ]));

                AmazonTokenRefreshRecovery::maybeRotateOnInvalidRequest($e, [
                    'receipt_account_id' => $receiptAccount->id,
                    'expense_id' => $expense->id,
                    'source' => 'FetchAmazonReceiptForExpense',
                ]);
                return;
            }

            $receiptAccount->update([
                'options->expires_in' => Carbon::now()->addMinutes(55)->toIso8601String(),
                'options->access_token' => $tokens->access_token,
            ]);

            $receiptAccount->refresh();
        }

        $credentials = new \Aws\Credentials\Credentials(env('AMAZON_AWS_ACCESS_TOKEN'), env('AMAZON_AWS_SECRET_TOKEN'));
        $s4 = new \Aws\Signature\SignatureV4('execute-api', 'us-east-1');

        $client = new \GuzzleHttp\Client([
            'headers' => [
                'host' => 'api.business.amazon.com',
                'x-amz-access-token' => $receiptAccount->options['access_token'],
                'x-amz-date' => Carbon::now()->toIso8601String(),
                'user-agent' => 'Hive Production/0.2 (Language=PHP;Platform=Linux)',
            ],
        ]);

        $baseUrl = 'https://na.business-api.amazon.com';

        // Fetch order directly by orderId — works regardless of age (no 29-day limit)
        $orderUrl = $baseUrl . '/reports/2021-01-08/orders/' . urlencode($expense->invoice)
            . '?includeCharges=true&includeLineItems=true&includeShipments=true';
        $request = new \GuzzleHttp\Psr7\Request('GET', $orderUrl);
        $signedRequest = $s4->signRequest($request, $credentials);

        try {
            $response = $client->send($signedRequest);
            $responseData = json_decode($response->getBody()->getContents(), true);
            $orders = $responseData['orders'] ?? [];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            Log::channel('amazon_orders')->error('Amazon API error during restore fetch', [
                'expense_id' => $expense->id,
                'status_code' => $e->getCode(),
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $order = collect($orders)->firstWhere('orderId', $expense->invoice);

        if (! $order) {
            Log::channel('amazon_orders')->info('No matching Amazon order found for restored expense', [
                'expense_id' => $expense->id,
                'invoice' => $expense->invoice,
                'date' => $expense->date,
            ]);
            return;
        }

        $orderDate = Carbon::parse($order['orderDate'])->setTimezone('America/Chicago')->format('Y-m-d');

        $receiptItems = [];
        foreach ($order['lineItems'] as $idx => $item) {
            $receiptItems[$idx] = [
                'Price' => $item['purchasedPricePerUnit']['amount'],
                'Quantity' => $item['itemQuantity'],
                'TotalPrice' => $item['itemSubTotal']['amount'] ?? 0.00,
                'Description' => $item['title'],
                'VendorCode' => $item['asin'],
            ];
        }

        $charges = [];
        foreach ($order['charges'] as $key => $charge) {
            $charges[$key] = [
                'transactionDate' => $charge['transactionDate'],
                'transactionId' => $charge['transactionId'],
                'amount' => $charge['amount']['amount'],
                'paymentInstrumentLast4Digits' => $charge['paymentInstrumentLast4Digits'],
            ];
        }

        $receiptData = [
            'items' => $receiptItems,
            'total' => $order['orderNetTotal']['amount'],
            'subtotal' => $order['orderSubTotal']['amount'],
            'total_tax' => $order['orderTax']['amount'],
            'invoice_number' => $order['orderId'],
            'purchase_order' => $order['purchaseOrderNumber'],
            'transaction_date' => $orderDate,
            'charges' => $charges,
        ];

        $newReceipt = ExpenseReceipts::create([
            'expense_id' => $expense->id,
            'receipt_html' => null,
            'receipt_items' => $receiptData,
            'receipt_filename' => null,
        ]);

        $orderStatus = strtoupper($order['orderStatus'] ?? '');
        if (! in_array($orderStatus, ['PENDING', 'CANCELLED'])) {
            $this->downloadOrderDocument($client, $s4, $credentials, $order['orderId'], $expense, $newReceipt);
        }

        Log::channel('amazon_orders')->info('Receipt created via restore fetch', [
            'expense_id' => $expense->id,
            'order_id' => $order['orderId'],
            'amount' => $order['orderNetTotal']['amount'],
            'line_items' => count($receiptItems),
        ]);
    }

    /**
     * Download OrderSummary PDF via Amazon Document API.
     */
    private function downloadOrderDocument(
        \GuzzleHttp\Client $client,
        \Aws\Signature\SignatureV4 $s4,
        \Aws\Credentials\Credentials $credentials,
        string $orderId,
        Expense $expense,
        ExpenseReceipts $expenseReceipt
    ): void {
        $baseUrl = 'https://na.business-api.amazon.com';

        try {
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
                return;
            }

            $reportDocumentId = null;
            $maxAttempts = 20;
            for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
                sleep(15);

                $getReportRequest = new \GuzzleHttp\Psr7\Request(
                    'GET',
                    $baseUrl . '/reports/2021-09-30/reports/' . $reportId
                );
                $signedRequest = $s4->signRequest($getReportRequest, $credentials);
                $response = $client->send($signedRequest);
                $reportData = json_decode($response->getBody()->getContents(), true);
                $status = $reportData['processingStatus'] ?? 'UNKNOWN';

                if ($status === 'DONE') {
                    $reportDocumentId = $reportData['reportDocumentId'] ?? null;
                    break;
                }

                if (in_array($status, ['CANCELLED', 'FATAL'])) {
                    return;
                }
            }

            if (! $reportDocumentId) {
                return;
            }

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
                return;
            }

            $downloadClient = new \GuzzleHttp\Client();
            $downloadResponse = $downloadClient->get($presignedUrl);
            $fileContents = $downloadResponse->getBody()->getContents();

            if ($compressionAlgorithm === 'GZIP') {
                $fileContents = gzdecode($fileContents);
            }

            $tempZipPath = tempnam(sys_get_temp_dir(), 'amz_doc_');
            file_put_contents($tempZipPath, $fileContents);

            $zip = new \ZipArchive();
            if ($zip->open($tempZipPath) === true) {
                $pdfContents = $zip->getFromIndex(0);
                $zip->close();
                unlink($tempZipPath);

                if ($pdfContents === false) {
                    return;
                }
            } else {
                $pdfContents = $fileContents;
                unlink($tempZipPath);
            }

            $filename = $expense->id . '-amazon-' . $orderId . '.pdf';
            Storage::disk('files')->put('receipts/' . $filename, $pdfContents);

            $expenseReceipt->receipt_filename = $filename;
            $expenseReceipt->save();

            Log::channel('amazon_orders')->info('PDF saved via restore fetch', [
                'order_id' => $orderId,
                'expense_id' => $expense->id,
                'filename' => $filename,
            ]);
        } catch (\Exception $e) {
            Log::channel('amazon_orders')->error('Document download failed during restore fetch', [
                'order_id' => $orderId,
                'expense_id' => $expense->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
