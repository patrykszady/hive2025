<?php

namespace Database\Factories;

use App\Models\Expense;
use App\Models\ExpensePayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpensePayment>
 */
class ExpensePaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'expense_id' => Expense::factory(),
            'method' => ExpensePayment::CARD,
            'amount' => fake()->randomFloat(2, 5, 500),
            'last_four' => (string) fake()->numberBetween(1000, 9999),
            'brand' => 'VISA',
            'source' => ExpensePayment::SOURCE_RECEIPT_TEXT,
        ];
    }

    public function offBank(string $method = ExpensePayment::STORE_CREDIT): static
    {
        return $this->state(fn () => ['method' => $method, 'last_four' => null, 'brand' => null]);
    }
}
