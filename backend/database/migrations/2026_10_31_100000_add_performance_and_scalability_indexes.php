<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Кросс-СУБД функция для безопасного создания индексов (MySQL, SQLite, PostgreSQL)
        $createIndex = function (string $table, string $indexName, array $columns) {
            try {
                if (!Schema::hasIndex($table, $indexName)) {
                    Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName) {
                        $blueprint->index($columns, $indexName);
                    });
                }
            } catch (\Throwable $e) {
                // Игнорируем, если индекс уже существует
            }
        };

        // 1. Индексы таблицы sales для быстрой сортировки, диапазонных выборок и фильтров
        $createIndex('sales', 'idx_sales_approved_at_id', ['approved_at', 'id']);
        $createIndex('sales', 'idx_sales_status_approved_at_id', ['status', 'approved_at', 'id']);
        $createIndex('sales', 'idx_sales_cashier_approved', ['cashier_id', 'status', 'approved_at']);
        $createIndex('sales', 'idx_sales_order_status_due', ['is_on_order', 'fulfillment_status', 'due_date']);

        // 2. Индексы таблицы sale_items для мгновенных JOIN с sales и аналитических агрегаций
        $createIndex('sale_items', 'idx_sale_items_composite', ['sale_id', 'is_on_order']);
        $createIndex('sale_items', 'idx_sale_items_product_lookup', ['product_id', 'sale_id']);
        $createIndex('sale_items', 'idx_sale_items_search_name', ['product_name', 'variant_size']);
        $createIndex('sale_items', 'idx_sale_items_type', ['product_type']);

        // 3. Индексы таблицы products для фильтрации в каталоге
        $createIndex('products', 'idx_products_cat_status', ['category_id', 'status']);
        $createIndex('products', 'idx_products_type_status', ['type', 'status']);

        // 4. Индексы таблицы product_variants
        $createIndex('product_variants', 'idx_variants_prod_size', ['product_id', 'size']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $dropIndex = function (string $table, string $indexName) {
            try {
                if (Schema::hasIndex($table, $indexName)) {
                    Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
                        $blueprint->dropIndex($indexName);
                    });
                }
            } catch (\Throwable $e) {
                // Игнорируем, если индекс не найден
            }
        };

        $dropIndex('sales', 'idx_sales_approved_at_id');
        $dropIndex('sales', 'idx_sales_status_approved_at_id');
        $dropIndex('sales', 'idx_sales_cashier_approved');
        $dropIndex('sales', 'idx_sales_order_status_due');

        $dropIndex('sale_items', 'idx_sale_items_composite');
        $dropIndex('sale_items', 'idx_sale_items_product_lookup');
        $dropIndex('sale_items', 'idx_sale_items_search_name');
        $dropIndex('sale_items', 'idx_sale_items_type');

        $dropIndex('products', 'idx_products_cat_status');
        $dropIndex('products', 'idx_products_type_status');

        $dropIndex('product_variants', 'idx_variants_prod_size');
    }
};
