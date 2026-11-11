<?php

namespace App\Support\Transactions;

use App\Models\Transaction;
use Illuminate\Support\Collection;

/**
 * Who a card transaction actually paid, once the payment processors in the
 * way are set aside.
 *
 * Processors come from two places, read together:
 *  - the curated descriptor prefixes (PaymentProcessors) found in
 *    plaid_merchant_description and in Plaid's original_description — the
 *    raw bank text keeps a prefix Plaid's cleaned name drops ("Netflix"
 *    came in as "PAYPAL *NETFLIX.COM");
 *  - what Plaid declares in the transaction's details: payment_meta.
 *    payment_processor, and every counterparty whose type is not
 *    "merchant" (payment_app, payment_terminal, marketplace,
 *    financial_institution, income_source).
 * A "merchant" counterparty or merchant_name that is itself a known
 * processor is a processor too, not the merchant: Plaid called Paddle the
 * merchant of 29633.
 *
 * The merchant texts, best first: a merchant counterparty that is not a
 * processor ("Dataforseo" on 30216); the descriptor's text after the
 * processor ("DATAFORSE" on 29633 — the whole descriptor when it carries no
 * curated prefix), then the same from original_description when it adds
 * more; then plaid_merchant_name when it is not a processor. A vendor is
 * never matched through a processor's name, whichever source named it.
 *
 * One exception, from production data: Microsoft bills Microsoft 365 with
 * payment_processor "Microsoft", a lone merchant counterparty "Microsoft"
 * and merchant_name "Microsoft" on a bare reference descriptor
 * ("E0100KW0UY"). When Plaid's processor is the only party it names and no
 * curated prefix is present, the processor is billing for itself and is
 * the merchant; such a transaction has no processor at all.
 *
 * Plaid-declared names classify merchant names and rule texts only — they
 * are never used to split descriptor text, or "CAPITAL ONE MOBILE PYMT"
 * (financial_institution Capital One) would yield a merchant "MOBILE PYMT"
 * and the Capital One payment rules would stop claiming it.
 */
final class MerchantIdentity
{
    /** Counterparty types Plaid uses for the party that moved the money rather than the one paid. */
    private const PROCESSOR_COUNTERPARTY_TYPES = ['payment_app', 'payment_terminal', 'marketplace', 'financial_institution', 'income_source'];

    /** Lean aliases UnmatchedTransactionCards selects instead of the whole details JSON. */
    private const LEAN_PROCESSOR = 'plaid_payment_processor';

    private const LEAN_COUNTERPARTIES = 'plaid_counterparties';

    private const LEAN_ORIGINAL = 'plaid_original_description';

    /**
     * @param  list<string>  $processors  display names, descriptor prefixes first, then Plaid's
     * @param  list<array{text: string, source: string}>  $merchantTexts  best first; source is counterparty|description|original_description|merchant_name
     * @param  ?string  $descriptorMerchant  the text after a curated processor prefix in plaid_merchant_description — what a rule can match on
     * @param  ?string  $matchText  the rule prefix to propose for that descriptor (PaymentProcessors::matchText())
     * @param  list<string>  $plaidProcessorNames  normalised names Plaid declared (empty when the processor bills for itself)
     */
    private function __construct(
        public readonly array $processors,
        public readonly array $merchantTexts,
        public readonly ?string $descriptorMerchant,
        public readonly ?string $matchText,
        private readonly array $plaidProcessorNames,
    ) {}

    /**
     * Read a stored transaction: the full details JSON when it was loaded,
     * else the lean JSON-path aliases the Match Vendor cards select.
     */
    public static function of(Transaction $transaction): self
    {
        $attributes = $transaction->getAttributes();

        if (array_key_exists('details', $attributes)) {
            $details = is_array($transaction->details) ? $transaction->details : [];
            $paymentProcessor = data_get($details, 'payment_meta.payment_processor');
            $counterparties = (array) ($details['counterparties'] ?? []);
            $original = $details['original_description'] ?? null;
        } else {
            $paymentProcessor = $attributes[self::LEAN_PROCESSOR] ?? null;
            $raw = $attributes[self::LEAN_COUNTERPARTIES] ?? null;
            $counterparties = is_array($raw) ? $raw : (array) json_decode((string) $raw, true);
            $original = $attributes[self::LEAN_ORIGINAL] ?? null;
        }

        return self::fromParts(
            $transaction->plaid_merchant_description,
            $transaction->plaid_merchant_name,
            is_string($original) ? $original : null,
            is_string($paymentProcessor) ? $paymentProcessor : null,
            $counterparties,
        );
    }

