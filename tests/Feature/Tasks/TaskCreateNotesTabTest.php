<?php

use App\Livewire\Tasks\TaskCreate;
use Livewire\Livewire;

it('does not error when toggling checklist before task exists', function (): void {
    Livewire::test(TaskCreate::class)
        ->withoutRendering()
        ->set('form.checklist', [
            ['text' => 'First', 'completed' => false],
        ])
        ->call('toggleChecklistItem', 0)
        ->assertSet('form.checklist.0.completed', true);
});
