<?php

namespace App\Support\Transactions;

/**
 * Payment processors that print themselves first on a card descriptor, with
 * the company actually paid written after a separator.
 *
 * Paddle, Square, Toast, PayPal and friends are merchants of record: the
 * bank shows "PADDLE.NET* DATAFORSEO", "TST* BLOWFISH", "IN *BM INSURANCE
 * AGENCY". A match rule written for the prefix alone claims every other
 * company billed through the same processor — rule 280 ("PADDLE.NET",
 * 2023-09-25, from when Laravel Daily's charge read just that) is how two
 * DataForSEO charges (30216, 29633) were filed under Laravel Daily on
 * 2026-11-10.
 *
 * Curated from the local copy of production (read 2026-11-10: 1,382 live
 * descriptors containing "*", 1,210 distinct, grouped by the token before
 * the "*") plus the well-known US processors. Prefixes that are a brand's
 * own order reference are deliberately absent — "Amazon.com*0H1IL89S3",
 * "MSFT * E01009BEDW", "HLU*HULU", "DNH*GODADDY.COM", "ADOBE *CREATIVE
 * CLOUD", "AT&T*BILL PAYMENT", "eBay O*03-11916": there the text before
 * the "*" IS the merchant. Apple ("APPLE.COM/BILL") and Patreon ("PATREON*
 * MEMBERSHIP") are absent for the opposite reason: they bill for others
 * but never name them, so the only vendor a rule can point at is Apple or
 * Patreon itself. Processors Plaid names in a transaction's own payload
 * (payment_processor, payment_app counterparties) are read on top of this
 * list by MerchantIdentity.
 *
 * Each entry's `pattern` matches the processor token where it appears in a
 * descriptor — bank text such as "DEBIT PURCHASE Sep 04 4849 " may precede
 * it — including its separator, so what follows is the merchant. Short
 * codes (SQ, SP, FS, IN, TST…) require the "*" or they would match ordinary
 * words; `bare` is the processor's name on its own, used to recognise a
 * rule or descriptor that is nothing but the processor. `names` are the
 * spellings Plaid uses for the processor in merchant_name / counterparties,
 * reduced to letters and digits. `evidence` says why the entry exists.
 *
 * Framework-free on purpose: the unit suite runs it on a bare PHPUnit case.
 */
final class PaymentProcessors
{
    /**
     * Citi's "DEBIT PURCHASE …" lines keep 21 characters of merchant text —
     * "PADDLE.NET* DATAFORSE", "IN *BM INSURANCE AGEN", "TST*SPEEDOS HARBOR
     * SI", "SQU*SQ *WOLF & PALATI" all read that long in production — so a
     * match text cut to what fits after the prefix covers the truncated
     * form as well as the full one ("DATAFORSE" claims both DataForSEO
     * charges).
     */
    public const DESCRIPTOR_WINDOW = 21;

    /** A cut match text keeps at least this many characters, else it is left whole. */
    private const MIN_MATCH_TEXT = 5;

    /** Nothing letter-or-digit directly before a processor token. */
    private const BOUNDARY = '(?<![A-Z0-9])';

    /** Separators and reference junk a rule or descriptor may end with. */
    private const TRAILING_JUNK = '[\s\*\.\-:#\/]*';

