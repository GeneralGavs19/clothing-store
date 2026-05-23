<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
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

        /** @var array<int, array{product: array<string, mixed>, variants: array<int, array<string, mixed>>}> $catalog */
        $catalog = [
            [
                'product' => [
                    'name' => 'Смартфон Samsung Galaxy',
                    'category_id' => $electronics->id,
                    'purchase_price' => 15000,
                    'base_sale_price' => 25000,
                ],
                'variants' => [
                    [
                        'size' => '128GB',
                        'sku' => 'SAM-GAL-001',
                        'stock_warehouse' => 10,
                        'stock_store' => 5,
                        'low_stock_threshold' => 3,
                        'sale_price' => 25000,
                        'purchase_price' => 15000,
                    ],
                ],
            ],
            [
                'product' => [
                    'name' => 'Ноутбук HP Pavilion',
                    'category_id' => $electronics->id,
                    'purchase_price' => 40000,
                    'base_sale_price' => 65000,
                ],
                'variants' => [
                    [
                        'size' => '15"',
                        'sku' => 'HP-PAV-001',
                        'stock_warehouse' => 5,
                        'stock_store' => 3,
                        'low_stock_threshold' => 2,
                        'sale_price' => 65000,
                        'purchase_price' => 40000,
                    ],
                ],
            ],
            [
                'product' => [
                    'name' => 'Наушники Bluetooth',
                    'category_id' => $electronics->id,
                    'purchase_price' => 2000,
                    'base_sale_price' => 4500,
                ],
                'variants' => [
                    [
                        'size' => 'ONE',
                        'sku' => 'AUD-BT-001',
                        'stock_warehouse' => 20,
                        'stock_store' => 10,
                        'low_stock_threshold' => 5,
                        'sale_price' => 4500,
                        'purchase_price' => 2000,
                    ],
                ],
            ],
            [
                'product' => [
                    'name' => 'Футболка мужская',
                    'category_id' => $clothing->id,
                    'purchase_price' => 500,
                    'base_sale_price' => 1500,
                ],
                'variants' => [
                    [
                        'size' => 'M',
                        'sku' => 'CLO-TSH-M',
                        'stock_warehouse' => 10,
                        'stock_store' => 8,
                        'low_stock_threshold' => 5,
                        'sale_price' => 1500,
                        'purchase_price' => 500,
                    ],
                    [
                        'size' => 'L',
                        'sku' => 'CLO-TSH-L',
                        'stock_warehouse' => 12,
                        'stock_store' => 7,
                        'low_stock_threshold' => 5,
                        'sale_price' => 1500,
                        'purchase_price' => 500,
                    ],
                    [
                        'size' => 'XL',
                        'sku' => 'CLO-TSH-XL',
                        'stock_warehouse' => 8,
                        'stock_store' => 0,
                        'low_stock_threshold' => 5,
                        'sale_price' => 1500,
                        'purchase_price' => 500,
                    ],
                ],
            ],
            [
                'product' => [
                    'name' => 'Джинсы женские',
                    'category_id' => $clothing->id,
                    'purchase_price' => 1200,
                    'base_sale_price' => 3500,
                ],
                'variants' => [
                    [
                        'size' => '28',
                        'sku' => 'CLO-JEA-28',
                        'stock_warehouse' => 8,
                        'stock_store' => 4,
                        'low_stock_threshold' => 3,
                        'sale_price' => 3500,
                        'purchase_price' => 1200,
                    ],
                    [
                        'size' => '30',
                        'sku' => 'CLO-JEA-30',
                        'stock_warehouse' => 7,
                        'stock_store' => 4,
                        'low_stock_threshold' => 3,
                        'sale_price' => 3500,
                        'purchase_price' => 1200,
                    ],
                ],
            ],
            [
                'product' => [
                    'name' => 'Кофе растворимый',
                    'category_id' => $food->id,
                    'purchase_price' => 300,
                    'base_sale_price' => 800,
                ],
                'variants' => [
                    [
                        'size' => '200г',
                        'sku' => 'FOD-COF-200',
                        'stock_warehouse' => 50,
                        'stock_store' => 25,
                        'low_stock_threshold' => 10,
                        'sale_price' => 800,
                        'purchase_price' => 300,
                    ],
                ],
            ],
            [
                'product' => [
                    'name' => 'Чай зеленый',
                    'category_id' => $food->id,
                    'purchase_price' => 250,
                    'base_sale_price' => 600,
                ],
                'variants' => [
                    [
                        'size' => '100п',
                        'sku' => 'FOD-TEA-100',
                        'stock_warehouse' => 40,
                        'stock_store' => 20,
                        'low_stock_threshold' => 8,
                        'sale_price' => 600,
                        'purchase_price' => 250,
                    ],
                ],
            ],
            [
                'product' => [
                    'name' => 'Шоколад молочный',
                    'category_id' => $food->id,
                    'purchase_price' => 150,
                    'base_sale_price' => 400,
                ],
                'variants' => [
                    [
                        'size' => '90г',
                        'sku' => 'FOD-CHO-90',
                        'stock_warehouse' => 60,
                        'stock_store' => 30,
                        'low_stock_threshold' => 12,
                        'sale_price' => 400,
                        'purchase_price' => 150,
                    ],
                ],
            ],
        ];

        foreach ($catalog as $bucket) {
            $attrs = $bucket['product'];
            $product = Product::query()->firstOrCreate(
                ['name' => $attrs['name'], 'category_id' => $attrs['category_id']],
                array_merge($attrs, [
                    'description' => null,
                    'brand' => null,
                    'created_by' => null,
                ])
            );

            foreach ($bucket['variants'] as $index => $v) {
                ProductVariant::query()->firstOrCreate(
                    [
                        'product_id' => $product->id,
                        'sku' => $v['sku'],
                    ],
                    array_merge($v, [
                        'product_id' => $product->id,
                        'color' => $v['color'] ?? null,
                        'position' => $index,
                    ])
                );
            }

            $product->applyAggregatesFromVariants();
            $product->save();
        }
    }
}
