<?php

namespace App\Console\Commands;

use App\Services\ContentUnderstandingService;
use Illuminate\Console\Command;

class SyncContentUnderstandingAnalyzer extends Command
{
    protected $signature = 'content-understanding:sync-analyzer
                            {type=receipt             : Analyzer type to sync: receipt or coi}
                            {--base=prebuilt-document  : Prebuilt analyzer to use as base schema}
                            {--model=gpt-4.1           : Completion model name for the analyzer}
                            {--dry-run                 : Print the schema that would be sent without calling the API}';

    protected $description = 'Create or update a Content Understanding analyzer schema (receipt or COI).';

    public function handle(ContentUnderstandingService $cu): int
    {
        $type      = $this->argument('type');
        $baseId    = $this->option('base');
        $model     = $this->option('model');

        if (! in_array($type, ['receipt', 'coi'], true)) {
            $this->error("Unknown analyzer type '{$type}'. Use 'receipt' or 'coi'.");
            return self::FAILURE;
        }

        $analyzerId = $type === 'coi'
            ? config('services.azure_cu.analyzer_id_coi')
            : config('services.azure_cu.analyzer_id');

        $this->info("Syncing '{$type}' analyzer: {$analyzerId}");
        $this->info("Fetching base schema from '{$baseId}'...");

        $base       = $cu->getAnalyzerDefinition($baseId);
        $baseFields = $base['fieldSchema']['fields'] ?? [];

        $definition = $type === 'coi'
            ? $this->buildCoiDefinition($model)
            : $this->buildReceiptDefinition($model, $baseFields);

        if ($this->option('dry-run')) {
            $this->info('--- DRY RUN: schema that would be sent ---');
            $this->line(json_encode($definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        // Ensure resource defaults are configured (required on new Foundry resources).
        $this->info("Setting resource defaults (completion model: {$model})...");
        try {
            $cu->setDefaults([
                'modelDeployments' => ['completion' => $model],
            ]);
            $this->info('Defaults set.');
        } catch (\Throwable $e) {
            $this->warn('Could not set defaults (may already be configured): ' . $e->getMessage());
        }

        $this->info("Creating / updating analyzer '{$analyzerId}'...");
        $this->info('(This may take up to 60 seconds while Azure builds the analyzer)');

        $result = $cu->putAnalyzer($analyzerId, $definition);

        $status = $result['status'] ?? ($result['result']['status'] ?? 'unknown');
        $this->info("Done. Analyzer status: {$status}");
        $this->info("Analyzer ID ready to use: {$analyzerId}");

        return self::SUCCESS;
    }

    private function buildReceiptDefinition(string $model, array $baseFields): array
    {
        $customFields = [

            // ── Standard fields kept from prebuilt-receipt (defined explicitly
            //    so they survive even if the prebuilt removes them in a later version)
            'MerchantName' => [
                'type'        => 'string',
                'description' => 'The name of the merchant or vendor on the receipt.',
                'method'      => 'extract',
            ],
            'MerchantAddress' => [
                'type'        => 'string',
                'description' => 'Full address of the merchant.',
                'method'      => 'extract',
            ],
            'MerchantPhoneNumber' => [
                'type'        => 'string',
                'description' => 'Phone number of the merchant.',
                'method'      => 'extract',
            ],
            'TransactionDate' => [
                'type'        => 'date',
                'description' => 'The date of the transaction.',
                'method'      => 'extract',
            ],
            'TransactionTime' => [
                'type'        => 'string',
                'description' => 'The time of the transaction.',
                'method'      => 'extract',
            ],
            'SubTotal' => [
                'type'        => 'number',
                'description' => 'The subtotal before tax, fees, and tip.',
                'method'      => 'extract',
            ],
            'TotalTax' => [
                'type'        => 'number',
                'description' => 'Total tax amount charged.',
                'method'      => 'extract',
            ],
            'TotalAmount' => [
                'type'        => 'number',
                'description' => 'The grand total amount charged including tax, fees, and tip.',
                'method'      => 'extract',
            ],

            // ── Custom fields ──────────────────────────────────────────────

            'InvoiceId' => [
                'type'        => 'string',
                'description' => 'The primary transaction identifier on the document. Look for labels such as "Transaction Number", "Transaction #", "Invoice Number", "Invoice #", "Receipt Number", "Receipt #", "Order Number", "Order #", "Confirmation Number", or "Trans #". Prefer the Transaction Number if multiple identifiers are present.',
                'method'      => 'extract',
            ],

            'PurchaseOrder' => [
                'type'        => 'string',
                'description' => 'Purchase Order number, PO#, Job Name, Job Number, JobName, PRO JobName, or project reference. This value may appear anywhere on the document including loyalty, rewards, or membership sections. Extract only the short code or number, not the label.',
                'method'      => 'extract',
            ],

            'Tip' => [
                'type'        => 'number',
                'description' => 'Tip or gratuity amount paid.',
                'method'      => 'extract',
            ],

            'Fees' => [
                'type'        => 'number',
                'description' => 'Total of any miscellaneous fees, service charges, delivery fees, or surcharges that are not tax or tip.',
                'method'      => 'extract',
            ],

            // ── Line items ─────────────────────────────────────────────────
            'Items' => [
                'type'        => 'array',
                'description' => 'List of purchased line items on the receipt.',
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'Description' => [
                            'type'        => 'string',
                            'description' => 'Name or description of the item.',
                            'method'      => 'extract',
                        ],
                        'ProductCode' => [
                            'type'        => 'string',
                            'description' => 'SKU, item number, or product code.',
                            'method'      => 'extract',
                        ],
                        'Quantity' => [
                            'type'        => 'number',
                            'description' => 'Quantity purchased.',
                            'method'      => 'extract',
                        ],
                        'Price' => [
                            'type'        => 'number',
                            'description' => 'Unit price per item.',
                            'method'      => 'extract',
                        ],
                        'TotalPrice' => [
                            'type'        => 'number',
                            'description' => 'Total price for this line item (quantity × unit price).',
                            'method'      => 'extract',
                        ],
                    ],
                ],
            ],

            // ── Payment methods table ──────────────────────────────────────
            'Payments' => [
                'type'        => 'array',
                'description' => 'List of payment methods used on this receipt. A receipt may have multiple rows when paying with a combination of methods (e.g. gift card + credit card).',
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'PaymentType' => [
                            'type'        => 'string',
                            'description' => 'Payment method type. One of: CreditCard, DebitCard, Cash, GiftCard, StoreCredit, Check, Other.',
                            'method'      => 'classify',
                            'enum'        => ['CreditCard', 'DebitCard', 'Cash', 'GiftCard', 'StoreCredit', 'Check', 'Other'],
                        ],
                        'LastFour' => [
                            'type'        => 'string',
                            'description' => 'Last four digits of the card number, if applicable.',
                            'method'      => 'extract',
                        ],
                        'Amount' => [
                            'type'        => 'number',
                            'description' => 'Amount paid via this payment method.',
                            'method'      => 'extract',
                        ],
                    ],
                ],
            ],
        ];

