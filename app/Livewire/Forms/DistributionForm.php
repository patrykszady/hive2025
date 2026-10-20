<?php

namespace App\Livewire\Forms;

use App\Models\Distribution;
use App\Services\Distributions\DistributionFigures;
use Livewire\Attributes\Rule;
// use Livewire\Attributes\Validate;
use Livewire\Form;

class DistributionForm extends Form
{
    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\User>|array */
    public $users = [];

    /** Validated in rules(): must be one of DistributionFigures::eligibleUsers(), not just any integer. */
    public $user_id = null;

    #[Rule('required|min:3')]
    public $name = null;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', \Illuminate\Validation\Rule::in(collect($this->users)->pluck('id'))],
        ];
    }

    public function store(): Distribution
    {
        $this->validate();

        return app(DistributionFigures::class)->create(auth()->user()->vendor, (int) $this->user_id, $this->name);
    }
}
