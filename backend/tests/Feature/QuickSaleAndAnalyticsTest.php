<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuickSaleAndAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function authenticateUser(string $role = 'admin'): array
    {
        $user = User::factory()->create([
            'role' => $role,
            'is_active' => true,
        ]);

        $token = app(\App\Services\JwtService::class)->issue($user);

        return [$user, ['Authorization' => 'Bearer '.$token]];
    }

    public function test_quick_sale_creates_product_and_sale_simultaneously(): void
    {
        [$user, $headers] = $this->authenticateUser('cashier');

        $response = $this->postJson('/api/sales/quick', [
            'name' => 'Футболка Oversize Vintage',
            'type' => 'Футболка',
            'category_name' => 'Одежда',
            'size' => 'L',
            'color' => 'Черный',
            'sale_price' => 7500,
            'purchase_price' => 3000,
            'quantity' => 2,
            'remaining_stock' => 5,
            'cashier_note' => 'Быстрая продажа клиенту',
        ], $headers);

        $response->assertStatus(201);
        $response->assertJsonPath('product.name', 'Футболка Oversize Vintage');
        $response->assertJsonPath('product.type', 'Футболка');
        $response->assertJsonPath('sale.items.0.quantity', 2);
        $response->assertJsonPath('sale.items.0.variant_size', 'L');
        $response->assertJsonPath('sale.subtotal', '15000.00');

        $this->assertDatabaseHas('products', [
            'name' => 'Футболка Oversize Vintage',
            'type' => 'Футболка',
        ]);

        $this->assertDatabaseHas('product_variants', [
            'size' => 'L',
            'stock_store' => 5,
        ]);

        $this->assertDatabaseHas('sales', [
            'status' => 'approved',
            'subtotal' => 15000,
        ]);
    }

    public function test_analytics_endpoint_returns_hierarchical_data(): void
    {
        [$user, $headers] = $this->authenticateUser('admin');

        // Create a quick sale
        $this->postJson('/api/sales/quick', [
            'name' => 'Худи Warm Fleece',
            'type' => 'Худи',
            'category_name' => 'Верхняя одежда',
            'size' => 'XL',
            'sale_price' => 12000,
            'purchase_price' => 6000,
            'quantity' => 3,
            'remaining_stock' => 2,
        ], $headers)->assertStatus(201);

        $response = $this->getJson('/api/reports/analytics', $headers);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'summary' => [
                'total_initial_items',
                'total_sold_items',
                'total_stock_items',
                'total_revenue',
                'total_profit',
                'sales_count',
                'average_check',
            ],
            'by_type',
            'by_category',
            'by_product',
        ]);

        $response->assertJsonPath('summary.total_sold_items', 3);
        $response->assertJsonPath('summary.total_stock_items', 2);
        $response->assertJsonPath('summary.total_initial_items', 5); // 3 + 2
        $response->assertJsonPath('summary.total_revenue', 36000);

        // Check by_type contains 'Худи'
        $types = collect($response->json('by_type'));
        $hoodie = $types->firstWhere('type', 'Худи');
        $this->assertNotNull($hoodie);
        $this->assertEquals(3, $hoodie['sold_quantity']);
        $this->assertEquals(2, $hoodie['stock_quantity']);
        $this->assertEquals(5, $hoodie['initial_quantity']);

        // Check by_product contains sizes
        $products = collect($response->json('by_product'));
        $prod = $products->firstWhere('name', 'Худи Warm Fleece');
        $this->assertNotNull($prod);
        $this->assertEquals(3, $prod['sold_quantity']);
        $this->assertNotEmpty($prod['sizes']);
        $sizeXl = collect($prod['sizes'])->firstWhere('size', 'XL');
        $this->assertNotNull($sizeXl);
        $this->assertEquals(3, $sizeXl['sold_quantity']);
        $this->assertEquals(2, $sizeXl['stock_quantity']);
        $this->assertEquals(5, $sizeXl['initial_quantity']);
    }

    public function test_selling_with_zero_stock_succeeds(): void
    {
        [$user, $headers] = $this->authenticateUser('cashier');

        $category = Category::create(['name' => 'Штаны', 'slug' => 'pants']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Штаны карго',
            'sale_price' => 8000,
            'stock_quantity' => 0,
            'display_quantity' => 0,
            'status' => 'active',
        ]);

        $variant = $product->variants()->create([
            'size' => 'M',
            'stock_warehouse' => 0,
            'stock_store' => 0,
            'sale_price' => 8000,
        ]);

        // Standard sale of variant with 0 stock
        $response = $this->postJson('/api/sales', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_variant_id' => $variant->id,
                    'quantity' => 4,
                ],
            ],
        ], $headers);

        $response->assertStatus(201);
        $response->assertJsonPath('status', 'approved');

        // Check that initial quantity is computed from sales
        $product->refresh();
        $this->assertEquals(4, $product->total_sold);
        $this->assertEquals(4, $product->initial_quantity); // 4 sold + 0 stock
    }

    public function test_order_type_is_on_order_for_quick_and_standard_sales(): void
    {
        [$user, $headers] = $this->authenticateUser('cashier');

        // 1. Quick sale marked as "on order" (is_on_order = true)
        $resp1 = $this->postJson('/api/sales/quick', [
            'name' => 'Куртка зимняя на заказ',
            'type' => 'Куртка',
            'size' => 'L',
            'sale_price' => 35000,
            'is_on_order' => true,
            'quantity' => 1,
            'remaining_stock' => 0,
            'cashier_note' => 'Предоплата 100%',
        ], $headers);

        $resp1->assertStatus(201);
        $resp1->assertJsonPath('sale.is_on_order', true);
        $resp1->assertJsonPath('sale.order_type_label', 'На заказ');
        $resp1->assertJsonPath('sale.items.0.is_on_order', true);

        // 2. Standard sale marked as in-stock (is_on_order = false)
        $product = Product::create([
            'name' => 'Кепка Basic',
            'sale_price' => 5000,
            'status' => 'active',
        ]);
        $variant = $product->variants()->create([
            'size' => 'One Size',
            'stock_store' => 10,
            'sale_price' => 5000,
        ]);

        $resp2 = $this->postJson('/api/sales', [
            'is_on_order' => false,
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_variant_id' => $variant->id,
                    'quantity' => 2,
                ],
            ],
        ], $headers);

        $resp2->assertStatus(201);
        $resp2->assertJsonPath('is_on_order', false);
        $resp2->assertJsonPath('order_type_label', 'В наличии');

        // 3. Test filtering in Sales list
        $listOnOrder = $this->getJson('/api/sales?is_on_order=1', $headers);
        $listOnOrder->assertStatus(200);
        $this->assertCount(1, $listOnOrder->json('data'));
        $this->assertTrue($listOnOrder->json('data.0.is_on_order'));

        $listInStock = $this->getJson('/api/sales?is_on_order=0', $headers);
        $listInStock->assertStatus(200);
        $this->assertCount(1, $listInStock->json('data'));
        $this->assertFalse($listInStock->json('data.0.is_on_order'));

        // 4. Test searching by product name
        $searchResp = $this->getJson('/api/sales?search=зимняя', $headers);
        $searchResp->assertStatus(200);
        $this->assertCount(1, $searchResp->json('data'));

        // 5. Test Excel download
        $excelResp = $this->get('/api/reports/sales.xlsx', $headers);
        $excelResp->assertStatus(200);
        $excelResp->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}
