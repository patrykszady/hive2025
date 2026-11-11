<?php

use App\Support\Transactions\PaymentProcessors;

/**
 * One real (or, for the well-known ones with no row in the dev DB, the
 * documented) descriptor form per shipped processor: the merchant split()
 * finds after it, the match text proposed for a rule (the merchant cut to
 * the bank's 21-character window), and the processor-only rule text as the
 * form would store it. The dataset is built from this map and a separate
 * test insists the map covers the whole list, so a processor added without
 * an example fails here.
 *
 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>  descriptor, merchant, match text, processor-only rule
 */
function paymentProcessorExamples(): array
{
    return [
        'Paddle' => ['PADDLE.NET* DATAFORSEO', 'DATAFORSEO', 'DATAFORSE', 'PADDLE.NET'],
        'PayPal' => ['PAYPAL *METWOOD INC', 'METWOOD INC', 'METWOOD INC', 'PAYPAL \*'],
        'Square' => ['SQU*SQ *WOLF & PALATI Prospect Heig 19291', 'WOLF & PALATI Prospect Heig', 'WOLF & PALATI', 'SQ \*'],
        'Toast' => ['DEBIT PURCHASE Aug 12 4846 TST*SPEEDOS HARBOR SI 24226', 'SPEEDOS HARBOR SI', 'SPEEDOS HARBOR SI', 'TST\*'],
        'Shopify' => ['SP * SHOWERBASE.COM', 'SHOWERBASE.COM', 'SHOWERBASE.COM', 'SP \*'],
        'FastSpring' => ['DEBIT PURCHASE Mar 07 4849 FS *ROOMSKETCHER 26067', 'ROOMSKETCHER', 'ROOMSKETCHER', 'FS \*'],
        '2Checkout' => ['2CO.COM*ACME SOFTWARE', 'ACME SOFTWARE', 'ACME SOFTWARE', '2CO.COM\*'],
        'Intuit' => ['IN *BM INSURANCE AGENCY', 'BM INSURANCE AGENCY', 'BM INSURANCE AGEN', 'IN \*'],
        'Google' => ['GOOGLE *YouTube', 'YouTube', 'YouTube', 'GOOGLE \*'],
        'Lemon Squeezy' => ['LEMONSQUEEZY* ACME', 'ACME', 'ACME', 'LEMONSQUEEZY\*'],
        'Teleflora' => ['TLF*AMLINGS FLOWERLAND', 'AMLINGS FLOWERLAND', 'AMLINGS FLOWERLAN', 'TLF\*'],
        'GoDaddy Payments' => ["GDP*Danielle's Delights", "Danielle's Delights", "Danielle's Deligh", 'GDP\*'],
        'Slice' => ['SLICE*ROSATISPIZZA', 'ROSATISPIZZA', 'ROSATISPIZZA', 'SLICE\*'],
        'Xsolla' => ['DEBIT PURCHASE Aug 01 4842 XSOLLA *ROCKSTAR 25214', 'ROCKSTAR', 'ROCKSTAR', 'XSOLLA \*'],
        'Official Payments' => ['OPC*SELECTIVE INSURAN 19033', 'SELECTIVE INSURAN', 'SELECTIVE INSURAN', 'OPC\*'],
        'Link2Gov' => ['DEBIT PURCHASE Sep 02 4846 L2G*WBMI INS. BILL PA 20248', 'WBMI INS. BILL PA', 'WBMI INS. BILL PA', 'L2G\*'],
        '4TE' => ['4TE*ILSecofStateSFee', 'ILSecofStateSFee', 'ILSecofStateSFee', '4TE\*'],
        'USA Technologies' => ['USA*VEND AT AIR SERV', 'VEND AT AIR SERV', 'VEND AT AIR SERV', 'USA\*'],
    ];
}

dataset('shipped processors', function () {
    foreach (paymentProcessorExamples() as $processor => [$descriptor, $merchant, $matchText, $rule]) {
        yield $processor => [$processor, $descriptor, $merchant, $matchText, $rule];
    }
});

it('has an example for every shipped processor', function () {
    expect(array_keys(paymentProcessorExamples()))->toEqualCanonicalizing(array_keys(PaymentProcessors::all()));
});

it('splits the processor off its descriptor and proposes the merchant match text', function (string $processor, string $descriptor, string $merchant, string $matchText, string $rule) {
    expect(PaymentProcessors::split($descriptor))->toBe(['processor' => $processor, 'merchant' => $merchant])
        ->and(PaymentProcessors::matchText($descriptor))->toBe($matchText)
        ->and(PaymentProcessors::isProcessorOnly($descriptor))->toBeFalse();
})->with('shipped processors');

it('recognises a rule that names nothing but the processor, as the form stores it', function (string $processor, string $descriptor, string $merchant, string $matchText, string $rule) {
    expect(PaymentProcessors::isProcessorOnly($rule))->toBeTrue()
        // The same rule with the merchant after it is specific and stays valid.
        ->and(PaymentProcessors::isProcessorOnly($rule.' '.str_replace('*', '\*', $merchant)))->toBeFalse();
})->with('shipped processors');

it('knows the processor by the names Plaid gives it', function (string $processor) {
    foreach (PaymentProcessors::all()[$processor]['names'] as $name) {
        expect(PaymentProcessors::isProcessorName($name))->toBeTrue();
    }
    expect(PaymentProcessors::isProcessorName($processor))->toBeTrue();
})->with('shipped processors');

