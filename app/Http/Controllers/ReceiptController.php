<?php

namespace App\Http\Controllers;

use App\Jobs\TransactionVendorBulkMatchJob;
use App\Models\CompanyEmail;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\Project;
use App\Models\Receipt;
use App\Models\ReceiptAccount;
use App\Models\Transaction;
use App\Models\TransactionBulkMatch;
use App\Models\Distribution;
use App\Models\Vendor;
use App\Services\ContentUnderstandingService;
use App\Services\NylasService;
// use App\Http\Requests\GetGiftCardRequest;

use Carbon\Carbon;
use Carbon\CarbonPeriod;

use File;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Intervention\Image\Facades\Image;
use Nesk\Puphpeteer\Puppeteer;
use setasign\Fpdi\Fpdi;
use Symfony\Component\DomCrawler\Crawler;
use App\Support\ApiErrorFormatter;

class ReceiptController extends Controller
{
    private NylasService $nylasService;

    public function __construct(NylasService $nylasService)
    {
        $this->nylasService = $nylasService;
    }

    /**
     * Return latest Home Depot gift card redeem URL + screenshot path (JSON response).
     */
    // public function getHomeDepotMessages(GetGiftCardRequest $request)
    // {
    //     $companyEmailId = (int) $request->validated()['company_email_id'];
    //     $result = $this->giftCardService->captureLatest($companyEmailId);

    //     if (!$result['success']) {
    //         return response()->json($result, 422);
    //     }

    //     return response()->json($result);
    // }

    public function amazon_login()
    {
        $url = 'https://www.amazon.com/b2b/abws/oauth';

        $params = [
            'state' => '100',
            'redirect_uri' => env('AMAZON_REDIRECT_URI'),
            'applicationId' => env('AMAZON_APPLICATION_ID'),
        ];
        header('Location: '.$url.'?'.http_build_query($params));
    }

    public function amazon_auth_response()
    {
        if (isset(request()->query()['code'])) {
            $code = request()->query()['code'];
        } else {
            ///6-16-2023 return with error ... no code
            return redirect(route('company_emails.index'));
        }

        $guzzle = new Client;

        $url = 'https://api.amazon.com/auth/O2/token';
        $amazon_account_tokens = json_decode($guzzle->post($url, [
            'form_params' => [
                'client_id' => env('AMAZON_CLIENT_ID'),
                'client_secret' => env('AMAZON_CLIENT_SECRET'),
                'code' => $code,
                'redirect_uri' => env('AMAZON_REDIRECT_URI'),
                'grant_type' => 'authorization_code',
            ],
        ])->getBody()->getContents());

        $receipt_account = ReceiptAccount::where('vendor_id', 54)->first();

        //json
        $api_data = [
            'access_token' => $amazon_account_tokens->access_token,
            'refresh_token' => $amazon_account_tokens->refresh_token,
            //->setTimezone('America/Chicago')
            'expires_in' => Carbon::now()->addMinutes(55)->toIso8601String(),
            'token_type' => $amazon_account_tokens->token_type,
        ];

        $api_data = json_encode($api_data);

        $receipt_account->options = $api_data;
        $receipt_account->save();

        return redirect(route('company_emails.index'));
    }

