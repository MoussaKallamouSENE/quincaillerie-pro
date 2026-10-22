<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'phone' => fake()->numerify('+221 7# ## ## ##'),
            'email' => fake()->unique()->safeEmail(),
            'address' => fake()->address(),
            'credit_limit' => fake()->randomFloat(2, 0, 500000),
            'outstanding_balance' => 0,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the customer is archived.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
