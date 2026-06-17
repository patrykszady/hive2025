<?php

use App\Http\Controllers\CompanyEmailController;

function companyEmailControllerForInvoiceExtraction(): CompanyEmailController
{
    return new CompanyEmailController(app(\App\Services\NylasService::class));
}

function callCompanyEmailControllerMethod(object $object, string $method, array $args = []): mixed
{
    $reflectionMethod = new ReflectionMethod($object, $method);

    return $reflectionMethod->invoke($object, ...$args);
}

it('extracts invoice number from OCR raw content invoice number block', function () {
    $rawContent = "Invoice Number:\nPRRCA202602421 - 359894,Residential Permit Deposit\n\nPayment Amount:\n$100.00";

    $invoice = callCompanyEmailControllerMethod(
        companyEmailControllerForInvoiceExtraction(),
        'extractInvoiceFromOcrRawContent',
        [$rawContent]
    );

    expect($invoice)->toBe('PRRCA202602421 - 359894');
});

it('extracts invoice number from inline invoice number line', function () {
    $rawContent = "Header\nInvoice Number: ABC-12345\nFooter";

    $invoice = callCompanyEmailControllerMethod(
        companyEmailControllerForInvoiceExtraction(),
        'extractInvoiceFromOcrRawContent',
        [$rawContent]
    );

    expect($invoice)->toBe('ABC-12345');
});

it('returns null when OCR raw content has no invoice token', function () {
    $invoice = callCompanyEmailControllerMethod(
        companyEmailControllerForInvoiceExtraction(),
        'extractInvoiceFromOcrRawContent',
        ['Payment Amount: $100.00']
    );

    expect($invoice)->toBeNull();
});