    public function amazon_orders_api()
    {
        ini_set('max_execution_time', '4800');

        $receipt_accounts = ReceiptAccount::withoutGlobalScopes()->where('vendor_id', 54)->whereNotNull('options->refresh_token')->get();

        //Initialize the Credentials object.
        //access token and secret from AWS
        $credentials = new \Aws\Credentials\Credentials(env('AMAZON_AWS_ACCESS_TOKEN'), env('AMAZON_AWS_SECRET_TOKEN'));
        foreach ($receipt_accounts as $receipt_account) {
            //if NOW  is greater than > expires_in ... get new access_token
            //get new access_token valid for 1 hour and change 'expires_in' to 55 minutes from when submitted
            //ONLY if access token is expired....
            if (Carbon::now() > Carbon::parse($receipt_account->options['expires_in'])) {
                try {
                    $guzzle = new Client;
                    $url = 'https://api.amazon.com/auth/O2/token';
                    $amazon_account_tokens = json_decode($guzzle->post($url, [
                        'form_params' => [
                            'client_id' => env('AMAZON_CLIENT_ID'),
                            'client_secret' => env('AMAZON_CLIENT_SECRET'),
                            'refresh_token' => $receipt_account->options['refresh_token'],
                            'access_token' => $receipt_account->options['access_token'],
                            'grant_type' => 'refresh_token',
                        ],
                    ])->getBody()->getContents());
                } catch (RequestException $e) {
                    if ($e->hasResponse()) {
                        $response = $e->getResponse();
                        $responseBody = $response->getBody()->getContents();
                        $error = $responseBody;
                    } else {
                        $error = $e->getMessage();
                    }

                    $receipt_account->options += ['errors' => json_decode($error, true)];
                    $receipt_account->save();

                    Log::channel('company_emails_login_error')->error('Amazon token refresh failed', ApiErrorFormatter::format($e, [
                        'receipt_account_id' => $receipt_account->id,
                        'belongs_to_vendor_id' => $receipt_account->belongs_to_vendor_id,
                        'has_response' => $e->hasResponse(),
                    ]));
                    continue;
                }

                $receipt_account->update([
                    'options->expires_in' => Carbon::now()->addMinutes(55)->toIso8601String(),
                    'options->access_token' => $amazon_account_tokens->access_token,
                ]);

                $receipt_account->fresh();
            }

            // Instantiate Client object with api key header.
            $client = new \GuzzleHttp\Client([
                'headers' => [
                    'host' => 'api.business.amazon.com',
                    'x-amz-access-token' => $receipt_account->options['access_token'],
                    'x-amz-date' => Carbon::now()->toIso8601String(),
                    'user-agent' => 'Hive Production/0.2 (Language=PHP;Platform=Linux)',
                ],
            ]);

            $url = 'https://na.business-api.amazon.com';
            $path = '/reports/2021-01-08/orders/';
            $s4 = new \Aws\Signature\SignatureV4('execute-api', 'us-east-1');

            // Incremental sync: fetch from last sync or default to 2 days ago.
            // Full sync is clamped to Amazon's rolling 30-day window.
            $lastFullSync = isset($receipt_account->options['amazon_orders_full_synced_at'])
                ? Carbon::parse($receipt_account->options['amazon_orders_full_synced_at'])
                : null;
            
            $needsFullSync = !$lastFullSync || $lastFullSync->lt(Carbon::today());
            $nowUtc = Carbon::now('UTC');
            $maxLookbackStart = $nowUtc->copy()->subDays(29)->startOfDay();
            
            if ($needsFullSync) {
                $startDate = $maxLookbackStart->copy();
            } else {
                $startDate = isset($receipt_account->options['amazon_orders_synced_at'])
                    ? Carbon::parse($receipt_account->options['amazon_orders_synced_at'])->setTimezone('UTC')
                    : $nowUtc->copy()->subDays(2);
            }

            if ($startDate->lt($maxLookbackStart)) {
                Log::channel('amazon_orders')->warning('Clamping Amazon orders startDate to API lookback window', [
                    'receipt_account_id' => $receipt_account->id,
                    'original_start' => $startDate->toIso8601String(),
                    'clamped_start' => $maxLookbackStart->toIso8601String(),
                ]);
                $startDate = $maxLookbackStart->copy();
            }
            
            $endDate = $nowUtc->copy();

            // Chunk date ranges into 1-day windows (Amazon API max range is 24 hours)
            $chunkStart = $startDate->copy();
            while ($chunkStart->lte($endDate)) {
                $chunkEnd = $chunkStart->copy()->endOfDay()->setTimezone('UTC');
                if ($chunkEnd->gt($endDate)) {
                    $chunkEnd = $endDate->copy();
                }

                // Paginate through all orders in this date chunk
                $nextPageToken = null;
                do {
                    $params = [
                        'startDate' => $chunkStart->toIso8601String(),
                        'endDate' => $chunkEnd->toIso8601String(),
                        'includeCharges' => 'true',
                        'includeLineItems' => 'true',
                        'includeShipments' => 'true',
                    ];

                    if ($nextPageToken) {
                        $params = ['nextPageToken' => $nextPageToken];
                    }

                    $full_url = $url.$path.'?'.http_build_query($params);
                    $request = new \GuzzleHttp\Psr7\Request('GET', $full_url);
                    $signedRequest = $s4->signRequest($request, $credentials);

                    try {
                        $response = $client->send($signedRequest);
                        $responseData = json_decode($response->getBody()->getContents(), true);
                        $orders = collect($responseData['orders']);
                        $nextPageToken = $responseData['nextPageToken'] ?? null;
                    } catch (\GuzzleHttp\Exception\ClientException $e) {
                        if ($e->getCode() === 429) {
                            Log::channel('amazon_orders')->warning('Rate limited, retrying after delay', [
                                'chunk_start' => $chunkStart->toDateString(),
                                'chunk_end' => $chunkEnd->toDateString(),
                                'receipt_account_id' => $receipt_account->id,
                            ]);
                            sleep(5);
                            continue;
                        }
                        throw $e;
                    }

                    // Respect rate limit: 0.5 req/sec (1 request per 2 seconds)
                    sleep(2);

                    Log::channel('amazon_orders')->info('Orders fetched', [
                        'chunk_start' => $chunkStart->toDateString(),
                        'chunk_end' => $chunkEnd->toDateString(),
                        'receipt_account_id' => $receipt_account->id,
                        'belongs_to_vendor_id' => $receipt_account->belongs_to_vendor_id,
                        'order_count' => $orders->count(),
                        'has_next_page' => ! is_null($nextPageToken),
                        'is_full_sync' => $needsFullSync,
                    ]);

                foreach ($orders as $orders_key => $order) {
                    //Log full order payload
                    Log::channel('amazon_orders')->debug('Processing order', [
                        'receipt_account_id' => $receipt_account->id,
                        'belongs_to_vendor_id' => $receipt_account->belongs_to_vendor_id,
                        'order_id' => $order['orderId'],
                        'order_date' => $order['orderDate'],
                        'order_status' => $order['orderStatus'],
                        'order_amount' => $order['orderNetTotal']['amount'],
                        'full_payload' => $order,
                    ]);
                    $order_date = Carbon::parse($order['orderDate'])->setTimezone('America/Chicago')->format('Y-m-d');

                    $existingExpense = Expense::withoutGlobalScopes()
                        ->where('belongs_to_vendor_id', $receipt_account->belongs_to_vendor_id)
                        ->where('vendor_id', 54)
                        ->where('invoice', $order['orderId'])
                        ->where('amount', 'NOT LIKE', '-%')
                        ->where('date', $order_date)
                        ->first();

                    if (! $existingExpense) {
                        //create expense
                        $bulkMatch = TransactionBulkMatch::findMatchForAmount(54, (float) $order['orderNetTotal']['amount']);
                        $distributionId = $bulkMatch?->distribution_id
                            ?? $this->matchDistributionByPurchaseOrder($order['purchaseOrderNumber'] ?? '', $receipt_account->belongs_to_vendor_id);
                        $expense = Expense::create([
                            'amount' => $order['orderNetTotal']['amount'],
                            'date' => $order_date,
                            'project_id' => null,
                            'distribution_id' => $distributionId,
                            'created_by_user_id' => 0, //automated
                            'invoice' => $order['orderId'],
                            'vendor_id' => 54, //54 = AMAZON
                            'note' => null,
                            'belongs_to_vendor_id' => $receipt_account->belongs_to_vendor_id,
                        ]);
                        $bulkMatch?->applySplits($expense, (float) $order['orderNetTotal']['amount']);
                    } else {
                        $expense = $existingExpense;
                        if ($order['orderStatus'] == 'CANCELLED') {
                            $expense->amount = 0.00;
                            $expense->save();

                            $transactions = Transaction::withoutGlobalScopes()->where('expense_id', $expense->id)->get();
                            foreach ($transactions as $transaction) {
                                $transaction->expense_id = null;
                                $transaction->save();
                            }

                            $expense->delete();
                        } else {
                            // Use actual charge total when charges exist (what hits the bank),
                            // otherwise fall back to orderNetTotal.
                            $chargesTotal = 0.0;
                            foreach ($order['charges'] as $charge) {
                                $chargesTotal += (float) ($charge['amount']['amount'] ?? 0);
                            }
                            $correctAmount = $chargesTotal > 0 ? $chargesTotal : $order['orderNetTotal']['amount'];

                            $this->syncAmazonExpenseAmount($expense, (float) $correctAmount);
                        }

                        $charges = $this->formatAmazonCharges($order['charges']);

                        $receipt = $expense->receipts()->latest()->first();

                        if (! is_null($receipt)) {
                            $items = $receipt->receipt_items;
                            $items['charges'] = $charges;

                            $receipt->receipt_items = $items;
                            $receipt->save();

                            // Download OrderSummary PDF if this receipt doesn't have one yet
                            // Skip only PENDING (no invoice yet) and CANCELLED orders
                            $orderStatus = strtoupper($order['orderStatus'] ?? '');
                            if (empty($receipt->receipt_filename) && ! in_array($orderStatus, ['PENDING', 'CANCELLED'])) {
                                Log::channel('amazon_orders')->info('Attempting PDF download for existing expense', [
                                    'order_id' => $order['orderId'],
                                    'expense_id' => $expense->id,
                                    'order_status' => $order['orderStatus'],
                                ]);
                                $this->downloadAmazonOrderDocument($client, $s4, $credentials, $order['orderId'], $expense, $receipt);
                            }
                        } else {
                            // Create receipt data for existing expense that has none (e.g. restored after soft-delete)
                            $receiptItems = [];
                            foreach ($order['lineItems'] as $itemIdx => $item) {
                                $receiptItems[$itemIdx]['Price'] = $item['purchasedPricePerUnit']['amount'];
                                $receiptItems[$itemIdx]['Quantity'] = $item['itemQuantity'];
                                $receiptItems[$itemIdx]['TotalPrice'] = $item['itemSubTotal']['amount'] ?? 0.00;
                                $receiptItems[$itemIdx]['Description'] = $item['title'];
                                $receiptItems[$itemIdx]['ProductCode'] = $item['asin'];
                            }

                            $receiptData = [
                                'items' => $receiptItems,
                                'total' => $order['orderNetTotal']['amount'],
                                'subtotal' => $order['orderSubTotal']['amount'],
                                'total_tax' => $order['orderTax']['amount'],
                                'invoice_number' => $order['orderId'],
                                'purchase_order' => $order['purchaseOrderNumber'],
                                'transaction_date' => $order_date,
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
                                $this->downloadAmazonOrderDocument($client, $s4, $credentials, $order['orderId'], $expense, $newReceipt);
                            }
                        }

                        continue;
                    }

                    //create expense_receipt_data
                    $items = [];
                    foreach ($order['lineItems'] as $items_key => $item) {
                        $items[$items_key]['Price'] = $item['purchasedPricePerUnit']['amount'];
                        $items[$items_key]['Quantity'] = $item['itemQuantity'];
                        $items[$items_key]['TotalPrice'] = $item['itemSubTotal']['amount'] ?? 0.00;
                        $items[$items_key]['Description'] = $item['title'];
                        $items[$items_key]['ProductCode'] = $item['asin'];
                    }

                    $charges = $this->formatAmazonCharges($order['charges']);

                    //items array!
                    $expense_receipt_data = [
                        'items' => $items,
                        'total' => $order['orderNetTotal']['amount'],
                        'subtotal' => $order['orderSubTotal']['amount'],
                        'total_tax' => $order['orderTax']['amount'],
                        'invoice_number' => $order['orderId'],
                        'purchase_order' => $order['purchaseOrderNumber'],
                        'transaction_date' => $order_date,
                        'charges' => $charges,
                    ];

                    $expenseReceipt = ExpenseReceipts::create([
                        'expense_id' => $expense->id,
                        'receipt_html' => null,
                        'receipt_items' => $expense_receipt_data,
                        'receipt_filename' => null,
                    ]);

                    // Download OrderSummary PDF via Document API — skip PENDING (no invoice yet) and CANCELLED
                    $newOrderStatus = strtoupper($order['orderStatus'] ?? '');
                    if (! in_array($newOrderStatus, ['PENDING', 'CANCELLED'])) {
                        $this->downloadAmazonOrderDocument($client, $s4, $credentials, $order['orderId'], $expense, $expenseReceipt);
                    }
                }
                } while ($nextPageToken);

                $chunkStart = $chunkEnd->copy()->addDay()->startOfDay();
            }

            //Update orders sync timestamp after successful processing
            $updates = ['options->amazon_orders_synced_at' => Carbon::now()->toIso8601String()];
            
            //If this was a full sync, update the full sync timestamp
            if ($needsFullSync) {
                $updates['options->amazon_orders_full_synced_at'] = Carbon::now()->toIso8601String();
            }
            
            $receipt_account->update($updates);

            //Incremental sync: fetch transactions from last sync or default to 7 days ago
            $transactionStartDate = isset($receipt_account->options['amazon_transactions_synced_at'])
                ? Carbon::parse($receipt_account->options['amazon_transactions_synced_at'])
                : Carbon::now()->subDays(7);

            $path = '/reconciliation/2021-01-08/transactions';
            $params = [
                'feedStartDate' => $transactionStartDate->toIso8601String(),
                'feedEndDate' => Carbon::now()->toIso8601String(),
            ];

            $full_url = $url.$path.'?'.http_build_query($params);
            $request = new \GuzzleHttp\Psr7\Request('GET', $full_url);
            $signedRequest = $s4->signRequest($request, $credentials);
            $response = $client->send($signedRequest);

            $transactions = collect(json_decode($response->getBody()->getContents(), true));
            $chargeTransactions = collect($transactions['transactions'])->where('transactionType', 'CHARGE');
            $nonChargeTransactions = collect($transactions['transactions'])->where('transactionType', '!=', 'CHARGE');

            // Process CHARGE transactions: update existing expense amounts
            // when the actual charged amount differs from the order total.
            foreach ($chargeTransactions as $chargeTransaction) {
                $chargeOrderId = $chargeTransaction['transactionLineItems'][0]['orderId'] ?? null;
                if (! $chargeOrderId) {
                    continue;
                }

                $chargeAmount = (float) ($chargeTransaction['amount']['amount'] ?? 0);
                if ($chargeAmount <= 0) {
                    continue;
                }

                $existingExpense = Expense::where('belongs_to_vendor_id', $receipt_account->belongs_to_vendor_id)
                    ->where('vendor_id', 54)
                    ->whereNull('deleted_at')
                    ->where('invoice', $chargeOrderId)
                    ->where('amount', 'NOT LIKE', '-%')
                    ->first();

                if ($existingExpense) {
                    $this->syncAmazonExpenseAmount($existingExpense, $chargeAmount);
                }
            }

            foreach ($nonChargeTransactions as $transaction) {
                $order_date = Carbon::create($transaction['transactionDate'])->format('Y-m-d');
                $order_id = $transaction['transactionLineItems'][0]['orderId'];

                $existingRefund = Expense::where('belongs_to_vendor_id', $receipt_account->belongs_to_vendor_id)
                    ->where('vendor_id', 54)
                    ->whereNull('deleted_at')
                    ->where('invoice', $order_id)
                    ->where('amount', 'LIKE', '-%')
                    ->where('date', $order_date)
                    ->exists();

                if (! $existingRefund) {
                    //create expense Model
                    //CREATE expense
                    $refundAmount = (float) $transaction['amount']['amount'];
                    $bulkMatch = TransactionBulkMatch::findMatchForAmount(54, $refundAmount);

                    // Inherit distribution from the original order expense, or match PO
                    $refundDistributionId = $bulkMatch?->distribution_id;
                    if (! $refundDistributionId) {
                        $originalExpense = Expense::withoutGlobalScopes()
                            ->where('belongs_to_vendor_id', $receipt_account->belongs_to_vendor_id)
                            ->where('vendor_id', 54)
                            ->where('invoice', $order_id)
                            ->where('amount', 'NOT LIKE', '-%')
                            ->whereNull('deleted_at')
                            ->first();
                        $refundDistributionId = $originalExpense?->distribution_id
                            ?? $this->matchDistributionByPurchaseOrder(
                                $transaction['transactionLineItems'][0]['purchaseOrderNumber'] ?? '',
                                $receipt_account->belongs_to_vendor_id
                            );
                    }

                    $expense = Expense::create([
                        'amount' => '-'.$transaction['amount']['amount'],
                        'date' => $order_date,
                        'project_id' => null,
                        'distribution_id' => $refundDistributionId,
                        'created_by_user_id' => 0, //automated
                        'invoice' => $order_id,
                        'vendor_id' => 54, //54 = AMAZON
                        'note' => null,
                        'belongs_to_vendor_id' => $receipt_account->belongs_to_vendor_id,
                    ]);
                    $bulkMatch?->applySplits($expense, $refundAmount);

                    $associated = Expense::where('belongs_to_vendor_id', $receipt_account->belongs_to_vendor_id)
                        ->where('vendor_id', 54)
                        ->whereNull('deleted_at')
                        ->where('invoice', $order_id)
                        ->where('amount', 'NOT LIKE', '-%')
                        ->first();

                    if ($associated) {
                        $associated->parent_expense_id = $expense->id;
                        $associated->save();
                    }

                    //create expense_receipt_data
                    //ITEMS
                    $items = [];
                    foreach ($transaction['transactionLineItems'] as $transaction_key => $item) {
                        $items[$transaction_key]['Price'] = $item['principalAmount']['amount'];
                        $items[$transaction_key]['Quantity'] = $item['itemQuantity'];
                        $items[$transaction_key]['TotalPrice'] = $item['totalAmount']['amount'];
                        $items[$transaction_key]['Description'] = $item['productTitle'];
                        $items[$transaction_key]['ProductCode'] = $item['asin'];
                    }

                    //CHARGES
                    $charges = [];

                    $charges[0]['transactionDate'] = $order_date;
                    $charges[0]['transactionId'] = $transaction['transactionId'];
                    $charges[0]['amount'] = '-'.$transaction['amount']['amount'];
                    $charges[0]['paymentInstrumentLast4Digits'] = $transaction['paymentInstrumentLast4Digits'];

                    //items array!
                    $expense_receipt_data = [
                        'items' => $items,
                        'total' => '-'.$transaction['amount']['amount'],
                        'subtotal' => null,
                        'total_tax' => null,
                        'invoice_number' => $order_id,
                        'purchase_order' => $transaction['transactionLineItems'][0]['purchaseOrderNumber'],
                        'transaction_date' => $order_date,
                        'charges' => $charges,
                    ];

                    ExpenseReceipts::create([
                        'expense_id' => $expense->id,
                        'receipt_html' => null,
                        'receipt_items' => $expense_receipt_data,
                        'receipt_filename' => null,
                    ]);
                } else {
                    continue;
                }
            }

            //Update transactions sync timestamp after successful processing
            $receipt_account->update([
                'options->amazon_transactions_synced_at' => Carbon::now()->toIso8601String(),
            ]);

            sleep(1);
        }

        // Queue bulk match job to immediately process any new expenses with matching rules
        TransactionVendorBulkMatchJob::dispatch();
    }

