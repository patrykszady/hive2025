<?php

namespace Database\Factories;

use App\Models\Expense;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExpenseSplitsFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'amount' => $this->faker->randomFloat($nbMaxDecimals = 2, $min = -100, $max = 999),
            'expense_id' => function () {
                return Expense::withoutGlobalScopes()->inRandomOrder()->first()->id;
            },
            'project_id' => function () {
                return Project::inRandomOrder()->first()->id;
            },
            'belongs_to_vendor_id' => 1,
            'created_by_user_id' => 1,
        ];
    }
}
