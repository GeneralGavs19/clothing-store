<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'original_subtotal')) {
                $table->decimal('original_subtotal', 12, 2)->default(0)->after('subtotal');
            }
            if (! Schema::hasColumn('sales', 'discount_percent')) {
                $table->decimal('discount_percent', 5, 2)->default(0)->after('original_subtotal');
            }
            if (! Schema::hasColumn('sales', 'discount_amount')) {
                $table->decimal('discount_amount', 12, 2)->default(0)->after('discount_percent');
            }
        });

        Schema::table('sale_items', function (Blueprint $table) {
            if (! Schema::hasColumn('sale_items', 'original_price')) {
                $table->decimal('original_price', 12, 2)->default(0)->after('purchase_price');
            }
            if (! Schema::hasColumn('sale_items', 'discount_percent')) {
                $table->decimal('discount_percent', 5, 2)->default(0)->after('original_price');
            }
            if (! Schema::hasColumn('sale_items', 'discount_amount')) {
                $table->decimal('discount_amount', 12, 2)->default(0)->after('discount_percent');
            }
        });

        // Безопасная инициализация исторических данных
        try {
            DB::statement('UPDATE sales SET original_subtotal = subtotal WHERE original_subtotal = 0 OR original_subtotal IS NULL');
            DB::statement('UPDATE sale_items SET original_price = sale_price WHERE original_price = 0 OR original_price IS NULL');
        } catch (\Throwable) {
            // Игнорируем в случае чистой БД
        }
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            if (Schema::hasColumn('sale_items', 'discount_amount')) {
                $table->dropColumn('discount_amount');
            }
            if (Schema::hasColumn('sale_items', 'discount_percent')) {
                $table->dropColumn('discount_percent');
            }
            if (Schema::hasColumn('sale_items', 'original_price')) {
                $table->dropColumn('original_price');
            }
        });

        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'discount_amount')) {
                $table->dropColumn('discount_amount');
            }
            if (Schema::hasColumn('sales', 'discount_percent')) {
                $table->dropColumn('discount_percent');
            }
            if (Schema::hasColumn('sales', 'original_subtotal')) {
                $table->dropColumn('original_subtotal');
            }
        });
    }
};