    /**
     * @param  array<int, mixed>  $counterparties  Plaid's counterparties list
     */
    public static function fromParts(?string $description, ?string $merchantName, ?string $originalDescription, ?string $paymentProcessor, array $counterparties): self
    {
        $description = trim((string) $description);
        $merchantName = trim((string) $merchantName);
        $original = trim((string) $originalDescription);

        // MySQL hands a JSON null back as the string "null" through a JSON path.
        $declared = [];
        if ($paymentProcessor !== null && trim($paymentProcessor) !== '' && strtolower(trim($paymentProcessor)) !== 'null') {
            $declared[] = trim($paymentProcessor);
        }
        $merchantParties = [];
        foreach ($counterparties as $counterparty) {
            if (! is_array($counterparty)) {
                continue;
            }
            $name = trim((string) ($counterparty['name'] ?? ''));
            $type = strtolower(trim((string) ($counterparty['type'] ?? '')));
            if ($name === '') {
                continue;
            }
            if (in_array($type, self::PROCESSOR_COUNTERPARTY_TYPES, true)) {
                $declared[] = $name;
            } elseif ($type === 'merchant') {
                $merchantParties[] = $name;
            }
        }

        $descriptionSplit = PaymentProcessors::split($description);
        $originalSplit = $original !== '' && $original !== $description
            ? PaymentProcessors::split($original)
            : ['processor' => null, 'merchant' => null];

        $curated = array_values(array_unique(array_filter([$descriptionSplit['processor'], $originalSplit['processor']])));
        $declaredNormalized = array_values(array_unique(array_map([PaymentProcessors::class, 'normalize'], $declared)));

        // The processor billing for itself (Microsoft 365): Plaid names a
        // processor, a merchant counterparty that is that same processor,
        // nothing else, and no curated prefix says otherwise. Then there is
        // no processor here. A lone merchant_name equal to a payment app
        // (merchant_name "PayPal" under a PayPal payment_app, on "GITHUB
        // INC") is Plaid mislabeling the app, not the app billing itself —
        // hence the merchant counterparty requirement.
        $namedMerchants = array_values(array_filter(array_merge($merchantParties, [$merchantName])));
        $selfBilling = $curated === [] && $declaredNormalized !== [] && $merchantParties !== []
            && collect($namedMerchants)->every(fn (string $name) => in_array(PaymentProcessors::normalize($name), $declaredNormalized, true));
        if ($selfBilling) {
            $declared = [];
            $declaredNormalized = [];
        }

        $isProcessor = fn (string $name): bool => PaymentProcessors::isProcessorName($name)
            || in_array(PaymentProcessors::normalize($name), $declaredNormalized, true);

        $processors = $curated;
        foreach ($declared as $name) {
            $processors[] = $name;
        }
        foreach ($namedMerchants as $name) {
            if ($isProcessor($name)) {
                $processors[] = $name;
            }
        }

        $texts = [];
        foreach ($merchantParties as $name) {
            if (! $isProcessor($name)) {
                $texts[] = ['text' => $name, 'source' => 'counterparty'];
            }
        }
        // No curated prefix: the descriptor is the merchant text, minus the
        // names Plaid declared as processors ("BRAINTREE* ACME TOOLS" with
        // payment_processor Braintree reads "ACME TOOLS"), so a processor's
        // name in the bank text can never pass for the vendor.
        $descriptorMerchant = $descriptionSplit['processor'] !== null
            ? $descriptionSplit['merchant']
            : self::withoutNames($description, $declared);
        if ($descriptorMerchant !== null) {
            $texts[] = ['text' => $descriptorMerchant, 'source' => 'description'];
        }
        if ($originalSplit['merchant'] !== null) {
            $texts[] = ['text' => $originalSplit['merchant'], 'source' => 'original_description'];
        }
        if ($merchantName !== '' && ! $isProcessor($merchantName)) {
            $texts[] = ['text' => $merchantName, 'source' => 'merchant_name'];
        }

        return new self(
            processors: self::uniqueNames($processors),
            merchantTexts: self::uniqueTexts($texts),
            descriptorMerchant: $descriptionSplit['processor'] !== null ? $descriptionSplit['merchant'] : null,
            matchText: $descriptionSplit['processor'] !== null ? PaymentProcessors::matchText($description) : null,
            plaidProcessorNames: $declaredNormalized,
        );
    }

    /**
     * One identity for a Match Vendor card: every transaction sharing the
     * descriptor, merged (processors and merchant texts in first-seen order).
     *
     * @param  iterable<int, Transaction>  $transactions
     */
    public static function forCard(string $descriptor, iterable $transactions): self
    {
        $identities = [];
        foreach ($transactions as $transaction) {
            $identities[] = self::of($transaction);
        }

        if ($identities === []) {
            return self::fromParts($descriptor, null, null, null, []);
        }

        $processors = [];
        $texts = [];
        $plaid = [];
        $descriptorMerchant = null;
        $matchText = null;
        foreach ($identities as $identity) {
            $processors = array_merge($processors, $identity->processors);
            $texts = array_merge($texts, $identity->merchantTexts);
            $plaid = array_merge($plaid, $identity->plaidProcessorNames);
            $descriptorMerchant ??= $identity->descriptorMerchant;
            $matchText ??= $identity->matchText;
        }

        return new self(
            processors: self::uniqueNames($processors),
            merchantTexts: self::uniqueTexts($texts),
            descriptorMerchant: $descriptorMerchant,
            matchText: $matchText,
            plaidProcessorNames: array_values(array_unique($plaid)),
        );
    }

