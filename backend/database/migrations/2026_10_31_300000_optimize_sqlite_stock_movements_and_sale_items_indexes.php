<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Очистка осиротевших тестовых записей движения остатков из stock_movements
        DB::table('stock_movements')
            ->whereNotIn('product_id', DB::table('products')->select('id'))
            ->orWhere(function ($q) {
                $q->whereNotNull('sale_id')->whereNotIn('sale_id', DB::table('sales')->select('id'));
            })
            ->delete();

        // 2. Добавление недостающих индексов в stock_movements (product_id, sale_id)
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->index('product_id', 'stock_movements_product_id_index');
            $table->index('sale_id', 'stock_movements_sale_id_index');
        });

        // 3. Удаление дублирующих одиночных индексов на sale_items,
        // которые уже покрываются составными индексами (sale_id, is_on_order) и (product_id, sale_id)
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropIndex('sale_items_sale_id_index');
            $table->dropIndex('sale_items_product_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex('stock_movements_product_id_index');
            $table->dropIndex('stock_movements_sale_id_index');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->index('sale_id', 'sale_items_sale_id_index');
            $table->index('product_id', 'sale_items_product_id_index');
        });
    }
};
