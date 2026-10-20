<?php

use App\Http\Controllers\ExpenseAutoMatchController;

/**
 * A PO naming an employee and a job (2026-10-20): Climate Solutions' invoice
 * 68405 carried "BATES GREG" — Greg ordering for the Bates job — and was
 * filed under the "Greg - Home" distribution instead of project 403, because
 * "greg" matched the distribution outright. Words the distribution's name
 * leaves unexplained are now matched against projects on their own.
 */
function jobOverEmployee_controller(): ExpenseAutoMatchController
{
    return new class extends ExpenseAutoMatchController
    {
        public function target(string $po, string $date, array $distributions, array $projects): ?array
        {
            return $this->matchPurchaseOrderTarget($po, $date, $distributions, $projects);
        }
    };
}

/** A project candidate the way runNoProjectExpenseAutoMatch builds one. */
function jobOverEmployee_project(int $id, string $address, string $name, string $city, array $clientNames, array $statuses): array
{
    $norm = fn (string $v) => trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9\s]/', ' ', strtolower($v))));

    return [
        'id' => $id,
        'created_at' => 0,
        'statuses' => array_map(fn (array $s) => ['code' => $s[0], 'start_date' => $s[1]], $statuses),
        'variants' => array_values(array_unique(array_merge([$norm($address), $norm($address.' '.$name), $norm($name)], array_map($norm, $clientNames)))),
        'address_variants' => [$norm($address), $norm($address.' '.$name)],
        'city' => $norm($city),
    ];
}

function jobOverEmployee_distributions(): array
{
    return [
        ['id' => 1, 'normalized' => 'office'],
        ['id' => 2, 'normalized' => 'greg home'],
        ['id' => 3, 'normalized' => 'patryk home'],
    ];
}

function jobOverEmployee_projects(): array
{
    return [
        jobOverEmployee_project(403, '1400 Kenilwood Lane', 'Home Remodel', 'Kenilworth', ['Bonnie Bates', 'Bonnie', 'Bates', 'Bradley Bates', 'Bradley'], [[4, '2026-06-23'], [6, '2026-08-25']]),
        jobOverEmployee_project(410, '22 Maple St', 'House Remodel', 'Evanston', ['Ann Lee', 'Ann', 'Lee'], [[6, '2026-09-01']]),
    ];
}

it('files a PO naming an employee and a job under the job', function () {
    $match = jobOverEmployee_controller()->target('BATES GREG', '2026-10-16', jobOverEmployee_distributions(), jobOverEmployee_projects());

    expect($match)->toMatchArray(['type' => 'project', 'id' => 403, 'ambiguous' => false]);
});

it('keeps a PO that only names the employee on their distribution', function () {
    $match = jobOverEmployee_controller()->target('GREG HOME', '2026-10-16', jobOverEmployee_distributions(), jobOverEmployee_projects());

    expect($match)->toMatchArray(['type' => 'distribution', 'id' => 2]);
});

it('does not hand the employee\'s PO to a project that merely shares a word with it', function () {
    $match = jobOverEmployee_controller()->target('GREG HOUSE', '2026-10-16', jobOverEmployee_distributions(), jobOverEmployee_projects());

    expect($match)->toMatchArray(['type' => 'distribution', 'id' => 2]);
});
