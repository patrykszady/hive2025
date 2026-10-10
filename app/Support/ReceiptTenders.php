<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\ExpenseReceipts;

/**
 * How a purchase was paid, read from what Hive already holds about it: the
 * Amazon order's charges, the tender block printed on a store receipt, or
 * the receipt reader's payment_methods. Each tender becomes one line —
 * a card (with its last four), store credit, a gift card, points or cash.
 *
 * A set of lines is only trusted when it adds up to the expense: a receipt
 * that printed its tenders twice, or a tender the text did not catch, would
 * otherwise tell the bank matcher to look for the wrong charges.
 */
class ReceiptTenders
{
    /** Card brands as Home Depot, Menards and Floor & Decor print them. */
    protected const BRAND = 'VISA|MASTERCARD|MASTER CARD|AMEX|AMERICAN EXPRESS|DISCOVER|US DEBIT|DEBIT|US CREDIT|CREDIT';

    /**
     * Store tenders printed after a masked number or on a line of their own.
     * Menards' "Gift Certificate" is its rebate certificate, spent like a gift card.
     */
    protected const STORE_TENDER = 'STORE CREDIT|GIFT CARD|GIFT CERTIFICATE|PROXTRADOLLARS?|PRO XTRA DOLLARS?';

    /** Labels for Menards' own credits (kept in `brand`, shown with the method). */
    public const REBATE_CHECK = 'REBATE CHECK';

    public const CERTIFICATE = 'CERTIFICATE';

    /** One amount: 1,234.56 / $12.34 / -12.34 / 12.34- / (12.34). Never 10.2500% or a date. */
    protected const AMOUNT = '(\()?(-)?\s?\$?\s?(\d{1,3}(?:,\d{3})+|\d+)\.(\d{2})(?![\d%])(-)?(\))?';

