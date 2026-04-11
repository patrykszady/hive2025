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

        if (! in_array($type, ['receipt', 'coi', 'material_order'], true)) {
            $this->error("Unknown analyzer type '{$type}'. Use 'receipt', 'coi', or 'material_order'.");
            return self::FAILURE;
        }

        $analyzerId = match ($type) {
            'coi'            => config('services.azure_cu.analyzer_id_coi'),
            'material_order' => config('services.azure_cu.analyzer_id_material_order'),
            default          => config('services.azure_cu.analyzer_id'),
        };

        $this->info("Syncing '{$type}' analyzer: {$analyzerId}");
        $this->info("Fetching base schema from '{$baseId}'...");

        $base       = $cu->getAnalyzerDefinition($baseId);
        $baseFields = $base['fieldSchema']['fields'] ?? [];

        $definition = match ($type) {
            'coi'            => $this->buildCoiDefinition($model),
            'material_order' => $this->buildMaterialOrderDefinition($model),
            default          => $this->buildReceiptDefinition($model, $baseFields),
        };

        if ($this->option('dry-run')) {
            $this->info('--- DRY RUN: schema that would be sent ---');
            $this->line(json_encode($definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        // Ensure resource defaults are configured (required on new Foundry resources).
        $this->info("Setting resource defaults (completion model: {$model})...");
        try {
            $cu->setDefaults([
                'modelDeployments' => [
                    'completion' => $model,
                    $model       => $model,
                ],
            ]);
            $this->info('Defaults set.');
        } catch (\Throwable $e) {
            $this->warn('Could not set defaults (may already be configured): ' . $e->getMessage());
        }

        $this->info("Creating / updating analyzer '{$analyzerId}'...");
        $this->info('(This may take up to 60 seconds while Azure builds the analyzer)');

        try {
            $result = $cu->putAnalyzer($analyzerId, $definition);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), '409')) {
                $this->warn('Analyzer already exists — deleting and recreating...');
                $cu->deleteAnalyzer($analyzerId);
                sleep(2);
                $result = $cu->putAnalyzer($analyzerId, $definition);
            } else {
                throw $e;
            }
        }

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
                'description' => 'The primary transaction identifier on the document. Look for labels such as "Transaction Number", "Transaction #", "Invoice Number", "Invoice #", "Receipt Number", "Receipt #", "Order Number", "Order #", "Confirmation Number", or "Trans #". Prefer the Transaction Number if multiple identifiers are present. When the receipt header contains a multi-part number like "1913  00062  49221" (store, register, and transaction), return the FULL string with spaces (e.g. "1913 00062 49221"), not just the last segment.',
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
                'description' => 'List of purchased line items on the receipt. Each physical item should appear exactly once. Some retailers (e.g. Home Depot, Lowe\'s) print a barcode with an abbreviated name on one line and the full product description on the next line — these belong to the SAME item and must be merged into a single entry, not treated as two separate items. Only lines that have a price are actual items.',
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'Description' => [
                            'type'        => 'string',
                            'description' => 'Full name or description of the item. If the receipt shows a short/abbreviated name on one line and a longer product description on the next line, concatenate both lines into a single description. Do NOT include the product code, barcode, UPC, or item number in the description — those belong in the ProductCode field. Also strip any single-letter return-policy indicators such as <A>, <B>, <C>, etc. Example: given lines "084305382269 1QT BUCKET <A>" and "1QT HDX ALL PURP MIXING CONTAINER", the Description should be "1QT BUCKET 1QT HDX ALL PURP MIXING CONTAINER".',
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

    private function buildMaterialOrderDefinition(string $model): array
    {
        $fields = [
            'VendorName' => [
                'type'        => 'string',
                'description' => 'The vendor/supplier company name from the document header, logo, or "Remit To" section (e.g. "STUDIO 41/KOHLER STORE", "Virginia Tile Company", "Ferguson Enterprises"). Extract the FIRST occurrence from page 1 only — ignore repeated headers on subsequent pages.',
                'method'      => 'extract',
            ],
            'OrderNumber' => [
                'type'        => 'string',
                'description' => 'The primary document identifier. Look for labels: "ORDER NUMBER", "ORDER NO", "QUOTE NUMBER", "QUOTE NO", "INVOICE NUMBER", "INVOICE NO", "CONFIRMATION NUMBER", "CONFIRMATION#", "PO NUMBER". Extract from page 1 header only.',
                'method'      => 'extract',
            ],
            'OrderDate' => [
                'type'        => 'date',
                'description' => 'The date the order was placed or quoted. Look for labels: "ORDER DATE", "ORDER DT", "QUOTE DATE", "DATE REQ", "DATE ORDERED", "INVOICE DATE". Use the ORIGINAL order/quote date, NOT a print date, acknowledgment date, or ship date. Extract from page 1 header only.',
                'method'      => 'extract',
            ],
            'ShipTo' => [
                'type'        => 'string',
                'description' => 'The full "SHIP TO" address block — name, street, city/state/zip. Combine all lines into one newline-separated string. Extract from page 1 only.',
                'method'      => 'extract',
            ],
            'BillTo' => [
                'type'        => 'string',
                'description' => 'The full "BILL TO" / "QUOTE TO" / "SOLD TO" address block — name, street, city/state/zip. Combine all lines into one newline-separated string. Extract from page 1 only.',
                'method'      => 'extract',
            ],
            'CustomerPO' => [
                'type'        => 'string',
                'description' => 'Customer purchase order, job reference, or project reference. Look for labels: "CUSTOMER ORDER NUMBER", "CUSTOMER ORDER NO", "CUSTOMER ORD NUMBER", "PO NUMBER", "PO#", "CUST PO", "CUSTOMER PO", "RELEASE NUMBER", "RELEASE NO", "JOB NAME", "JOB#", "PROJECT". If both a Customer Order Number and a Release Number/Job Name are present, combine them as "Value1 / Value2" (e.g. "EGGER / LONG GROVE"). If only one is present, return just that value. IMPORTANT: These fields appear in the PAGE HEADER metadata section above the items table — do NOT confuse them with product data.',
                'method'      => 'extract',
            ],
            'Selection' => [
                'type'        => 'string',
                'description' => 'Selection number or Selection# from the order header (e.g. "300693"). This is a vendor-specific reference code. Only extract if a field explicitly labeled "Selection", "Selection#", or "SEL#" exists. Return null if no such field is present — do NOT guess or extract unrelated values.',
                'method'      => 'extract',
            ],
            'SubTotal' => [
                'type'        => 'number',
                'description' => 'The subtotal before tax. Look for labels: "Subtotal", "Sub Total", "Sub-Total", "Total Price", "Merchandise Total", "Net Amount". Usually in the summary section on the LAST page. Extract the numeric dollar amount.',
                'method'      => 'extract',
            ],
            'Taxes' => [
                'type'        => 'array',
                'description' => 'Individual tax line items from the summary section. Each distinct tax line is a separate entry. If only one aggregate tax line exists, return a single entry.',
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'TaxType' => [
                            'type'        => 'string',
                            'description' => 'The tax label as printed (e.g. "STATE TAX", "COUNTY TAX", "Est Tax", "SALES TAX").',
                            'method'      => 'extract',
                        ],
                        'Amount' => [
                            'type'        => 'number',
                            'description' => 'The dollar amount for this tax line.',
                            'method'      => 'extract',
                        ],
                    ],
                ],
                'method'      => 'extract',
            ],
            'TotalAmount' => [
                'type'        => 'number',
                'description' => 'The grand total including tax, shipping, and all charges. Look for labels: "Amount Due", "Total Due", "Grand Total", "Total Amount", "Balance Due", "TOTAL". Usually the largest/final number in the summary section on the last page.',
                'method'      => 'extract',
            ],
            'Deposit' => [
                'type'        => 'number',
                'description' => 'Any deposit or prepayment amount already applied. Look for labels: "Deposit", "Deposit Applied", "Payment Received", "Amount Paid", "Prepayment". Return null if no deposit field exists — do NOT return 0.',
                'method'      => 'extract',
            ],
            'BalanceDue' => [
                'type'        => 'number',
                'description' => 'The remaining balance after deposits. Look for labels: "Balance Due", "Amount Remaining", "Net Due". Return null if no such field exists — do NOT return 0.',
                'method'      => 'extract',
            ],
            'Shipping' => [
                'type'        => 'number',
                'description' => 'Total shipping, handling, and delivery charges. Look for labels: "S&H CHGS", "Shipping", "Freight", "Delivery", "Handling", "ENERGY HANDLING SCHG", "Freight Charges". SUM all shipping/handling/delivery line amounts into one total. Return null if no shipping charges exist — do NOT return 0.',
                'method'      => 'extract',
            ],
            'Fees' => [
                'type'        => 'number',
                'description' => 'Additional fees or surcharges that are NOT shipping, tax, or product line items (e.g. fuel surcharge, environmental fee, restocking fee, convenience fee). Return null if no fees exist — do NOT return 0.',
                'method'      => 'extract',
            ],
            'Items' => [
                'type'        => 'array',
                'description' => implode(' ', [
                    'All product line items from the order, spanning ALL pages.',

                    'WHAT IS AN ITEM: A table row that has BOTH a non-empty quantity cell AND a non-empty part/item number cell.',
                    'Each distinct part number is one item — do NOT merge rows with different part numbers.',

                    'MULTI-PAGE DOCUMENTS: Documents may have 2+ pages.',
                    'Each page repeats the same header block (vendor name, addresses, metadata, column headers) — IGNORE repeated headers on pages 2, 3, etc.',
                    'A "Continued on Next Page" footer simply means the items table continues on the next page.',
                    'Collect items from ALL pages into one flat array.',

                    'PAGE NUMBER TRAP: Each page header contains "PASE NO" or "PAGE NO" followed by a bare digit ("1", "2", "3") — this is the PAGE NUMBER.',
                    'It appears OUTSIDE the items table, in the header area near "ORDER TO:".',
                    'NEVER treat a page number as a quantity, line number, or any item field.',
                    'Item quantities are ONLY inside <td> cells of the items data table.',

                    'CONTINUATION ROWS: Rows where BOTH the quantity cell AND the part number cell are EMPTY but the description cell has text are NOT new items.',
                    'They are continuation text for the PRECEDING item — ALWAYS attribute them to the item directly above.',
                    'A continuation row\'s description cell may contain: (a) product-name text only → merge into Description, (b) disclaimer text only → merge into Notes, (c) MIXED content (product text + disclaimer + possibly TAG) → split appropriately.',
                    'Product-name text examples: "MULTI-FUNCTION SHOWER HEAD", "TRIM - LEVER POLISHED CHROME", "KIT, STOP", "-S-SHOWER COLUMN POLISHED CHROME", "CHROME".',
                    'Disclaimer text examples: "* SPECIAL ORDER/NO RETURNS *", "NON-RETURNABLE", "NON-CANCELLABLE", "FINAL SALE".',
                    'Mixed cell example: "CHROME<br>* SPECIAL ORDER/NO RETURNS *<br>TAG: HALL BATH" → "CHROME" goes to Description, "* SPECIAL ORDER/NO RETURNS *" goes to Notes, "HALL BATH" sets the Area for FOLLOWING items.',
                    'Mixed cell example: "MULTI-FUNCTION SHOWER HEAD * SPECIAL ORDER/NO RETURNS *" → "MULTI-FUNCTION SHOWER HEAD" is Description, "* SPECIAL ORDER/NO RETURNS *" is Notes.',
                    'CROSS-PAGE CONTINUATIONS: When a page ends mid-item and the next page starts with empty-qty/empty-part rows, those rows continue the LAST item from the previous page — including any disclaimer text for Notes.',
                    'CRITICAL: There may be MULTIPLE consecutive continuation rows for one item. Process ALL of them. Do NOT skip any.',
                    'CRITICAL: "* SPECIAL ORDER/NO RETURNS *" appearing on a continuation row MUST be captured as Notes for the preceding item. This is the most common note pattern.',
                    'Tip: After identifying each item, look at EVERY subsequent row until you hit a row with a non-empty qty/part cell or a TAG-only row — those continuation rows all belong to the current item.',

                    'EXCLUDE from items: (1) "TAG:" section header lines — those are area designators, not products.',
                    '(2) Continuation rows — merge them into the preceding item as described above.',
                    '(3) Shipping/freight/handling/surcharge lines — those go in the top-level Shipping field.',
                    '(4) Subtotal, tax, and total summary lines.',

                    'IMPORTANT: Extract Quantity, UnitPrice, and TotalPrice for each item from ITS OWN table row only.',
                ]),
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'LineNumber' => [
                            'type'        => 'string',
                            'description' => 'The line number or sequence number from the order (e.g. "0010", "0020", "1", "2"). Only extract if a dedicated LINE, LINE#, or LINE NO column exists. Return null otherwise.',
                            'method'      => 'extract',
                        ],
                        'ItemNumber' => [
                            'type'        => 'string',
                            'description' => 'The item number, SKU, part number, or product code from the PART NO / ITEM# / SKU / CATALOG# column. Examples: "3408127", "VTSMSARPENNYH", "CAEPOER1224R", "489114". Extract the raw value as printed. Do NOT extract the Customer Number (e.g. "602275") from the header — that is account metadata, not a product SKU.',
                            'method'      => 'extract',
                        ],
                        'Description' => [
                            'type'        => 'string',
                            'description' => implode(' ', [
                                'The full product name/description as a single string.',
                                'Concatenate the main description text with any continuation-row product text (from rows with empty qty and part cells that contain product-name words).',
                                'CROSS-PAGE CONTINUATION: If this is the last item on a page and the next page starts with empty-qty/empty-part rows containing product text, append that text.',
                                'Example: Item "BEAUCLERE : H2OKINETIC(R)" on page 1 + continuation row "MULTI-FUNCTION SHOWER HEAD" on page 2 = "BEAUCLERE : H2OKINETIC(R) MULTI-FUNCTION SHOWER HEAD".',
                                'Example: Item "PURIST VALVE" + continuation "TRIM - LEVER POLISHED CHROME" = "PURIST VALVE TRIM - LEVER POLISHED CHROME".',
                                'Example: Item "KOHLER UNIVERSAL RITE-TEMP PB VALV" + continuation "KIT, STOP" = "KOHLER UNIVERSAL RITE-TEMP PB VALV KIT, STOP".',
                                'EXCLUDE from Description: (1) Disclaimer text like "* SPECIAL ORDER/NO RETURNS *", "NON-RETURNABLE", "NON-CANCELLABLE", "FINAL SALE" — put in Notes instead.',
                                '(2) "TAG:" lines — those are area designators, not description text.',
                                '(3) Serial numbers, lead time text, coverage info — put in Notes instead.',
                                'MIXED CELLS: When a single cell or continuation row contains BOTH product text AND disclaimer text, split them. Product words go into Description, disclaimer text goes into Notes.',
                                'Example: "CHROME<br>* SPECIAL ORDER/NO RETURNS *" → Description gets "CHROME", Notes gets "* SPECIAL ORDER/NO RETURNS *".',
                                'Example: "MULTI-FUNCTION SHOWER HEAD * SPECIAL ORDER/NO RETURNS *" → Description gets "MULTI-FUNCTION SHOWER HEAD", Notes gets "* SPECIAL ORDER/NO RETURNS *".',
                                'Strip leading asterisks "*" from the product name.',
                                'Strip short vendor/supplier code prefixes (e.g. "S016", "S020") that precede the product name.',
                            ]),
                            'method'      => 'extract',
                        ],
                        'Area' => [
                            'type'        => 'string',
                            'description' => implode(' ', [
                                'The room or area this item is designated for.',
                                'DETERMINATION RULES (in priority order):',
                                '(1) Per-item area: A comment/note line directly below THIS item\'s description row that names a room (e.g. "PRIMARY BATH", "SHOWER WALL", "KITCHEN", "LAUNDRY"). If present, use this.',
                                '(2) TAG section header: Lines like "TAG: PRIMARY BATH", "TAG: HALL BATH", "TAG: MASTER BEDROOM" act as section dividers.',
                                'A TAG applies to ALL items that follow it until the NEXT TAG appears.',
                                'CRITICAL: Each item gets exactly ONE area — the most recent TAG that precedes it.',
                                'An item that appears BETWEEN "TAG: PRIMARY BATH" and "TAG: HALL BATH" belongs to "PRIMARY BATH" only.',
                                'An item that appears AFTER "TAG: HALL BATH" belongs to "HALL BATH" only.',
                                'NEVER combine two TAG values. NEVER assign both the preceding and following TAG to the same item.',
                                'Return just the area name without the "TAG:" prefix.',
                                'Strip vendor-specific prefixes (e.g. "C*") and leading/trailing dashes.',
                                'If no area can be determined, return null.',
                            ]),
                            'method'      => 'extract',
                        ],
                        'Notes' => [
                            'type'        => 'string',
                            'description' => implode(' ', [
                                'Disclaimers, special order notices, return policies, cancellation notices, serial numbers, lead times, coverage info, and any other non-product-name, non-area text associated with this item.',
                                'COMMON PATTERNS (vendor-agnostic): "* SPECIAL ORDER/NO RETURNS *", "SPECIAL ORDER", "NON-RETURNABLE", "NON-CANCELLABLE", "FINAL SALE", "SOLD AS-IS", "ALLOW X-X WEEKS", "LEAD TIME: X WEEKS", "CALL FOR PRICING", warranty text, special instructions.',
                                'WHERE TO FIND NOTES — check ALL of these locations for this item:',
                                '(1) INLINE in the same <td> cell as the Description, after a <br> or after the product-name text. Example: "*BEAUCLERE : SHOWER ARM AND FLANGE<br>* SPECIAL ORDER/NO RETURNS *" — the part after <br> is Notes.',
                                '(2) SEPARATE CONTINUATION ROW: A <tr> row IMMEDIATELY following this item where the qty and part-number cells are EMPTY and the description cell contains ONLY disclaimer text. Example: a row with <td></td><td></td><td>* SPECIAL ORDER/NO RETURNS *</td> — that entire text is Notes for the PRECEDING item.',
                                '(3) MIXED CONTINUATION ROW: A continuation row may contain BOTH product-name text AND disclaimer text. Example: "MULTI-FUNCTION SHOWER HEAD * SPECIAL ORDER/NO RETURNS *" — split into Description ("MULTI-FUNCTION SHOWER HEAD") and Notes ("* SPECIAL ORDER/NO RETURNS *").',
                                '(4) CROSS-PAGE: When a page ends with this item and the NEXT page starts with continuation rows, check those rows too.',
                                'CRITICAL: Do NOT skip continuation rows. Every "* SPECIAL ORDER/NO RETURNS *" or similar disclaimer MUST be captured in the Notes of the item it follows.',
                                'EXCLUDES: Product description text, area/TAG designators, and pricing.',
                                'Return null ONLY if no disclaimer/notice text exists for this item anywhere.',
                            ]),
                            'method'      => 'extract',
                        ],
                        'ETA' => [
                            'type'        => 'date',
                            'description' => 'The expected ship/delivery/availability date for this line item. Look in columns labeled: "SHIP DATE", "SHIP DT", "ETA", "EST DELIVERY", "EST SHIP", "DELIVERY DATE", "DELIVERY DT", "AVAIL DATE", "AVAIL DT", "READY DATE", "PICK UP DATE", "DUE DATE", "PROMISE DATE", "DISPATCH DATE". Also check for dates adjacent to status text. Dates may be in various formats — extract as a date and ignore trailing asterisks or other suffixes. Return null if no per-item date exists — do NOT use the header-level ship date.',
                            'method'      => 'extract',
                        ],
                        'Quantity' => [
                            'type'        => 'string',
                            'description' => implode(' ', [
                                'The quantity cell value for THIS line item, extracted AS-IS from the items data table.',
                                'WHERE TO FIND IT: The FIRST column of the items table (header: "ORDER QTY" / "ORDER GTY" / "QTY" / "QUANTITY"), in the SAME <tr> row as this item\'s part number.',
                                'Return the FULL cell text including the unit suffix — do NOT strip letters.',
                                'Examples of correct values: "2ea", "lea", "1ea", "10sf", "5pc", "3lf", "4bx".',
                                'The value ALWAYS contains letters (ea, sf, pc, lf, bx, ct) — a bare digit alone ("1", "2", "3") with NO letters is NEVER a valid quantity cell.',
                                'CRITICAL: The page header area contains "PASE NO" / "PAGE NO" followed by a bare digit ("1", "2", "3") — that is the page number, NOT a quantity.',
                                'A bare standalone digit without a unit suffix means you are reading from the wrong location.',
                                'Look for values WITH letter suffixes inside <td> cells of the ORDER QTY column.',
                            ]),
                            'method'      => 'extract',
                        ],
                        'Unit' => [
                            'type'        => 'string',
                            'description' => implode(' ', [
                                'Unit of measure. Extract ONLY from: (1) A dedicated "UM" or "UNIT" column in this item\'s row, or (2) The alphabetical suffix on the quantity value: "2ea" = "EA", "10sf" = "SF", "5pc" = "PC", "3lf" = "LF", "4bx" = "BX".',
                                'Valid values: EA, SF, PC, LF, BX, CT, SY, RL, CS, PR, SET, GAL, FT, IN, CM.',
                                'CRITICAL: Do NOT extract from page header cells or metadata fields.',
                                'Text like "RELEASE NUMBER", "RELEASE AUNBER", "SALESPERSON", "CUSTOMER NUMBER", "CUSTOMER ORDER NUMBER", "LONG GROVE" are HEADER labels/values — NOT units.',
                                'Normalize to uppercase. Return null if no unit can be determined.',
                            ]),
                            'method'      => 'extract',
                        ],
                        'UnitPrice' => [
                            'type'        => 'number',
                            'description' => 'The per-unit price from the "Net Prc" / "Unit Price" / "Price" column of THIS item\'s row. This is the price for ONE unit. VALIDATION: UnitPrice × Quantity should approximately equal TotalPrice.',
                            'method'      => 'extract',
                        ],
                        'TotalPrice' => [
                            'type'        => 'number',
                            'description' => 'The extended/total price from the "Ext Prc" / "Ext Prd" / "Amount" / "Total" column of THIS item\'s row. This is Quantity × UnitPrice. VALIDATION: Should equal Quantity × UnitPrice within rounding tolerance.',
                            'method'      => 'extract',
                        ],
                        'Status' => [
                            'type'        => 'string',
                            'description' => 'The order/fulfillment status from the STATUS column of this item\'s row. Common values: "Back Ord", "BO", "Availabl", "Available", "Open", "Received", "Recv", "Shipped", "Cancelled", "Cancel", "Partial". Extract the EXACT text as printed — do not normalize abbreviations. Return null if no STATUS column exists or the cell is empty.',
                            'method'      => 'extract',
                        ],
                    ],
                ],
            ],
        ];

        return [
            'description'    => implode(' ', [
                'Hive material order analyzer — extracts vendor info, order details, and line items with area/room designations from construction material orders, quotes, and invoices.',
                'DOCUMENT STRUCTURE: These documents may span multiple pages. ALL pages are ONE single order.',
                'Each page repeats the same header block (vendor name, order number, addresses, column headers) — ignore duplicated headers on pages 2+.',
                'Line items continue seamlessly across page breaks via "Continued on Next Page" footers.',
                'PAGE NUMBER WARNING: Each page header may contain "PASE NO" or "PAGE NO" with a bare digit ("1", "2", "3") — this is strictly the page number.',
                'It is NOT an item quantity, line number, or product data. NEVER extract it into any item field.',
                'Item quantities are ONLY found inside cells of the items data table (e.g. "2ea", "lea", "1ea").',
                'OCR NOISE: The underlying OCR may produce garbled characters (e.g. "Û" for ®/™, "GTY" for "QTY", "AUNBER" for "NUMBER", "TERNS" for "TERMS", "|" for inch marks).',
                'Read through OCR noise to extract the intended meaning.',
                'AREA ASSIGNMENT: "TAG:" lines define room sections — each item inherits the single most recent TAG above it, never multiple TAGs.',
            ]),
            'baseAnalyzerId' => 'prebuilt-document',
            'config'         => [
                'enableLayout' => true,
            ],
            'models'         => [
                'completion' => $model,
            ],
            'fieldSchema'    => [
                'name'        => 'HiveMaterialOrderSchema',
                'description' => implode(' ', [
                    'Schema for construction material order, quote, and invoice extraction with room/area mapping.',
                    'The entire PDF (all pages) is ONE document. Repeated page headers are structural noise — extract header fields from page 1 only.',
                    'PAGE NUMBERS: Bare digits after "PASE NO"/"PAGE NO" in page headers are page numbers, not item data.',
                    'Item quantities come from cells in the ORDER QTY / ORDER GTY column: "2ea" (=2), "lea" (=1), "10sf" (=10).',
                    'AREA: Each item gets exactly one area from the most recent preceding "TAG:" section header. Never combine multiple TAGs.',
                    'NULL CONVENTION: Omit fields that have no value rather than returning 0 or empty string.',
                ]),
                'fields'      => $fields,
            ],
        ];
    }
}
