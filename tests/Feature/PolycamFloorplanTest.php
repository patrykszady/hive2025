<?php

use App\Livewire\Estimates\EstimateAIGenerator;
use App\Services\EstimateAIService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A Polycam room scan (Room, Description, Value) carries far more than the
 * four totals the draft used to keep: per-room areas, casings, every
 * cabinet's size, the appliances. The fixture is a real kitchen scan with
 * the client's name and address replaced.
 */
function polycamMetrics(): array
{
    $generator = new EstimateAIGenerator();
    $method = new ReflectionMethod($generator, 'extractCsvFloorplanMetrics');

    return $method->invoke($generator, file_get_contents(base_path('tests/fixtures/polycam-kitchen.csv')));
}

it('reads the totals, the rooms, the cabinets and the appliances out of a Polycam scan', function () {
    $m = polycamMetrics();

    expect($m['floor_sqft'])->toBe(147.4)
        ->and($m['wall_sqft'])->toBe(341.2)
        ->and($m['ceiling_height_ft'])->toBe(8.17)
        // The whole plan's perimeter, not the last room's (30' 6" was what came through before).
        ->and($m['perimeter_ft'])->toBe(68.92)
        ->and($m['window_area_sqft'])->toBe(15.7)
        ->and($m['window_casing_lf'])->toBe(25.33)
        ->and($m['door_casing_lf'])->toBe(17.92)
        ->and($m['cabinet_count'])->toBe(10)
        // Five deep boxes are base cabinets (14' 6"); five shallow ones are uppers (19' 3").
        ->and($m['base_cabinet_lf'])->toBe(14.5)
        ->and($m['upper_cabinet_lf'])->toBe(19.25)
        ->and($m)->not->toHaveKey('tall_cabinet_lf')
        // Counter run: the base cabinets plus the 2' dishwasher under it.
        ->and($m['countertop_lf'])->toBe(16.5)
        ->and($m['appliances'])->toEqualCanonicalizing(['fridges' => 1, 'stoves' => 1, 'ovens' => 1, 'dishwashers' => 1, 'sinks' => 1]);

    expect(collect($m['rooms'])->pluck('name')->all())->toBe(['Kitchen', 'Dining Room'])
        ->and($m['rooms'][0])->toMatchArray(['floor_sqft' => 92.1, 'wall_sqft' => 219.6, 'ceiling_height_ft' => 8.17, 'perimeter_ft' => 38.42, 'window_casing_lf' => 11.67, 'dimensions' => "9' 11\" x 9' 3\""])
        ->and($m['rooms'][1])->toMatchArray(['floor_sqft' => 55.3, 'wall_sqft' => 121.6, 'perimeter_ft' => 30.5, 'door_casing_lf' => 17.92, 'window_casing_lf' => 13.67]);
});

it('keeps the scan\'s numbers, rooms and appliances for the model and drops the rest', function () {
    $kept = EstimateAIService::floorplanMetrics(polycamMetrics() + ['filename' => 'KITCHEN - Someone.csv', 'note' => 'from the client']);

    expect($kept)->not->toHaveKeys(['filename', 'note'])
        ->and($kept['perimeter_ft'])->toBe(68.92)
        ->and($kept['countertop_lf'])->toBe(16.5)
        ->and($kept['appliances']['dishwashers'])->toBe(1)
        ->and($kept['rooms'][0]['name'])->toBe('Kitchen')
        ->and($kept['rooms'][0]['floor_sqft'])->toBe(92.1);
});

it('sizes baseboard and casings from the scan, in linear feet only', function () {
    $service = new EstimateAIService();
    $method = new ReflectionMethod($service, 'applyFloorplanQuantities');

    $items = $method->invoke($service, [
        ['name' => 'Baseboard Install', 'unit_type' => 'li.ft.', 'quantity' => 1],
        ['name' => 'Window Casings', 'unit_type' => 'li.ft.', 'quantity' => 1],
        ['name' => 'Door Casings', 'unit_type' => 'li.ft.', 'quantity' => 1],
        ['name' => 'Baseboard Heater', 'unit_type' => 'pieces', 'quantity' => 2],
        ['name' => 'Floor Tile', 'unit_type' => 'sq.ft.', 'quantity' => 1],
    ], EstimateAIService::floorplanMetrics(polycamMetrics()));

    expect(collect($items)->pluck('quantity', 'name')->all())->toBe([
        'Baseboard Install' => 68.92,
        'Window Casings' => 25.33,
        'Door Casings' => 17.92,
        'Baseboard Heater' => 2,
        'Floor Tile' => 147.4,
    ]);
});

it('tells the model what each scan number sizes', function () {
    $service = new EstimateAIService();
    $method = new ReflectionMethod($service, 'userPrompt');

    $prompt = $method->invoke($service, 'Kitchen remodel', EstimateAIService::floorplanMetrics(polycamMetrics()), collect(), []);

    expect($prompt)->toContain('measured from a room scan')
        ->toContain('perimeter_ft sizes baseboard')
        ->toContain('"countertop_lf": 16.5')
        ->toContain('"name": "Kitchen"');
});
