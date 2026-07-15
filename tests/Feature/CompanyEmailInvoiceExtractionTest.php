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

it('extracts a Stripe-style Receipt # and ignores the word "invoicing" in the footer', function () {
    // Real regression: Village of Northbrook (BS&A → Stripe) receipt. The bare
    // "INV" pattern matched inside "invoicing" and produced the invoice "oicing".
    $rawContent = "Receipt from Miscellaneous - Village of\nNorthbrook, Cook County, IL\n\n"
        ."Receipt #1134-0898\n\nAMOUNT PAID\n\$70.00\n\nAmount paid\n\$70.00\n\n"
        ."You're receiving this email because you made a purchase at Miscellaneous -\n"
        ."Village of Northbrook, Cook County, IL, which partners with Stripe to provide\n"
        ."invoicing and payment processing.";

    $invoice = callCompanyEmailControllerMethod(
        companyEmailControllerForInvoiceExtraction(),
        'extractInvoiceFromOcrRawContent',
        [$rawContent]
    );

    expect($invoice)->toBe('1134-0898');
});

it('does not treat mid-word "inv" as an invoice label when no other token exists', function () {
    $invoice = callCompanyEmailControllerMethod(
        companyEmailControllerForInvoiceExtraction(),
        'extractInvoiceFromOcrRawContent',
        ['Stripe partners with merchants to provide invoicing and payment processing.']
    );

    expect($invoice)->toBeNull();
});

it('still extracts bare INV-prefixed invoice numbers', function () {
    $invoice = callCompanyEmailControllerMethod(
        companyEmailControllerForInvoiceExtraction(),
        'extractInvoiceFromOcrRawContent',
        ["Thanks for your payment.\nINV 78421\nTotal: \$50.00"]
    );

    expect($invoice)->toBe('78421');
});

it('returns null when OCR raw content has no invoice token', function () {
    $invoice = callCompanyEmailControllerMethod(
        companyEmailControllerForInvoiceExtraction(),
        'extractInvoiceFromOcrRawContent',
        ['Payment Amount: $100.00']
    );

    expect($invoice)->toBeNull();
});