    /**
     * The tender lines printed on a receipt, with their printed signs.
     *
     * @return list<array{method: string, amount: float, last_four: ?string, brand: ?string}>
     */
    public static function fromText(string $text): array
    {
        $lines = self::lines($text);
        $tenders = [];
        $consumed = [];
        $change = 0.0;

        foreach ($lines as $i => $line) {
            if (isset($consumed[$i])) {
                continue;
            }

            // Menards: a rebate check spent at the register prints BEFORE the total as a
            // negative line ("MENARD REBATE NO: 6323495338" then "214.98-"), so TOTAL SALE
            // is what was left after it. It pays like store credit: a positive tender.
            // The check's amount is the NEGATIVE one: on its line, the next, or the one
            // before. An item price the reader dragged onto the line ("… 20 @10.48
            // 209.60", 19310) is not it; failing those, the first trailing-minus amount
            // further down that no card, total or tender printed ("587.93 204.85-").
            if (preg_match('/^MENARDS? REBATE NO:?\s*(\d{4,})\b\s*(.*)$/i', $line, $m)) {
                $amount = self::negative(self::amountIn($m[2]));

                if ($amount === null) {
                    $peek = $consumed;
                    $next = self::amountAhead($lines, $i + 1, 1, $peek);

                    if ($next !== null && $next < 0) {
                        $consumed = $peek;
                        $amount = $next;
                    }
                }

                if ($amount === null && $i > 0 && ! isset($consumed[$i - 1]) && ($before = self::amountIn($lines[$i - 1])) !== null && $before < 0) {
                    $amount = $before;
                }

                $amount ??= self::trailingMinusAhead($lines, $i + 1);

                if ($amount !== null) {
                    $tenders[] = ['method' => ExpensePayment::STORE_CREDIT, 'amount' => abs($amount), 'last_four' => substr($m[1], -4), 'brand' => self::REBATE_CHECK];
                }

                continue;
            }

            // Menards: "CERTIFICATE-BARCODED 7.59" then "****** 7256".
            if (preg_match('/^CERTIFICATE(?:-BARCODED)?\b\s*(.*)$/i', $line, $m)) {
                $amount = self::amountIn($m[1]) ?? self::amountAhead($lines, $i + 1, 2, $consumed);
                $masked = self::maskedAhead($lines, $i + 1, 3);

                if ($masked !== null) {
                    $consumed[$masked['index']] = true;
                }

                if ($amount !== null) {
                    $tenders[] = ['method' => ExpensePayment::GIFT_CARD, 'amount' => $amount, 'last_four' => $masked['last_four'] ?? null, 'brand' => self::CERTIFICATE];
                }

                continue;
            }

            // The register ACE, True Value and lumber yards share: "BC AMT: $ 127.43",
            // then "BK CARD#: XXXXXXXXXXXX4849". A declined card ("AUTH : DECLINED")
            // paid nothing.
            if (preg_match('/^BC AMT:?\s*(.*)$/i', $line, $m)) {
                $amount = self::amountIn($m[1]);

                for ($j = $i + 1; $amount === null && $j <= $i + 3 && isset($lines[$j]); $j++) {
                    if (preg_match('/^'.self::AMOUNT.'$/', $lines[$j])) {
                        $consumed[$j] = true;
                        $amount = self::amountIn($lines[$j]);
                    }
                }

                $card = null;
                $declined = false;

                for ($j = $i + 1; $j <= $i + 10 && isset($lines[$j]); $j++) {
                    if (preg_match('/^BC AMT/i', $lines[$j])) {
                        break;
                    }

                    if ($card === null && preg_match('/(?:BK CARD#:?\s*)?X{4,16}\s?(\d{4})$/i', $lines[$j], $c)) {
                        $card = $c[1];
                        $consumed[$j] = true;
                    }

                    if (preg_match('/DECLINED/i', $lines[$j])) {
                        $declined = true;
                    }
                }

                if ($amount !== null && ! $declined) {
                    $tenders[] = self::tender(null, $amount, $card);
                }

                continue;
            }

            // Route 12 Rental: "VI Card #: XXXXXXXXXXXX4060 Type: AUTHORIZATION ONLY" is
            // the deposit hold at pickup, never charged; the "FORCE/PRE-AUTHORIZED" block
            // after it is the charge (27028). The type sits on the line or the next; the
            // amount is the first lone amount before the next card block.
            if (preg_match('/^(?:[A-Z]{2}\s+)?CARD\s*#:?\s*X{4,16}\s?(\d{4})\b\s*(.*)$/i', $line, $m)) {
                $type = $m[2];

                if (preg_match('/TYPE:?\s*$/i', $type) && isset($lines[$i + 1]) && ! preg_match('/'.self::AMOUNT.'/', $lines[$i + 1])) {
                    $type .= ' '.$lines[$i + 1];
                    $consumed[$i + 1] = true;
                }

                $amount = self::loneAmountAhead($lines, $i + 1, 10, $consumed);

                if ($amount !== null && ! preg_match('/AUTHORI[SZ]ATION ONLY/i', $type)) {
                    $tenders[] = self::tender(null, $amount, $m[1]);
                }

                continue;
            }

            // XXXXXXXXXXXX4846 MASTERCARD / XXXXXXXX5996 STORE CREDIT -5.82 / XXXXXXXX2432
            if (preg_match('/^X{4,16}\s?(\d{4})\b\s*(.*)$/i', $line, $m)) {
                [$label, $amount] = self::labelAndAmount($m[2]);
                $next = $i + 1;

                // Home Depot receipts read in two columns (2026): the CARD BALANCE
                // label, the tender's label and the tender/balance pair interleave —
                // "CARD BALANCE ProXtraDollar | 9.99 | 0.00", "STORE CREDIT | 0.00 |
                // 5.39". Of the pair, the zero is the balance; with no zero the
                // tender comes first. A "$34.05" between them is the TOTAL, displaced.
                if ($amount === null && ($pair = self::balancePairAhead($lines, $next, $consumed)) !== null) {
                    $tenders[] = self::tender($pair['label'], $pair['amount'], $m[1]);

                    continue;
                }

                if ($label === null && isset($lines[$next]) && self::labelOnly($lines[$next]) !== null) {
                    [$label, $amount] = self::labelAndAmount($lines[$next]);
                    $consumed[$next] = true;
                    $next++;
                }

                $amount ??= self::amountAhead($lines, $next, 3, $consumed, skip: '/^(TA|INVOICE|AUTH CODE.*|\d{5,})$/i', ignoreDollar: true);

                if ($amount !== null) {
                    $tenders[] = self::tender($label, $amount, $m[1]);
                }

                continue;
            }

            // A tender table with no card number: "Credit Card | $62.63", "Cash | $0.00".
            if (preg_match('/^(CREDIT CARD|DEBIT CARD)\s*('.self::AMOUNT.')?$/i', $line, $m)) {
                $amount = ($m[2] ?? '') !== '' ? self::amountIn($m[2]) : self::amountAhead($lines, $i + 1, 1, $consumed);

                if ($amount !== null) {
                    $tenders[] = self::tender(str_starts_with(strtoupper($m[1]), 'DEBIT') ? 'DEBIT' : 'CREDIT', $amount, null);
                }

                continue;
            }

            // Menards, Floor & Decor, Walgreens: VISA 4060 87.96- / Visa - 4060 / US Debit 4139 /
            // CAPITAL ONE VISA 4144 / MASTERCARD ACCT 4844
            if (preg_match('/^(?:CAPITAL ONE |CHASE |CITI )?('.self::BRAND.')\s*(?:ACCT\s*)?-?\s*(\d{4})\b\s*(.*)$/i', $line, $m)) {
                $amount = self::amountIn($m[3]) ?? self::amountAhead($lines, $i + 1, 3, $consumed, skip: '/^(Job #|PO #|EFT)/i');

                if ($amount !== null) {
                    $tenders[] = self::tender($m[1], $amount, $m[2]);
                }

                continue;
            }

            // Menards online receipts: "MASTERCARD DEBIT", its amount and "- 4846" on the
            // same line or the next two, in either order ("$107.50" then "- 4849", or
            // "- 4846" then "$12.30").
            if (preg_match('/^('.self::BRAND.')(?: DEBIT| CREDIT)?\s*('.self::AMOUNT.')?$/i', $line, $m)
                && ($last = self::dashLastFourAhead($lines, $i + 1, 2)) !== null) {
                $consumed[$last['index']] = true;
                $amount = ($m[2] ?? '') !== '' ? self::amountIn($m[2]) : null;

                for ($j = $i + 1; $amount === null && $j <= $i + 2 && isset($lines[$j]); $j++) {
                    if ($j !== $last['index'] && preg_match('/^(USD\$\s*)?'.self::AMOUNT.'$/i', $lines[$j])) {
                        $consumed[$j] = true;
                        $amount = self::amountIn($lines[$j]);
                    }
                }

                if ($amount !== null) {
                    $tenders[] = self::tender($m[1], $amount, $last['last_four']);
                }

                continue;
            }

            // Floor & Decor: "Visa" with the masked number on the next line or two; the
            // amount on the brand's line, between the two ("MasterCard | 21.93 | XXXX4846"),
            // or a few lines after the number.
            if (preg_match('/^('.self::BRAND.')\s*(.*)$/i', $line, $m) && ($masked = self::maskedAhead($lines, $i + 1, 2)) !== null) {
                $consumed[$masked['index']] = true;
                $amount = self::amountIn($m[2])
                    ?? self::amountAhead($lines, $i + 1, $masked['index'] - $i - 1, $consumed)
                    ?? self::amountAhead($lines, $masked['index'] + 1, 5, $consumed);

                if ($amount !== null) {
                    $tenders[] = self::tender($m[1], $amount, $masked['last_four']);
                }

                continue;
            }

            // A brand and its amount alone, the card number unreadable: "Visa (8.59)".
            if (preg_match('/^('.self::BRAND.')\s*('.self::AMOUNT.')$/i', $line, $m)) {
                $tenders[] = self::tender($m[1], self::amountIn($m[2]), null);

                continue;
            }

            // A store tender or cash on a line of its own: "GIFT CARD 6.85", "CASH 160.00".
            // The survey footer's "...WIN A $5,000 HOME DEPOT GIFT CARD" never starts a line with it.
            if (preg_match('/^('.self::STORE_TENDER.'|CASH)\b\s*(.*)$/i', $line, $m) && ! preg_match('/BAL/i', $m[2])) {
                $amount = self::amountIn($m[2]) ?? self::amountAhead($lines, $i + 1, 1, $consumed);

                if ($amount !== null) {
                    $tenders[] = self::tender($m[1], $amount, null);
                }

                continue;
            }

            // "CHANGE DUE 3.13", "CHANGE DUE" then "0.87", "Change back (Cash)" then "$2.00".
            if (preg_match('/^CHANGE\b/i', $line)) {
                $amount = self::amountIn($line);

                for ($j = $i + 1; $amount === null && $j <= $i + 2 && isset($lines[$j]); $j++) {
                    if (preg_match('/^'.self::AMOUNT.'$/', $lines[$j])) {
                        $amount = self::amountIn($lines[$j]);
                    }
                }

                $change += abs($amount ?? 0.0);
            }
        }

        // Change comes back out of cash. With no cash tender it is debit cash
        // back, already inside the card's charge: nothing to take off.
        if ($change > 0) {
            foreach ($tenders as &$tender) {
                if ($tender['method'] === ExpensePayment::CASH) {
                    $tender['amount'] = round(($tender['amount'] < 0 ? -1 : 1) * (abs($tender['amount']) - $change), 2);
                    break;
                }
            }
            unset($tender);
        }

        return array_values(array_filter($tenders, fn (array $tender) => abs($tender['amount']) >= 0.005));
    }

