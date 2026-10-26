<?php

use App\Support\Windows\WindowDiagram;

/*
 * The drawing a window line carries: white frame, black border, blue glass,
 * and the standard symbols — the V's point marks the hinge side, a bottom V
 * marks a tilting panel, an arrow marks the lite that slides.
 */

it('draws a picture window as one sash with no opening lines', function () {
    $svg = WindowDiagram::svg(['type' => 'picture', 'width_in' => 24, 'height_in' => 48]);

    expect($svg)->toContain('<svg')->toContain('fill="#ffffff" stroke="#000000"')->toContain('fill="#d6e8f5"')
        ->not->toContain('<polyline')->not->toContain('<line')
        ->toContain('aria-label="picture 24 by 48"');
});

it('keeps the real proportions, clamped so extremes still read', function () {
    expect(WindowDiagram::svg(['type' => 'picture', 'width_in' => 24, 'height_in' => 48]))->toContain('viewBox="0 0 108 208"');
    expect(WindowDiagram::svg(['type' => 'picture', 'width_in' => 60, 'height_in' => 12]))->toContain('viewBox="0 0 108 53"');
    expect(WindowDiagram::svg(['type' => 'picture', 'width_in' => 10, 'height_in' => 80]))->toContain('viewBox="0 0 108 228"');
});

it('points the V at the hinge side of a casement', function () {
    $left = WindowDiagram::svg(['type' => 'casement', 'width_in' => 30, 'height_in' => 40, 'hinge' => 'left']);
    $right = WindowDiagram::svg(['type' => 'casement', 'width_in' => 30, 'height_in' => 40, 'hinge' => 'right']);

    // The glass is inset 12 from the frame at 4: the apex sits on the glass's hinge-side edge (x 16 or 92), midway down.
    expect($left)->toMatch('/polyline points="92,16 16,[\d.]+ 92,[\d.]+"/')
        ->and($right)->toMatch('/polyline points="16,16 92,[\d.]+ 16,[\d.]+"/');
});

it('draws a hopper with its V from the bottom and an awning from the top', function () {
    expect(WindowDiagram::svg(['type' => 'hopper', 'width_in' => 30.75, 'height_in' => 36.125]))->toMatch('/polyline points="16,16 54,[\d.]+ 92,16"/');
    expect(WindowDiagram::svg(['type' => 'awning', 'width_in' => 40, 'height_in' => 30]))->toContain('<polyline points="13,70 54,13 95,70"');
});

it('arrows the active lite of a slider and a patio door in its travel direction, with a handle on the door', function () {
    $window = WindowDiagram::svg(['type' => 'slider', 'width_in' => 30.75, 'height_in' => 20, 'active_panel' => 'right', 'slide_direction' => 'left']);
    $door = WindowDiagram::svg(['type' => 'patio_door', 'width_in' => 59.25, 'height_in' => 79.375, 'active_panel' => 'right', 'slide_direction' => 'left']);

    expect(substr_count($window, '<rect'))->toBe(4, 'two sashes, each a frame and a glass')
        ->and($window)->toContain('<line')->not->toContain('height="14"')
        ->and($door)->toContain('height="14"');
});

it('gives a tilt-and-turn french door a hinge V per panel and a bottom V on the tilting one', function () {
    $svg = WindowDiagram::svg(['type' => 'tilt_turn', 'width_in' => 80, 'height_in' => 84, 'panels' => [['hinge' => 'left', 'tilt' => false], ['hinge' => 'right', 'tilt' => true]]]);

    expect(substr_count($svg, '<polyline'))->toBe(3);
});

it('draws anything that is not a window as a dashed box', function () {
    expect(WindowDiagram::svg(['type' => 'product', 'width_in' => 80, 'height_in' => 84]))->toContain('stroke-dasharray')->not->toContain('#d6e8f5');
    expect(WindowDiagram::normaliseType('Sliding Patio Door'))->toBe('product');
    expect(WindowDiagram::normaliseType('sliding_patio_door'))->toBe('patio_door');
    expect(WindowDiagram::normaliseType('fixed'))->toBe('picture');
});

it('prints inches the way a window order does', function () {
    expect(WindowDiagram::inches(59.25))->toBe('59¼')
        ->and(WindowDiagram::inches(79.375))->toBe('79⅜')
        ->and(WindowDiagram::inches(30.75))->toBe('30¾')
        ->and(WindowDiagram::inches(36.125))->toBe('36⅛')
        ->and(WindowDiagram::inches(24))->toBe('24')
        ->and(WindowDiagram::inches(24.3))->toBe('24.3');
});
