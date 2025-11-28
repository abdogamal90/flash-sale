<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{


    public function definition(): array
    {
      $totalStock = fake()->numberBetween(0, 1000);
        return [
            'name' => fake()->name(),
            'total_stock' => $totalStock,
            'available_stock' => fake()->numberBetween(0, $totalStock),
            'price' => fake()->randomFloat(2, 0, 1000),
        ];
    }
}
