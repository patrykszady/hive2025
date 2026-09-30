<?php

use App\Livewire\Estimates\EstimateAIGenerator;
use App\Services\EstimateAIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Support/estimate-ai-fixtures.php';

/**
 * Several layouts on one draft (2026-09-30): a two-storey job, or a basement,
 * is scanned floor by floor. Each layout is measured, the numbers are added
 * up, and each floor's own are kept for the model.
 */
function layoutCsv(string $room, float $floor, float $wall, float $ceiling): string
{
    return "Room,Floor Area (sq ft),Wall Area (sq ft),Ceiling Height (ft)\n{$room},{$floor},{$wall},{$ceiling}\n";
}

it('adds several layouts up, names each room by its floor and keeps each floor', function () {
    $combined = EstimateAIGenerator::combineFloorplans([
        ['label' => 'Floor 1', 'data' => ['filename' => 'first.csv', 'floor_sqft' => 820.5, 'wall_sqft' => 1500, 'ceiling_height_ft' => 9, 'base_cabinet_lf' => 14,
            'rooms' => [['name' => 'Kitchen', 'floor_sqft' => 220]], 'appliances' => ['sinks' => 1, 'dishwashers' => 1]]],
        ['label' => 'Floor 2', 'data' => ['filename' => 'second.csv', 'floor_sqft' => 640, 'wall_sqft' => 1200, 'ceiling_height_ft' => 8,
            'rooms' => [['name' => 'Hall Bath', 'floor_sqft' => 60]], 'appliances' => ['sinks' => 2, 'toilets' => 1], 'note' => 'OCR is not configured.']],
    ]);

    expect($combined['floor_sqft'])->toBe(1460.5)
        ->and($combined['wall_sqft'])->toEqual(2700)
        ->and($combined['base_cabinet_lf'])->toEqual(14)
        ->and($combined['ceiling_height_ft'])->toEqual(9)
        ->and(array_column($combined['rooms'], 'name'))->toBe(['Floor 1 · Kitchen', 'Floor 2 · Hall Bath'])
        ->and($combined['appliances'])->toBe(['sinks' => 3, 'dishwashers' => 1, 'toilets' => 1])
        ->and($combined['note'])->toBe('Floor 2: OCR is not configured.')
        ->and(array_column($combined['floors'], 'label'))->toBe(['Floor 1', 'Floor 2'])
        ->and($combined['floors'][1]['floor_sqft'])->toEqual(640);
});

it('sends each floor to the model beside the totals, and says how to read them', function () {
    $metrics = EstimateAIService::floorplanMetrics(EstimateAIGenerator::combineFloorplans([
        ['label' => 'Main floor', 'data' => ['floor_sqft' => 800, 'wall_sqft' => 1400]],
        ['label' => 'Basement', 'data' => ['floor_sqft' => 700, 'wall_sqft' => 900]],
    ]));

    expect($metrics['floor_sqft'])->toEqual(1500)
        ->and($metrics['floors'])->toBe([
            ['label' => 'Main floor', 'floor_sqft' => 800.0, 'wall_sqft' => 1400.0],
            ['label' => 'Basement', 'floor_sqft' => 700.0, 'wall_sqft' => 900.0],
        ]);

    $service = new EstimateAIService;
    $prompt = (new ReflectionMethod($service, 'userPrompt'))->invoke($service, 'Finish the basement and paint upstairs', $metrics, collect(), []);

    expect($prompt)->toContain('several layouts (floors)')->toContain('"label": "Basement"');
});

it('one layout reads exactly as it did before', function () {
    expect(EstimateAIService::floorplanMetrics(['floor_sqft' => 200, 'floors' => [['label' => 'Floor 1', 'floor_sqft' => 200]]]))
        ->toBe(['floor_sqft' => 200.0]);
});

it('takes layouts one after another, names them, and drafts from all of them', function () {
    [$fx, $component] = draftingGenerator();

    $component
        ->set('newFloorplans', [UploadedFile::fake()->createWithContent('first-floor.csv', layoutCsv('Kitchen', 220, 480, 9))])
        ->set('newFloorplans', [
            UploadedFile::fake()->createWithContent('second-floor.csv', layoutCsv('Hall Bath', 60, 210, 8)),
            UploadedFile::fake()->createWithContent('garage.csv', layoutCsv('Garage', 400, 600, 10)),
        ])
        ->assertCount('floorplans', 3)
        ->assertSet('floorplanLabels', ['Floor 1', 'Floor 2', 'Floor 3'])
        ->assertSee('second-floor.csv')
        ->call('removeFloorplan', 2)
        ->assertCount('floorplans', 2)
        ->assertDontSee('garage.csv')
        ->set('floorplanLabels.1', 'Upstairs');

    capturingLivewireStream(fn () => $component->call('generate'));

    $service = app(EstimateAIService::class);
    $prompt = collect($service->sentRequests[0]['messages'])->pluck('content')->implode("\n");

    // Plain CSVs carry totals, not rooms (a Polycam scan carries both): the
    // totals are summed and each floor is listed under its own name.
    expect($prompt)->toContain('"floor_sqft": 280')
        ->toContain('"label": "Floor 1"')
        ->toContain('"label": "Upstairs"')
        ->toContain('several layouts (floors)');
});
