<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        // Создаем категории
        $electronics = Category::firstOrCreate([
            'name' => 'Электроника',
        ], [
            'slug' => 'elektronika',
            'description' => 'Электронные устройства и аксессуары',
        ]);

        $clothing = Category::firstOrCreate([
            'name' => 'Одежда',
        ], [
            'slug' => 'odezhda',
            'description' => 'Одежда для мужчин и женщин',
        ]);

        $food = Category::firstOrCreate([
            'name' => 'Продукты питания',
        ], [
            'slug' => 'produkty-pitaniya',
            'description' => 'Продукты питания и напитки',
        ]);

        // Создаем товары
        $products = [
            [
                'name' => 'Смартфон Samsung Galaxy',
                'sku' => 'SAM-GAL-001',
                'category_id' => $electronics->id,
                'purchase_price' => 15000,
                'sale_price' => 25000,
                'stock_quantity' => 10,
                'display_quantity' => 5,
            ],
            [
                'name' => 'Ноутбук HP Pavilion',
                'sku' => 'HP-PAV-001',
                'category_id' => $electronics->id,
                'purchase_price' => 40000,
                'sale_price' => 65000,
                'stock_quantity' => 5,
                'display_quantity' => 3,
            ],
            [
                'name' => 'Наушники Bluetooth',
                'sku' => 'AUD-BT-001',
                'category_id' => $electronics->id,
                'purchase_price' => 2000,
                'sale_price' => 4500,
                'stock_quantity' => 20,
                'display_quantity' => 10,
            ],
            [
                'name' => 'Футболка мужская',
                'sku' => 'CLO-TSH-001',
                'category_id' => $clothing->id,
                'purchase_price' => 500,
                'sale_price' => 1500,
                'stock_quantity' => 30,
                'display_quantity' => 15,
            ],
            [
                'name' => 'Джинсы женские',
                'sku' => 'CLO-JEA-001',
                'category_id' => $clothing->id,
                'purchase_price' => 1200,
                'sale_price' => 3500,
                'stock_quantity' => 15,
                'display_quantity' => 8,
            ],
            [
                'name' => 'Кофе растворимый',
                'sku' => 'FOD-COF-001',
                'category_id' => $food->id,
                'purchase_price' => 300,
                'sale_price' => 800,
                'stock_quantity' => 50,
                'display_quantity' => 25,
            ],
            [
                'name' => 'Чай зеленый',
                'sku' => 'FOD-TEA-001',
                'category_id' => $food->id,
                'purchase_price' => 250,
                'sale_price' => 600,
                'stock_quantity' => 40,
                'display_quantity' => 20,
            ],
            [
                'name' => 'Шоколад молочный',
                'sku' => 'FOD-CHO-001',
                'category_id' => $food->id,
                'purchase_price' => 150,
                'sale_price' => 400,
                'stock_quantity' => 60,
                'display_quantity' => 30,
            ],
        ];

        foreach ($products as $product) {
            Product::firstOrCreate(['sku' => $product['sku']], $product);
        }
    }
}
