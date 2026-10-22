<?php

namespace Database\Factories;

use App\Models\CashSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashSession>
 */
class CashSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'opening_balance' => fake()->randomFloat(2, 0, 100000),
            'closing_balance' => null,
            'status' => 'open',
            'opened_at' => now(),
            'closed_at' => null,
        ];
    }

    /**
     * Indicate that the cash session is closed with a counted amount.
     */
    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'closed',
            'closing_balance' => $attributes['opening_balance'],
            'closed_at' => now(),
        ]);
    }
}
