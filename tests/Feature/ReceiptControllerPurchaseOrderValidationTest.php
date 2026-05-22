<?php

use App\Console\Commands\SyncContentUnderstandingAnalyzer;

/**
 * Regression: Azure CU sometimes misextracts invoice summary rows as CustomerPO.
 * E.g., Microsoft invoice where "Customer PO Number:" label is followed by
 * "Discounts: 0.00" on the same line (tab-separated), causing CU to extract
 * "Discounts: 0.00" as the PO value instead of leaving it empty.
 *
 * This must be prevented in the CU analyzer definition itself so bad values are
 * not mapped to PO fields in the first place.
 */

it('includes billing-summary exclusions in receipt PurchaseOrder analyzer field', function () {
    $command = app(SyncContentUnderstandingAnalyzer::class);

    $reflection = new ReflectionClass($command);
    $method = $reflection->getMethod('buildReceiptDefinition');
    $method->setAccessible(true);

    $definition = $method->invoke($command, 'gpt-4.1', []);
    $description = (string) data_get($definition, 'fieldSchema.fields.PurchaseOrder.description', '');
    $customerPoDescription = (string) data_get($definition, 'fieldSchema.fields.CustomerPO.description', '');

    expect($description)->toContain('Do NOT return billing-summary labels or amounts');
    expect($description)->toContain('Discounts: 0.00');
    expect($description)->toContain('Credits: 0.00');
    expect($description)->toContain('If the text on or near a PO label is clearly a billing-summary field, return null for PurchaseOrder.');

    expect($customerPoDescription)->toContain('If the Customer PO field is blank, return null.');
    expect($customerPoDescription)->toContain('do NOT return "Discounts: 0.00"');
    expect($customerPoDescription)->toContain('Do NOT return billing-summary labels or amounts');
});

it('includes billing-summary exclusions in material-order CustomerPO analyzer field', function () {
    $command = app(SyncContentUnderstandingAnalyzer::class);

    $reflection = new ReflectionClass($command);
    $method = $reflection->getMethod('buildMaterialOrderDefinition');
    $method->setAccessible(true);

    $definition = $method->invoke($command, 'gpt-4.1');
    $description = (string) data_get($definition, 'fieldSchema.fields.CustomerPO.description', '');

    expect($description)->toContain('Do NOT return billing-summary labels or amounts');
    expect($description)->toContain('Discounts');
    expect($description)->toContain('Credits');
    expect($description)->toContain('Tax');
});

it('keeps handwritten notes focused on ink annotations and excludes merchant location lines', function () {
    $command = app(SyncContentUnderstandingAnalyzer::class);

    $reflection = new ReflectionClass($command);
    $method = $reflection->getMethod('buildReceiptDefinition');
    $method->setAccessible(true);

    $definition = $method->invoke($command, 'gpt-4.1', []);
    $description = (string) data_get($definition, 'fieldSchema.fields.HandwrittenNote.description', '');

    expect($description)->toContain('NEVER return: the printed merchant name or address');
    expect($description)->toContain('MOUNT PROSPECT');
    expect($description)->toContain('MOUNT PROSPECT, IL 60056');
    expect($description)->toContain('added by a person on top of the printed receipt');
});
