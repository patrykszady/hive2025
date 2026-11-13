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

/** Elm Street jobs as they stood on 2026-09-29: only the Kitchen at 949 S Elm Ct was under way. */
function poMatch_elmJobs(): array
{
    return [
        poMatch_project(147, '949 S Elm Ct', 'Basement', 'Palatine', ['Joe Doe'], [[7, '2021-10-12']]),
        poMatch_project(381, '949 S Elm Ct', 'Kitchen', 'Palatine', ['Joe Doe'], [[4, '2026-08-01'], [6, '2026-09-08']]),
        poMatch_project(451, '1801 Elm St', 'Primary Suite', 'Park Ridge', ['Ann Roe'], [[2, '2026-09-10'], [3, '2026-09-29']]),
    ];
}

it('matches a PO naming a street to the one project on it under way', function () {
    expect(poMatch_controller()->match('ELM', '2026-09-29', poMatch_elmJobs()))->toMatchArray(['project_id' => 381, 'ambiguous' => false]);
});

it('leaves a street with two projects under way for a person', function () {
    $second = poMatch_project(452, '1801 Elm St', 'Garage', 'Park Ridge', ['Ann Roe'], [[6, '2026-09-20']]);

    expect(poMatch_controller()->match('ELM', '2026-09-29', [...poMatch_elmJobs(), $second])['ambiguous'] ?? null)->toBeTrue();
});

it('never guesses from a short PO that names no street or city under way', function () {
    $finished = [poMatch_project(147, '949 S Elm Ct', 'Basement', 'Palatine', ['Elm Smith'], [[7, '2026-09-01']])];

    expect(poMatch_controller()->match('ELM', '2026-09-29', $finished))->toBeNull()
        ->and(poMatch_controller()->match('BOB', '2026-09-29', poMatch_elmJobs()))->toBeNull();
});

it('matches a PO that is only a house number to the one project at that number under way', function () {
    // The dump ticket of 2026-11-04 marked "949": three older jobs at 949 S Elm Ct are finished, the Kitchen is Active.
    $jobs = [
        ...poMatch_elmJobs(),
        poMatch_project(178, '949 S Elm Ct', 'Basement Bar', 'Palatine', ['Joe Doe'], [[6, '2022-05-11'], [7, '2022-09-01']]),
        poMatch_project(211, '949 S Elm Ct', 'Mud Room', 'Palatine', ['Joe Doe'], [[6, '2023-10-01'], [7, '2023-12-12']]),
    ];

    expect(poMatch_controller()->match('949', '2026-11-04', $jobs))->toMatchArray(['project_id' => 381, 'ambiguous' => false]);
});

it('counts a Scheduled job and a Service Call at that number as under way, and a two-digit number too', function () {
    $scheduled = poMatch_project(500, '17 N Marcella Rd', 'Basement', 'Arlington Heights', ['Ann Lee'], [[4, '2026-09-01'], [5, '2026-10-20']]);
    $serviceCall = poMatch_project(147, '949 S Elm Ct', 'Basement', 'Palatine', ['Joe Doe'], [[7, '2021-10-12'], [8, '2026-11-01']]);

    expect(poMatch_controller()->match('17', '2026-11-04', [$scheduled, ...poMatch_elmJobs()])['project_id'] ?? null)->toBe(500)
        ->and(poMatch_controller()->match('949', '2026-11-04', [$serviceCall])['project_id'] ?? null)->toBe(147);
});

it('leaves a house number with two jobs under way for a person', function () {
    $otherStreet = poMatch_project(460, '949 N Oak St', 'Bathroom', 'Mount Prospect', ['Sue Poe'], [[6, '2026-10-01']]);

    expect(poMatch_controller()->match('949', '2026-11-04', [...poMatch_elmJobs(), $otherStreet])['ambiguous'] ?? null)->toBeTrue();
});