    /**
     * Amazon's own record of the order's charges. A charge with no card is a
     * gift card balance or reward points (Amazon reports both the same way);
     * a $0.00 one is an authorization, not a payment.
     *
     * @param  array<int, array<string, mixed>>  $charges
     * @return list<array{method: string, amount: float, last_four: ?string, brand: ?string, paid_at: ?string, source_ref: ?string}>
     */
    public static function fromAmazonCharges(array $charges): array
    {
        $lines = [];

        foreach ($charges as $charge) {
            $amount = round((float) str_replace(['$', ',', ' '], '', (string) ($charge['amount'] ?? '0')), 2);

            if (abs($amount) < 0.005) {
                continue;
            }

            $lastFour = preg_replace('/\D/', '', (string) ($charge['paymentInstrumentLast4Digits'] ?? '')) ?: null;
            $lines[] = [
                'method' => $lastFour ? ExpensePayment::CARD : ExpensePayment::GIFT_CARD_OR_POINTS,
                'amount' => $amount,
                'last_four' => $lastFour,
                'brand' => null,
                'paid_at' => ! empty($charge['transactionDate']) ? substr((string) $charge['transactionDate'], 0, 10) : null,
                'source_ref' => $charge['transactionId'] ?? null,
            ];
        }

        return $lines;
    }