    /** The card identity of the unmatched transactions carrying this descriptor. */
    public static function forDescriptor(string $descriptor): self
    {
        $transactions = Transaction::transactionsSinVendor()
            ->where('plaid_merchant_description', $descriptor)
            ->get(['id', 'plaid_merchant_description', 'plaid_merchant_name', 'details']);

        return self::forCard($descriptor, $transactions);
    }

    public function hasProcessor(): bool
    {
        return $this->processors !== [];
    }

    /** The merchant texts alone, best first. @return list<string> */
    public function texts(): array
    {
        return array_column($this->merchantTexts, 'text');
    }

    /** The single best merchant text, or null when only processors are named. */
    public function merchant(): ?string
    {
        return $this->merchantTexts[0]['text'] ?? null;
    }

    /** Is this name just one of this transaction's processors — curated or declared by Plaid? */
    public function isProcessorName(?string $name): bool
    {
        $name = trim((string) $name);
        if ($name === '') {
            return false;
        }

        return PaymentProcessors::isProcessorName($name)
            || in_array(PaymentProcessors::normalize($name), $this->plaidProcessorNames, true);
    }

    /** Is any merchant named at all, or only processors ("PADDLE.NET" alone)? */
    public function namesMerchant(): bool
    {
        return $this->merchantTexts !== [];
    }

    /**
     * Does this rule text (as stored or as typed) name nothing but a
     * processor — from the curated list, or one Plaid declared on these
     * charges (so a "BRAINTREE" rule is caught even though Braintree has no
     * descriptor prefix of its own)?
     */
    public function isProcessorOnlyRule(string $ruleDesc): bool
    {
        if (PaymentProcessors::isProcessorOnly($ruleDesc)) {
            return true;
        }

        $text = PaymentProcessors::normalize((string) preg_replace('/\\\\(.)/', '$1', $ruleDesc));

        return $text !== '' && in_array($text, $this->plaidProcessorNames, true);
    }

    /**
     * The refusal for a match text that names only a processor, else null.
     * A card that names nothing but the processor — a bare "PADDLE.NET"
     * charge, as Laravel Daily's read in 2023 — can only be matched on the
     * processor, and such a rule claims only such bare charges, so it is
     * let through.
     */
    public function refusal(string $matchText): ?string
    {
        $matchText = trim($matchText);
        if ($matchText === '' || ! $this->isProcessorOnlyRule($matchText)) {
            return null;
        }
        if ($this->hasProcessor() && ! $this->namesMerchant()) {
            return null;
        }

        return PaymentProcessors::refusalMessage($matchText, $this->matchText);
    }

    /**
     * A descriptor with the given processor names blanked out of it, with
     * separators tidied; null when nothing letter-like remains.
     *
     * @param  list<string>  $names
     */
    private static function withoutNames(string $description, array $names): ?string
    {
        $text = $description;
        foreach (array_filter(array_map('trim', $names)) as $name) {
            $text = (string) preg_replace('/'.preg_quote($name, '/').'(?:\.(?:NET|COM))?/i', ' ', $text);
        }
        $text = trim((string) preg_replace('/\s+/', ' ', $text), " \t*-:#/");

        return preg_match('/[A-Z]/i', $text) === 1 ? $text : null;
    }

    /**
     * What to call a NEW retail vendor created from a match text: the
     * merchant part when the text still carries the processor prefix
     * ("PADDLE.NET* DATAFORSEO" → "DATAFORSEO"), and the whole merchant when
     * the text is its truncated prefix ("DATAFORSE" → "Dataforseo"). The
     * Vendor model title-cases what it is given.
     */
    public function vendorNameFor(string $matchText): string
    {
        $text = trim($matchText);
        $split = PaymentProcessors::split($text);
        if ($split['processor'] !== null && $split['merchant'] !== null) {
            $text = $split['merchant'];
        }

        foreach ($this->texts() as $candidate) {
            if (strlen($candidate) > strlen($text) && stripos($candidate, $text) === 0) {
                return $candidate;
            }
        }

        return $text;
    }

    /**
     * A plain sentence for the Match Vendor card and the AI prompt, or null
     * when no processor is involved.
     */
    public function note(): ?string
    {
        if (! $this->hasProcessor()) {
            return null;
        }

        $note = 'Billed through '.implode(' and ', $this->processors).', a payment processor many companies bill through';
        if ($this->merchant() !== null) {
            $note .= ' — the merchant is '.$this->merchant();
        }

        return $note.'.';
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private static function uniqueNames(array $names): array
    {
        $seen = [];
        $unique = [];
        foreach ($names as $name) {
            $key = PaymentProcessors::normalize($name);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $name;
        }

        return $unique;
    }

    /**
     * @param  list<array{text: string, source: string}>  $texts
     * @return list<array{text: string, source: string}>
     */
    private static function uniqueTexts(array $texts): array
    {
        $seen = [];
        $unique = [];
        foreach ($texts as $text) {
            $key = PaymentProcessors::normalize($text['text']);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $text;
        }

        return $unique;
    }
}
