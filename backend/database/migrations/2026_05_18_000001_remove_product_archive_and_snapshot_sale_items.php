<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->string('product_name')->nullable()->after('product_id');
            $table->string('product_sku', 80)->nullable()->after('product_name');
        });

        $items = DB::table('sale_items')->select('id', 'product_id')->get();

        foreach ($items as $item) {
            $product = DB::table('products')->where('id', $item->product_id)->first(['name', 'sku']);

            if ($product) {
                DB::table('sale_items')->where('id', $item->id)->update([
                    'product_name' => $product->name,
                    'product_sku' => $product->sku,
                ]);
            }
        }

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable()->change();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
        });

        DB::table('products')->where('status', 'archived')->update(['status' => 'out_of_stock']);

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE products MODIFY status ENUM('active', 'low_stock', 'out_of_stock') NOT NULL DEFAULT 'active'");
        }
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable(false)->change();
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn(['product_name', 'product_sku']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE products MODIFY status ENUM('active', 'low_stock', 'out_of_stock', 'archived') NOT NULL DEFAULT 'active'");
        }
    }
};
