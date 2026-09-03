<?php

namespace App\Console\Commands;

use App\Services\ContentUnderstandingService;
use Illuminate\Console\Command;

class SyncContentUnderstandingAnalyzer extends Command
{
    protected $signature = 'content-understanding:sync-analyzer
                            {type=receipt             : Analyzer type to sync: receipt or coi}
                            {--base=prebuilt-document  : Prebuilt analyzer to use as base schema}
                            {--model=gpt-5.2           : Completion model name for the analyzer}
                            {--dry-run                 : Print the schema that would be sent without calling the API}';

    protected $description = 'Create or update a Content Understanding analyzer schema (receipt or COI).';

    public function handle(ContentUnderstandingService $cu): int
    {
        $type      = $this->argument('type');
        $baseId    = $this->option('base');
        $model     = $this->option('model');

        if (! in_array($type, ['receipt', 'coi', 'material_order', 'check_statement', 'check', 'waiver'], true)) {
            $this->error("Unknown analyzer type '{$type}'. Use 'receipt', 'coi', 'material_order', 'check_statement', 'check', or 'waiver'.");
            return self::FAILURE;
        }

        $analyzerId = match ($type) {
            'coi'             => config('services.azure_cu.analyzer_id_coi'),
            'material_order'  => config('services.azure_cu.analyzer_id_material_order'),
            'check_statement' => config('services.azure_cu.analyzer_id_check_statement'),
            'check'           => config('services.azure_cu.analyzer_id_check'),
            'waiver'          => config('services.azure_cu.analyzer_id_waiver'),
            default           => config('services.azure_cu.analyzer_id'),
        };

        $this->info("Syncing '{$type}' analyzer: {$analyzerId}");
        $this->info("Fetching base schema from '{$baseId}'...");

        $base       = $cu->getAnalyzerDefinition($baseId);
        $baseFields = $base['fieldSchema']['fields'] ?? [];

        $definition = match ($type) {
            'coi'             => $this->buildCoiDefinition($model),
            'material_order'  => $this->buildMaterialOrderDefinition($model),
            'check_statement' => $this->buildCheckStatementDefinition($model),
            'check'           => $this->buildCheckDefinition($model),
            'waiver'          => $this->buildWaiverDefinition($model),
            default           => $this->buildReceiptDefinition($model, $baseFields),
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
                'description' => 'The subtotal before tax, fees, and tip — the value on the SUBTOTAL line, WITH its printed sign. On a RETURN / REFUND receipt it is negative (e.g. "SUBTOTAL -232.99" → -232.99).',
                'method'      => 'extract',
            ],
            'TotalTax' => [
                'type'        => 'number',
                'description' => 'Total tax amount on the TAX / SALES TAX line, WITH its printed sign. On a RETURN / REFUND receipt the tax is refunded and negative (e.g. "SALES TAX -23.30" → -23.30).',
                'method'      => 'extract',
            ],
            'TotalAmount' => [
                'type'        => 'number',
                'description' => 'The transaction total: the amount on the line labeled "TOTAL" (or "Grand Total", "Amount Due", "Invoice Total"), including tax, fees and tip, WITH ITS PRINTED SIGN. A RETURN / REFUND receipt (cues: "REFUND", "RETURN", "CREDIT", "ORIG REC", negative item prices) has a NEGATIVE total — return it negative exactly as printed ("TOTAL -$256.29" → -256.29), never its absolute value. NEVER return a card or account BALANCE as the total: lines such as "CARD BALANCE", "GIFT CARD BALANCE", "STORE CREDIT BALANCE", "REMAINING BALANCE", "NEW BALANCE", "AVAILABLE BALANCE" show what is LEFT on a gift card or store-credit card AFTER the transaction and are printed BELOW the TOTAL and the tender lines — they are not the sale. Worked example (Home Depot return): "SUBTOTAL -232.99 / SALES TAX -23.30 / TOTAL -$256.29 / STORE CREDIT -256.29 / CARD BALANCE 262.99" → TotalAmount = -256.29 (NOT 262.99). Worked example (purchase paid with a gift card): "TOTAL 111.94 / GIFT CARD 111.94 / CARD BALANCE 122.21" → TotalAmount = 111.94 (NOT 122.21). If in doubt, TotalAmount must equal SubTotal + TotalTax (+ fees/tip), sign included.',
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
                'description' => 'Purchase Order number, PO#, Job Name, Job Number, JobName, PRO JobName, or project reference. This value may appear anywhere on the document including loyalty, rewards, or membership sections. Extract only the short code or number, not the label. Return null when no PO/Job label is present. NEVER infer a PO from unrelated numbers on the receipt. Specifically, do NOT return: rebate receipt numbers (e.g. the number that follows "THE FOLLOWING REBATE RECEIPTS WERE PRINTED FOR THIS TRANSACTION" on Menards receipts), store numbers, register numbers, cashier IDs, the cashier\'s name, item totals, account numbers, transaction numbers, credit-card last-four, EFT references, authorization codes, or any value already extracted as InvoiceId. Do NOT return billing-summary labels or amounts such as "Discounts: 0.00", "Credits: 0.00", "Tax: 0.00", "Subtotal", "Total", "Charges", or "Balance Due". If the text on or near a PO label is clearly a billing-summary field, return null for PurchaseOrder.',
                'method'      => 'extract',
            ],

            'CustomerPO' => [
                'type'        => 'string',
                'description' => 'Customer PO value for invoices that explicitly label a field as "Customer PO", "Customer PO Number", "Customer P.O.", or similar. Extract ONLY the value associated with that PO label. If the Customer PO field is blank, return null. NEVER take values from adjacent summary columns on the same row (for example in lines like "Customer PO Number:    Discounts: 0.00", do NOT return "Discounts: 0.00"). Do NOT return billing-summary labels or amounts such as "Discounts", "Credits", "Tax", "Subtotal", "Total", "Charges", or "Balance Due".',
                'method'      => 'extract',
            ],

            'ServiceAddress' => [
                'type'        => 'string',
                'description' => 'The SERVICE / JOB-SITE / DELIVERY address on the document — the location where the work was performed or materials were delivered, NOT the merchant\'s own address and NOT the billing/mailing address. Look for labels such as "Service Address", "Service Location", "Job Address", "Job Site", "Jobsite", "Site Address", "Delivery Address", "Deliver To", "Ship To", "Project Address", "Work Performed At", "Location of Service". Example: "Service Address: 3154 VIOLET LN, NORTHBROOK, IL 60062" → return "3154 VIOLET LN, NORTHBROOK, IL 60062". Return the full address value as printed (street, city, state, zip when present) WITHOUT the label. When "Ship To" repeats the billing party\'s name, still return the address portion. Do NOT return the merchant/store address, the remittance ("Remit To") address, or the customer billing ("Bill To") address when a separate service/delivery address exists. Return null when no service/job-site/delivery address is present.',
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
                'description' => 'List of purchased line items on the receipt. Each physical item must appear EXACTLY ONCE. Many retailers print each item across TWO consecutive lines — DESCRIPTION + manufacturer-model on the first line, then VENDOR-SKU + "qty@$price" + line total on the second line. These two lines describe ONE item and MUST be merged into a single entry — do NOT emit one entry with the description and null price plus a second entry with the SKU and price. Examples to merge into a single item: (a) Menards: "1G TANK SPRAYER 70000\\n2631202 1@$10.49      $10.49" → one item {Description:"1G TANK SPRAYER", VendorCode:"2631202", ManufacturerPartNumber:"70000", Quantity:1, Price:10.49, TotalPrice:10.49}. (b) Home Depot: see the dedicated structure rule below. Only emit an item if you can attach a price to it; if a line has no price and the next line clearly continues it, merge them.' . "\n\n" . 'HOME DEPOT TWO-LINE ITEM STRUCTURE — the full description sits BELOW its UPC line, never above. Every Home Depot line item is printed as a block of two (sometimes three) lines:' . "\n" . '  line 1: `<12-digit UPC> <ABBREVIATED NAME> <A>` with the line total at the right edge (the `<A>`/`<B>` is a return-policy flag, never part of any name);' . "\n" . '  line 2: the item\'s FULL product description, on its own line;' . "\n" . '  line 3 (only when quantity > 1): `N@U.UU` followed by the extended line total.' . "\n" . 'Walk the receipt TOP-DOWN: each new 12-digit UPC line STARTS a new item, and every non-UPC line that follows (the full description, a `N@U.UU` qty/price line) belongs to the item whose UPC line is DIRECTLY ABOVE it — NEVER to the next UPC below. Do NOT pair a full-description line with the UPC that follows it.' . "\n" . 'Example (three items):' . "\n" . '"070798005853 PW WT 6 <A>  10.48\\nDAP PLASTIC WOOD LTX WHITE 6 OZ\\n044021850480 WHT MOULDING <A>  6.58\\n1/4 X 1-3/4 X 96 PS WHT LATTICE MLDG\\n773204104996 49 CRWN PRMD <A>\\n9/16 X3-5/8 PFJ LWM49 CROWN\\n4@1.97  7.88" →' . "\n" . '  item 1 {VendorCode:"070798005853", Description:"PW WT 6 DAP PLASTIC WOOD LTX WHITE 6 OZ", Quantity:1, Price:10.48, TotalPrice:10.48}' . "\n" . '  item 2 {VendorCode:"044021850480", Description:"WHT MOULDING 1/4 X 1-3/4 X 96 PS WHT LATTICE MLDG", Quantity:1, Price:6.58, TotalPrice:6.58}' . "\n" . '  item 3 {VendorCode:"773204104996", Description:"49 CRWN PRMD 9/16 X3-5/8 PFJ LWM49 CROWN", Quantity:4, Price:1.97, TotalPrice:7.88}' . "\n" . 'Getting this pairing wrong shifts every description onto the wrong item — if your item list pairs a full description with the UPC printed AFTER it, re-read the receipt.' . "\n\n" . 'REPEATED IDENTICAL LINES = QUANTITY, NOT DUPLICATES: when the receipt prints the SAME product (same SKU/vendor code, same description, same unit price) on N separate lines — common when a cashier scans each unit individually (e.g. Floor & Decor printing "SCHNE 3/8in AL MAT BRT WHT / 100840677 / $25.19 1 $25.19" three times) — emit ONE item with Quantity = N, Price = the unit price, and TotalPrice = N × unit price (e.g. Quantity 3, Price 25.19, TotalPrice 75.57). NEVER silently drop the repeats: the sum of all TotalPrice values must account for every printed line.',
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'Description' => [
                            'type'        => 'string',
                            'description' => 'The product name/description ONLY — do NOT include the manufacturer name, manufacturer part number, vendor SKU, barcode, or any "qty@$price" fragment. Example A: from "KOHLER K-728-K-NA MASTERSHOWER TRANSFER VALVE", Description is "MASTERSHOWER TRANSFER VALVE" (KOHLER → Manufacturer, K-728-K-NA → ManufacturerPartNumber). Example B (Menards): from "1G TANK SPRAYER 70000" (description+model) followed by "2631202 1@$10.49 $10.49" (SKU+qty/price), Description is "1G TANK SPRAYER" (70000 → ManufacturerPartNumber, 2631202 → VendorCode). NEVER put a numeric SKU, "NNNN N@$N.NN" pricing fragment, or `<A>`/`<B>` return-policy indicator in the Description. If the receipt shows a short/abbreviated name on one line and a longer product description on the next line, concatenate both lines into a single description — and the longer description line always belongs to the abbreviated-name/UPC line ABOVE it (see the Home Depot structure rule on the Items field), never to the next item below.',
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
                            'description' => 'Amount paid (or, on a refund, credited back) via this payment method, WITH its printed sign — on a return the tender line is negative ("STORE CREDIT -256.29" → -256.29). Never the card\'s remaining balance ("CARD BALANCE", "GIFT CARD BALANCE").',
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
                'description' => 'Any HANDWRITTEN note(s) added by a person on top of the printed receipt — typically a job-site address (e.g. "215 Lincoln", "1422 Oak St", "911 Will", "17 MARCELA", "17 Marcella"), a project/client name (e.g. "Smith remodel"), a worker first-name tag (e.g. "Greg", "Mike", "Tony", "Joe", "Pat") scribbled at the very top so the office knows who bought the materials, or a single short word/letter like "Office", "Shop", "Warehouse", "Garage", "Truck", "Job", "R", "X", "OK".' . "\n\n" . 'IF MULTIPLE separate handwritten notes exist on the same receipt (e.g. an address scrawled across the merchant header AND a circled letter elsewhere on the page), JOIN them with " | " — for example: "911 Will | R".' . "\n\n" . 'STRIP PRINTED FIELD LABELS — return the VALUE only, never the label:' . "\n" . 'Some receipts (e.g. Menards) have a PRINTED labeled blank that the contractor writes a value into — for example a printed "Job # or Name :" line with a handwritten "3143" after it. Return ONLY the handwritten value ("3143"), NEVER the printed label text. Strip these leading labels and their separators (":", "#", "-") when present: "Job # or Name", "Job Name", "Job #", "Job", "PO", "PO #", "PO Number", "PO/Job Name", "P.O.", "PRO JobName", "Customer PO". Example: printed "Job # or Name :" with handwritten "3143" → return "3143", NOT "Job # or Name : 3143". If stripping the label leaves nothing, return null.' . "\n\n" . 'DETECTION CUES (use BOTH visual and textual):' . "\n" . '1. VISUAL: handwriting has irregular stroke widths, varied baseline, slants differently from printed text, and is often written in pen on whitespace, in a margin, OVER existing printed text (e.g. across the merchant address), or inside a hand-drawn circle.' . "\n" . '2. TEXTUAL/POSITIONAL — in the OCR text/markdown, a handwritten note often appears as:' . "\n" . '   - A short isolated line (1–4 words OR a single 1–2 character token) that interrupts the normal flow of the printed receipt.' . "\n" . '   - A line near the TOP of the document, BEFORE OR INTERLEAVED WITH the printed merchant name/address/phone (e.g. between the printed street address and "KEEP YOUR RECEIPT").' . "\n" . '   - A line near the BOTTOM of the document, AFTER all totals and payment info.' . "\n" . '   - A token containing slashes/letters in odd combinations like "911W/II", "9/1 Will", "3/15" — these are usually OCR\'s best attempt to read cursive handwriting.' . "\n" . '   - A SINGLE uppercase letter alone on a line (e.g. "R", "X", "P") that is not a label or column header — but ONLY when it has supporting handwriting evidence (it is clearly circled, appears in a margin, OR appears AFTER all totals/payment info at the bottom of the receipt). NEVER return a lone single letter that sits inside the printed header area of the receipt (between the merchant name/address block and the start of the invoice/items table) — on form-style invoices (e.g. JCLicht, Sherwin-Williams) a stray "X", "S", "O", "L", "D", "T" in the header is almost always a printed checkbox marker, account-type indicator, or OCR fragmentation of a vertically-printed "SOLD TO" / "SHIP TO" label, NOT a handwritten annotation.' . "\n" . '   - A line that does NOT fit the printed receipt\'s structure: not a label, not a price, not an address line of the merchant, not a barcode/SKU, not a date/time stamp, not a register/cashier ID, not a return-policy sentence.' . "\n" . '   - Casing may not match the printed receipt (e.g. lowercase "office" on an otherwise ALL-CAPS receipt is a strong signal it was handwritten).' . "\n\n" . 'STRONG POSITIVE SIGNAL — TOP-OF-RECEIPT STREET-NAME FRAGMENT:' . "\n" . 'If the FIRST line(s) of the OCR (before the merchant name) consist of a short "<number> <Word>" or "<number> <Word> <Word>" pattern that LOOKS LIKE a partial street address (e.g. "17 MARCELA", "215 Lincoln", "1422 Oak", "911 Will", "73 Birch"), this is almost CERTAINLY a handwritten job-site address scrawled by the contractor BEFORE filing the receipt — return it. Construction-receipt scribbles often drop the street suffix ("St", "Rd", "Ave") and may have small spelling quirks (e.g. "MARCELA" for "Marcella"). Do NOT skip these just because they have clean casing — on construction receipts they are the single most common handwritten annotation. The merchant name on these receipts is ALWAYS a recognizable retailer ("MENARDS", "HOME DEPOT", "LOWE\'S", "ACE HARDWARE", "MENARDS - MOUNT PROS", "LURVEY SUPPLY", etc.) that appears on a SEPARATE later line — anything appearing strictly BEFORE that retailer line and matching the number+name pattern is a handwritten note.' . "\n\n" . 'STRONG POSITIVE SIGNAL — TOP-OF-RECEIPT BARE HOUSE/JOB NUMBER:' . "\n" . 'Crews often write ONLY the house number or job number of the job site — no street name at all. If a short bare number (2-5 digits, e.g. "912", "3143", "5132") appears ALONE on its own line at the very top of the OCR, before or interleaved with the printed merchant header, return it as the handwritten note. OCR often mangles handwritten digits — accept renders like "9/2", "91 2", "0912", "S132" (for "5132") when they sit isolated at the top where no printed element belongs. Do NOT confuse these with PRINTED numbers, which are always attached to context: store numbers ("#1927", "STORE 3841"), register/transaction lines ("1927 00051 87620"), street addresses that include a street name on the SAME line, phone fragments, or dates. A printed number never floats alone above the merchant name — a handwritten one almost always does.' . "\n\n" . 'STRONG POSITIVE SIGNAL — TOP-OF-RECEIPT FIRST-NAME / WORKER TAG:' . "\n" . 'If the FIRST line of the OCR (before the merchant name) is a SINGLE proper-noun token — typically a first name like "Greg", "Mike", "Tony", "Joe", "Pat", "Sam", "Dan", "Will", "Ron", "Ed", or its OCR misread (e.g. "Gray" for cursive "Greg", "Tomy" for "Tony", "Jue" for "Joe") — that does NOT match any printed receipt element (not the merchant name, not "Sales Rep", not "Cashier", not a product brand), return it as a handwritten note. Workers commonly scrawl their own first name at the top of receipts before turning them in to the office. Treat ANY isolated single-word line at the very top of the document, that is NOT the merchant header and NOT a corporate-suffix phrase, as handwritten. Do NOT skip these just because the word is clean-cased or could plausibly be a color/brand — on construction receipts a single isolated word floating above the merchant name is almost always handwriting.' . "\n\n" . 'STRONG POSITIVE SIGNAL — HANDWRITING FUSED INTO A PRINTED HEADER/TAGLINE LINE:' . "\n" . 'When handwriting sits on top of the printed logo/tagline area, OCR often merges it INTO that printed line instead of isolating it. Look at the merchant-header and tagline lines (e.g. Home Depot\'s "How doers get more done.", Menards\' "SAVE BIG MONEY", "Dedicated to Service & Quality") for STRAY leading/trailing tokens that are not part of the known printed phrase: a short number (2-5 digits) and/or a 1-3 letter fragment. Those stray tokens are the handwriting — return THEM (joined with " | " if clearly separate marks) and drop the printed phrase. Example: "# 812 RA How doers get more done." → the printed tagline is "How doers get more done."; "812" and "RA" are stray → HandwrittenNote = "812 | RA".' . "\n\n" . 'STRONG POSITIVE SIGNAL — HANDWRITING EMBEDDED IN A LOGO FIGURE CAPTION:' . "\n" . 'CU emits the merchant logo at the top of a receipt as a markdown figure: `![<alt-text>](figures/N.N)`. When a worker scrawls a handwritten note (typically their first name) in the whitespace next to or across the logo, Azure OCR pulls that handwriting INTO the figure\'s alt-text alongside the logo words. SCAN the alt-text of any `![...](figures/...)` caption appearing on the first line(s) of the OCR. Split the alt-text on whitespace and look for a SHORT (≤6 character) token that does NOT match: the printed merchant name fragments (e.g. "ROUTE", "12", "RENTAL", "MENARDS", "HOME", "DEPOT", "ACE", "LOWE\'S"), a phone number (digits/dashes/parens), a URL (contains "." or "www"), the letter "o"/"O" used as a bullet/separator, or a common corporate-suffix word ("Inc", "LLC", "Co"). Such a stray token is almost certainly the OCR\'s mangled read of handwritten text — return it as the HandwrittenNote. Example: alt-text "o ROUTE 12 Guz RENTALO 847-253-4404 www.route12rental.com" → "Guz" is the unknown token (the cursive "Greg" written across the logo) → HandwrittenNote = "Guz".' . "\n\n" . 'EXAMPLES:' . "\n" . '  - raw_content starting "17 MARCELA\n\nMENARDS - MOUNT PROS\n740 E. RAND ROAD\nMOUNT PROSPECT, IL\n..." → HandwrittenNote = "17 MARCELA".' . "\n" . '  - raw_content starting "Gray\n\nLurvey Supply\n1819 N Wilke Rd\nArlington Heights, IL\n..." → HandwrittenNote = "Gray" (OCR misread of handwritten "Greg").' . "\n" . '  - raw_content starting "Mike\n\nHOME DEPOT\n..." → HandwrittenNote = "Mike".' . "\n" . '  - raw_content starting "912\n\nHow doers get more done.\n825 EAST DUNDEE ROAD\n..." → HandwrittenNote = "912" (bare handwritten house number above the printed header).' . "\n\n" . 'WHEN IN DOUBT — if a short word, address-like fragment, or single letter appears alone on its own line and does NOT match any standard printed receipt element, return it as a handwritten note.' . "\n\n" . 'NEVER return: the printed merchant name or address (including city/state/store-location lines such as "MOUNT PROSPECT", "MOUNT PROSPECT, IL 60056", or similar address fragments), the printed phone/email, store/register/transaction/cashier numbers, dates, times, item descriptions or codes, prices, tax/total/subtotal lines, payment-method labels, card last-four, EFT/Ref#/Auth/AID/ARQC values, return-policy sentences, rebate-receipt numbers (the number that follows "THE FOLLOWING REBATE RECEIPTS WERE PRINTED FOR THIS TRANSACTION"), coupon/promo/loyalty/rewards numbers, signatures, printed cashier/clerk/sales-rep/operator/associate name labels (e.g. "CASHIER ADRIANA", "Cashier: John", "Sales Rep Mike", "OPERATOR 12 MARIA", "Associate: Steve" — these are printed by the register on every Home Depot / Lowe\'s / Menards / Ace receipt and are NEVER handwriting, no matter how isolated the line looks), or any printed form label like "Sold To", "Ship To", "Bill To", "Customer", "P/O", "Order #", "Invoice #", "Date", "Signature".' . "\n\n" . 'CRITICAL ANTI-HALLUCINATION RULES:' . "\n" . '   - The FIRST line of a multi-line printed business header (e.g. "Solid Waste Agency of" followed by "Northern Cook County" / "Glenview Transfer Station" / "Operated by" / "Groot Industries, Inc") is the merchant name — it is PRINTED, never handwritten, even though it sits at the very top of the document. If a candidate line flows grammatically into the next printed line (e.g. ends with "of", "&", a comma, or is part of a corporate suffix like "Inc", "LLC", "Co", "Corp", "Ltd"), it is the merchant header — return null.' . "\n" . '   - Do NOT invent or fabricate a handwritten note when no handwriting markers (irregular casing, slashes, single letters, OCR-mangled cursive tokens, a top-of-receipt number+name street fragment, OR an isolated single proper-noun token at the very top above the merchant line) are visible in the OCR text. When the only candidate is a clean, properly-cased printed phrase that matches a known merchant header, return null.' . "\n\n" . 'Return ONLY the handwritten text, exactly as the OCR captured it (preserve original casing). If there is no handwritten note, return null.',
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

    /**
     * Reader for returned wet-signed lien waiver / GCSS scans arriving at
     * waivers@hive.contractors. enableBarcode makes CU decode the Code 128
     * footer barcode ("HLW-{waiver id}" / "HSS-{statement id}") into the
     * markdown as ![Code128](... "HLW-123"); the BarcodeValue field surfaces
     * it, and WaiverScanIngest also regexes the markdown directly.
     */
    private function buildWaiverDefinition(string $model): array
    {
        return [
            'description' => 'Hive lien waiver / GCSS signed-scan reader: barcode, footer filename, signature presence',
            'baseAnalyzerId' => 'prebuilt-document',
            'config' => [
                'returnDetails' => true,
                'enableBarcode' => true,
            ],
            'models' => ['completion' => $model],
            'fieldSchema' => [
                'name' => 'HiveWaiverScan',
                'description' => 'Identifies a returned wet-signed Hive lien waiver or sworn statement scan',
                // NOTE: no BarcodeValue LLM field — the decoded Code 128 value
                // arrives deterministically as a markdown annotation
                // (![Code128](... "HLW-2")) from the barcode engine, and an
                // LLM echo of it grounds on random spans and can hallucinate.
                'fields' => [
                    'FooterDocumentId' => [
                        'type' => 'string',
                        'method' => 'extract',
                        'description' => 'The bold document id printed inside the bordered card at the bottom-left of the page, directly beneath the barcode and beside the QR code. It looks like "HLW-16" or "HSS-6" — the letter H, then LW or SS, then a hyphen and digits. Return it exactly as printed, including the hyphen. Do NOT return any similar-looking text from elsewhere on the page, such as amounts or table values. If not present, leave empty.',
                    ],
                    'DocumentType' => [
                        'type' => 'string',
                        'method' => 'classify',
                        'description' => 'The type of document.',
                        'enum' => ['lien_waiver', 'sworn_statement', 'other'],
                    ],
                    'ClaimantCompanyName' => [
                        'type' => 'string',
                        'method' => 'extract',
                        'description' => 'On a lien waiver: the company name printed next to "COMPANY NAME". On a sworn statement: the contractor company the affiant swears for. This is the party whose document this is — NOT the general contractor who employed them (not the name after "has been employed by").',
                    ],
                    'PropertyAddress' => [
                        'type' => 'string',
                        'method' => 'extract',
                        'description' => 'The jobsite/premises street address the document concerns (after "premises known as" or "BUILDING LOCATED AT").',
                    ],
                    'IsWetSigned' => [
                        'type' => 'boolean',
                        'method' => 'generate',
                        'description' => 'True if the document carries at least one handwritten ink signature (in the SIGNATURE AND TITLE, SIGNATURE, or SIGNED blanks). Printed/typed names alone do not count.',
                    ],
                    'SignedDate' => [
                        'type' => 'date',
                        'method' => 'extract',
                        'description' => 'The handwritten date filled into the DATE blank in the TOP waiver section (next to COMPANY NAME), if any.',
                    ],
                    'AffiantName' => [
                        'type' => 'string',
                        'method' => 'extract',
                        'description' => 'The handwritten name filled into the (NAME) blank of the CONTRACTOR\'S AFFIDAVIT ("THE UNDERSIGNED, (NAME) ___ BEING DULY SWORN..."). Transcribe the handwriting as best you can. Empty if the blank is unfilled or there is no affidavit.',
                    ],
                    'AffiantPosition' => [
                        'type' => 'string',
                        'method' => 'extract',
                        'description' => 'The handwritten position/title filled into the "IS (POSITION) ___" blank of the CONTRACTOR\'S AFFIDAVIT (e.g. "President", "Secretary"). Empty if unfilled.',
                    ],
                    'AffidavitDate' => [
                        'type' => 'date',
                        'method' => 'extract',
                        'description' => 'The handwritten date in the DATE blank directly BELOW the affidavit table, next to the SIGNATURE line. Handwritten separators are often misread — "7125126" means 7/25/26. Empty if unfilled.',
                    ],
                    'WaiverSignatureText' => [
                        'type' => 'string',
                        'method' => 'extract',
                        'description' => 'Best-effort transcription of the handwriting on the SIGNATURE AND TITLE line in the top waiver section — usually a signature plus a title (e.g. "Rayde Jmy - Secretory"). The handwriting may land on its own line before/after the printed label in reading order. Empty only if that line is truly blank.',
                    ],
                    'CompanyAddressText' => [
                        'type' => 'string',
                        'method' => 'extract',
                        'description' => 'The claimant company\'s address on the ADDRESS line directly under COMPANY NAME in the waiver signature block. It may be pre-printed or handwritten. Empty only if that line is truly blank.',
                    ],
                    'AffidavitSignatureText' => [
                        'type' => 'string',
                        'method' => 'extract',
                        'description' => 'Best-effort transcription of the handwriting on the SIGNATURE line below the affidavit table. Signatures often transcribe as short scribble text (e.g. "por m") — return whatever is there. Empty only if that line is truly blank.',
                    ],
                    'StatementSignatureText' => [
                        'type' => 'string',
                        'method' => 'extract',
                        'description' => 'For a SWORN STATEMENT OF CONTRACTOR document: best-effort transcription of the handwriting on the SIGNED line (above the ADDRESS line, right side of the page). Signatures may transcribe as short scribble-like text — return whatever is there. Empty if blank or if this is not a sworn statement.',
                    ],
                    'NotaryDay' => [
                        'type' => 'string',
                        'method' => 'extract',
                        'description' => 'The handwritten day number in the jurat "SUBSCRIBED AND SWORN TO BEFORE ME THIS ___ DAY OF" (e.g. "25"). On a sworn statement the phrase prints in lowercase: "Subscribed and sworn to before me this ___ day of". Empty if that blank is unfilled.',
                    ],
                    'NotaryMonth' => [
                        'type' => 'string',
                        'method' => 'extract',
                        'description' => 'The handwritten month name in the jurat "DAY OF ___ , 20" (or lowercase "day of ___ , 20" on a sworn statement) — e.g. "July"; handwriting may transcribe imperfectly, e.g. "Juhy". Empty if that blank is unfilled.',
                    ],
                    'NotaryYear' => [
                        'type' => 'string',
                        'method' => 'extract',
                        'description' => 'The handwritten two-digit year after ", 20" in the jurat (e.g. "26"). Empty if that blank is unfilled.',
                    ],
                    'NotaryName' => [
                        'type' => 'string',
                        'method' => 'extract',
                        'description' => 'The notary\'s printed name as it appears on the official seal/stamp impression (e.g. "ADRIANA Y ALVAREZ"). The stamp may be recognized as a figure whose text reads like "OFFICIAL SEAL / <name> / Notary Public, State of Illinois / Commission No. ... / My Commission Expires ...". Empty if no stamp.',
                    ],
                    'NotaryCommissionNumber' => [
                        'type' => 'string',
                        'method' => 'extract',
                        'description' => 'The commission number printed on the notary seal/stamp (e.g. "840218"). Empty if no stamp or not readable.',
                    ],
                    'NotaryCommissionExpires' => [
                        'type' => 'date',
                        'method' => 'extract',
                        'description' => 'The commission expiration date printed on the notary seal/stamp (e.g. "September 23, 2028"). Empty if no stamp or not readable.',
                    ],
                    'HasNotaryStamp' => [
                        'type' => 'boolean',
                        'method' => 'generate',
                        'description' => 'True if an official notary seal/stamp impression is visible anywhere on the document — an inked or embossed stamp typically reading "OFFICIAL SEAL", the notary\'s name, "Notary Public, State of ...", a commission number and/or commission expiry. A handwritten signature on the NOTARY PUBLIC line alone is NOT a stamp.',
                    ],
                    'NotarySignatureText' => [
                        'type' => 'string',
                        'method' => 'extract',
                        'description' => 'Best-effort transcription of the notary\'s handwritten signature — the handwriting immediately ABOVE the printed "NOTARY PUBLIC" label (printed as "Notary Public" on a sworn statement, bottom-left). Signatures often transcribe as short scribble-like text; return whatever is legible. Empty only if that line is truly blank.',
                    ],
                ],
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

    private function buildCheckDefinition(string $model): array
    {
        $fields = [
            // ── Grouped: dates ─────────────────────────────────────────────
            'Date' => [
                'type'        => 'object',
                'description' => 'The check dates: (1) the handwritten date on the check face, (2) the typed bank caption date below the check image.',
                'properties'  => [
                    'OnCheck' => [
                        'type'        => 'date',
                        'description' => 'The HANDWRITTEN date on the check\'s DATE line (e.g. "06/03/26" → 2026-06-03, "6/3/26" → 2026-06-03). Read it from the check face only — ignore the typed caption below the image. Two-digit years are 20xx. Return null if the date line is blank or unreadable.',
                        'method'      => 'extract',
                    ],
                    'Bank' => [
                        'type'        => 'date',
                        'description' => 'The typed bank date from the caption "Ck Date: MM/DD/YYYY" printed below the check image (the date the bank processed the check). Return null when no caption is present.',
                        'method'      => 'extract',
                    ],
                ],
            ],

            // ── Grouped: check numbers ─────────────────────────────────────
            'CheckNumber' => [
                'type'        => 'object',
                'description' => 'The check number from its three sources: (1) printed on the check face, (2) encoded in the MICR line, (3) the typed bank caption. All three should normally agree.',
                'properties'  => [
                    'OnCheck' => [
                        'type'        => 'string',
                        'description' => 'The check number as PRINTED on the check itself, in the top-right corner (e.g. "2612"). Read it from the check face only — ignore the typed caption below the image. Digits only.',
                        'method'      => 'extract',
                    ],
                    'Micr' => [
                        'type'        => 'string',
                        'description' => 'The check number as encoded in the MICR line — the LAST group of digits at the bottom of the check, after the account number (e.g. "2612"). Digits only.',
                        'method'      => 'extract',
                    ],
                    'Bank' => [
                        'type'        => 'string',
                        'description' => 'The check number from the typed caption "Ck No: NNNN" printed below the check image. Digits only. Return null when no caption is present.',
                        'method'      => 'extract',
                    ],
                ],
            ],

            // ── Grouped: amounts ───────────────────────────────────────────
            'Amount' => [
                'type'        => 'object',
                'description' => 'The check amount from its two sources: (1) the handwritten courtesy box on the check face, (2) the typed bank caption below the check image.',
                'properties'  => [
                    'OnCheck' => [
                        'type'        => 'string',
                        'description' => implode(' ', [
                            'The HANDWRITTEN courtesy-box amount from the check face — the digits next to the "$" symbol at the end of the payee line (NOT the typed "Ck Date / Ck No / Amt" caption below the check image; that is a different field).',
                            'The courtesy box is ALWAYS in the same place on every check: the upper-right area of the check face, immediately to the right of a "$" sign, level with the payee line. It ALWAYS contains handwriting on a filled-out check — if the OCR there is garbled or low-confidence, still read it and correct it using the amount-in-words line; NEVER return null merely because the handwriting is messy.',
                            'Decide the output in this order:',
                            '(1) If the courtesy-box text is VALID US currency formatting AND agrees with the amount-in-words line, copy it as written — keep the writer\'s commas and decimal point (e.g. "2,397.50" stays "2,397.50"; "6,000.00" stays "6,000.00"; "990.00" stays "990.00").',
                            '(2) If the formatting is INVALID US currency (e.g. a comma followed by exactly two digits at the end like "23,30", or "6,289,00" with two commas), it is an OCR/handwriting artifact — output the MINIMAL correction: change or remove ONLY the wrong characters, never add separators or cents that are not written. Examples: "23,30" (words "twenty three hundred thirty") → "2330" (drop the stray comma — NOT "2,330.00"); "6,289,00" → "6,289.00" (second comma becomes the decimal point).',
                            '(3) If any DIGIT contradicts the amount-in-words line, the words win — especially the LEADING digit: cursive "$1,215.00" is often misread as "6215.00" because the "1," loop looks like a "6"; when the words read "one thousand two hundred fifteen", output "1215.00" (only the wrong digit changed).',
                            'MINIMAL-EDIT PRINCIPLE for corrections: the output must stay as close as possible to the characters visible in the courtesy box — change only what is wrong. Do not reformat.',
                            'The amount-in-words line is ALWAYS the authoritative cross-check for magnitude and digits.',
                            'Handwritten cents may appear as "50/100" (= .50) or "00/100" (= .00). No "$" sign.',
                            'This field must always be sourced from the courtesy box on the check face — never from the typed caption below the check image.',
                            'Return null if the courtesy box is blank or unreadable.',
                        ]),
                        'method'      => 'extract',
                    ],
                    'Bank' => [
                        'type'        => 'number',
                        'description' => 'The check amount in dollars from the typed caption "Amt: $N.NN" printed below the check image (e.g. 2330.00 from "Amt: $2330.00"). Return null when no caption is present.',
                        'method'      => 'extract',
                    ],
                ],
            ],

            'AmountWords' => [
                'type'        => 'string',
                'description' => implode(' ', [
                    'The handwritten amount-in-words line of the check — ONLY that single line (it ends near the printed "DOLLARS" label). Never source this from the payee line, the courtesy box, or the typed caption below the check.',
                    'Transcribe the line AS WRITTEN, but CORRECT OCR-garbled tokens into the real English number words the writer intended — use the check amount to disambiguate garbled words.',
                    'Example: OCR "two thousand wille hundred 00/200us" on a $2,900.00 check → "two thousand nine hundred" ("wille" is a misread of "nine").',
                    'Example: OCR "two thousand two hunded eigty 0" on a $2,280.00 check → "two thousand two hundred eighty".',
                    'Example: OCR "twenty three hundred thirty and 100" on a $2,330.00 check → "twenty three hundred thirty" — KEEP the writer\'s phrasing; do NOT rephrase it as "two thousand three hundred thirty".',
                    'PRESERVE the phrasing and word order as handwritten — only fix misread tokens, never restructure the number into a different wording.',
                    'EVERY word in the output MUST be a valid English number word ("one" … "ninety", "hundred", "thousand", "million") or the connector "and" — this line is ALWAYS an amount in words. NEVER output OCR garble like "sir", "hundrd", "trenty", "hundes", "fivet", "wille", "hunokd", "eigty" — replace each garbled token with the valid number word it visually resembles, cross-checked against the check amount (e.g. on a $6,620.00 check, OCR "six thousand sir hundrd trenty" → "six thousand six hundred twenty").',
                    'Omit the trailing cents fraction when it is zero ("00/100"); append "and NN/100" only when cents are non-zero (e.g. "two thousand three hundred ninety seven and 50/100").',
                    'Use lowercase words. Return null only when the line is completely blank.',
                ]),
                'method'      => 'extract',
            ],

            'Memo' => [
                'type'        => 'array',
                'description' => implode(' ', [
                    'The individual references handwritten on the MEMO line at the bottom-left of the check, as an array with ONE entry per reference (like PO numbers on an expense).',
                    'Split on commas — e.g. handwriting "1124, 1125" → ["1124", "1125"]; "#282,285,301(1800)" → ["282", "285", "301(1800)"]; "25-9, 17-1000" → ["25-9", "17-1000"].',
                    'Strip a leading "#" from reference numbers. A single word or address is one entry (e.g. ["Violet"], ["Capital credit"]).',
                    'NEVER return the MICR digit line printed along the very bottom edge of the check (long digit groups, often with ⑆/⑈ symbols) — that is not a memo.',
                    'Return an empty array when the memo line is blank.',
                ]),
                'items'       => [
                    'type'        => 'string',
                    'description' => 'One memo reference exactly as handwritten (leading "#" stripped).',
                    'method'      => 'extract',
                ],
            ],

            // ── Grouped: payer / payee info ────────────────────────────────
            'CheckInfo' => [
                'type'        => 'object',
                'description' => 'Who wrote the check and who it is written to: the printed payer block at the top-left of the check face, and the handwritten payee.',
                'properties'  => [
                    'Payee' => [
                        'type'        => 'string',
                        'description' => 'Who the check is written to — the handwritten name on the "PAY TO THE ORDER OF" line. May be a person (e.g. "Grzegorz Szady", "Jesus De La Torre") or a business (e.g. "Shartech Electric", "Vol Nat Heating", "TBD Painting", "RG Tiles", "Accomplished Plumbing"). Read the handwriting carefully; return the name as written with normal capitalization.',
                        'method'      => 'extract',
                    ],
                    'PayerName' => [
                        'type'        => 'string',
                        'description' => 'The printed account-holder name block at the top-left of the check (e.g. "GS CONSTRUCTION AND REMODELING"). Name only, without the address lines.',
                        'method'      => 'extract',
                    ],
                    'PayerAddress' => [
                        'type'        => 'string',
                        'description' => implode(' ', [
                            'The printed payer address under the account-holder name at the top-left of the check — street and city/state/zip combined into one string separated by ", " (e.g. "400 N WHEELING ROAD, PROSPECT HEIGHTS, ILLINOIS 60070"). Do NOT include the payer name or phone number.',
                            'These checks are fax-quality scans and OCR often misreads printed characters — CORRECT obvious misreads so the result is a plausible US address:',
                            'A ZIP code is EXACTLY 5 DIGITS — fix letter/digit confusions in it (letter O → digit 0, G → 6, B → 8, S → 5, I/l → 1; e.g. OCR "GO070" or "COD70" → "60070", "ILLINORS" → "ILLINOIS").',
                            'Restore missing spaces the OCR dropped (e.g. "NWHEELING" → "N WHEELING").',
                            'Fix punctuation misreads (a period between city and state is a comma: "PROSPECT HEIGHTS. ILLINOIS" → "PROSPECT HEIGHTS, ILLINOIS").',
                        ]),
                        'method'      => 'extract',
                    ],
                    'PayerPhone' => [
                        'type'        => 'string',
                        'description' => implode(' ', [
                            'The printed phone number in the payer block at the top-left of the check, if present, formatted "(XXX) XXX-XXXX".',
                            'A US phone number has EXACTLY 10 digits with a 3-DIGIT area code — correct OCR misreads so the result is valid:',
                            'e.g. OCR "(1347) 873-7217" has a 4-digit area code, which is impossible — the printed "8" was misread as "13", so the correct value is "(847) 873-7217".',
                            'Common confusions on blurry scans: "8" read as "13", "8" read as "6" (a filled-in or faded top loop — inspect the glyph closely before accepting a "6" in a printed phone number, e.g. "673-7217" is usually a misread of "873-7217"), "6" as "G" or "0", "0" as "O" or "D".',
                            'Return null when no phone is printed.',
                        ]),
                        'method'      => 'extract',
                    ],
                ],
            ],

            // ── Grouped: bank info ─────────────────────────────────────────
            'BankInfo' => [
                'type'        => 'object',
                'description' => 'The payer\'s bank details: the printed bank name and the routing/account groups from the MICR line at the bottom of the check.',
                'properties'  => [
                    'BankName' => [
                        'type'        => 'string',
                        'description' => 'The printed bank name on the check (e.g. "citibank"). Return ONLY the clean bank name: strip OCR artifacts from the trademark/registered symbol next to the logo (an apostrophe, asterisk, quote, or degree sign — "citibank\'" or "cítìbank*" → "citibank") and remove stray accents/diacritics OCR adds to logo letters ("cítìbank" → "citibank").',
                        'method'      => 'extract',
                    ],
                    'RoutingNumber' => [
                        'type'        => 'string',
                        'description' => 'The bank routing number — the FIRST group of the MICR line at the bottom of the check, always exactly 9 digits (e.g. "271070801"). Digits only.',
                        'method'      => 'extract',
                    ],
                    'AccountNumber' => [
                        'type'        => 'string',
                        'description' => 'The payer bank account number — the SECOND group of the MICR line, between the routing number and the check number (e.g. "0800854903"). Digits only, preserving leading zeros.',
                        'method'      => 'extract',
                    ],
                ],
            ],
        ];

        return [
            'description'    => 'Hive 2025 individual check analyzer — extracts check number, date, amount, payee, memo, and MICR details from a single scanned check image (typically cropped from a bank statement, with the typed "Ck Date / Ck No / Amt" caption line included below the image).',
            'baseAnalyzerId' => 'prebuilt-document',
            'config'         => [
                'returnDetails'                    => true,
                'estimateFieldSourceAndConfidence' => true,
            ],
            'models'         => [
                'completion' => $model,
            ],
            'fieldSchema'    => [
                'name'        => 'HiveCheckSchema',
                'description' => 'Schema for a single scanned check image. Handwritten fields (payee, memo, amount words) require careful cursive reading; typed caption values are authoritative for number/date/amount.',
                'fields'      => $fields,
            ],
        ];
    }

    private function buildCheckStatementDefinition(string $model): array
    {
        $fields = [
            'AccountNumber' => [
                'type'        => 'string',
                'description' => 'The bank account number printed in the statement page header (e.g. "Account 800854903" → "800854903"). Digits only, without the "Account" label.',
                'method'      => 'extract',
            ],
            'StatementPeriod' => [
                'type'        => 'string',
                'description' => 'The statement period printed in the page header (e.g. "Statement Period: Jun 1 - Jun 30, 2026" → "Jun 1 - Jun 30, 2026"). Return the period text without the label.',
                'method'      => 'extract',
            ],
            'Checks' => [
                'type'        => 'array',
                'description' => implode(' ', [
                    'One row per imaged check across ALL pages of the document.',
                    'Bank statement check-image pages show scanned check images laid out in a grid (typically 2 columns × up to 5 rows per page).',
                    'Directly BELOW each check image there is a machine-printed caption line in the form "Ck Date: MM/DD/YYYY Ck No: NNNN Amt: $N.NN" (e.g. "Ck Date: 06/02/2026 Ck No: 2607 Amt: $2397.50").',
                    'ALWAYS read CheckNumber, CheckDate, and CheckAmount from this printed caption line — it is typed and reliable, unlike the handwritten check contents above it.',
                    'Every caption line on every page is exactly one check — do NOT skip any, do NOT merge two checks, and do NOT invent checks that have no caption.',
                    'Process pages in order and within a page read the grid left-to-right, top-to-bottom.',
                ]),
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'CheckNumber' => [
                            'type'        => 'string',
                            'description' => 'Check number from the caption "Ck No: NNNN" printed below the check image (e.g. "2607"). The same number is also printed in the top-right corner of the check image itself — if the caption is unreadable, fall back to that. Digits only.',
                            'method'      => 'extract',
                        ],
                        'CheckDate' => [
                            'type'        => 'date',
                            'description' => 'Check date from the caption "Ck Date: MM/DD/YYYY" printed below the check image (e.g. "06/02/2026"). Use the typed caption date, NOT the handwritten date on the check image.',
                            'method'      => 'extract',
                        ],
                        'CheckAmount' => [
                            'type'        => 'number',
                            'description' => 'Check amount in dollars from the caption "Amt: $N.NN" printed below the check image (e.g. 2397.50 from "Amt: $2397.50"). Use the typed caption amount, NOT the handwritten courtesy box on the check image.',
                            'method'      => 'extract',
                        ],
                    ],
                ],
            ],
        ];

        return [
            'description'    => 'Hive 2025 bank-statement check-image analyzer — extracts one row per imaged check (check number, date, amount) from the printed caption below each check image, across all pages.',
            'baseAnalyzerId' => 'prebuilt-document',
            'config'         => [
                // Field grounding: return the source polygon of each extracted value so the
                // app can locate every check's caption and crop the check image above it.
                'returnDetails'                    => true,
                'estimateFieldSourceAndConfidence' => true,
            ],
            'models'         => [
                'completion' => $model,
            ],
            'fieldSchema'    => [
                'name'        => 'HiveCheckStatementSchema',
                'description' => 'Schema for bank statement check-image page extraction. Each printed "Ck Date / Ck No / Amt" caption equals exactly one check.',
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
                    'Do NOT return billing-summary labels or amounts such as "Discounts", "Credits", "Tax", "Subtotal", "Total", "Charges", or "Balance Due".',
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
