<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $purchasePrice = fake()->randomFloat(2, 500, 50000);

        return [
            'sku' => 'SKU-'.fake()->unique()->numerify('#####'),
            'name' => fake()->words(3, true),
            'category_id' => Category::factory(),
            'unit_id' => Unit::factory(),
            'purchase_price' => $purchasePrice,
            'sale_price' => round($purchasePrice * 1.35, 2),
            'stock_quantity' => fake()->numberBetween(0, 200),
            'min_stock_alert' => fake()->numberBetween(1, 20),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the product is below its minimum stock alert threshold.
     */
    public function lowStock(): static
    {
        return $this->state(fn (array $attributes) => [
            'stock_quantity' => 1,
            'min_stock_alert' => 10,
        ]);
    }
}
