<?php

namespace App\Services\Estimates;

use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Reads a subcontractor's estimate or invoice — a PDF, a photo or a
 * spreadsheet — into its line items with OpenAI, the way receipts are read
 * (ReceiptLineItemRecovery): one chat completion with a strict JSON schema.
 * A PDF or image goes to the model as is (it reads scans too); a
 * spreadsheet is laid out as text first.
 */
class SubDocumentReader
{
    private const SYSTEM = <<<'PROMPT'
You read construction subcontractors' estimates, proposals and invoices for a general contractor.
Extract EVERY line item exactly as the document lists it: keep the description's wording, the quantity, the unit if one is shown (pieces, sq.ft., li.ft., hours…), the unit price and the extended amount. Number the items in document order starting at 1.
Lump sums with no quantity get quantity 1 when the document shows "1", otherwise null. Never invent quantities or prices that are not on the document; leave them null.
Do not include subtotal, tax, total, deposit or payment lines as items — report the document total and any payments separately.
Also report who issued the document (the company name), whether it is an invoice, an estimate/proposal, or something else, its number, its date (YYYY-MM-DD) and the project address or job name it refers to.
Return JSON only, matching the schema.
PROMPT;

    /**
     * @return array{vendor_name: ?string, document_type: string, document_number: ?string, document_date: ?string, project_reference: ?string, total: ?float, payments_to_date: ?float, items: list<array{index: int, description: string, quantity: ?float, unit: ?string, unit_price: ?float, amount: ?float}>, model: string}
     */
    public function read(string $absolutePath, string $mimeType, string $originalName): array
    {
        $apiKey = (string) config('services.openai.api_key') ?: throw new RuntimeException('OpenAI API key missing (services.openai.api_key)');
        $model = (string) config('services.openai.sub_document_model', 'gpt-4.1');

        $response = Http::withToken($apiKey)
            ->timeout(180)
            ->retry(2, 3000, throw: false)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'temperature' => 0,
                'messages' => [
                    ['role' => 'system', 'content' => self::SYSTEM],
                    ['role' => 'user', 'content' => $this->userContent($absolutePath, $mimeType, $originalName)],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => ['name' => 'sub_document', 'strict' => true, 'schema' => self::schema()],
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('sub_document_read_http_'.$response->status().': '.mb_substr((string) $response->body(), 0, 300));
        }

        $parsed = json_decode((string) data_get($response->json(), 'choices.0.message.content'), true);

        if (! is_array($parsed) || ! is_array($parsed['items'] ?? null)) {
            throw new RuntimeException('sub_document_read_invalid_json');
        }

        // Numbered here, in document order: the model's own numbering restarts
        // on a second page (Smartech's two-page invoice), and the matcher
        // refers to items by number.
        $items = [];
        foreach ($parsed['items'] as $item) {
            $description = trim((string) ($item['description'] ?? ''));
            if ($description === '') {
                continue;
            }
            $items[] = [
                'index' => count($items) + 1,
                'description' => $description,
                'quantity' => self::number($item['quantity'] ?? null),
                'unit' => isset($item['unit']) && trim((string) $item['unit']) !== '' ? trim((string) $item['unit']) : null,
                'unit_price' => self::number($item['unit_price'] ?? null),
                'amount' => self::number($item['amount'] ?? null),
            ];
        }

        return [
            'vendor_name' => self::text($parsed['vendor_name'] ?? null),
            'document_type' => in_array($parsed['document_type'] ?? null, ['invoice', 'estimate', 'other'], true) ? $parsed['document_type'] : 'other',
            'document_number' => self::text($parsed['document_number'] ?? null),
            'document_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($parsed['document_date'] ?? '')) ? $parsed['document_date'] : null,
            'project_reference' => self::text($parsed['project_reference'] ?? null),
            'total' => self::number($parsed['total'] ?? null),
            'payments_to_date' => self::number($parsed['payments_to_date'] ?? null),
            'items' => $items,
            'model' => $model,
        ];
    }

    /**
     * The document as the model takes it: a PDF as a file, an image as an
     * image, anything tabular (xlsx, xls, csv) laid out as text.
     *
     * @return list<array<string, mixed>>
     */
    protected function userContent(string $absolutePath, string $mimeType, string $originalName): array
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $lead = ['type' => 'text', 'text' => 'Document: '.$originalName];

        if ($mimeType === 'application/pdf' || $extension === 'pdf') {
            return [$lead, ['type' => 'file', 'file' => [
                'filename' => $originalName,
                'file_data' => 'data:application/pdf;base64,'.base64_encode((string) file_get_contents($absolutePath)),
            ]]];
        }

        if (str_starts_with($mimeType, 'image/')) {
            return [$lead, ['type' => 'image_url', 'image_url' => [
                'url' => 'data:'.$mimeType.';base64,'.base64_encode((string) file_get_contents($absolutePath)),
                'detail' => 'high',
            ]]];
        }

        if (in_array($extension, ['xlsx', 'xls', 'csv', 'ods'], true)) {
            return [$lead, ['type' => 'text', 'text' => mb_substr(self::spreadsheetText($absolutePath), 0, 60000)]];
        }

        return [$lead, ['type' => 'text', 'text' => mb_substr((string) file_get_contents($absolutePath), 0, 60000)]];
    }

    /** Every sheet as lines of " | "-separated cells, blank rows dropped. */
    public static function spreadsheetText(string $absolutePath): string
    {
        $reader = IOFactory::createReaderForFile($absolutePath);
        $reader->setReadDataOnly(true);
        $workbook = $reader->load($absolutePath);
        $out = [];

        foreach ($workbook->getAllSheets() as $sheet) {
            $out[] = '=== Sheet: '.$sheet->getTitle();

            foreach ($sheet->toArray(null, true, true, false) as $row) {
                $cells = array_map(fn ($cell) => trim((string) $cell), $row);
                if (implode('', $cells) !== '') {
                    $out[] = implode(' | ', $cells);
                }
            }
        }

        return implode("\n", $out);
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $nullableNumber = ['type' => ['number', 'null']];
        $nullableString = ['type' => ['string', 'null']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['vendor_name', 'document_type', 'document_number', 'document_date', 'project_reference', 'total', 'payments_to_date', 'items'],
            'properties' => [
                'vendor_name' => $nullableString,
                'document_type' => ['type' => 'string', 'enum' => ['invoice', 'estimate', 'other']],
                'document_number' => $nullableString,
                'document_date' => $nullableString,
                'project_reference' => $nullableString,
                'total' => $nullableNumber,
                'payments_to_date' => $nullableNumber,
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['index', 'description', 'quantity', 'unit', 'unit_price', 'amount'],
                        'properties' => [
                            'index' => ['type' => 'integer'],
                            'description' => ['type' => 'string'],
                            'quantity' => $nullableNumber,
                            'unit' => $nullableString,
                            'unit_price' => $nullableNumber,
                            'amount' => $nullableNumber,
                        ],
                    ],
                ],
            ],
        ];
    }

    private static function number(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 250);
    }
}