    /**
     * The receipt reader's payment_methods (receipts read with hive_Receipts_1
     * since 2026-03-20). Early rows say "Credit"/"Debit" instead of the enum.
     *
     * @param  array<int, array<string, mixed>>  $methods
     * @param  list<float>  $authorizedOnly  amounts the receipt printed as AUTHORIZATION ONLY (authorizationOnlyAmounts)
     * @return list<array{method: string, amount: float, last_four: ?string, brand: ?string}>
     */
    public static function fromReaderPaymentMethods(array $methods, array $authorizedOnly = []): array
    {
        $lines = [];

        foreach ($methods as $method) {
            $amount = round((float) ($method['amount'] ?? 0), 2);

            if (abs($amount) < 0.005) {
                continue;
            }

            // A card line for an amount the receipt printed as AUTHORIZATION ONLY is the hold, not a payment.
            foreach ($authorizedOnly as $k => $hold) {
                if (abs(abs($amount) - $hold) < 0.005 && in_array((string) ($method['type'] ?? ''), ['CreditCard', 'Credit', 'DebitCard', 'Debit'], true)) {
                    unset($authorizedOnly[$k]);

                    continue 2;
                }
            }

            $lines[] = [
                'method' => match ((string) ($method['type'] ?? '')) {
                    'CreditCard', 'Credit', 'DebitCard', 'Debit' => ExpensePayment::CARD,
                    'StoreCredit' => ExpensePayment::STORE_CREDIT,
                    'GiftCard' => ExpensePayment::GIFT_CARD,
                    'Cash' => ExpensePayment::CASH,
                    default => ExpensePayment::OTHER,
                },
                'amount' => $amount,
                'last_four' => preg_replace('/\D/', '', (string) ($method['last_four'] ?? '')) ?: null,
                'brand' => null,
            ];
        }

        return $lines;
    }

