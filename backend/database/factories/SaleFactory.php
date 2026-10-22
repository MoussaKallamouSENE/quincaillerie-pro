<?php

namespace Database\Factories;

use App\Models\CashSession;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => null,
            'user_id' => User::factory(),
            'cash_session_id' => CashSession::factory(),
            'reference' => 'VTE-'.fake()->unique()->numerify('######'),
            'total_amount' => fake()->randomFloat(2, 1000, 500000),
            'payment_status' => 'paid',
        ];
    }

    /**
     * Indicate that the sale is not fully paid.
     */
    public function credit(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_status' => 'credit',
        ]);
    }
}