    /**
     * Single entry-point for receipt OCR: analyse the file via Content Understanding
     * and normalise the extracted fields.
     *
     * @param  string       $ocrPath        Path on the 'files' disk (e.g. '_temp_ocr/2025-06-18-12-00-00-42.pdf')
     * @param  string       $docType        File extension: pdf, png, jpg
     * @param  float|null   $expenseAmount  Known expense amount (manual upload flow)
     * @param  string|null  $email          Non-null when processing email receipts
     * @param  Receipt|null $receipt        Receipt template for option overrides
     * @return array{content: string, fields: array}|array{error: true}
     */
    public function extractReceipt(string $ocrPath, string $docType, ?float $expenseAmount = null, mixed $email = null, ?Receipt $receipt = null): array
    {
        // ── 1. Analyse via Content Understanding ──────────────────────
        /** @var ContentUnderstandingService $cu */
        $cu = app(ContentUnderstandingService::class);
        $analyzeResult = $cu->analyze($ocrPath, $docType, 'nylas');

        // Merge all document field maps into a single flat map
        $allFields = [];
        foreach ($analyzeResult['analyzeResult']['documents'] ?? [] as $document) {
            $allFields = array_merge_recursive($allFields, $document['fields'] ?? []);
        }

        if (empty($allFields)) {
            return ['error' => true];
        }

        $prefix  = $allFields;
        $rawContent = $analyzeResult['analyzeResult']['content'] ?? '';
        // Convert any HTML table markup to plain text layout before encoding
        $rawContent = preg_replace('/<br\s*\/?>/i', "\n", $rawContent);
        $rawContent = preg_replace('/<\/td>\s*<td[^>]*>/i', "\t", $rawContent);
        $rawContent = preg_replace('/<\/tr>\s*/i', "\n", $rawContent);
        $rawContent = strip_tags($rawContent);
        $rawContent = preg_replace('/\n{3,}/', "\n\n", $rawContent);
        $content = htmlspecialchars(trim($rawContent), ENT_QUOTES, 'UTF-8');
        $styles  = $analyzeResult['analyzeResult']['styles'] ?? [];

        $keyValuePairs = null;
        if (isset($analyzeResult['analyzeResult']['keyValuePairs'])) {
            $keyValuePairs = collect(json_decode(json_encode($analyzeResult['analyzeResult']['keyValuePairs'])));
        }

        // ── 2. Tip ────────────────────────────────────────────────────
        $tip = null;
        if (isset($prefix['Tip'])) {
            $tipContent = $prefix['Tip']['content'] ?? $prefix['Tip']['valueString'] ?? '';
            $isFee = preg_match('/\b(fee|surcharge|convenience|service\s*charge)\b/i', $tipContent);
            $tip = $isFee ? null : $this->extractCurrencyAmount($prefix['Tip']);
        }

        // ── 3. Handwritten notes ──────────────────────────────────────
        $handwrittenNotes = [];
        foreach ($styles as $style) {
            if (($style['isHandwritten'] ?? false) && ($style['confidence'] ?? 0) > 0.6) {
                foreach ($style['spans'] ?? [] as $span) {
                    $handwrittenNotes[] = substr($content, $span['offset'], $span['length']);
                }
            }
        }

        // ── 4. Merchant / Vendor Name ─────────────────────────────────
        $merchantName = $prefix['MerchantName']['valueString']
            ?? $prefix['MerchantName']['content']
            ?? $prefix['VendorName']['valueString']
            ?? null;

        if ($merchantName !== null) {
            $merchantName = str_replace("\n", '', $merchantName);
        }

        // ── 5. Invoice Number ─────────────────────────────────────────
        $invoiceNumber = null;
        if (isset($prefix['InvoiceId']) && ($prefix['InvoiceId']['confidence'] ?? 0) > 0) {
            $invoiceNumber = $prefix['InvoiceId']['valueString'] ?? $prefix['InvoiceId']['content'] ?? null;
        } elseif (isset($prefix['invoice_number'])) {
            $invoiceNumber = $prefix['invoice_number'];
        } elseif (isset($prefix['OrderNumber']) && ($prefix['OrderNumber']['confidence'] ?? 0) > 0) {
            $invoiceNumber = $prefix['OrderNumber']['valueString'] ?? $prefix['OrderNumber']['content'] ?? null;
        }

        // ── 6. Purchase Order / Job Name ──────────────────────────────
        $purchaseOrder = trim($prefix['PurchaseOrder']['valueString'] ?? '');
        $jobName       = trim($prefix['JobName']['valueString'] ?? '');

        $values = array_filter([$purchaseOrder, $jobName], fn ($v) => $v !== '');
        $purchaseOrderNumber = count($values) > 1 ? implode(', ', $values) : implode('', $values);

        // ── 7. Total Tax ──────────────────────────────────────────────
        $totalTax = null;
        if (isset($prefix['TotalTaxAmount'])) {
            $totalTax = $this->extractCurrencyAmount($prefix['TotalTaxAmount']);
        } elseif (isset($prefix['TotalTax'])) {
            $totalTax = $prefix['TotalTax']['valueCurrency']['amount']
                ?? (isset($prefix['TotalTax']['valueNumber']) ? (float) $prefix['TotalTax']['valueNumber'] : null);
        }

        // ── 8. Transaction Date ───────────────────────────────────────
        $transactionDate = null;
        if (isset($prefix['TransactionDate'])) {
            $transactionDate = $prefix['TransactionDate']['valueDate'] ?? $prefix['TransactionDate']['content'] ?? null;
        } elseif (isset($prefix['DepartureDate'])) {
            $transactionDate = $prefix['DepartureDate']['valueDate'];
        } elseif (isset($prefix['InvoiceDate'])) {
            $transactionDate = $prefix['InvoiceDate']['valueDate'] ?? null;
        } elseif ($keyValuePairs) {
            foreach (['Order Date', 'Completed Date:', 'ORDER DATE'] as $label) {
                $match = $keyValuePairs->where('key.content', $label)->first();
                if ($match) {
                    $transactionDate = $match->value->content ?? null;
                    break;
                }
            }
        }

        if ($transactionDate !== null) {
            if (is_array($transactionDate)) {
                $transactionDate = $transactionDate[0];
            }
            $transactionDate = Carbon::parse($transactionDate);
            if ($transactionDate->year < date('Y', strtotime('-8 years'))) {
                $transactionDate = $transactionDate->year(now()->year);
            }
            $transactionDate = $transactionDate->format('Y-m-d');
        } else {
            if ($email !== null || $expenseAmount) {
                $transactionDate = null;
            } else {
                return ['error' => true];
            }
        }

        // ── 9. Subtotal ──────────────────────────────────────────────
        $subtotal = null;
        foreach (['SubtotalAmount', 'SubTotal', 'Subtotal'] as $key) {
            if (isset($prefix[$key])) {
                $subtotal = $this->extractCurrencyAmount($prefix[$key]);
                break;
            }
        }

        // ── 10. Line Items ────────────────────────────────────────────
        $items = $prefix['Items']['valueArray'] ?? $prefix['LineItems']['valueArray'] ?? [];

        $formattedItems = null;
        if (!empty($items)) {
            $formattedItems = [];
            foreach ($items as $key => $lineItem) {
                if (!isset($lineItem['valueObject']) || !is_array($lineItem['valueObject'])) {
                    continue;
                }
                $line = $lineItem['valueObject'];

                $description = $line['Description']['valueString'] ?? null;
                if (isset($lineItem['content']) && is_string($lineItem['content'])) {
                    $cleaned = preg_replace('/(?:Part\s*Number|Warranty|Quantity|Item\s*Total|Price|Unit\s*Price)\s*:.*$/is', '', trim($lineItem['content']));
                    $cleaned = trim($cleaned);
                    if ($cleaned !== '' && ($description === null || str_starts_with($cleaned, $description))) {
                        $description = $cleaned;
                    }
                }

                $formattedItems[$key]['Description'] = $description;
                $formattedItems[$key]['ProductCode']  = $this->sanitizeProductCode($line['ProductCode']['valueString'] ?? null);

                // Strip product code and return-policy indicators (e.g. <A>) from description
                if ($formattedItems[$key]['Description'] && $formattedItems[$key]['ProductCode']) {
                    $formattedItems[$key]['Description'] = trim(str_replace($formattedItems[$key]['ProductCode'], '', $formattedItems[$key]['Description']));
                }
                if ($formattedItems[$key]['Description']) {
                    $formattedItems[$key]['Description'] = trim(preg_replace('/\s*<[A-Z]>\s*/i', ' ', $formattedItems[$key]['Description']));
                }

                if (isset($line['TotalPrice'])) {
                    $formattedItems[$key]['TotalPrice'] = $this->extractCurrencyAmount($line['TotalPrice']);
                } elseif (isset($line['TotalAmount'])) {
                    $formattedItems[$key]['TotalPrice'] = $this->extractCurrencyAmount($line['TotalAmount']);
                } elseif (isset($line['Amount'])) {
                    $formattedItems[$key]['TotalPrice'] = $this->extractCurrencyAmount($line['Amount']);
                } else {
                    $formattedItems[$key]['TotalPrice'] = null;
                }

                $formattedItems[$key]['Quantity'] = isset($line['Quantity'])
                    ? ($line['Quantity']['valueNumber'] ?? $this->extractCurrencyAmount($line['Quantity']))
                    : 1;

                if (isset($line['Price'])) {
                    $formattedItems[$key]['Price'] = $this->extractCurrencyAmount($line['Price']);
                } elseif (isset($line['UnitPrice'])) {
                    $formattedItems[$key]['Price'] = $this->extractCurrencyAmount($line['UnitPrice']);
                } else {
                    $formattedItems[$key]['Price'] = $formattedItems[$key]['TotalPrice'];
                }

                // Backfill TotalPrice from Price × Quantity when OCR missed it
                if ($formattedItems[$key]['TotalPrice'] === null && $formattedItems[$key]['Price'] !== null) {
                    $formattedItems[$key]['TotalPrice'] = round($formattedItems[$key]['Price'] * ($formattedItems[$key]['Quantity'] ?? 1), 2);
                }

                // Backfill Price from TotalPrice / Quantity when OCR missed unit price
                $qty = $formattedItems[$key]['Quantity'] ?? 1;
                if ($formattedItems[$key]['Price'] === null && $formattedItems[$key]['TotalPrice'] !== null && $qty > 0) {
                    $formattedItems[$key]['Price'] = round($formattedItems[$key]['TotalPrice'] / $qty, 2);
                }

                // If quantity defaulted to 1 but we have both unit price and total price,
                // calculate the actual quantity from TotalPrice / Price.
                if (
                    $formattedItems[$key]['Quantity'] == 1
                    && $formattedItems[$key]['Price']
                    && $formattedItems[$key]['TotalPrice']
                    && $formattedItems[$key]['Price'] > 0
                    && $formattedItems[$key]['TotalPrice'] > $formattedItems[$key]['Price']
                ) {
                    $calculatedQty = $formattedItems[$key]['TotalPrice'] / $formattedItems[$key]['Price'];
                    $roundedQty = round($calculatedQty);

                    if ($roundedQty > 1 && abs($calculatedQty - $roundedQty) < 0.01) {
                        $formattedItems[$key]['Quantity'] = (int) $roundedQty;
                    }
                }
            }
        }

        // ── 11. Total Amount ──────────────────────────────────────────
        $amount = null;
        if (isset($prefix['TotalAmount'])) {
            $amount = $this->extractCurrencyAmount($prefix['TotalAmount']);
        } elseif (isset($prefix['Total'])) {
            $amount = $this->extractCurrencyAmount($prefix['Total']);
        } elseif (isset($prefix['InvoiceTotal'])) {
            $amount = $this->extractCurrencyAmount($prefix['InvoiceTotal']);
        } elseif (isset($prefix['SubTotal']) && isset($prefix['TotalTax'])) {
            $amount = (float) ($this->extractCurrencyAmount($prefix['SubTotal']) ?? 0) + (float) ($this->extractCurrencyAmount($prefix['TotalTax']) ?? 0);
        } elseif (isset($prefix['SubtotalAmount']) && isset($prefix['TotalTaxAmount'])) {
            $amount = (float) ($this->extractCurrencyAmount($prefix['SubtotalAmount']) ?? 0) + (float) ($this->extractCurrencyAmount($prefix['TotalTaxAmount']) ?? 0);
        } elseif ($keyValuePairs) {
            $authMatch = $keyValuePairs->where('key.content', 'Authorized Amount:')->first();
            if ($authMatch) {
                $amount = $authMatch->value->content ?? null;
            }
        }

        if ($amount === null && is_array($formattedItems)) {
            $lineItemsTotal = $this->sumLineItemTotals($formattedItems);
            if ($lineItemsTotal !== null) {
                $amount = $lineItemsTotal;
            }
        }

        $contentTotal = null;
        if (is_string($content) && $content !== '') {
            $contentTotal = $this->extractTotalFromContent($content);
        }

        if ($amount === null && $contentTotal !== null) {
            $amount = $contentTotal;
        }

        if ($contentTotal !== null && $amount !== null) {
            if (($amount >= 0 && $amount < 1) || $contentTotal > ($amount * 10)) {
                $amount = $contentTotal;
            }
        }

        if ($amount === null && $expenseAmount !== null) {
            $amount = $expenseAmount;
        }

        if ($amount === null && $subtotal === null) {
            return ['error' => true];
        }

        // Normalize arrays to scalars
        if (is_array($amount)) { $amount = $amount[0]; }
        if (is_array($subtotal)) { $subtotal = $subtotal[0]; }
        if (is_array($totalTax)) { $totalTax = $totalTax[0]; }

        if ($amount === null && $subtotal !== null && $totalTax !== null) {
            $amount = $subtotal + $totalTax;
        }

        if (empty($totalTax) && empty($subtotal) && isset($amount)) {
            $subtotal = $amount;
        }

        if ($subtotal !== null && $amount !== null && $subtotal < 1 && $amount >= 1 && empty($totalTax)) {
            $subtotal = $amount;
        }

        // Fix OCR error: subtotal equals total but tax exists
        if ($subtotal !== null && $amount !== null && $totalTax !== null && $subtotal == $amount && $totalTax > 0) {
            if ($expenseAmount !== null) {
                $expAmtFloat = (float) $expenseAmount;
                if (abs($amount - $expAmtFloat) < 0.01) {
                    $subtotal = $amount - $totalTax;
                } else {
                    $amount = $subtotal + $totalTax;
                }
            } else {
                $amount = $subtotal + $totalTax;
            }
        }

        if (is_array($formattedItems) && !empty($formattedItems)) {
            $formattedItems = $this->supplementLineItemsFromContent($formattedItems, (string) ($content ?? ''), $subtotal);
            $formattedItems = $this->supplementQuantitiesFromContent($formattedItems, (string) ($content ?? ''));
            $formattedItems = $this->deduplicateLineItems($formattedItems);
        }

        // Misc fees = gap between total and (subtotal + tax + tip)
        $miscFees = null;
        if ($amount !== null && $subtotal !== null) {
            $knownSum = $subtotal + ($totalTax ?? 0) + ($tip ?? 0);
            $gap = round($amount - $knownSum, 2);
            if ($gap > 0.004) {
                $miscFees = $gap;
            }
        }

        return [
            'content' => $content,
            'fields'  => [
                'items'             => $formattedItems,
                'subtotal'          => $subtotal,
                'total'             => $amount,
                'total_tax'         => $totalTax,
                'tip'               => $tip,
                'misc_fees'         => $miscFees,
                'transaction_date'  => $transactionDate,
                'merchant_name'     => $merchantName,
                'invoice_number'    => $invoiceNumber,
                'purchase_order'    => $purchaseOrderNumber,
                'handwritten_notes' => $handwrittenNotes,
                'payment_methods'   => $this->extractPaymentMethods($prefix),
            ],
        ];
    }


