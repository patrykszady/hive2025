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
                'description' => 'The brand or business name of the merchant/vendor on the receipt. '
                    . 'Prefer the prominent BRAND name shown in the logo or store header at the top of '
                    . 'the receipt (e.g. "THE HOME DEPOT", "MENARDS", "LOWE\'S", "WALMART"). '
                    . 'IMPORTANT: Do NOT use marketing slogans, taglines, or advertising phrases as the '
                    . 'merchant name. Examples of taglines to IGNORE: "How doers get more done." (Home Depot), '
                    . '"Save big money" (Menards), "Save money. Live better." (Walmart). '
                    . 'If only a logo is visible without printed brand text, infer the brand from visual '
                    . 'cues (logo shape/color, store layout, receipt format, address) and return the '
                    . 'canonical brand name. If genuinely unknown, return an empty string.',
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
                'description' => 'The primary transaction identifier on the document. Look for labels such as "Transaction Number", "Transaction #", "Invoice Number", "Invoice #", "Receipt Number", "Receipt #", "Order Number", "Order #", "Confirmation Number", or "Trans #". Prefer the Transaction Number if multiple identifiers are present. When the receipt header contains a multi-part number like "1913  00062  49221" (store, register, and transaction), return the FULL string with spaces (e.g. "1913 00062 49221"), not just the last segment. NEVER return a credit-card / EFT processor reference number. Specifically, do NOT return values labeled "Ref#", "Reference", "Auth Code", "Authorization", "Approval Code", "AID", "ARQC", "TC", "TVR", "TSI", "Trace", "Sequence #", "Batch #", "Terminal ID", "Merchant ID", or any number that appears next to a card brand line ("VISA", "MASTERCARD", "DISCOVER", "AMEX", "DEBIT", "EFT", "Contactless"). For Menards-style receipts the printed identifier appears at the very bottom in the form "NNNNN NN NNNN MM/DD/YY HH:MM PM NNNN" (e.g. "89449 07 6674 04/01/26 01:05PM 3254") — return the leading multi-segment number with spaces preserved ("89449 07 6674").',
                'method'      => 'extract',
            ],

            'PurchaseOrder' => [
                'type'        => 'string',
                'description' => 'Purchase Order number, PO#, Job Name, Job Number, JobName, PRO JobName, or project reference. This value may appear anywhere on the document including loyalty, rewards, or membership sections. Extract only the short code or number, not the label. Return null when no PO/Job label is present. NEVER infer a PO from unrelated numbers on the receipt. Specifically, do NOT return: rebate receipt numbers (e.g. the number that follows "THE FOLLOWING REBATE RECEIPTS WERE PRINTED FOR THIS TRANSACTION" on Menards receipts), store numbers, register numbers, cashier IDs, the cashier\'s name, item totals, account numbers, transaction numbers, credit-card last-four, EFT references, authorization codes, or any value already extracted as InvoiceId.',
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

            'Shipping' => [
                'type'        => 'number',
                'description' => 'Total shipping, handling, and delivery charges. Look ONLY for an explicit numeric dollar amount appearing on the SAME line as one of these labels: "Shipping", "Freight", "Delivery", "Handling", "S&H". Return null if the label is absent, OR if the value next to the label is non-numeric such as "FREE", "Free", "Included", "N/A", "—", or "$0.00". NEVER use a value from a Tax, Subtotal, Total, Tip, or Fees line. Do NOT infer or guess — if no explicit dollar amount appears next to a shipping/delivery/handling/freight label, return null. Do NOT return 0.',
                'method'      => 'extract',
            ],

            'Deposit' => [
                'type'        => 'number',
                'description' => 'Any deposit or prepayment amount already applied. Look for labels: "Deposit", "Deposit Applied", "Payment Received", "Amount Paid", "Prepayment", "Payments to Date". Return null if no deposit field exists — do NOT return 0.',
                'method'      => 'extract',
            ],

            'BalanceDue' => [
                'type'        => 'number',
                'description' => 'Remaining balance owed after any deposits or partial payments. Look for labels: "Balance Due", "Amount Remaining", "Net Due", "Balance". Return null if no such field exists — do NOT return 0.',
                'method'      => 'extract',
            ],

            // ── Line items ─────────────────────────────────────────────────
            'Items' => [
                'type'        => 'array',
                'description' => 'List of purchased line items on the receipt. Each physical item must appear EXACTLY ONCE. Many retailers print each item across TWO consecutive lines — DESCRIPTION + manufacturer-model on the first line, then VENDOR-SKU + "qty@$price" + line total on the second line. These two lines describe ONE item and MUST be merged into a single entry — do NOT emit one entry with the description and null price plus a second entry with the SKU and price. Examples to merge into a single item: (a) Menards: "1G TANK SPRAYER 70000\\n2631202 1@$10.49      $10.49" → one item {Description:"1G TANK SPRAYER", VendorCode:"2631202", ManufacturerPartNumber:"70000", Quantity:1, Price:10.49, TotalPrice:10.49}. (b) Home Depot: barcode + abbreviated name on one line, full product description on the next. Only emit an item if you can attach a price to it; if a line has no price and the next line clearly continues it, merge them.',
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'Description' => [
                            'type'        => 'string',
                            'description' => 'The product name/description ONLY — do NOT include the manufacturer name, manufacturer part number, vendor SKU, barcode, or any "qty@$price" fragment. Example A: from "KOHLER K-728-K-NA MASTERSHOWER TRANSFER VALVE", Description is "MASTERSHOWER TRANSFER VALVE" (KOHLER → Manufacturer, K-728-K-NA → ManufacturerPartNumber). Example B (Menards): from "1G TANK SPRAYER 70000" (description+model) followed by "2631202 1@$10.49 $10.49" (SKU+qty/price), Description is "1G TANK SPRAYER" (70000 → ManufacturerPartNumber, 2631202 → VendorCode). NEVER put a numeric SKU, "NNNN N@$N.NN" pricing fragment, or `<A>`/`<B>` return-policy indicator in the Description. If the receipt shows a short/abbreviated name on one line and a longer product description on the next line, concatenate both lines into a single description.',
                            'method'      => 'extract',
                        ],
                        'VendorCode' => [
                            'type'        => 'string',
                            'description' => 'Vendor SKU, item number, barcode, or product code assigned by this specific retailer/vendor. Typically a numeric or short alphanumeric code that appears on its own at the start of the price/quantity line (e.g. "2631202" before "1@$10.49"). Return ONE code per item — never concatenate codes from two adjacent items (e.g. do NOT return "66122544312415"; that is two SKUs, "6612254" and "4312415", from two different items).',
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
                        'Manufacturer' => [
                            'type'        => 'string',
                            'description' => 'Brand or manufacturer name for this item (e.g. "KOHLER", "MOEN", "DELTA", "AMERICAN STANDARD"). Often appears as all-caps words at the start of the description before the model number. Return null if not identifiable.',
                            'method'      => 'extract',
                        ],
                        'ManufacturerPartNumber' => [
                            'type'        => 'string',
                            'description' => 'Manufacturer model number or part number (e.g. "K-8304-KS-NA", "T14238-RB"). This is the manufacturer\'s own product code — a hyphenated alphanumeric code — distinct from the vendor SKU. Return null if not present.',
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

            // ── Pagination & continuation ──────────────────────────────────
            'PageNumber' => [
                'type'        => 'number',
                'description' => 'The page number printed on this document. Look for labels like "Page # 1 of 2", "Page 1/2", "Page 1", or "Pg 1". Return only the integer for the current page (e.g. 1 from "Page # 1 of 2"). Return null if no page indicator is printed.',
                'method'      => 'extract',
            ],
            'PageTotal' => [
                'type'        => 'number',
                'description' => 'The total page count printed on this document. Look for the second number in labels like "Page # 1 of 2" (return 2). Return null if no total is printed.',
                'method'      => 'extract',
            ],
            'ContinuedFromPrevious' => [
                'type'        => 'boolean',
                'description' => 'True if this document is a continuation of a previous page. Indicators include: a "Page # 2 of 2" (or higher) marker, the phrase "Continued from previous page", missing header/totals on what looks like a partial ship-ticket, or signature/notes-only pages that reference an order printed on a prior page. False otherwise.',
                'method'      => 'extract',
            ],

            // ── Handwritten note (job address, project name, location word) ─
            'HandwrittenNote' => [
                'type'        => 'string',
                'method'      => 'generate',
                'description' => 'Any HANDWRITTEN note(s) added by a person on top of the printed receipt — typically a job-site address (e.g. "215 Lincoln", "1422 Oak St", "911 Will"), a project/client name (e.g. "Smith remodel"), or a single short word/letter like "Office", "Shop", "Warehouse", "Garage", "Truck", "Job", "R", "X", "OK".' . "\n\n" . 'IF MULTIPLE separate handwritten notes exist on the same receipt (e.g. an address scrawled across the merchant header AND a circled letter elsewhere on the page), JOIN them with " | " — for example: "911 Will | R".' . "\n\n" . 'DETECTION CUES (use BOTH visual and textual):' . "\n" . '1. VISUAL: handwriting has irregular stroke widths, varied baseline, slants differently from printed text, and is often written in pen on whitespace, in a margin, OVER existing printed text (e.g. across the merchant address), or inside a hand-drawn circle.' . "\n" . '2. TEXTUAL/POSITIONAL — in the OCR text/markdown, a handwritten note often appears as:' . "\n" . '   - A short isolated line (1–4 words OR a single 1–2 character token) that interrupts the normal flow of the printed receipt.' . "\n" . '   - A line near the TOP of the document, BEFORE OR INTERLEAVED WITH the printed merchant name/address/phone (e.g. between the printed street address and "KEEP YOUR RECEIPT").' . "\n" . '   - A line near the BOTTOM of the document, AFTER all totals and payment info.' . "\n" . '   - A token containing slashes/letters in odd combinations like "911W/II", "9/1 Will", "3/15" — these are usually OCR\'s best attempt to read cursive handwriting.' . "\n" . '   - A SINGLE uppercase letter alone on a line (e.g. "R", "X", "P") that is not a label or column header — usually a circled annotation.' . "\n" . '   - A line that does NOT fit the printed receipt\'s structure: not a label, not a price, not an address line of the merchant, not a barcode/SKU, not a date/time stamp, not a register/cashier ID, not a return-policy sentence.' . "\n" . '   - Casing may not match the printed receipt (e.g. lowercase "office" on an otherwise ALL-CAPS receipt is a strong signal it was handwritten).' . "\n\n" . 'WHEN IN DOUBT — if a short word, address-like fragment, or single letter appears alone on its own line and does NOT match any standard printed receipt element, return it as a handwritten note.' . "\n\n" . 'NEVER return: the printed merchant name or address (e.g. "2700 Lake Cook Rd", "Long Grove, IL 60047", "Solid Waste Agency of"), the printed phone/email, store/register/transaction/cashier numbers, dates, times, item descriptions or codes, prices, tax/total/subtotal lines, payment-method labels, card last-four, EFT/Ref#/Auth/AID/ARQC values, return-policy sentences, rebate-receipt numbers (the number that follows "THE FOLLOWING REBATE RECEIPTS WERE PRINTED FOR THIS TRANSACTION"), coupon/promo/loyalty/rewards numbers, signatures, or any printed form label like "Sold To", "Ship To", "Bill To", "Customer", "P/O", "Order #", "Invoice #", "Date", "Signature".' . "\n\n" . 'CRITICAL ANTI-HALLUCINATION RULES:' . "\n" . '   - The FIRST line of a multi-line printed business header (e.g. "Solid Waste Agency of" followed by "Northern Cook County" / "Glenview Transfer Station" / "Operated by" / "Groot Industries, Inc") is the merchant name — it is PRINTED, never handwritten, even though it sits at the very top of the document. If a candidate line flows grammatically into the next printed line (e.g. ends with "of", "&", a comma, or is part of a corporate suffix like "Inc", "LLC", "Co", "Corp", "Ltd"), it is the merchant header — return null.' . "\n" . '   - Do NOT invent or fabricate a handwritten note when no handwriting markers (irregular casing, slashes, single letters, OCR-mangled cursive tokens) are visible in the OCR text. When the only candidate is a clean, properly-cased printed phrase, return null.' . "\n\n" . 'Return ONLY the handwritten text, exactly as the OCR captured it (preserve original casing). If there is no handwritten note, return null.',
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
                'description' => 'IMPORTANT — this line often appears near the bottom of the LAST page, between the last product item and the totals section. Total shipping, handling, and delivery charges. Look for labels: "S&H CHGS", "Shipping", "Freight", "Delivery", "Handling", "ENERGY HANDLING SCHG", "Freight Charges". This is NOT a line item — it is a standalone charge line. SUM all shipping/handling/delivery amounts into one total. Return null if no shipping charges exist — do NOT return 0.',
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

                    'HOW TO IDENTIFY ITEMS: Look for a tabular list of products. Items typically have a line number or row index, a product code/SKU, description, quantity, unit price, and extended price. Column headers vary by vendor (e.g. "LINE", "ITEM#", "QTY", "UNIT PRICE", "PRICE", or "SKU", "DESCRIPTION", "QTY ORD", "AMOUNT", etc.). Identify the pattern from the table header row and extract one object per product row.',

                    'CRITICAL — ITEMS AFTER PAGE BREAKS: A page that starts with continuation text (e.g. "continued from previous page", "continued", or similar) and notes for a prior item will ALSO contain NEW items below that continuation block. You MUST extract those new items. The continuation text belongs to the previous item; a new line number below it starts a completely independent item.',

                    'PAGE BREAKS AND CONTINUATION TEXT: When a new page starts with continuation text, assign ALL text between that marker and the NEXT line number to the LAST item from the previous page. When multiple line numbers appear stacked in the same cell, the continuation on the NEXT page belongs to the LAST of those stacked items.',

                    'COMPLETENESS CHECK: Every item you output MUST have LineNumber (if the document uses line numbers), ItemNumber, Description, Quantity, UnitPrice, and TotalPrice. If any are missing, re-read that item\'s row — the data is in the table columns.',

                    'VENDOR SUB-CODES: Some vendors print a narrow column of supplier sub-codes (e.g. Virginia Tile shows "S016", "S017", "S020" between the ItemNumber column and the Description column). These sub-codes are NOT separate items, NOT manufacturer names, and NOT part of the description. Skip them when building Description and do NOT put them in Manufacturer or ManufacturerPartNumber.',

                    'PROJECT/CUSTOMER LABELS: Text like a customer name or project name appearing alone above the items table is a section label — NOT a product description. Skip it.',

                    'NOT LINE ITEMS — do NOT extract these as items: repeated page headers, shipping/handling/freight charges (extract those in the Shipping field instead), weight summaries, order totals, signature/acceptance lines, and footer text.',
                ]),
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'LineNumber' => [
                            'type'        => 'string',
                            'description' => 'The line number or row index from the document table (e.g. "0010", "0020", "1", "2"). Return null if the document does not use line numbers.',
                            'method'      => 'extract',
                        ],
                        'ItemNumber' => [
                            'type'        => 'string',
                            'description' => implode(' ', [
                                'Primary vendor product code / SKU from the ITEM# (or SKU, PRODUCT CODE, ITEM NUMBER) column \u2014 the FIRST code column to the right of the LINE column.',
                                'Always extract this when the table has an item-code column. Examples: Virginia Tile rows look like LINE | ITEM# | <sub-code> | DESCRIPTION, where ITEM# values are like \"VTSMSARPENNYH\", \"WOWYKDS25\", \"VTSMSARBARSMH\", \"CAEPOER1224R\", \"ANALMCP1224HN\" \u2014 each of these is the ItemNumber for its row.',
                                'When TWO code columns appear between LINE and DESCRIPTION (a long alphanumeric SKU followed by a short S###/A### sub-code), the ItemNumber is the LONGER, FIRST code; the short sub-code is a vendor sub-code and goes nowhere (skip it).',
                                'Return null only when the document genuinely has no SKU column.',
                            ]),
                            'method'      => 'extract',
                        ],
                        'Manufacturer' => [
                            'type'        => 'string',
                            'description' => implode(' ', [
                                'Brand or manufacturer name for this item (e.g. "KOHLER", "MOEN", "DELTA", "AMERICAN STANDARD", "GROHE").',
                                'A real Manufacturer is a RECOGNIZABLE COMPANY/BRAND NAME that you would find on the manufacturer\'s actual product packaging or website.',
                                'It must appear EITHER (a) in a dedicated "Manufacturer" / "Brand" column on the receipt, OR (b) as the leading brand word(s) of the product description IMMEDIATELY followed by a real ManufacturerPartNumber (a hyphenated code that contains at least one DIGIT, e.g. "K-8304-KS-NA").',
                                'CRITICAL — DO NOT FABRICATE: If there is no dedicated brand column AND the description does not begin with "<BRAND> <PART#-WITH-DIGITS>", return null. Do NOT guess a brand from generic product words.',
                                'NEVER include descriptive product words as the manufacturer. Examples of WRONG extractions to AVOID: "PVC ISLAND DRAIN FREESTANDING TUB DRAIN" (this is a product description, not a brand), "KOHLER UNIVERSAL" from "KOHLER UNIVERSAL RITE-TEMP PB VALVE KIT" (the brand is just "KOHLER"; "UNIVERSAL" is a product line word, and "RITE-TEMP" is a product family name not a part number).',
                                'CRITICAL — NOT A MANUFACTURER: Short alphanumeric supplier codes that appear in their OWN column between the ItemNumber and the Description (e.g. Virginia Tile prints a column of codes like "S016", "S017", "S020"). Patterns to REJECT as Manufacturer: a single letter followed by 3 digits (S017, A123), or 1–3 letters followed by 1–4 digits.',
                                'For Virginia Tile orders specifically: Manufacturer should usually be null because the product description rarely contains a brand word. Do NOT use the S### sub-code as the manufacturer.',
                                'When in doubt, return null. A null value is always preferable to a wrong value.',
                            ]),
                            'method'      => 'extract',
                        ],
                        'ManufacturerPartNumber' => [
                            'type'        => 'string',
                            'description' => implode(' ', [
                                'Manufacturer model number or part number (e.g. "K-8304-KS-NA", "T14238-RB", "559-ORB").',
                                'This is the manufacturer\'s own product code — a hyphenated alphanumeric code — distinct from the vendor\'s ItemNumber/SKU.',
                                'CRITICAL — MUST CONTAIN AT LEAST ONE DIGIT: A real manufacturer part number always contains digits. Reject any candidate that is purely letters with hyphens.',
                                'NEVER extract descriptive hyphenated product words as a part number. Examples of WRONG extractions to AVOID: "ROUGH-IN", "RITE-TEMP", "TWO-HANDLE", "PULL-DOWN", "BUILT-IN", "DROP-IN", "TUB-AND-SHOWER", "NON-SLIP", "SELF-CLOSING", "HEAVY-DUTY". These are descriptive terms found in the product description, not SKUs.',
                                'Only extract a value when it is clearly a SKU/model code (alphanumeric with at least one digit) appearing in a dedicated part-number column OR as the second token of the description right after the brand name.',
                                'When in doubt, return null. A null value is always preferable to a wrong value.',
                            ]),
                            'method'      => 'extract',
                        ],
                        'Description' => [
                            'type'        => 'string',
                            'description' => implode(' ', [
                                'The product name/description ONLY — do NOT include the manufacturer name or manufacturer part number.',
                                'Example: if the raw text is "KOHLER K-728-K-NA MASTERSHOWER 2 OR 3 WAY TRANSFER VALVE", the Description is "MASTERSHOWER 2 OR 3 WAY TRANSFER VALVE" (KOHLER goes in Manufacturer, K-728-K-NA goes in ManufacturerPartNumber).',
                                'Example: "AMERICAN STANDARD T14238-RB COLONIAL SHOWER VALVE TRIM" → Description is "COLONIAL SHOWER VALVE TRIM".',
                                'Combine ALL product-name text lines for this item, including text that continues on the next row or across a page break.',
                                'CROSS-PAGE WRAP — CRITICAL: When an item is the LAST item on a page and its description text continues on the next page (after a "continued from previous page" marker), you MUST stitch the trailing text from the next page onto this item\'s Description. The text immediately after the continuation marker, up until the first non-description line (C* tag, Serial#, note, blank line, or the next item\'s line number), is the tail of THIS item\'s description. Examples that REQUIRE stitching: "MOSCATO ARGENTO 1/2X12 BAR" on page 1 + "LINER HONED" on page 2 → "MOSCATO ARGENTO 1/2X12 BAR LINER HONED". "PORTRAITS ERICE 12X24 MATTE" + "RECT" → "PORTRAITS ERICE 12X24 MATTE RECT".',
                                'CROSS-PAGE RULE: If a description is split across a page break, continuation text belongs to the PREVIOUS item, not the next one.',
                                'STOP collecting description text when you reach area designations (lines starting with "C*" or room labels), serial numbers, notes, or disclaimers.',
                                'Do NOT include: manufacturer name, manufacturer part number, project/customer labels, area designations, ordering notes, serial numbers, disclaimers, or continuation markers.',
                            ]),
                            'method'      => 'generate',
                        ],
                        'Area' => [
                            'type'        => 'string',
                            'description' => implode(' ', [
                                'Room or area designation for this item, if present.',
                                'SECTION TAGS (Studio 41 and similar vendors): Lines like "TAG: PRIMARY BATH" or "TAG: HALL BATH" act as SECTION HEADERS.',
                                'A TAG applies to ALL items that follow it until the next TAG line appears.',
                                'Example: if "TAG: PRIMARY BATH" appears, then items 3408127, 3451610, 3182272 etc. all get Area="PRIMARY BATH". When "TAG: HALL BATH" appears later, all subsequent items get Area="HALL BATH".',
                                'Extract only the room/area name after "TAG:" — do NOT include the "TAG:" prefix itself.',
                                'PER-ITEM AREAS (Virginia Tile and similar vendors): "C*" prefixed lines (e.g. "C* PRIMARY BATH/C* SHOWER WALL") indicate room/area for that specific item — include both parts separated by " / ".',
                                'CRITICAL — SUB-AREA LINE: When the line IMMEDIATELY AFTER a "C*" area block does NOT start with "C*" and does NOT start with "*", and contains words like "ACCENT", "WALL", "FLOOR", "BACKSPLASH", "COUNTER", "NICHE", "TRIM", "BORDER", "SHOWER", "TUB", "VANITY", you MUST append it to the Area value (separated by " / "). It is part of the Area, NOT a Note.',
                                'EXAMPLE: Lines "C* PRIMARY BATH/C* ACCENT" then "SHOWER ACCENT & BACKSPLASH ACCENT" then "C* SPECIAL ORDER..." → Area = "PRIMARY BATH / ACCENT / SHOWER ACCENT & BACKSPLASH ACCENT". The middle line is a sub-area, NOT a note.',
                                'IGNORE lines that are ordering notes rather than areas (e.g. "C* SPECIAL ORDER", "C* STOCK MATERIAL", lines starting with "C* ***", lines starting with "*").',
                                'Other vendors may use different formats — look for room names, locations, or installation areas associated with the item.',
                                'Return null if no area/room designation exists for this item.',
                            ]),
                            'method'      => 'generate',
                        ],
                        'Notes' => [
                            'type'        => 'string',
                            'description' => 'All remaining text in this item\'s section that is NOT the product Description and NOT an Area designation (including sub-area clarifications like "SHOWER ACCENT & BACKSPLASH ACCENT", "VANITY WALL", "TUB DECK", etc. — those belong in Area, NOT here). Copy verbatim in document order. Include ALL of: serial numbers (Serial#), ordering notes, special order notices, material specs, disclaimers, stock/lead time info, quantities per carton, etc. Notes text may appear both BEFORE and AFTER the Area line — capture it all. Each distinct note on its own line. Return null if none.',
                            'method'      => 'generate',
                        ],
                        'ETA' => [
                            'type'        => 'date',
                            'description' => 'Ship date, delivery date, or ETA for this item. May be labeled "SHIP DATE", "ETA", "DELIVERY", "EST SHIP", etc. Ignore trailing asterisks or other markers. Null if none.',
                            'method'      => 'extract',
                        ],
                        'Quantity' => [
                            'type'        => 'string',
                            'description' => 'The numeric quantity ordered. May be labeled "QTY", "QTY ORD", "QUANTITY", "ORDER QTY", etc. This is always a number. Do NOT extract page numbers, text, or values from other columns.',
                            'method'      => 'extract',
                        ],
                        'Unit' => [
                            'type'        => 'string',
                            'description' => 'Unit of measure from the U/M column (e.g. "SF", "PC", "EA", "LF").',
                            'method'      => 'extract',
                        ],
                        'UnitPrice' => [
                            'type'        => 'number',
                            'description' => 'Per-unit price. May be labeled "UNIT PRICE", "PRICE/UNIT", "RATE", "EACH", etc.',
                            'method'      => 'extract',
                        ],
                        'TotalPrice' => [
                            'type'        => 'number',
                            'description' => 'Extended line total (quantity × unit price). May be labeled "PRICE", "AMOUNT", "EXT PRICE", "TOTAL", "LINE TOTAL", etc.',
                            'method'      => 'extract',
                        ],
                        'Status' => [
                            'type'        => 'string',
                            'description' => 'Order/fulfillment status for this item (e.g. "Back Ord", "Available", "Shipped", "Open", "Cancelled"). Null if no status column exists.',
                            'method'      => 'extract',
                        ],
                    ],
                ],
            ],
        ];

        return [
            'description'    => implode(' ', [
                'Material order / order acknowledgment analyzer for construction supply documents from various vendors.',
                'CRITICAL: All pages are ONE continuous document. Pages 2, 3, etc. continue the same items table from page 1.',
                'Repeated page headers on subsequent pages must be IGNORED.',
                'Continuation text (e.g. "continued from previous page") belongs to the LAST item from the previous page — it is NOT a new item.',
                'Each distinct line number or product row represents exactly one item.',
                'Description text may span multiple rows and even cross page boundaries — combine all product-name lines.',
                'Shipping, handling, and freight charges are NOT line items — extract them in the Shipping field.',
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
                    'Construction material order extraction schema for multiple vendors.',
                    'All pages = one continuous document. Ignore repeated headers on pages 2+.',
                    'Continuation text = continuation of the previous item, NOT a new item.',
                    'Follow visual reading order across all table columns left-to-right.',
                    'Only create an item when you see a new product row with a product code/SKU and a price.',
                ]),
                'fields'      => $fields,
            ],
        ];
    }
}
