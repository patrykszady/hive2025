<?php

use App\Support\MetaDescription;

it('keeps a description that already fits', function () {
    expect(MetaDescription::from('Short and complete.'))->toBe('Short and complete.');
});

it('keeps whole sentences when they fill most of the limit', function () {
    $text = str_repeat('Every word here counts. ', 5).'This last sentence would run past the limit and is left out entirely.';

    $description = MetaDescription::from($text);

    expect($description)->toEndWith('counts.')
        ->and(mb_strlen($description))->toBeLessThanOrEqual(160);
});

it('ends on a whole word with an ellipsis when whole sentences would be too short', function () {
    $text = 'Hive brings your business under one roof. Automate your finances, communication and project planning with vendor collaboration, homeowner updates and timesheets in one app.';

    $description = MetaDescription::from($text);

    expect($description)->toEndWith('…')
        ->and(mb_strlen($description))->toBeLessThanOrEqual(160)
        ->and($description)->toStartWith('Hive brings your business under one roof. Automate')
        ->and(rtrim($description, '…'))->not->toEndWith(' ')->not->toEndWith(',');
});

it('drops markup and collapses whitespace', function () {
    expect(MetaDescription::from("<strong>Bold</strong>   claim,\n  plainly."))->toBe('Bold claim, plainly.');
});
