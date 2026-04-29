<?php

use App\Services\ReceiptItemEnricher;

it('strips manufacturer prefix from MPN when supplement merges into existing row', function () {
    $enricher = new ReceiptItemEnricher();

    $existing = [
        ['Description' => 'OTHER PUSH BUTTON POP-UP WITH OVERFLOW CHROME', 'Manufacturer' => '', 'ManufacturerPartNumber' => '', 'Quantity' => 1],
    ];
    $supplement = [
        ['Description' => 'OTHER PUSH BUTTON POP-UP WITH OVERFLOW CHROME', 'Manufacturer' => 'Brizo', 'ManufacturerPartNumber' => 'BRIZORP72414PC', 'Quantity' => 1],
    ];

    $updates = $enricher->planMerge($existing, $supplement);
    $merged = $enricher->applyUpdates($existing, $updates);

    expect($merged[0]['Manufacturer'])->toBe('Brizo');
    expect($merged[0]['ManufacturerPartNumber'])->toBe('RP72414PC');
});

it('appends supplement-only items that do not exist on the receipt', function () {
    $enricher = new ReceiptItemEnricher();

    $existing = [
        ['Description' => 'BEAUCLERE WIDESPREAD LAVATORY FAUCET', 'Manufacturer' => 'Brizo', 'ManufacturerPartNumber' => '65365LF', 'Quantity' => 2],
    ];
    $supplement = [
        ['Description' => 'BEAUCLERE WIDESPREAD LAVATORY FAUCET', 'Manufacturer' => 'Brizo', 'ManufacturerPartNumber' => '65365LF', 'Quantity' => 2],
        ['Description' => 'ESSENTIAL 22 X 34 RECTANGLE DECORATIVE MIRROR', 'Manufacturer' => 'Kohler', 'VendorCode' => 'K26052BLL', 'Quantity' => 2],
    ];

    $updates = $enricher->planMerge($existing, $supplement);
    $appended = $enricher->unmatchedSupplementItems($existing, $supplement, $updates);

    expect($appended)->toHaveCount(1);
    expect($appended[0]['Description'])->toBe('ESSENTIAL 22 X 34 RECTANGLE DECORATIVE MIRROR');
    expect($appended[0]['ManufacturerPartNumber'])->toBe('K26052BLL');
    expect($appended[0]['product_url'])->toBeNull();
    expect($appended[0]['image_url'])->toBeNull();
});

it('dedups supplement items by MPN against existing receipt rows', function () {
    $enricher = new ReceiptItemEnricher();

    $existing = [
        ['Description' => 'WIDGET A', 'Manufacturer' => 'Brizo', 'ManufacturerPartNumber' => '87465PC', 'Quantity' => 1],
    ];
    $supplement = [
        ['Description' => 'COMPLETELY DIFFERENT TEXT HERE NOTHING MATCHES', 'Manufacturer' => 'Brizo', 'ManufacturerPartNumber' => '87465-PC', 'Quantity' => 1],
    ];

    $updates = $enricher->planMerge($existing, $supplement);
    $appended = $enricher->unmatchedSupplementItems($existing, $supplement, $updates);

    expect($appended)->toHaveCount(0);
});

it('dedups supplement items by relaxed description overlap', function () {
    $enricher = new ReceiptItemEnricher();

    $existing = [
        ['Description' => 'BEAUCLERE WIDESPREAD LAVATORY FAUCET WITH ARC SPOUT LESS HANDLES', 'Manufacturer' => '', 'ManufacturerPartNumber' => '', 'Quantity' => 2],
    ];
    $supplement = [
        ['Description' => 'BEAUCLERE WIDESPREAD LAV FAUCET WHEEL HANDLE', 'Manufacturer' => 'Brizo', 'ManufacturerPartNumber' => '65365LFPCLHP', 'Quantity' => 2],
    ];

    $updates = $enricher->planMerge($existing, $supplement);
    $appended = $enricher->unmatchedSupplementItems($existing, $supplement, $updates);

    expect($appended)->toHaveCount(0);
});

it('sanitizeMpn strips brand prefix only when present', function () {
    $enricher = new ReceiptItemEnricher();

    expect($enricher->sanitizeMpn('BRIZORP72414PC', 'Brizo'))->toBe('RP72414PC');
    expect($enricher->sanitizeMpn('KOHLERK26052BLL', 'Kohler'))->toBe('K26052BLL');
    // Already clean MPNs untouched
    expect($enricher->sanitizeMpn('K26052BLL', 'Kohler'))->toBe('K26052BLL');
    // Short manufacturer name (e.g. AO) is ignored to avoid false positives
    expect($enricher->sanitizeMpn('AO12345', 'AO'))->toBe('AO12345');
    // Empty/whitespace
    expect($enricher->sanitizeMpn('  ', 'Brizo'))->toBe('');
});

it('matches verbose supplement descriptions to terse existing quotation lines', function () {
    $enricher = new ReceiptItemEnricher();

    $existing = [
        ['Description' => 'C3-455 CLEANSING TOILET SEAT WHITE', 'Manufacturer' => '', 'ManufacturerPartNumber' => '', 'Quantity' => 1],
        ['Description' => 'KOHLER UNIVERSAL RITE-TEMP PB VALVE KIT, STOP', 'Manufacturer' => '', 'ManufacturerPartNumber' => '', 'Quantity' => 1],
    ];
    $supplement = [
        ['Description' => 'Purewash E820 Elongated Bidet Toilet Seat with Remote Control', 'Manufacturer' => 'Kohler', 'VendorCode' => 'K8298CR0', 'Quantity' => 1],
        ['Description' => 'Rite-Temp Pressure-Balancing Valve Body and Cartridge Kit with Service Stops (1/2 5.0 gpm)', 'Manufacturer' => 'Kohler', 'VendorCode' => 'K8304KSNA', 'Quantity' => 1],
        ['Description' => 'Essential 22 x 34 Rectangle Decorative Mirror', 'Manufacturer' => 'Kohler', 'VendorCode' => 'K26052BLL', 'Quantity' => 2],
    ];

    $updates = $enricher->planMerge($existing, $supplement);
    $merged = $enricher->applyUpdates($existing, $updates);
    $appended = $enricher->unmatchedSupplementItems($merged, $supplement, $updates);

    expect($merged[0]['ManufacturerPartNumber'])->toBe('K8298CR0');
    expect($merged[1]['ManufacturerPartNumber'])->toBe('K8304KSNA');
    expect($appended)->toHaveCount(1);
    expect($appended[0]['Description'])->toBe('Essential 22 x 34 Rectangle Decorative Mirror');
});