it('reads both DataForSEO forms and the bare 2023 Paddle charge', function () {
    expect(PaymentProcessors::split('PADDLE.NET* DATAFORSEO'))->toBe(['processor' => 'Paddle', 'merchant' => 'DATAFORSEO'])
        ->and(PaymentProcessors::split('DEBIT PURCHASE Sep 04 4849 PADDLE.NET* DATAFORSE 26248'))->toBe(['processor' => 'Paddle', 'merchant' => 'DATAFORSE'])
        // Plaid's original_description adds the city; the match text is still the 21-character cut.
        ->and(PaymentProcessors::split('DEBIT PURCHASE Sep 04 4849 PADDLE.NET* DATAFORSE New York NY 26248'))->toBe(['processor' => 'Paddle', 'merchant' => 'DATAFORSE New York NY'])
        ->and(PaymentProcessors::matchText('PADDLE.NET* DATAFORSEO'))->toBe('DATAFORSE')
        ->and(PaymentProcessors::matchText('DEBIT PURCHASE Sep 04 4849 PADDLE.NET* DATAFORSE 26248'))->toBe('DATAFORSE')
        ->and(PaymentProcessors::matchText('DEBIT PURCHASE Sep 04 4849 PADDLE.NET* DATAFORSE New York NY 26248'))->toBe('DATAFORSE')
        // The processor alone: no merchant, nothing to propose.
        ->and(PaymentProcessors::split('PADDLE.NET'))->toBe(['processor' => 'Paddle', 'merchant' => null])
        ->and(PaymentProcessors::split('PAYPAL'))->toBe(['processor' => 'PayPal', 'merchant' => null])
        ->and(PaymentProcessors::matchText('PADDLE.NET'))->toBeNull();
});

it('leaves descriptors that only look like a processor alone', function (string $descriptor) {
    expect(PaymentProcessors::split($descriptor))->toBe(['processor' => null, 'merchant' => null])
        ->and(PaymentProcessors::matchText($descriptor))->toBeNull();
})->with([
    'a paddle shop' => 'PADDLE SPORTS LLC',
    'a word, not Intuit' => 'DEBIT PURCHASE IN STORE',
    'a PayPal transfer' => 'PAYPAL INST XFER',
    'the bank itself' => 'CAPITAL ONE MOBILE PYMT',
    'Google without the star' => 'DEBIT PURCHASE Nov 01 4849 Google CLOUD vzWQRJ 26306',
    'Amazon order reference' => 'Amazon.com*0H1IL89S3',
    'Microsoft order reference' => 'MSFT * E01009BEDW',
    'Apple billing' => 'APPLE.COM/BILL',
    'masked card number' => 'AIRBNB * **********',
    'an ordinary merchant' => 'NORTHBROOK VLG MISC',
]);

it('reports a processor whose merchant part is only digits as the processor alone', function () {
    expect(PaymentProcessors::split('SHOPIFY * 12345678'))->toBe(['processor' => 'Shopify', 'merchant' => null]);
});

it('tells processor-only rules from merchant rules', function () {
    expect(PaymentProcessors::isProcessorOnly('PADDLE.NET'))->toBeTrue()
        ->and(PaymentProcessors::isProcessorOnly('PAYPAL \*'))->toBeTrue()
        ->and(PaymentProcessors::isProcessorOnly('PAYPAL'))->toBeTrue()
        ->and(PaymentProcessors::isProcessorOnly('SQ \*'))->toBeTrue()
        ->and(PaymentProcessors::isProcessorOnly('PADDLE\.NET\* ?DATAFORSE'))->toBeFalse()
        ->and(PaymentProcessors::isProcessorOnly('DATAFORSE'))->toBeFalse()
        ->and(PaymentProcessors::isProcessorOnly('TST\* TASTES ON THE FLY'))->toBeFalse()
        ->and(PaymentProcessors::isProcessorOnly('FASTSPRING SEJDA'))->toBeFalse()
        ->and(PaymentProcessors::isProcessorOnly('Google ADS'))->toBeFalse()
        ->and(PaymentProcessors::isProcessorOnly('IPR\* [A-Z]{5}'))->toBeFalse()
        ->and(PaymentProcessors::isProcessorOnly('Microsoft'))->toBeFalse()
        ->and(PaymentProcessors::isProcessorOnly('APPLE.COM'))->toBeFalse()
        ->and(PaymentProcessors::isProcessorOnly(''))->toBeFalse();
});

it('tells a processor name from a merchant name', function () {
    expect(PaymentProcessors::isProcessorName('Paddle'))->toBeTrue()
        ->and(PaymentProcessors::isProcessorName('Paddle.net'))->toBeTrue()
        ->and(PaymentProcessors::isProcessorName('PayPal'))->toBeTrue()
        ->and(PaymentProcessors::isProcessorName('Square'))->toBeTrue()
        ->and(PaymentProcessors::isProcessorName('Toast'))->toBeTrue()
        ->and(PaymentProcessors::isProcessorName('Dataforseo'))->toBeFalse()
        ->and(PaymentProcessors::isProcessorName('Laravel Daily'))->toBeFalse()
        ->and(PaymentProcessors::isProcessorName('Google Cloud'))->toBeFalse()
        ->and(PaymentProcessors::isProcessorName('Microsoft'))->toBeFalse()
        ->and(PaymentProcessors::isProcessorName(''))->toBeFalse()
        ->and(PaymentProcessors::isProcessorName(null))->toBeFalse();
});

it('words the refusal plainly, naming the merchant text to use instead', function () {
    expect(PaymentProcessors::refusalMessage('PADDLE.NET', 'DATAFORSE'))
        ->toBe('PADDLE.NET is a payment processor many companies bill through. Match on the company name after it instead, like DATAFORSE.')
        ->and(PaymentProcessors::refusalMessage('PAYPAL *'))
        ->toBe('PAYPAL * is a payment processor many companies bill through. Match on the company name after it instead.');
});
