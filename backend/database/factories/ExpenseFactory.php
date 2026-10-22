<?php

namespace Database\Factories;

use App\Models\CashSession;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cash_session_id' => CashSession::factory(),
            'user_id' => User::factory(),
            'name' => fake()->randomElement(['Transport', 'Fournitures', 'Eau', 'Électricité']),
            'amount' => fake()->randomFloat(2, 500, 50000),
            'date' => now()->toDateString(),
        ];
    }
}
