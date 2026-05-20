<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('barcode', 64)->nullable()->after('sku');
        });

        DB::table('products')->orderBy('id')->chunkById(200, function ($products) {
            foreach ($products as $product) {
                if (!empty($product->barcode)) {
                    continue;
                }
                $barcode = '29'.str_pad((string) $product->id, 11, '0', STR_PAD_LEFT);
                DB::table('products')->where('id', $product->id)->update(['barcode' => $barcode]);
            }
        });

        Schema::table('products', function (Blueprint $table) {
            $table->unique('barcode');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['barcode']);
            $table->dropColumn('barcode');
        });
    }
};