    /**
     * Safely extract a currency/number amount from a flexible OCR field shape.
     * Accepts arrays like ['valueCurrency' => ['amount' => 12.34]],
     * ['valueNumber' => 12.34], ['valueString' => '$12.34'], ['content' => '12,34'],
     * or raw numeric/string values.
     */
    private function extractCurrencyAmount(mixed $field): ?float
    {
        if (is_array($field)) {
            if (isset($field['valueObject']) && is_array($field['valueObject'])) {
                $valueObject = $field['valueObject'];

                if (isset($valueObject['Amount'])) {
                    return $this->extractCurrencyAmount($valueObject['Amount']);
                }

                if (isset($valueObject['amount'])) {
                    return $this->extractCurrencyAmount($valueObject['amount']);
                }
            }

            if (isset($field['Amount'])) {
                return $this->extractCurrencyAmount($field['Amount']);
            }

            if (isset($field['amount'])) {
                return $this->extractCurrencyAmount($field['amount']);
            }

            if (isset($field['valueCurrency']['amount'])) {
                return (float) $field['valueCurrency']['amount'];
            }

            if (isset($field['valueNumber'])) {
                return is_numeric($field['valueNumber']) ? (float) $field['valueNumber'] : $this->parseAmountFromString((string) $field['valueNumber']);
            }

            if (isset($field['valueString'])) {
                return $this->parseAmountFromString((string) $field['valueString']);
            }

            if (isset($field['content'])) {
                return $this->parseAmountFromString((string) $field['content']);
            }
        } elseif (is_numeric($field)) {
            return (float) $field;
        } elseif (is_string($field)) {
            return $this->parseAmountFromString($field);
        }

        return null;
    }

