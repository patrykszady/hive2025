<?php

namespace App\Services\Receipts;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Re-reads a receipt's line items from its OCR text when Azure Content
 * Understanding's own Items field came back short: on Climate Solutions'
 * three-page invoice 68405 (expense 28901, 2026-10-20) it returned 8 of the
 * 11 lines although the OCR text held every one of them.
 *
 * The answer is only used when it adds up: the recovered lines must total
 * the printed subtotal to the cent, so a model that misreads, double counts
 * a price breakdown or invents a line changes nothing.
 */
class ReceiptLineItemRecovery
{
    private const TOLERANCE = 0.02;

    private const SYSTEM = <<<'TXT'
You read the OCR text of a supplier invoice, receipt or material order and
list its priced line items.

- One entry per line item that has its own extended amount in the
  subtotal/amount column, across every page, in document order.
- A line's price breakdown (base price, glass, screens, options, finishes
  printed under it with their own amounts) is part of that line, not a
  separate item: use the line's own quantity, unit price and extended amount.
- Leave out subtotals, taxes, delivery, fees, deposits, payments and totals.
- description: the product as printed on the line (no size or color notes
  unless they are part of the product name). vendor_code: the item/SKU code
  when one is printed, else null. line_number: the printed line number, else
  null. unit_price may be null when only the extended amount is printed.
TXT;

    /**
     * Every line of the receipt, or null when they could not be recovered so
     * that they add up to $subtotal.
     *
     * @return list<array{line_number: ?string, description: string, vendor_code: ?string, quantity: float, unit_price: ?float, total_price: float}>|null
     */
    public function recover(string $ocrText, float $subtotal): ?array
    {
        $apiKey = (string) config('services.openai.api_key');

        if (! config('services.openai.receipt_item_recovery') || $apiKey === '' || trim($ocrText) === '' || $subtotal <= 0) {
            return null;
        }

        $model = (string) config('services.openai.receipt_item_recovery_model', 'gpt-4.1');

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['items'],
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['line_number', 'description', 'vendor_code', 'quantity', 'unit_price', 'total_price'],
                        'properties' => [
                            'line_number' => ['type' => ['string', 'null']],
                            'description' => ['type' => 'string'],
                            'vendor_code' => ['type' => ['string', 'null']],
                            'quantity' => ['type' => 'number'],
                            'unit_price' => ['type' => ['number', 'null']],
                            'total_price' => ['type' => 'number'],
                        ],
                    ],
                ],
            ],
        ];

        try {
            $response = Http::withToken($apiKey)
                ->timeout(90)
                ->retry(2, 2000, throw: false)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'temperature' => 0,
                    'messages' => [
                        ['role' => 'system', 'content' => self::SYSTEM],
                        ['role' => 'user', 'content' => mb_substr($ocrText, 0, 60000)],
                    ],
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => ['name' => 'receipt_line_items', 'strict' => true, 'schema' => $schema],
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::warning('Receipt line item recovery threw', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Receipt line item recovery failed', ['status' => $response->status()]);

            return null;
        }

        $items = json_decode((string) data_get($response->json(), 'choices.0.message.content'), true)['items'] ?? null;

        if (! is_array($items) || $items === []) {
            return null;
        }

        $lines = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! is_numeric($item['total_price'] ?? null) || trim((string) ($item['description'] ?? '')) === '') {
                return null;
            }

            $lines[] = [
                'line_number' => isset($item['line_number']) ? trim((string) $item['line_number']) : null,
                'description' => trim((string) $item['description']),
                'vendor_code' => isset($item['vendor_code']) && trim((string) $item['vendor_code']) !== '' ? trim((string) $item['vendor_code']) : null,
                'quantity' => is_numeric($item['quantity'] ?? null) && (float) $item['quantity'] > 0 ? (float) $item['quantity'] : 1.0,
                'unit_price' => is_numeric($item['unit_price'] ?? null) ? round((float) $item['unit_price'], 2) : null,
                'total_price' => round((float) $item['total_price'], 2),
            ];
        }

        $sum = round(array_sum(array_column($lines, 'total_price')), 2);

        if (abs($sum - $subtotal) > self::TOLERANCE) {
            Log::info('Receipt line item recovery did not add up', ['subtotal' => $subtotal, 'sum' => $sum, 'lines' => count($lines)]);

            return null;
        }

        return $lines;
    }
}