    /**
     * @return array<string, array{pattern: string, bare: string, names: list<string>, evidence: string}>
     */
    public static function all(): array
    {
        return [
            'Paddle' => [
                'pattern' => 'PADDLE(?:\.(?:NET|COM)\s?\*?|\s?\*)',
                'bare' => 'PADDLE(?:\.(?:NET|COM))?',
                'names' => ['paddle', 'paddlenet', 'paddlecom'],
                'evidence' => 'Dev DB 3 rows: PADDLE.NET* DATAFORSEO (30216), DEBIT PURCHASE Sep 04 4849 PADDLE.NET* DATAFORSE 26248 (29633), bare PADDLE.NET (20442, 2023).',
            ],
            'PayPal' => [
                'pattern' => 'PAYPAL(?:\.COM)?\s?\*|PP\s?\*',
                'bare' => 'PAYPAL(?:\.COM)?',
                'names' => ['paypal'],
                'evidence' => 'Dev DB: PAYPAL *METWOOD INC; 33 rows carry payment_meta.payment_processor PayPal whose original_description reads PAYPAL *NETFLIX.COM, PAYPAL *GITHUB INC, PAYPAL *UBER while Plaid\'s cleaned name drops the prefix.',
            ],
            'Square' => [
                'pattern' => 'SQU\*SQ\s?\*|SQ\s?\*|SQUAREUP\s?\*',
                'bare' => 'SQ|SQU|SQUARE|SQUAREUP',
                'names' => ['square', 'squareup', 'sq'],
                'evidence' => 'Dev DB 7 descriptors: SQ *ALLIED A/C & HE, SQ *BEN & JERRY\'S GATLINB, SQU*SQ *PNS NOODLE SH, SQU*SQ *WOLF & PALATI; Plaid payment_terminal Square on 13 rows.',
            ],
            'Toast' => [
                'pattern' => 'TST\s?\*',
                'bare' => 'TST|TOAST|TOASTTAB',
                'names' => ['toast', 'toasttab', 'tst'],
                'evidence' => 'Dev DB 32 rows, 27 descriptors: TST* BLOWFISH, TST* EGG HARBOR CAFE - TH, TST*SPEEDOS HARBOR SI, TST*LAMPLIGHTER INN TAVE; Plaid payment_terminal Toast on 26 rows.',
            ],
            'Shopify' => [
                'pattern' => 'SHOPIFY(?:\.COM)?(?![A-Z0-9])\s?\*?|SP\s?\*',
                'bare' => 'SHOPIFY(?:\.COM)?|SP',
                'names' => ['shopify', 'sp'],
                'evidence' => 'Dev DB 2 rows: SP * IBLINDS HAB HOME, SP * SHOWERBASE.COM.',
            ],
            'FastSpring' => [
                'pattern' => 'FASTSPRING(?:\.COM)?(?![A-Z0-9])\s?\*?|FS\s?\*',
                'bare' => 'FASTSPRING(?:\.COM)?|FS',
                'names' => ['fastspring', 'fs'],
                'evidence' => 'Dev DB 2 rows: DEBIT PURCHASE Mar 07 4849 FS *ROOMSKETCHER 26067, FASTSPRING SEJDA (rule 192 "FASTSPRING SEJDA" names its merchant and stays valid).',
            ],
            '2Checkout' => [
                'pattern' => '2CO(?:\.COM)?\s?\*|2CHECKOUT(?:\.COM)?(?![A-Z0-9])\s?\*?',
                'bare' => '2CO(?:\.COM)?|2CHECKOUT(?:\.COM)?',
                'names' => ['2checkout', '2co', 'verifone'],
                'evidence' => 'Well-known US software merchant of record ("2CO.COM*MERCHANT"); none in the dev DB.',
            ],
            'Intuit' => [
                'pattern' => 'INT\*IN\s?\*|IN\s?\*',
                'bare' => 'IN|INT|INTUIT|QUICKBOOKS',
                'names' => ['intuit', 'intuitquickbooks', 'quickbooks'],
                'evidence' => 'Dev DB 19 rows: IN *BM INSURANCE AGENCY, IN *BM TAXES & ACCOUNTING, IN *THE MULCH CENTER LLC, INT*IN *BM INSURANCE 19227 — QuickBooks Payments invoices.',
            ],
            'Google' => [
                'pattern' => 'GOOGLE\s?\*',
                'bare' => 'GOOGLE(?:\.COM)?',
                'names' => ['google', 'googleplay', 'googleplaystore', 'googlewallet'],
                'evidence' => 'Well-known "GOOGLE *YouTube" / "GOOGLE *App Name" Play billing; Plaid names Google Wallet (10 rows) and Google Play Store (4 rows). The dev DB\'s own "Google CLOUD vzWQRJ" rows have no "*" and are not split.',
            ],
            'Lemon Squeezy' => [
                'pattern' => 'LEMON ?SQUEEZY(?:\.COM)?(?![A-Z0-9])\s?\*?',
                'bare' => 'LEMON ?SQUEEZY(?:\.COM)?',
                'names' => ['lemonsqueezy'],
                'evidence' => 'Well-known software merchant of record ("LEMONSQUEEZY* MERCHANT"); none in the dev DB.',
            ],
            'Teleflora' => [
                'pattern' => 'TLF\s?\*',
                'bare' => 'TLF|TELEFLORA',
                'names' => ['teleflora', 'tlf'],
                'evidence' => 'Dev DB 4 rows: TLF*AMLINGS FLOWERLAND, BUSINESS DEBIT TLF*AMLINGS FLOWERL ARLINGTON HTS IL.',
            ],
            'GoDaddy Payments' => [
                'pattern' => 'GDP\s?\*',
                'bare' => 'GDP|GODADDY PAYMENTS',
                'names' => ['godaddypayments', 'gdp'],
                'evidence' => 'Dev DB 1 row: GDP*Danielle\'s Delights. GoDaddy\'s own "DNH*GODADDY.COM" is GoDaddy the merchant and is not listed.',
            ],
            'Slice' => [
                'pattern' => 'SLICE\s?\*',
                'bare' => 'SLICE',
                'names' => ['slice'],
                'evidence' => 'Dev DB 1 row: SLICE*ROSATISPIZZA; Plaid marketplace Slice on 1 row.',
            ],
            'Xsolla' => [
                'pattern' => 'XSOLLA\s?\*',
                'bare' => 'XSOLLA',
                'names' => ['xsolla'],
                'evidence' => 'Dev DB 1 row: DEBIT PURCHASE Aug 01 4842 XSOLLA *ROCKSTAR 25214.',
            ],
            'Official Payments' => [
                'pattern' => 'OPC\s?\*',
                'bare' => 'OPC|OFFICIAL PAYMENTS(?: CORP)?',
                'names' => ['officialpayments', 'opc'],
                'evidence' => 'Dev DB 2 rows: OPC*SELECTIVE INSURAN 19033 / 19051 — the insurer\'s bill-pay processor.',
            ],
            'Link2Gov' => [
                'pattern' => 'L2G\s?\*',
                'bare' => 'L2G|LINK2GOV',
                'names' => ['link2gov', 'l2g'],
                'evidence' => 'Dev DB 1 row: DEBIT PURCHASE Sep 02 4846 L2G*WBMI INS. BILL PA 20248.',
            ],
            '4TE' => [
                'pattern' => '4TE\s?\*',
                'bare' => '4TE',
                'names' => ['4te'],
                'evidence' => 'Dev DB 2 rows with two different payees after it: 4TE*ILSecofStateSFee, 4TE*SOSBS CERTIFICATES FO — the Illinois Secretary of State online-fee processor.',
            ],
            'USA Technologies' => [
                'pattern' => 'USA\s?\*',
                'bare' => 'USA TECHNOLOGIES|CANTALOUPE',
                'names' => ['usatechnologies', 'cantaloupe'],
                'evidence' => 'Dev DB 1 row: USA*VEND AT AIR SERV — the vending-machine card reader network.',
            ],
        ];
    }