    /**
     * Extract the Payments array from an OCR field prefix (CU custom analyzer field).
     * Returns array of ['type', 'last_four', 'amount'] entries, or empty array.
     *
     * @param  array<string, mixed>  $prefix
     * @return array<int, array{type: string|null, last_four: string|null, amount: float|null}>
     */
    private function extractPaymentMethods(array $prefix): array
    {
        $paymentMethods = [];

        if (!isset($prefix['Payments']['valueArray'])) {
            return $paymentMethods;
        }

        foreach ($prefix['Payments']['valueArray'] as $row) {
            $obj = $row['valueObject'] ?? [];

            $type = $obj['PaymentType']['valueString']
                ?? $obj['PaymentType']['content']
                ?? null;
            $lastFour = $obj['LastFour']['valueString']
                ?? $obj['LastFour']['content']
                ?? null;
            $amount = isset($obj['Amount']) ? $this->extractCurrencyAmount($obj['Amount']) : null;

            if ($type !== null) {
                $paymentMethods[] = [
                    'type'      => $type,
                    'last_four' => $lastFour,
                    'amount'    => $amount,
                ];
            }
        }

        return $paymentMethods;
    }

    /**
     * Single source of truth for updating an Amazon expense amount.
     * Updates the expense and its receipt total when the correct amount differs.
     */
    private function syncAmazonExpenseAmount(Expense $expense, float $correctAmount): void
    {
        if ((float) $expense->amount === $correctAmount) {
            return;
        }

        $expense->amount = $correctAmount;
        $expense->save();

        $receipt = $expense->receipts()->latest()->first();
        if ($receipt) {
            $items = $receipt->receipt_items;
            $items['total'] = $correctAmount;
            $receipt->receipt_items = $items;
            $receipt->save();
        }
    }

