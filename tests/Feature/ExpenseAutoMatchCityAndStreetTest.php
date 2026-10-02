<?php

use App\Http\Controllers\ExpenseAutoMatchController;

/**
 * Which project a receipt's purchase order names (2026-10-02): the PO "oak
 * park" on a Menards receipt went to Mark Brodson's Northbrook job, because
 * "park" was compared with the client's first name as if it were a street
 * and one letter apart scored 0.75. A city now matches only the project
 * being worked on there at the expense date, and street-name comparisons
 * run on addresses only, with short words matching exactly or not at all.
 */
function poMatch_controller(): ExpenseAutoMatchController
{
    return new class extends ExpenseAutoMatchController
    {
        public function match(string $po, string $date, array $candidates): ?array
        {
            return $this->matchPurchaseOrderToProjectAtDate($po, $date, $candidates);
        }
    };
}

/** A project candidate the way runNoProjectExpenseAutoMatch builds one. */
function poMatch_project(int $id, string $address, string $name, string $city, array $clientNames, array $statuses): array
{
    $norm = fn (string $v) => trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9\s]/', ' ', strtolower($v))));
    $address = $norm($address);
    $full = $norm($address.' '.$name);

    return [
        'id' => $id,
        'created_at' => 0,
        'statuses' => array_map(fn (array $s) => ['code' => $s[0], 'start_date' => $s[1]], $statuses),
        'variants' => array_values(array_unique(array_merge([$address, $full, $norm($name)], array_map($norm, $clientNames)))),
        'address_variants' => [$address, $full],
        'city' => $norm($city),
    ];
}

function poMatch_brodsonNorthbrook(): array
{
    return poMatch_project(364, '3154 Violet Ln', 'Home Renovation', 'Northbrook', ['Mark Brodson', 'Mark', 'Brodson'], [[4, '2026-04-10'], [6, '2026-06-01']]);
}

function poMatch_frombergOakPark(array $statuses = [[4, '2026-06-02'], [6, '2026-07-09']]): array
{
    return poMatch_project(399, '1124 South Harvey Avenue', 'Bathrooms', 'Oak Park', ['David Fromberg', 'Fromberg'], $statuses);
}

it('matches a PO naming a city to the project being worked on there', function () {
    $match = poMatch_controller()->match('oak park', '2026-10-01', [poMatch_brodsonNorthbrook(), poMatch_frombergOakPark()]);

    expect($match)->toMatchArray(['project_id' => 399, 'ambiguous' => false]);
});

it('also finds the city inside a longer PO', function () {
    expect(poMatch_controller()->match('Oak Park bath', '2026-10-01', [poMatch_brodsonNorthbrook(), poMatch_frombergOakPark()])['project_id'] ?? null)->toBe(399);
});

it('counts a Scheduled project in the city as being worked on', function () {
    $scheduled = poMatch_frombergOakPark([[4, '2026-06-02'], [5, '2026-09-20']]);

    expect(poMatch_controller()->match('oak park', '2026-10-01', [poMatch_brodsonNorthbrook(), $scheduled])['project_id'] ?? null)->toBe(399);
});

it('leaves a city with two projects under way for a person', function () {
    $second = poMatch_project(410, '200 N Oak Park Ave', 'Kitchen', 'Oak Park', ['Jane Doe'], [[6, '2026-09-01']]);

    $match = poMatch_controller()->match('oak park', '2026-10-01', [poMatch_brodsonNorthbrook(), poMatch_frombergOakPark(), $second]);

    expect($match['ambiguous'] ?? null)->toBeTrue();
});

it('matches nothing when no project in the city is under way, rather than guessing a name', function () {
    $finished = poMatch_frombergOakPark([[6, '2025-03-01'], [7, '2025-06-01']]);

    expect(poMatch_controller()->match('oak park', '2026-10-01', [poMatch_brodsonNorthbrook(), $finished]))->toBeNull();
});

it('does not read a client\'s first name as a street a letter away', function () {
    $hale = poMatch_project(286, '148 N Hale St', 'Kitchen', 'Wheaton', ['Mark Smith'], [[6, '2026-09-01']]);

    $controller = poMatch_controller();

    expect($controller->match('park', '2026-10-01', [$hale]))->toBeNull()
        ->and($controller->match('dale', '2026-10-01', [$hale]))->toBeNull()
        ->and($controller->match('hale', '2026-10-01', [$hale])['project_id'] ?? null)->toBe(286);
});

it('still matches a misspelled long street name and a full address', function () {
    $marcella = poMatch_project(500, '17 N Marcella Rd', 'Basement', 'Arlington Heights', ['Ann Lee'], [[6, '2026-09-01']]);

    $controller = poMatch_controller();

    expect($controller->match('M MARCELA', '2026-10-01', [$marcella])['project_id'] ?? null)->toBe(500)
        ->and($controller->match('1124 S Harvey', '2026-10-01', [poMatch_brodsonNorthbrook(), poMatch_frombergOakPark()])['project_id'] ?? null)->toBe(399);
});
