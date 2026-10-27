<?php

namespace Database\Factories;

use App\Models\VillageCodes;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VillageCodes>
 */
class VillageCodesFactory extends Factory
{
    protected $model = VillageCodes::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'town' => $this->faker->unique()->city(),
            'state' => 'IL',
            'status' => VillageCodes::STATUS_FOUND,
            'found_by' => VillageCodes::FOUND_BY_AI,
            'source_url' => 'https://www.example-village.org/202/Building-Codes',
            'codes' => [
                'International Residential Code, 2021 Edition',
                'International Building Code, 2021 Edition',
                'National Electrical Code, 2020 Edition',
            ],
            'checked_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => VillageCodes::STATUS_PENDING, 'codes' => null, 'source_url' => null, 'checked_at' => null]);
    }

    public function notFound(): static
    {
        return $this->state(fn () => ['status' => VillageCodes::STATUS_NOT_FOUND, 'codes' => null, 'source_url' => null, 'checked_at' => now()]);
    }
}