    /**
     * What a Menards purchase really cost when rebate checks paid part of it.
     * Menards takes the checks off before tax and prints what is left: TOTAL
     * (pre-tax, after the checks), the TAX lines, TOTAL SALE. The purchase:
     * subtotal = TOTAL + checks (the line items), tax = what was charged,
     * total = TOTAL SALE + checks. Menards' own transaction total, which the
     * receipt import uses, is the smaller TOTAL SALE. Expense 25950 (2025-10-31)
     * was $243.01 of lumber paid entirely by a check: TOTAL SALE 0.00, no tax.
     * Null when the receipt spent no rebate check.
     *
     * @return array{sale: float, rebates: float, total: float, subtotal: float, tax: float}|null
     */
    public static function menardsRebateTotal(string $text): ?array
    {
        $rebates = round(array_sum(array_map(
            fn (array $tender) => $tender['amount'],
            array_filter(self::fromText($text), fn (array $tender) => $tender['brand'] === self::REBATE_CHECK),
        )), 2);

        if ($rebates <= 0) {
            return null;
        }

        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', str_replace("\t", ' ', $text)) ?: []), fn ($line) => $line !== ''));
        $sale = null;
        $preTax = null;
        $tax = 0.0;

        foreach ($lines as $i => $line) {
            if (preg_match('/^TOTAL SALE\b\s*(.*)$/i', $line, $m)) {
                $sale ??= self::amountIn($m[1]) ?? self::amountOnNextLines($lines, $i, 3);

                continue;
            }

            if (preg_match('/^TOTAL\b(?!\s*(?:SALE|NUMBER|SAVINGS))\s*(.*)$/i', $line, $m)) {
                $preTax ??= self::amountIn($m[1]) ?? self::amountOnNextLines($lines, $i, 1);

                continue;
            }

            if (preg_match('/^TAX\b[^%]*%\s*(.*)$/i', $line, $m)) {
                $tax += self::amountIn($m[1]) ?? self::amountOnNextLines($lines, $i, 1) ?? 0.0;
            }
        }

        if ($sale === null) {
            return null;
        }

        $tax = $preTax !== null ? round($sale - $preTax, 2) : round($tax, 2);

        return [
            'sale' => $sale,
            'rebates' => $rebates,
            'total' => round($sale + $rebates, 2),
            'subtotal' => round(($preTax ?? $sale - $tax) + $rebates, 2),
            'tax' => $tax,
        ];
    }

    /**
     * The receipt's own subtotal, tax and total, put right for rebate checks
     * (see menardsRebateTotal); Menards' printed figures are kept alongside.
     * Unchanged when the receipt spent no rebate check.
     *
     * @param  array<string, mixed>  $fields  receipt_items
     * @return array<string, mixed>
     */
    public static function withMenardsRebateTotals(array $fields, string $text): array
    {
        $full = self::menardsRebateTotal($text);

        if ($full === null) {
            return $fields;
        }

        return array_merge($fields, [
            'subtotal' => $full['subtotal'],
            'total_tax' => $full['tax'],
            'total' => $full['total'],
            'menards_rebate_checks' => $full['rebates'],
            'menards_total_sale' => $full['sale'],
        ]);
    }

    /** @param  list<string>  $lines */
    protected static function amountOnNextLines(array $lines, int $i, int $reach): ?float
    {
        for ($j = $i + 1; $j <= $i + $reach && isset($lines[$j]); $j++) {
            if (preg_match('/^(USD\$\s*)?'.self::AMOUNT.'$/i', $lines[$j])) {
                return self::amountIn($lines[$j]);
            }
        }

        return null;
    }

