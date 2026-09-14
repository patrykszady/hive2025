<?php

use App\Models\User;

it('title-cases a name typed flat and keeps one capitalised as written', function (string $typed, string $expected) {
    $method = new \ReflectionMethod(User::class, 'titleCaseName');
    $method->setAccessible(true);

    expect($method->invoke(null, $typed))->toBe($expected);
})->with([
    'lowercase' => ['smith', 'Smith'],
    'shouted' => ['JEAN-LUC PICARD', 'Jean-Luc Picard'],
    'apostrophe' => ["o'brien", "O'Brien"],
    'curly apostrophe' => ['o’brien', 'O’Brien'],
    'accents' => ['ÉMILIE ZOË', 'Émilie Zoë'],
    'two words, one flat' => ['John smith', 'John Smith'],
    'capital inside' => ['DiMarco', 'DiMarco'],
    'scottish' => ['McDonald', 'McDonald'],
    'dutch' => ['DeVries', 'DeVries'],
    'starts small, capital inside' => ['mIchael', 'Michael'],
    'empty' => ['', ''],
]);
