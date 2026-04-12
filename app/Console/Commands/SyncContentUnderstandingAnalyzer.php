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
                'description' => 'The vendor/supplier company name. ALWAYS prefer the "REMIT TO" section — extract the legal entity name from there (e.g. "VIRGINIA TILE COMPANY LLC", "Ferguson Enterprises", "STUDIO 41/KOHLER STORE"). The REMIT TO name is the correct legal name with proper word spacing. Do NOT use the header logo text which often runs words together (e.g. "VIRGINIATILE" is wrong, "VIRGINIA TILE COMPANY LLC" is correct). Only fall back to the header/logo if no REMIT TO section exists. Extract from page 1 only.',
                'method'      => 'extract',
            ],
            'OrderNumber' => [
                'type'        => 'string',
                'description' => 'The primary document identifier. Look for labels: "ORDER NUMBER", "ORDER NO", "ORDER#", "QUOTE NUMBER", "QUOTE NO", "INVOICE NUMBER", "INVOICE NO", "CONFIRMATION NUMBER", "CONFIRMATION#", "PO NUMBER". Extract from page 1 header only.',
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
                'description' => implode(' ', [
                    'Customer purchase order, job reference, or project reference.',
                    'Look in the PAGE HEADER metadata table (above the items table) for columns labeled "CUST", "CUSTOMER", "PO#/JOB", "CUSTOMER ORDER NUMBER", "PO NUMBER", "PO#", "RELEASE NUMBER", "JOB NAME", "JOB#", "PROJECT".',
                    'CRITICAL: "CUST" and "PO#/JOB" are TWO SEPARATE COLUMNS whose values must be COMBINED into one string.',
                    'The CUST column often contains a customer name or code. The PO#/JOB column contains a job or project reference.',
                    'Read the values from BOTH columns, then merge them, removing any duplicated words.',
                    'Example: CUST="EGGER" PO#/JOB="EGGER RESI" → return "EGGER RESI" (not just "RESI").',
                    'Example: CUST="EGGER EGGER" PO#/JOB="RESI" → return "EGGER RESI" (deduplicate "EGGER").',
                    'Example: CUST="SMITH" PO#/JOB="SMITH KITCHEN" → return "SMITH KITCHEN".',
                    'Example: CUST="" PO#/JOB="REMODEL 2026" → return "REMODEL 2026".',
                    'If the table cell text is mangled or merged (e.g. "EGGER EGGER" + "RESI" in adjacent cells), still combine unique words.',
                    'Do NOT confuse these header-table columns with product item data.',
                    'Do NOT return just the PO#/JOB value if the CUST column has a non-empty value.',
                ]),
                'method'      => 'generate',
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
                'description' => implode("\n", [
                    'Extract ALL product line items from the ENTIRE document. All pages form ONE continuous table — pages 2, 3, etc. continue the same table from page 1.',

                    'HOW TO IDENTIFY ITEMS: Each item has a 4-digit LINE number (0010, 0020, 0030, ...) in the LINE column, an ITEM# code, and numeric values in QTY ORD, UNIT PRICE, and PRICE columns. Only rows with ALL of these are real items. You must output exactly one object per real item.',

                    'CRITICAL — ITEMS AFTER PAGE BREAKS: A page that starts with "continued from previous page" text and notes for a prior item will ALSO contain NEW items below that continuation block. You MUST extract those new items with ALL their fields (LineNumber, ItemNumber, Quantity, UnitPrice, TotalPrice, Status, ETA). Do NOT skip items just because they appear on the same page as continuation text. The continuation text belongs to the previous item; the new LINE number below it starts a completely independent item that MUST have all its tabular fields extracted from the table columns.',

                    'PAGE BREAKS AND CONTINUATION TEXT: When a new page starts with "continued from previous page", assign ALL text between that marker and the NEXT LINE number to the LAST item (highest LINE number) that appeared on the previous page. IMPORTANT: When a rowspan cell contains stacked LINE numbers (e.g. 0040 and 0050), BOTH items appear on the SAME page — the continuation on the NEXT page belongs to the LAST of those stacked items (0050), NOT the first (0040). The last item\'s notes may appear partially in its own row and then fully in the continuation — use the continuation to COMPLETE that item\'s notes.',

                    'STACKED LINE NUMBERS IN ROWSPAN: When a rowspan cell contains multiple LINE numbers (e.g. "0040<br>0050"), the data rows within that rowspan are individual items — assign them in order: first data row with an ITEM# → first LINE, second data row with an ITEM# → second LINE, etc. Each item\'s Description, Area, Notes, and other fields come ONLY from its own row and (for the last item) any continuation on the next page.',

                    'COMPLETENESS CHECK: Every item you output MUST have LineNumber, ItemNumber, Description, Quantity, UnitPrice, and TotalPrice populated from the table. If any of these are missing for an item, re-read that item\'s table row — the data IS there in the LINE, ITEM#, QTY ORD, UNIT PRICE, and PRICE columns.',

                    'SUB-CODES: Codes like S016, S017, S020 appearing after the ITEM# are supplier sub-codes, NOT separate items. Ignore them.',

                    'PROJECT LABELS: Text like "EGGER PRIMARY" appearing alone above the first item row is a project/customer label — NOT a product description. Skip it.',

                    'SKIP completely: repeated page headers, ENERGY HANDLING SCHG, Weight lines, totals, "Agreed and Accepted", footer text.',
                ]),
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'LineNumber' => [
                            'type'        => 'string',
                            'description' => 'The 4-digit line number from the LINE column (e.g. "0010", "0020", "0030"). Every real item MUST have a LineNumber.',
                            'method'      => 'extract',
                        ],
                        'ItemNumber' => [
                            'type'        => 'string',
                            'description' => 'Product/SKU code from the ITEM# column (e.g. "VTSMSARPENNYH", "CAEPOER1224R", "ANALMCP1224HN"). Skip sub-codes (S016, S017, S020) — those are supplier references, not the item code.',
                            'method'      => 'extract',
                        ],
                        'Description' => [
                            'type'        => 'string',
                            'description' => implode(' ', [
                                'Full product name from the DESCRIPTION column.',
                                'Combine ALL product-name text lines for this item, including text that continues on the next row or the next page after "continued from previous page".',
                                'STOP collecting description text when you reach a line starting with "C*", "Serial#", or "*" (asterisk followed by text) — those are area/notes, not product name.',
                                'CROSS-PAGE RULE: If an item\'s description is split across a page break, the continuation text (after "continued from previous page") belongs to the PREVIOUS item, not the next one.',
                                'Example: LINE 0030 on page 1 has "MOSCATO ARGENTO 1/2X12 BAR". Page 2 starts with "continued from previous page" then "LINER HONED". The full description for 0030 is "MOSCATO ARGENTO 1/2X12 BAR LINER HONED". The text "LINER HONED" does NOT belong to LINE 0040.',
                                'Example: "LA MARCA CALACATTA PAONAZZO" on one row + "HONED 12X24 RECT NEW PKG" on next row = "LA MARCA CALACATTA PAONAZZO HONED 12X24 RECT NEW PKG".',
                                'Example: "PORTRAITS ERICE 12X24 MATTE" + "RECT" on next line = "PORTRAITS ERICE 12X24 MATTE RECT".',
                                'SKIP PROJECT/CUSTOMER LABELS: Text like "EGGER PRIMARY", "SMITH MASTER", or similar customer/project identifiers appearing alone on a line above the actual product name are NOT part of the description. These are section labels — do NOT prepend them to the product name.',
                                'Do NOT include: project labels, area designations ("C* PRIMARY BATH"), notes ("* NATURAL STONE..."), serial numbers, or "continued from previous page".',
                            ]),
                            'method'      => 'generate',
                        ],
                        'Area' => [
                            'type'        => 'string',
                            'description' => implode(' ', [
                                'Room/area designation from "C*" lines in this item\'s section.',
                                'Format: "C* ROOM/C* SUB-AREA" — include BOTH parts separated by " / ".',
                                'Also include any clarifying sub-area text on the line immediately after the C* area line (e.g. "SHOWER ACCENT & BACKSPLASH ACCENT") — append it after the sub-area.',
                                'IGNORE these C* lines (they are notes, not areas): "C* SPECIAL ORDER", "C* STOCK MATERIAL", "C* MUST SET", "C* ***".',
                                'Return null if no C* area lines exist for this item.',
                            ]),
                            'method'      => 'generate',
                        ],
                        'Notes' => [
                            'type'        => 'string',
                            'description' => 'All remaining text in this item\'s section that is NOT the product Description and NOT an Area designation (including sub-area clarifications). Copy verbatim in document order. Include ALL of: serial numbers (Serial#), ordering notes, special order notices, material specs, disclaimers, stock/lead time info, quantities per carton, etc. Notes text may appear both BEFORE and AFTER the Area line — capture it all. Each distinct note on its own line. Return null if none.',
                            'method'      => 'generate',
                        ],
                        'ETA' => [
                            'type'        => 'date',
                            'description' => 'Ship/delivery date for this item from the SHIP DATE column (e.g. "4/10/26*" → 2026-04-10, "3/17/26" → 2026-03-17). Ignore trailing asterisks. Null if none.',
                            'method'      => 'extract',
                        ],
                        'Quantity' => [
                            'type'        => 'string',
                            'description' => 'The numeric quantity from the QTY ORD column (e.g. "9.90", "121.41", "40.68", "108.50"). This is always a number. Do NOT extract page numbers, text, or values from other columns.',
                            'method'      => 'extract',
                        ],
                        'Unit' => [
                            'type'        => 'string',
                            'description' => 'Unit of measure from the U/M column (e.g. "SF", "PC", "EA", "LF").',
                            'method'      => 'extract',
                        ],
                        'UnitPrice' => [
                            'type'        => 'number',
                            'description' => 'Per-unit price from the UNIT PRICE column (e.g. 17.250, 4.500).',
                            'method'      => 'extract',
                        ],
                        'TotalPrice' => [
                            'type'        => 'number',
                            'description' => 'Extended line total from the PRICE column (e.g. 170.78, 1511.55). This equals Quantity × UnitPrice.',
                            'method'      => 'extract',
                        ],
                        'Status' => [
                            'type'        => 'string',
                            'description' => 'Order status from the STATUS column (e.g. "Back Ord", "Availabl", "Available", "Shipped").',
                            'method'      => 'extract',
                        ],
                    ],
                ],
            ],
        ];

        return [
            'description'    => implode(' ', [
                'Material order / order acknowledgment analyzer for construction supply documents.',
                'CRITICAL: All pages are ONE continuous document. Pages 2, 3, etc. continue the same items table from page 1.',
                'Repeated page headers (vendor name, bill-to, ship-to, column headers) on subsequent pages must be IGNORED.',
                'Text after "continued from previous page" belongs to the LAST item from the previous page — it is NOT a new item.',
                'LINE numbers (0010, 0020, 0030, ...) in the LINE column are the ONLY way to identify distinct items.',
                'Each LINE number appears exactly once across the entire document. Count items by distinct LINE numbers.',
                'Description text may span multiple rows and even cross page boundaries — combine all product-name lines.',
            ]),
            'baseAnalyzerId' => 'prebuilt-document',
            'config'         => [
                'enableLayout' => false,
            ],
            'models'         => [
                'completion' => $model,
            ],
            'fieldSchema'    => [
                'name'        => 'HiveMaterialOrderSchema',
                'description' => implode(' ', [
                    'Construction material order extraction schema.',
                    'All pages = one continuous document. Ignore repeated headers on pages 2+.',
                    '"continued from previous page" = continuation of the previous item, NOT a new item.',
                    'Follow visual reading order across all table columns left-to-right.',
                    'Only create an item when you see a new LINE number (0010, 0020, ...) with an ITEM# code and numeric PRICE.',
                ]),
                'fields'      => $fields,
            ],
        ];
    }
}
