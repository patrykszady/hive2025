<?php

use App\Http\Controllers\ReceiptController;
use App\Services\NylasService;

/**
 * On a return, and on a purchase paid with a gift card, the last prominent
 * number on the receipt is the CARD'S balance after the transaction. CU kept
 * returning it as the total, so a $256.29 refund was stored as a $262.99
 * purchase (expense 27404). The receipt's own TOTAL line wins, sign included.
 */
function printedTotalController(): object
{
    return new class(app(NylasService::class)) extends ReceiptController
    {
        public function prefer(?float $amount, string $content): ?float
        {
            return $this->preferPrintedTotal($amount, $content);
        }

        public function fromContent(string $content): ?float
        {
            return $this->extractTotalFromContent($content);
        }
    };
}

const HD_RETURN = "How doers\nget more done.\n\n1913 00017 55628-06/18/26 11:33 AM\nCASHIER JEAN\n\\* ORIG REC: 8119 077 14389 06/17/26 TA *\n\n1012-218-309 WhisperGreen Select-232.99\n\nSUBTOTAL\n\n-232.99\n\nSALES TAX\n\n-23.30\n\nTOTAL\n\n-\$256.29\n\nXXXXXXXX3649\n\nSTORE CREDIT\n\n-256.29\n\nCARD BALANCE\n\n262.99\n\nTA\nREFUND-CUSTOMER COPY\n";

const HD_GIFT_CARD_PURCHASE = "SUBTOTAL\t103.65\nSALES TAX\t8.29\nTOTAL\t111.94\nXXXXXXXX3649 GIFT CARD\t111.94\nCARD BALANCE\t122.21\n";

it('stores a return as its negative printed TOTAL, not the card balance', function () {
    expect(printedTotalController()->prefer(262.99, HD_RETURN))->toBe(-256.29);
});

it('stores a gift-card purchase as its TOTAL, not the remaining balance', function () {
    expect(printedTotalController()->prefer(122.21, HD_GIFT_CARD_PURCHASE))->toBe(111.94);
});

it('restores the sign of a return CU read as positive', function () {
    expect(printedTotalController()->prefer(256.29, HD_RETURN))->toBe(-256.29);
});

it('leaves an amount alone when it matches the printed TOTAL, or nothing explains the difference', function () {
    $c = printedTotalController();

    expect($c->prefer(111.94, HD_GIFT_CARD_PURCHASE))->toBe(111.94)
        // A different number with no balance line to blame: keep CU's answer.
        ->and($c->prefer(99.00, "SUBTOTAL 90.00\nTAX 9.00\nTOTAL 99.00\nVISA 99.00\n"))->toBe(99.00)
        ->and($c->prefer(105.00, "SUBTOTAL 90.00\nTAX 9.00\nTOTAL 99.00\nVISA 99.00\n"))->toBe(105.00)
        // No printed TOTAL at all: nothing to prefer.
        ->and($c->prefer(42.00, "Thanks for shopping\n"))->toBe(42.00)
        // Nothing from CU: the printed TOTAL fills in.
        ->and($c->prefer(null, HD_RETURN))->toBe(-256.29);
});

it('no longer mistakes a CARD BALANCE line for the total in the content fallback', function () {
    expect(printedTotalController()->fromContent(HD_RETURN))->toBe(-256.29)
        ->and(printedTotalController()->fromContent("Invoice\nBALANCE DUE 150.00\n"))->toBe(150.00);
});