    /** The receipt's OCR text: raw_content on newer rows, receipt_html on older ones. */
    public static function receiptText(ExpenseReceipts $receipt): string
    {
        $items = is_array($receipt->receipt_items) ? $receipt->receipt_items : (json_decode((string) $receipt->getRawOriginal('receipt_items'), true) ?: []);

        if (! empty($items['raw_content']) && is_string($items['raw_content'])) {
            return $items['raw_content'];
        }

        $html = preg_replace('/<\s*(br|\/p|\/div|\/tr|\/td|\/li|\/h\d)\b[^>]*>/i', "\n", (string) $receipt->receipt_html);

        return html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5);
    }

    /**
     * The payment lines for an expense: Amazon's charges first, then each
     * receipt's printed tenders, then the receipt reader's — the first set
     * that adds up to the expense amount, signed like the expense. A receipt
     * that printed its tenders twice is tried once more without repeats.
     *
     * @return array{status: 'matched'|'mismatch'|'none', source: ?string, receipt_id: ?int, lines: list<array<string, mixed>>, found: ?float}
     */
    public static function forExpense(Expense $expense): array
    {
        $target = round((float) $expense->amount, 2);
        $closest = null;
        $bySource = [];

        foreach ($expense->receipts as $receipt) {
            $items = is_array($receipt->receipt_items) ? $receipt->receipt_items : [];
            $text = self::receiptText($receipt);
            $candidates = [
                ExpensePayment::SOURCE_AMAZON => self::fromAmazonCharges(is_array($items['charges'] ?? null) ? $items['charges'] : []),
                ExpensePayment::SOURCE_RECEIPT_TEXT => self::fromText($text),
                ExpensePayment::SOURCE_RECEIPT_READER => self::fromReaderPaymentMethods(is_array($items['payment_methods'] ?? null) ? $items['payment_methods'] : [], self::authorizationOnlyAmounts($text)),
            ];

            foreach ($candidates as $source => $lines) {
                if ($lines === []) {
                    continue;
                }

                foreach ($lines as $line) {
                    $bySource[$source][] = ['receipt_id' => $receipt->id] + $line;
                }

                foreach ([$lines, self::withoutRepeats($lines)] as $attempt) {
                    $sum = round(array_sum(array_column($attempt, 'amount')), 2);

                    if (abs(abs($sum) - abs($target)) <= 0.02) {
                        $sign = ($target < 0) === ($sum < 0) ? 1 : -1;

                        return [
                            'status' => 'matched',
                            'source' => $source,
                            'receipt_id' => $receipt->id,
                            'lines' => array_map(fn (array $line) => ['amount' => round($line['amount'] * $sign, 2)] + $line, $attempt),
                            'found' => $sum,
                        ];
                    }
                }

                $closest ??= ['source' => $source, 'receipt_id' => $receipt->id, 'lines' => $lines, 'found' => round(array_sum(array_column($lines, 'amount')), 2)];
            }
        }

        // Receipts that pay one purchase between them: a Home Depot deposit on
        // one, the final sale with the deposit's refund on the other (26045).
        // Each line keeps its receipt.
        if ($expense->receipts->count() > 1) {
            foreach ($bySource as $source => $lines) {
                foreach ([$lines, self::withoutRepeats($lines)] as $attempt) {
                    $sum = round(array_sum(array_column($attempt, 'amount')), 2);

                    if (abs(abs($sum) - abs($target)) <= 0.02) {
                        $sign = ($target < 0) === ($sum < 0) ? 1 : -1;

                        return [
                            'status' => 'matched',
                            'source' => $source,
                            'receipt_id' => null,
                            'lines' => array_map(fn (array $line) => ['amount' => round($line['amount'] * $sign, 2)] + $line, $attempt),
                            'found' => $sum,
                        ];
                    }
                }
            }
        }

        return $closest === null
            ? ['status' => 'none', 'source' => null, 'receipt_id' => null, 'lines' => [], 'found' => null]
            : ['status' => 'mismatch'] + $closest;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    protected static function withoutRepeats(array $lines): array
    {
        $seen = [];

        return array_values(array_filter($lines, function (array $line) use (&$seen) {
            $key = $line['method'].'|'.($line['last_four'] ?? '').'|'.$line['amount'];

            return ! isset($seen[$key]) && ($seen[$key] = true);
        }));
    }

    /** @return array{method: string, amount: float, last_four: ?string, brand: ?string} */
    protected static function tender(?string $label, float $amount, ?string $lastFour): array
    {
        $label = strtoupper(trim((string) $label));

        $method = match (true) {
            $label === 'STORE CREDIT' => ExpensePayment::STORE_CREDIT,
            $label === 'GIFT CARD' => ExpensePayment::GIFT_CARD,
            str_starts_with(str_replace(' ', '', $label), 'PROXTRADOLLAR') => ExpensePayment::POINTS,
            $label === 'GIFT CERTIFICATE' => ExpensePayment::GIFT_CARD,
            $label === 'CASH' => ExpensePayment::CASH,
            default => ExpensePayment::CARD,
        };

        $brand = $method === ExpensePayment::CARD && $label !== ''
            ? match ($label) {
                'MASTER CARD' => 'MASTERCARD',
                'US DEBIT' => 'DEBIT',
                'US CREDIT' => 'CREDIT',
                'AMERICAN EXPRESS' => 'AMEX',
                default => $label,
            }
            : null;

        return ['method' => $method, 'amount' => $amount, 'last_four' => $method === ExpensePayment::CASH ? null : $lastFour, 'brand' => $brand];
    }

    /** @return array{0: ?string, 1: ?float} the tender label and amount printed in one piece of text */
    protected static function labelAndAmount(string $text): array
    {
        $label = null;

        if (preg_match('/^('.self::STORE_TENDER.'|'.self::BRAND.')\b/i', trim($text), $m)) {
            $label = $m[1];
        }

        return [$label, self::amountIn($text)];
    }

    /** The label when the line is nothing but a tender label (and maybe its amount). */
    protected static function labelOnly(string $line): ?string
    {
        return preg_match('/^('.self::STORE_TENDER.'|'.self::BRAND.')\s*('.self::AMOUNT.')?$/i', $line, $m) ? $m[1] : null;
    }

    /** @return list<string> the receipt's non-empty lines, whitespace collapsed */
    protected static function lines(string $text): array
    {
        return array_values(array_filter(
            array_map(fn (string $line) => trim(preg_replace('/\s+/', ' ', $line)), preg_split('/\R/u', str_replace("\t", ' ', $text)) ?: []),
            fn (string $line) => $line !== '',
        ));
    }

    protected static function negative(?float $amount): ?float
    {
        return $amount !== null && $amount < 0 ? $amount : null;
    }

    /**
     * The first trailing-minus amount ("204.85-") from $from on that is not
     * on a card's, a total's or a tender's own line: a Menards rebate check
     * the reader moved away from its REBATE NO line.
     *
     * @param  list<string>  $lines
     */
    protected static function trailingMinusAhead(array $lines, int $from): ?float
    {
        for ($j = $from; $j < count($lines); $j++) {
            if (preg_match('/^(X{4,}|CARD BALANCE|TOTAL|SUBTOTAL|TAX|CHANGE|CERTIFICATE|CASH|'.self::STORE_TENDER.'|(?:CAPITAL ONE |CHASE |CITI )?(?:'.self::BRAND.'))\b/i', $lines[$j])) {
                continue;
            }

            if (preg_match_all('/(\d{1,3}(?:,\d{3})+|\d+)\.(\d{2})-(?![\d%])/', $lines[$j], $all, PREG_SET_ORDER)) {
                $m = end($all);

                return -(float) (str_replace(',', '', $m[1]).'.'.$m[2]);
            }
        }

        return null;
    }

    /**
     * The first line within reach that is only an amount, stopping at the
     * next card block ("Card #").
     *
     * @param  list<string>  $lines
     * @param  array<int, bool>  $consumed
     */
    protected static function loneAmountAhead(array $lines, int $from, int $reach, array &$consumed): ?float
    {
        for ($j = $from; $j <= $from + $reach && isset($lines[$j]); $j++) {
            if (preg_match('/CARD\s*#/i', $lines[$j])) {
                return null;
            }

            if (preg_match('/^'.self::AMOUNT.'$/', $lines[$j])) {
                $consumed[$j] = true;

                return self::amountIn($lines[$j]);
            }
        }

        return null;
    }

    /**
     * Amounts the receipt printed as "AUTHORIZATION ONLY": a rental's deposit
     * hold, never charged, which the receipt reader lists as a payment.
     *
     * @return list<float>
     */
    public static function authorizationOnlyAmounts(string $text): array
    {
        $lines = self::lines($text);
        $amounts = [];
        $consumed = [];

        foreach ($lines as $i => $line) {
            if (preg_match('/AUTHORI[SZ]ATION ONLY/i', $line) && ($amount = self::loneAmountAhead($lines, $i + 1, 10, $consumed)) !== null) {
                $amounts[] = abs($amount);
            }
        }

        return $amounts;
    }

    /** The last amount in a piece of text, signed as printed. */
    protected static function amountIn(string $text): ?float
    {
        if (! preg_match_all('/'.self::AMOUNT.'/', $text, $all, PREG_SET_ORDER)) {
            return null;
        }

        $m = end($all);
        $value = (float) (str_replace(',', '', $m[3]).'.'.$m[4]);
        $negative = ($m[2] ?? '') === '-' || ($m[5] ?? '') === '-' || (($m[1] ?? '') === '(' && ($m[6] ?? '') === ')');

        return $negative ? -$value : $value;
    }

    /**
     * The first line within reach that is only an amount ("USD$ 147.08",
     * "$356.79", "-5.82"), skipping lines that match $skip. Stops at the
     * next tender, a CARD BALANCE, or a total.
     *
     * @param  list<string>  $lines
     * @param  array<int, bool>  $consumed
     */
    protected static function amountAhead(array $lines, int $from, int $reach, array &$consumed, ?string $skip = null, bool $ignoreDollar = false): ?float
    {
        for ($j = $from; $j < min(count($lines), $from + $reach + ($skip ? 2 : 0)); $j++) {
            $line = $lines[$j];

            if ($skip !== null && preg_match($skip, $line)) {
                continue;
            }

            // Home Depot prints tenders as "USD$ 27.32" or a bare amount; a lone
            // "$94.71" after the card number is the receipt's TOTAL, displaced.
            if ($ignoreDollar && preg_match('/^-?\$/', $line)) {
                continue;
            }

            if (preg_match('/^(X{4,}|CARD BALANCE|TOTAL|SUBTOTAL|CHANGE DUE|'.self::STORE_TENDER.'|'.self::BRAND.')\b/i', $line)) {
                return null;
            }

            if (preg_match('/^(USD\$\s*)?'.self::AMOUNT.'$/i', $line)) {
                $consumed[$j] = true;

                return self::amountIn($line);
            }
        }

        return null;
    }

    /**
     * The tender in Home Depot's two-column layout: after the card number,
     * "CARD BALANCE" (maybe with the tender label on the same line, maybe a
     * "TA" first), the label, then the tender and balance amounts in either
     * order. A decimal comma ("0,00") is the reader's misreading of a point.
     *
     * @param  list<string>  $lines
     * @param  array<int, bool>  $consumed
     * @return array{label: string, amount: float}|null
     */
    protected static function balancePairAhead(array $lines, int $from, array &$consumed): ?array
    {
        $j = $from;

        while (isset($lines[$j]) && strtoupper($lines[$j]) === 'TA') {
            $j++;
        }

        if (! isset($lines[$j]) || ! preg_match('/^CARD BALANCE\b\s*(?:TA\b)?\s*(.*)$/i', $lines[$j], $m)) {
            return null;
        }

        $label = self::labelOnly($m[1]) ?? null;
        $used = [$j];
        $amounts = [];

        for ($k = $j + 1; $k < min(count($lines), $j + 6) && count($amounts) < 2; $k++) {
            $line = $lines[$k];

            if ($label === null && ($found = self::labelOnly($line)) !== null) {
                $label = $found;
                $used[] = $k;

                continue;
            }

            if (strtoupper($line) === 'TA' || preg_match('/^-?\$/', $line)) {
                continue;
            }

            if (preg_match('/^(-?)(\d+),(\d{2})(-?)$/', $line, $comma)) {
                $line = $comma[1].$comma[2].'.'.$comma[3].$comma[4];
            }

            if (preg_match('/^'.self::AMOUNT.'$/', $line)) {
                $amounts[] = self::amountIn($line);
                $used[] = $k;

                continue;
            }

            break;
        }

        if ($label === null || $amounts === []) {
            return null;
        }

        foreach ($used as $index) {
            $consumed[$index] = true;
        }

        $amount = count($amounts) === 2 && abs($amounts[0]) < 0.005 ? $amounts[1] : $amounts[0];

        return ['label' => $label, 'amount' => $amount];
    }

    /**
     * Menards' "- 4846" line under a card brand.
     *
     * @param  list<string>  $lines
     * @return array{index: int, last_four: string}|null
     */
    protected static function dashLastFourAhead(array $lines, int $from, int $reach): ?array
    {
        for ($j = $from; $j < min(count($lines), $from + $reach); $j++) {
            if (preg_match('/^-\s*(\d{4})$/', $lines[$j], $m)) {
                return ['index' => $j, 'last_four' => $m[1]];
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     * @return array{index: int, last_four: string}|null
     */
    protected static function maskedAhead(array $lines, int $from, int $reach): ?array
    {
        for ($j = $from; $j < min(count($lines), $from + $reach); $j++) {
            if (preg_match('/^(?:X{4,16}|\*{4,16})\s?(\d{4})$/i', $lines[$j], $m)) {
                return ['index' => $j, 'last_four' => $m[1]];
            }
        }

        return null;
    }
}
