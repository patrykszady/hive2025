<?php

use App\Models\Agent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('normalizes duplicate address variants within a business', function () {
    Agent::query()->create([
        'business_name' => 'Handzel & Associates LTD',
        'name' => 'Aracely Pineda',
        'email' => 'apin@example.com',
        'address' => '1590 Wilkening Road Schaumburg, IL 60173',
    ]);

    Agent::query()->create([
        'business_name' => 'Handzel & Associates LTD',
        'name' => 'David Babovich',
        'email' => 'dbab@example.com',
        'address' => '1590 Wilkening Road SCHAUMBURG, IL 60173',
    ]);

    Agent::query()->create([
        'business_name' => 'Handzel & Associates LTD',
        'name' => 'Ellen Danilovich',
        'email' => 'edan@example.com',
        'address' => '5361 N Harlem Ave Chicago, IL 60656',
    ]);

    $this->artisan('agents:merge-duplicates')
        ->assertExitCode(0);

    $addresses = Agent::query()
        ->where('business_name', 'Handzel & Associates LTD')
        ->orderBy('email')
        ->pluck('address')
        ->all();

    expect($addresses)->toBe([
        '1590 Wilkening Road Schaumburg, IL 60173',
        '1590 Wilkening Road Schaumburg, IL 60173',
        '5361 N Harlem Ave Chicago, IL 60656',
    ]);
});

it('supports dry-run mode', function () {
    Agent::query()->create([
        'business_name' => 'Handzel & Associates LTD',
        'name' => 'Aracely Pineda',
        'email' => 'apin2@example.com',
        'address' => '1590 Wilkening Road Schaumburg, IL 60173',
    ]);

    Agent::query()->create([
        'business_name' => 'Handzel & Associates LTD',
        'name' => 'David Babovich',
        'email' => 'dbab2@example.com',
        'address' => '1590 Wilkening Road SCHAUMBURG, IL 60173',
    ]);

    $this->artisan('agents:merge-duplicates --dry-run')
        ->assertExitCode(0);

    expect(Agent::query()->where('email', 'dbab2@example.com')->value('address'))
        ->toBe('1590 Wilkening Road SCHAUMBURG, IL 60173');
});