    /**
     * Match an Amazon Purchase Order number to a distribution.
     * Normalizes both strings (lowercase, strip non-alphanumeric) for fuzzy matching.
     */
    private function matchDistributionByPurchaseOrder(string $purchaseOrder, int $belongsToVendorId): ?int
    {
        $purchaseOrder = trim($purchaseOrder);
        if ($purchaseOrder === '') {
            return null;
        }

        $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', $purchaseOrder));

        $distributions = Distribution::withoutGlobalScopes()
            ->where('vendor_id', $belongsToVendorId)
            ->get();

        foreach ($distributions as $distribution) {
            $normalizedName = strtolower(preg_replace('/[^a-z0-9]/i', '', $distribution->name));
            if ($normalized === $normalizedName) {
                return $distribution->id;
            }
        }

        return null;
    }

    /**
     * Download an Amazon OrderSummary PDF via the Document API and save it as the expense receipt.
     * 3-step async process: createReport → poll getReport → download from presigned URL.
     */
    private function downloadAmazonOrderDocument(
        \GuzzleHttp\Client $client,
        \Aws\Signature\SignatureV4 $s4,
        \Aws\Credentials\Credentials $credentials,
        string $orderId,
        Expense $expense,
        ExpenseReceipts $expenseReceipt
    ): void {
        $baseUrl = 'https://na.business-api.amazon.com';

        try {
            // Step 1: Request the OrderSummary report
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
                Log::channel('amazon_orders')->warning('Document API: no reportId returned', [
                    'order_id' => $orderId,
                    'expense_id' => $expense->id,
                    'response' => $createData,
                ]);
                return;
            }

            // Step 2: Poll getReport until processing is complete (max ~5 minutes)
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

                if (in_array($status, ['DONE'])) {
                    $reportDocumentId = $reportData['reportDocumentId'] ?? null;
                    break;
                }

                if (in_array($status, ['CANCELLED', 'FATAL'])) {
                    Log::channel('amazon_orders')->warning('Document API: report processing failed', [
                        'order_id' => $orderId,
                        'expense_id' => $expense->id,
                        'report_id' => $reportId,
                        'status' => $status,
                    ]);
                    return;
                }
            }

