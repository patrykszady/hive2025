<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

trait HasToJsonMethod
{
    public function toJSON(): array
    {
        return [];
    }
}
