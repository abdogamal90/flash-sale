<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Product;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;


class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // Product::factory()->create([
        //     'name' => 'Sample Product',
        //     'available_stock' => 100,
        //     'price' => 29.99,
        //     'total_stock' => 200,
        // ]);

        for($i = 1; $i <= 5; $i++) {
            Product::factory()->create([
                'name' => 'Product ' . $i,
                'available_stock' => rand(50, 150),
                'price' => rand(10, 100),
                'total_stock' => rand(100, 200),
            ]);
        }
    }
}
