<?php

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;

uses(RefreshDatabase::class);

it('names the homeowner when a task waits on their preferred times', function () {
    $html = Blade::render('<x-task-preferred-indicator :task="$task" indicator="pending" />', ['task' => new Task]);

    expect($html)->toContain('Awaiting Homeowner')
        ->toContain('Waiting for the homeowner to share preferred times')
        ->not->toContain('Awaiting Client');
});