        return [
            'description'    => 'Hive 2025 receipt & invoice analyzer — extracts standard receipt fields plus PO/Job, Invoice #, line items, tip, fees, and per-method payment details.',
            'baseAnalyzerId' => 'prebuilt-document',
            'models'         => [
                'completion' => $model,
            ],
            'fieldSchema'    => [
                'name'        => 'HiveReceiptSchema',
                'description' => 'Schema for construction company receipt and invoice OCR.',
                'fields'      => $customFields,
            ],
        ];
    }

    private function buildCoiDefinition(string $model): array
    {
        $policyProperties = fn (string $prefix, string $label) => [
            "{$prefix}_policy_number" => [
                'type'        => 'string',
                'description' => "Policy number for the {$label} policy.",
                'method'      => 'extract',
            ],
            "{$prefix}_eff" => [
                'type'        => 'date',
                'description' => "Effective date of the {$label} policy.",
                'method'      => 'extract',
            ],
            "{$prefix}_exp" => [
                'type'        => 'date',
                'description' => "Expiration date of the {$label} policy.",
                'method'      => 'extract',
            ],
        ];

        $fields = [
            'insured_name' => [
                'type'        => 'string',
                'description' => 'The name of the insured party / company. Usually near the top of the certificate.',
                'method'      => 'extract',
            ],
            'insured_address' => [
                'type'        => 'string',
                'description' => 'Full mailing address of the insured party.',
                'method'      => 'extract',
            ],
            'holder_name' => [
                'type'        => 'string',
                'description' => 'The name of the certificate holder (the company requesting proof of insurance).',
                'method'      => 'extract',
            ],
            'holder_address' => [
                'type'        => 'string',
                'description' => 'Full mailing address of the certificate holder.',
                'method'      => 'extract',
            ],
            'agent_email' => [
                'type'        => 'string',
                'description' => 'Email address of the insurance agent or producer.',
                'method'      => 'extract',
            ],
            'agent_name' => [
                'type'        => 'string',
                'description' => 'Name of the insurance agent or producer.',
                'method'      => 'extract',
            ],
            'agent_agency' => [
                'type'        => 'string',
                'description' => 'Name of the insurance agency.',
                'method'      => 'extract',
            ],
            'agent_agency_address' => [
                'type'        => 'string',
                'description' => 'Full address of the insurance agency.',
                'method'      => 'extract',
            ],
            'agent_phone' => [
                'type'        => 'string',
                'description' => 'Phone number of the insurance agent or agency.',
                'method'      => 'extract',
            ],
            'general_multi' => [
                'type'        => 'array',
                'description' => 'Commercial General Liability policies listed on the certificate. Most COIs have one, but some list multiple.',
                'items'       => [
                    'type'       => 'object',
                    'properties' => $policyProperties('general', 'Commercial General Liability'),
                ],
            ],
            'professional_multi' => [
                'type'        => 'array',
                'description' => 'Professional Liability / Errors & Omissions policies listed on the certificate.',
                'items'       => [
                    'type'       => 'object',
                    'properties' => $policyProperties('professional', 'Professional Liability'),
                ],
            ],
            'workers_multi' => [
                'type'        => 'array',
                'description' => "Workers' Compensation policies listed on the certificate.",
                'items'       => [
                    'type'       => 'object',
                    'properties' => $policyProperties('workers', "Workers' Compensation"),
                ],
            ],
        ];

        return [
            'description'    => 'Hive 2025 Certificate of Insurance (COI) analyzer — extracts insured/holder info, agent details, and policy arrays (general, professional, workers comp).',
            'baseAnalyzerId' => 'prebuilt-document',
            'models'         => [
                'completion' => $model,
            ],
            'fieldSchema'    => [
                'name'        => 'HiveCoiSchema',
                'description' => 'Schema for Certificate of Insurance extraction.',
                'fields'      => $fields,
            ],
        ];
    }
}
