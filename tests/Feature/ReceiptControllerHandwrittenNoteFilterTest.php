<?php

use App\Http\Controllers\ReceiptController;

function invokeIsMeaningfulHandwrittenNote(string $note): bool
{
    $controller = app(ReceiptController::class);

    $method = new ReflectionMethod(ReceiptController::class, 'isMeaningfulHandwrittenNote');
    $method->setAccessible(true);

    return (bool) $method->invoke($controller, $note);
}

it('rejects policy account billing statement text as handwritten note', function () {
    expect(invokeIsMeaningfulHandwrittenNote('Policy / Account Billing # : Q610227734'))->toBeFalse();
});

it('accepts meaningful short job-style note text', function () {
    expect(invokeIsMeaningfulHandwrittenNote('3952'))->toBeTrue();
});

it('rejects symbol-only handwritten noise', function () {
    expect(invokeIsMeaningfulHandwrittenNote('&amp;'))->toBeFalse();
});