it('finds a lone house number at a job that was active near the date, when none is under way', function () {
    $finishedLastMonth = poMatch_project(381, '949 S Elm Ct', 'Kitchen', 'Palatine', ['Joe Doe'], [[6, '2026-09-08'], [7, '2026-10-15']]);
    $soldNotStarted = poMatch_project(603, '949 S Elm Ct', 'Attic', 'Palatine', ['Joe Doe'], [[2, '2026-09-10'], [3, '2026-10-20']]);
    $controller = poMatch_controller();

    expect($controller->match('949', '2026-11-04', [$finishedLastMonth])['project_id'] ?? null)->toBe(381)
        ->and($controller->match('949', '2026-11-04', [$soldNotStarted])['project_id'] ?? null)->toBe(603);
});

it('matches a lone house number digit for digit, never by likeness, and never to a job long finished', function () {
    $finishedYearsAgo = [poMatch_project(147, '949 S Elm Ct', 'Basement', 'Palatine', ['Joe Doe'], [[6, '2021-06-01'], [7, '2021-10-12']])];
    $longerNumber = [poMatch_project(600, '2949 W Main St', 'Bath', 'Palatine', ['Al Poe'], [[6, '2026-10-01']])];
    $numberInTheName = [poMatch_project(601, '12 Oak St', 'Unit 949 Bath', 'Palatine', ['Al Poe'], [[6, '2026-10-01']])];
    $controller = poMatch_controller();

    expect($controller->match('949', '2026-11-04', $finishedYearsAgo))->toBeNull('the job at 949 was finished years ago')
        ->and($controller->match('949', '2026-11-04', $longerNumber))->toBeNull('2949 is another house')
        ->and($controller->match('949', '2026-11-04', $numberInTheName))->toBeNull('a number in a job\'s name is not its address')
        ->and($controller->match('948', '2026-11-04', poMatch_elmJobs()))->toBeNull()
        ->and($controller->match('9490', '2026-11-04', poMatch_elmJobs()))->toBeNull('a longer number is another address, or an order number')
        ->and($controller->match('1333827', '2026-11-04', poMatch_elmJobs()))->toBeNull();
});

it('takes three digits as enough for a house number, two only for a job under way, and still four letters for a word', function () {
    $finishedLastMonth = [poMatch_project(500, '17 N Marcella Rd', 'Basement', 'Arlington Heights', ['Ann Lee'], [[6, '2026-09-01'], [7, '2026-10-15']])];
    $clientNamedElm = [poMatch_project(147, '949 S Elm Ct', 'Basement', 'Palatine', ['Elm Smith'], [[7, '2026-09-01']])];
    $controller = poMatch_controller();

    expect($controller->match('17', '2026-11-04', $finishedLastMonth))->toBeNull('two digits never go on to scoring')
        ->and($controller->match('ELM', '2026-09-29', $clientNamedElm))->toBeNull('three letters match a street or a city under way, or nothing')
        ->and($controller->match('949', '2026-09-29', $clientNamedElm)['project_id'] ?? null)->toBe(147);
});

it('leaves a house number of four digits or more to the scoring it always had when no job at it is under way', function () {
    // 1801 Elm St was sold, not yet under way: no job-under-way match, and the older scoring still finds the address.
    expect(poMatch_controller()->match('1801', '2026-11-04', poMatch_elmJobs())['project_id'] ?? null)->toBe(451);
});

it('reads the street past a spelled-out direction', function () {
    expect(poMatch_controller()->match('harvey', '2026-10-01', [poMatch_brodsonNorthbrook(), poMatch_frombergOakPark()])['project_id'] ?? null)->toBe(399);
});

it('keeps a three-letter street PO, and still drops noise', function (string $po, ?string $kept) {
    $controller = new class extends ExpenseAutoMatchController
    {
        public function normalize(string $po): ?string
        {
            return $this->normalizePurchaseOrderCandidate($po);
        }
    };

    expect($controller->normalize($po))->toBe($kept);
})->with([
    ['ELM', 'ELM'],
    ['901', '901'],
    ['TBD', null],
    ['PP', null],
    ['N/A', null],
]);