            if (! $reportDocumentId) {
                Log::channel('amazon_orders')->warning('Document API: timed out waiting for report', [
                    'order_id' => $orderId,
                    'expense_id' => $expense->id,
                    'report_id' => $reportId,
                ]);
                return;
            }

            // Step 3: Get the presigned URL and download the document
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
                Log::channel('amazon_orders')->warning('Document API: no presigned URL', [
                    'order_id' => $orderId,
                    'expense_id' => $expense->id,
                    'report_document_id' => $reportDocumentId,
                ]);
                return;
            }

            // Download the file from the presigned URL (no auth needed)
            $downloadClient = new \GuzzleHttp\Client();
            $downloadResponse = $downloadClient->get($presignedUrl);
            $fileContents = $downloadResponse->getBody()->getContents();

            // Decompress: gzip first, then zip to get the PDF
            if ($compressionAlgorithm === 'GZIP') {
                $fileContents = gzdecode($fileContents);
            }

            // The result after gzip decompression is a zip archive containing the PDF
            $tempZipPath = tempnam(sys_get_temp_dir(), 'amz_doc_');
            file_put_contents($tempZipPath, $fileContents);

            $zip = new \ZipArchive();
            if ($zip->open($tempZipPath) === true) {
                // Extract the first file (the PDF)
                $pdfContents = $zip->getFromIndex(0);
                $zip->close();
                unlink($tempZipPath);

                if ($pdfContents === false) {
                    Log::channel('amazon_orders')->warning('Document API: zip archive empty', [
                        'order_id' => $orderId,
                        'expense_id' => $expense->id,
                    ]);
                    return;
                }
            } else {
                // Not a zip — the decompressed content may already be the PDF
                $pdfContents = $fileContents;
                unlink($tempZipPath);
            }

            // Save PDF to storage
            $filename = $expense->id . '-amazon-' . $orderId . '.pdf';
            Storage::disk('files')->put('receipts/' . $filename, $pdfContents);

            // Update the receipt record with the filename
            $expenseReceipt->receipt_filename = $filename;
            $expenseReceipt->save();

            Log::channel('amazon_orders')->info('Document API: OrderSummary PDF saved', [
                'order_id' => $orderId,
                'expense_id' => $expense->id,
                'filename' => $filename,
            ]);
        } catch (\Exception $e) {
            Log::channel('amazon_orders')->error('Document API: failed to download document', [
                'order_id' => $orderId,
                'expense_id' => $expense->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Format Amazon order charges into a normalized array.
     */
    private function formatAmazonCharges(array $rawCharges): array
    {
        $charges = [];
        foreach ($rawCharges as $key => $charge) {
            $charges[$key] = [
                'transactionDate' => $charge['transactionDate'],
                'transactionId' => $charge['transactionId'],
                'amount' => $charge['amount']['amount'],
                'paymentInstrumentLast4Digits' => $charge['paymentInstrumentLast4Digits'],
            ];
        }

        return $charges;
    }

    private function sumLineItemTotals(array $items): ?float
    {
        $total = 0.0;
        $found = false;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $lineTotal = $item['TotalPrice'] ?? null;
            if (is_numeric($lineTotal)) {
                $total += (float) $lineTotal;
                $found = true;
            }
        }

        return $found ? $total : null;
    }

    private function supplementLineItemsFromContent(array $items, string $content, ?float $subtotal): array
    {
        if ($content === '' || is_null($subtotal)) {
            return $items;
        }

        $lineItemsTotal = $this->sumLineItemTotals($items);
        if (is_null($lineItemsTotal) || (($subtotal - $lineItemsTotal) <= 0.01)) {
            return $items;
        }

        $decoded = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = str_replace("\r", "\n", $decoded);

        $existingSignatures = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $code = trim((string) ($item['ProductCode'] ?? ''));
            $qty = (float) ($item['Quantity'] ?? 0);
            $total = (float) ($item['TotalPrice'] ?? 0);
            $existingSignatures[$code . '|' . $qty . '|' . $total] = true;
        }

        $pattern = '/(\d+(?:\.\d+)?)\s+\d+(?:\.\d+)?\s+EA\s+([A-Z0-9][A-Z0-9.\/-]{2,})\s+EA\s+(\d{1,3}(?:,\d{3})?\.\d{2,4})\s+(\d{1,3}(?:,\d{3})?\.\d{2})(?:\s+\d+(?:\.\d+)?)?/';
        preg_match_all($pattern, $decoded, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            $quantity = $this->parseAmountFromString((string) ($match[1][0] ?? ''));
            $productCode = $this->sanitizeProductCode((string) ($match[2][0] ?? '')) ?? trim((string) ($match[2][0] ?? ''));
            $price = $this->parseAmountFromString((string) ($match[3][0] ?? ''));
            $totalPrice = $this->parseAmountFromString((string) ($match[4][0] ?? ''));

            if (empty($productCode) || is_null($quantity) || is_null($totalPrice)) {
                continue;
            }

            $signature = $productCode . '|' . $quantity . '|' . $totalPrice;
            if (isset($existingSignatures[$signature])) {
                continue;
            }

            $description = null;
            $matchStart = $match[0][1] ?? null;
            $matchLen = strlen((string) ($match[0][0] ?? ''));
            if (!is_null($matchStart)) {
                $tail = substr($decoded, $matchStart + $matchLen, 220);
                if (preg_match('/\n\s*([^\n]{4,140})/', (string) $tail, $descMatch)) {
                    $candidate = trim((string) $descMatch[1]);
                    if (!preg_match('/^(ordered\s+as|carrier|tracking|subtotal|tax|total|qty|ordered|shipped|uom|disp\.?)/i', $candidate)) {
                        $description = $candidate;
                    }
                }
            }

            $items[] = [
                'Description' => $description,
                'ProductCode' => $productCode,
                'TotalPrice' => $totalPrice,
                'Quantity' => $quantity,
                'Price' => $price ?? $totalPrice,
            ];

            $existingSignatures[$signature] = true;
        }

        return array_values($items);
    }

    /**
     * Attempt to fix Quantity=1 defaults by finding the line item's TotalPrice
     * in the raw OCR content and reading the actual quantity from adjacent numbers.
     *
     * Handles invoice-style layouts where quantities appear as columns:
     *   23    0    0    23    3.29    75.67T
     */
    private function supplementQuantitiesFromContent(array $items, string $content): array
    {
        if ($content === '') {
            return $items;
        }

        $hasQuantityOne = false;
        foreach ($items as $item) {
            if (($item['Quantity'] ?? 1) == 1 && ($item['TotalPrice'] ?? 0) > ($item['Price'] ?? 0)) {
                $hasQuantityOne = true;
                break;
            }
        }

        if (! $hasQuantityOne) {
            return $items;
        }

        $decoded = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = preg_split('/\n/', $decoded);

        foreach ($items as $key => &$item) {
            if (($item['Quantity'] ?? 1) != 1 || ! isset($item['TotalPrice']) || ! isset($item['Price'])) {
                continue;
            }

            if ($item['Price'] <= 0 || $item['TotalPrice'] <= $item['Price']) {
                continue;
            }

            $totalStr = number_format($item['TotalPrice'], 2, '.', ',');
            $totalStrNoComma = number_format($item['TotalPrice'], 2, '.', '');

            foreach ($lines as $line) {
                // Look for lines containing the total price (with optional trailing letter like "T")
                if (strpos($line, $totalStr) === false && strpos($line, $totalStrNoComma) === false) {
                    continue;
                }

                // Extract all numbers from the line
                preg_match_all('/(\d+(?:,\d{3})*(?:\.\d+)?)/', $line, $nums);
                if (empty($nums[1]) || count($nums[1]) < 3) {
                    continue;
                }

                $numbers = array_map(fn ($n) => (float) str_replace(',', '', $n), $nums[1]);

                // Find the total price in the number list
                $totalIdx = null;
                foreach ($numbers as $i => $num) {
                    if (abs($num - $item['TotalPrice']) < 0.01) {
                        $totalIdx = $i;
                        break;
                    }
                }

                if ($totalIdx === null) {
                    continue;
                }

                // Find the unit price position
                $priceIdx = null;
                foreach ($numbers as $i => $num) {
                    if ($i === $totalIdx) {
                        continue;
                    }
                    if (abs($num - $item['Price']) < 0.01) {
                        $priceIdx = $i;
                    }
                }

                // Look for a quantity number that, when multiplied by unit price, equals total
                foreach ($numbers as $i => $num) {
                    if ($i === $totalIdx || $i === $priceIdx) {
                        continue;
                    }
                    if ($num < 1 || $num != floor($num)) {
                        continue;
                    }

                    $expectedTotal = $num * $item['Price'];
                    if (abs($expectedTotal - $item['TotalPrice']) < 0.01) {
                        $item['Quantity'] = (int) $num;
                        break 2;
                    }
                }
            }
        }
        unset($item);

        return $items;
    }

    private function deduplicateLineItems(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $normalized[] = [
                'Description' => $item['Description'] ?? null,
                'ProductCode' => $item['ProductCode'] ?? null,
                'TotalPrice' => $item['TotalPrice'] ?? null,
                'Quantity' => $item['Quantity'] ?? 1,
                'Price' => $item['Price'] ?? ($item['TotalPrice'] ?? null),
            ];
        }

        // First pass: exact signature dedupe
        $seen = [];
        $deduped = [];
        foreach ($normalized as $item) {
            $code = trim((string) ($item['ProductCode'] ?? ''));
            $qty = (string) ($item['Quantity'] ?? '');
            $total = (string) ($item['TotalPrice'] ?? '');
            $sig = $code . '|' . $qty . '|' . $total;

            if (isset($seen[$sig])) {
                continue;
            }

            $seen[$sig] = true;
            $deduped[] = $item;
        }

        // Second pass: if a no-code row matches qty+total of a SKU row, keep the SKU row only
        $final = [];
        foreach ($deduped as $index => $item) {
            $code = trim((string) ($item['ProductCode'] ?? ''));
            if ($code !== '') {
                $final[] = $item;
                continue;
            }

            $quantity = (float) ($item['Quantity'] ?? 0);
            $totalPrice = (float) ($item['TotalPrice'] ?? 0);
            $hasSpecificMatch = false;

            foreach ($deduped as $otherIndex => $other) {
                if ($otherIndex === $index) {
                    continue;
                }

                $otherCode = trim((string) ($other['ProductCode'] ?? ''));
                if ($otherCode === '') {
                    continue;
                }

                $otherQty = (float) ($other['Quantity'] ?? 0);
                $otherTotal = (float) ($other['TotalPrice'] ?? 0);

                if (abs($otherQty - $quantity) < 0.001 && abs($otherTotal - $totalPrice) < 0.01) {
                    $hasSpecificMatch = true;
                    break;
                }
            }

            if (!$hasSpecificMatch) {
                $final[] = $item;
            }
        }

        return array_values($final);
    }

    private function extractTotalFromContent(string $content): ?float
    {
        $patterns = [
            '/\b(?:TOTAL|AMOUNT\s+DUE|AMOUNT|BALANCE|CHARGE|PAYMENT)\b[^0-9-]{0,20}(-?\d{1,3}(?:,\d{3})*(?:\.\d{2}))/i',
            '/(-?\d{1,3}(?:,\d{3})*(?:\.\d{2}))\s+(?:master\s*card|visa|amex|discover|card|debit|credit|mc)\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches) && ! empty($matches[1])) {
                $candidate = $matches[1][count($matches[1]) - 1] ?? null;
                if (is_string($candidate)) {
                    $amount = $this->parseAmountFromString($candidate);
                    if (! is_null($amount)) {
                        return $amount;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Parse a numeric amount from a string, handling commas, currency symbols,
     * and parentheses for negatives.
     */
    private function parseAmountFromString(string $value): ?float
    {
        $value = trim($value);
        $negative = false;

        // Handle parentheses indicating negatives, e.g., (12.34)
        if (preg_match('/^\((.*)\)$/', $value, $m)) {
            $negative = true;
            $value = $m[1];
        }

        // Strip everything except digits, comma, dot, and minus
        $value = preg_replace('/[^0-9,.-]/', '', $value) ?? '';

        if ($value === '' || $value === '-' || $value === '.') {
            return null;
        }

        // If both comma and dot present, assume comma is thousands sep
        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = str_replace(',', '', $value);
        } elseif (str_contains($value, ',') && ! str_contains($value, '.')) {
            // If only comma present, assume it's the decimal separator
            $value = str_replace(',', '.', $value);
        } else {
            // No commas or only dot: ensure no stray commas
            $value = str_replace(',', '', $value);
        }

        $number = (float) $value;
        return $negative ? -$number : $number;
    }

    /**
     * Sanitize a ProductCode extracted from OCR.
     * Removes trailing suffixes like "-4.5" that are incorrectly appended to numeric barcodes.
     * Preserves alphanumeric SKU-style codes (e.g. BRK.SM500V).
     */
    private function sanitizeProductCode(?string $code): ?string
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        $code = trim($code);

        // Normalize spaces first
        $code = preg_replace('/\s+/', '', $code) ?? $code;

        // For numeric barcodes only, remove OCR-appended decimal suffixes (e.g. 081099015861-4.5)
        if (preg_match('/^([0-9]{8,})-\d+(?:\.\d+)?$/', $code, $matches)) {
            return $matches[1];
        }

        // Keep common SKU characters and strip other OCR noise
        $code = preg_replace('/[^A-Za-z0-9._\/-]/', '', $code) ?? '';

        return $code !== '' ? $code : null;
    }

    //1-18-2023 combine the next 2 functions into one. Pass type = original or temp
    //Show full-size receipt to anyone with a link
    public function original_receipt($folder, $filename)
    {
        // Build candidate paths preserving case and with common fallbacks
        $candidates = [
            $filename,
            strtolower($filename),
            strtoupper($filename),
        ];

        $resolvedPath = null;
        foreach ($candidates as $name) {
            $try = storage_path('files/'.$folder.'/'.$name);
            if (file_exists($try)) {
                $resolvedPath = $try;
                $filename = $name; // normalize for extension checks
                break;
            }
        }

        if (! $resolvedPath) {
            return response('File not found', 404);
        }

        $ext = strtolower(File::extension($filename));
        if ($ext === 'pdf') {
            return Response::make(file_get_contents($resolvedPath), 200, [
                'Content-Type' => 'application/pdf',
            ]);
        }

        return Image::make($resolvedPath)->response();
    }

    public function temp_receipt($filename)
    {
        $path = storage_path('files/_temp_ocr/'.$filename);

        if (strtolower(File::extension($filename)) === 'pdf') {
            $response = Response::make(file_get_contents($path), 200, [
                'Content-Type' => 'application/pdf',
            ]);
        } else {
            $response = Image::make($path)->response();
        }

        return $response;
    }
}