    /**
     * The processor a descriptor is billed through and the merchant text
     * after it. The processor may sit anywhere ("DEBIT PURCHASE Sep 04 4849
     * PADDLE.NET* DATAFORSE 26248"); trailing reference numbers, masked
     * digits and card digits are trimmed off the merchant. A descriptor that
     * is nothing but the processor ("PADDLE.NET") yields a null merchant.
     *
     * @return array{processor: ?string, merchant: ?string}
     */
    public static function split(string $descriptor): array
    {
        $located = self::locate($descriptor);

        return [
            'processor' => $located['processor'] ?? null,
            'merchant' => $located['merchant'] ?? null,
        ];
    }

    /**
     * Does this rule pattern (as stored: "*" escaped, maybe a regex) name
     * nothing but a processor? "PADDLE.NET", "PAYPAL \*", "SQ \*" do;
     * "PADDLE\.NET\* ?DATAFORSE", "TST\* TASTES ON THE FLY", "DATAFORSE"
     * do not.
     */
    public static function isProcessorOnly(string $ruleDesc): bool
    {
        $text = self::unescape($ruleDesc);
        if ($text === '') {
            return false;
        }

        $split = self::split($text);

        return $split['processor'] !== null && $split['merchant'] === null;
    }

    /**
     * Is this merchant name (Plaid's merchant_name or a counterparty) just a
     * processor — "Paddle", "PayPal", "Square", "Toast"?
     */
    public static function isProcessorName(?string $name): bool
    {
        $normalized = self::normalize((string) $name);
        if ($normalized === '') {
            return false;
        }

        foreach (self::all() as $processor) {
            if (in_array($normalized, $processor['names'], true)) {
                return true;
            }
            if (preg_match('/^(?:'.$processor['bare'].')'.self::TRAILING_JUNK.'$/i', trim((string) $name)) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The match text to propose for a processor descriptor: the merchant
     * part, cut to what the bank's descriptor window (DESCRIPTOR_WINDOW
     * characters) keeps after the processor prefix, so one rule claims the
     * full and the truncated form. "PADDLE.NET* DATAFORSEO" → "DATAFORSE",
     * "IN *BM INSURANCE AGENCY" → "BM INSURANCE AGEN", "TST* BLOWFISH" →
     * "BLOWFISH". Null when the descriptor names no merchant after a
     * processor.
     */
    public static function matchText(string $descriptor): ?string
    {
        $located = self::locate($descriptor);
        if ($located === null || $located['merchant'] === null) {
            return null;
        }

        $budget = self::DESCRIPTOR_WINDOW - ($located['merchant_start'] - $located['offset']);
        $merchant = $located['merchant'];

        if ($budget >= self::MIN_MATCH_TEXT && strlen($merchant) > $budget) {
            $merchant = rtrim(substr($merchant, 0, $budget), " \t*-.:#/");
        }

        return $merchant !== '' ? $merchant : null;
    }

    /**
     * The plain refusal shown when a rule would be written for a processor
     * alone. $example is the merchant text to match on instead, when known.
     */
    public static function refusalMessage(string $matchText, ?string $example = null): string
    {
        $message = trim($matchText).' is a payment processor many companies bill through. Match on the company name after it instead';

        return $message.($example !== null && $example !== '' ? ', like '.$example.'.' : '.');
    }

    /** Letters and digits only, lowercase — how `names` are spelled. */
    public static function normalize(string $text): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '', $text));
    }

    /**
     * A stored rule back to plain text: VendorTransaction escapes "*" and
     * "/" on the way in, and some rules are regexes ("PADDLE\.NET\* ?DATAFORSE").
     * Regex quantifiers left over after unescaping are harmless here —
     * they only make the text name more than a processor.
     */
    private static function unescape(string $ruleDesc): string
    {
        return trim((string) preg_replace('/\\\\(.)/', '$1', $ruleDesc));
    }

    /**
     * @return array{processor: string, offset: int, length: int, merchant_start: int, merchant: ?string}|null
     */
    private static function locate(string $descriptor): ?array
    {
        $descriptor = trim($descriptor);
        if ($descriptor === '') {
            return null;
        }

        $best = null;
        foreach (self::all() as $name => $processor) {
            $regex = '/'.self::BOUNDARY.'(?:'.$processor['pattern'].')/i';
            if (preg_match($regex, $descriptor, $match, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }
            [$text, $offset] = $match[0];
            $length = strlen($text);
            if ($best === null || $offset < $best['offset'] || ($offset === $best['offset'] && $length > $best['length'])) {
                $best = ['processor' => $name, 'offset' => $offset, 'length' => $length];
            }
        }

        if ($best === null) {
            foreach (self::all() as $name => $processor) {
                if (preg_match('/^(?:'.$processor['bare'].')'.self::TRAILING_JUNK.'$/i', $descriptor) === 1) {
                    return ['processor' => $name, 'offset' => 0, 'length' => strlen($descriptor), 'merchant_start' => strlen($descriptor), 'merchant' => null];
                }
            }

            return null;
        }

        $rest = substr($descriptor, $best['offset'] + $best['length']);
        $skipped = strlen($rest) - strlen(ltrim($rest, " \t*-:#"));

        return $best + [
            'merchant_start' => $best['offset'] + $best['length'] + $skipped,
            'merchant' => self::merchantText($rest),
        ];
    }

    /**
     * The merchant part of a descriptor, with separators, trailing
     * reference numbers ("26248", "131045946186"), masked or card digits
     * ("**********", "#0430") and phone numbers trimmed. Null when nothing
     * letter-like is left.
     */
    private static function merchantText(string $rest): ?string
    {
        $text = trim(ltrim($rest, " \t*-:#"));

        while (preg_match('/^(.*?)[\s\-]+(?:\d{4,}|[\*X]{3,}\d*|#\d+|\d{3}-\d{3}-\d{4})$/i', $text, $match) === 1) {
            $text = rtrim($match[1]);
        }

        $text = rtrim($text, " \t*-.:#/");

        return preg_match('/[A-Z]/i', $text) === 1 ? $text : null;
    }
}
