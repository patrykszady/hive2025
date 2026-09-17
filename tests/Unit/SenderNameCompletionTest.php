<?php

use App\Support\SenderName;

test('a sign-off in the message completes a first name, and only for the same person', function (string $first, string $message, ?string $expected) {
    expect(SenderName::fromSignOff($message, $first))->toBe($expected);
})->with([
    'thanks comma name' => ['Katherine', "Please call me.\n\nThanks, Katherine Brown", 'Katherine Brown'],
    'sign-off then name on the next line' => ['Katherine', "Please call me.\nRegards,\nKatherine Brown", 'Katherine Brown'],
    'dash sign-off' => ['Katherine', "Please call me.\n-- Katherine Brown", 'Katherine Brown'],
    'bare name as the last line' => ['Katherine', "Please call me.\n\nKatherine Brown", 'Katherine Brown'],
    'my name is' => ['Katherine', 'Hi, my name is Katherine Brown and we want to remodel.', 'Katherine Brown'],
    'nickname signs the full name' => ['Kate', "Thanks,\nKatherine Brown", 'Kate Brown'],
    'two-word surname' => ['Katherine', "Thanks, Katherine De Luca", 'Katherine De Luca'],
    'first name alone is no surname' => ['Katherine', "Thanks,\nKatherine", null],
    'someone else\'s name is not theirs' => ['Katherine', "Please call Bob Smith to arrange.\nThanks, Bob Smith", null],
    'a name with digits is a phone line' => ['Katherine', "Thanks\nKatherine 847-404-1401", null],
    'lowercase is not a signature' => ['Katherine', "thanks, katherine brown", null],
    'device word is not a surname' => ['Katherine', "Sent from Katherine iPhone", null],
    'no message' => ['Katherine', '', null],
]);

test('an address gives the surname when it plainly spells it', function (string $first, string $email, ?string $expected) {
    expect(SenderName::surnameFromAddress($first, $email))->toBe($expected);
})->with([
    'first.last' => ['Valina', 'valina.markhay@gmail.com', 'Markhay'],
    'nickname in the address' => ['Mike', 'michael_dimarco@gmail.com', 'Dimarco'],
    'apostrophe cased' => ['Valina', 'valina.obrien@gmail.com', 'Obrien'],
    'joined to a long first name' => ['Katherine', 'katherinebrown521@gmail.com', 'Brown'],
    'joined to a short first name says nothing' => ['Will', 'willjohn1089@example.com', null],
    'three parts with one candidate' => ['Valina', 'valina.markhay.home@gmail.com', 'Markhay'],
    'no first name in the address' => ['Valina', 'the.markhays@gmail.com', null],
]);

test('a full name stands, a first name is completed from the message before the address, and otherwise stays', function () {
    expect(SenderName::completeFromMessage('Patricia Schooler', 'patschooler1233@gmail.com', 'Thanks, Pat Jones'))->toBe('Patricia Schooler')
        ->and(SenderName::completeFromMessage('Katherine', 'kb@gmail.com', "Thanks,\nKatherine Brown"))->toBe('Katherine Brown')
        ->and(SenderName::completeFromMessage('Katherine', 'katherinebrown521@gmail.com', 'Please call me.'))->toBe('Katherine Brown')
        ->and(SenderName::completeFromMessage('Bob', 'bobby77@gmail.com', 'Please call me.'))->toBe('Bob')
        ->and(SenderName::completeFromMessage('Toby 312', 'toby@gmail.com', null))->toBe('Toby')
        ->and(SenderName::completeFromMessage('', 'x@y.com', 'Hi'))->toBeNull();
});
