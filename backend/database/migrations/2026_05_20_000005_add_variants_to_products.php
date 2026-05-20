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
            $table->json('variants')->nullable()->after('size');
        });

        DB::table('products')->orderBy('id')->chunkById(200, function ($products) {
            foreach ($products as $product) {
                $size = trim((string) ($product->size ?? ''));
                if ($size === '') {
                    continue;
                }
                $variants = [[
                    'size' => $size,
                    'quantity' => (int) ($product->stock_quantity ?? 0),
                ]];
                DB::table('products')->where('id', $product->id)->update([
                    'variants' => json_encode($variants, JSON_UNESCAPED_UNICODE),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('variants');
        });
    }
};
