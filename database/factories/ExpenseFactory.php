<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExpenseFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'amount' => $this->faker->randomFloat($nbMaxDecimals = 2, $min = -100, $max = 999),
            'date' => $this->faker->dateTimeBetween('-3 years', 'now'),
            'project_id' => function () {
                return Project::inRandomOrder()->first()->id;
            },
            'vendor_id' => function () {
                return Vendor::withoutGlobalScopes()->whereBetween('id', [2, 49])->inRandomOrder()->first()->id;
            },
            'belongs_to_vendor_id' => 1,
            'created_by_user_id' => 1,
        ];
    }
}
