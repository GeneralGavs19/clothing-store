<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SaleItem;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Ensure category "Шапки" exists
        $hatCategory = Category::where('id', 29)->first()
            ?: Category::all()->first(fn($c) => mb_stripos($c->name, 'шапк') !== false);

        if (!$hatCategory) {
            $hatCategory = Category::create([
                'name' => 'Шапки',
                'slug' => 'shapki',
            ]);
        }

        // 2. Find canonical product "Шапка" (ID 149 or first product named "Шапка")
        $canonicalProduct = Product::where('id', 149)->first()
            ?: Product::all()->first(fn($p) => mb_strtolower(trim($p->name)) === 'шапка');

        if (!$canonicalProduct) {
            return;
        }

        $canonicalProduct->name = 'Шапка';
        $canonicalProduct->type = 'Шапка';
        $canonicalProduct->category_id = $hatCategory->id;
        $canonicalProduct->color = 'Синий';
        $canonicalProduct->sale_price = 5000;
        $canonicalProduct->base_sale_price = 5000;
        $canonicalProduct->save();

        // 3. Ensure canonical product has a variant
        $canonicalVariant = ProductVariant::where('product_id', $canonicalProduct->id)->first();
        if (!$canonicalVariant) {
            $canonicalVariant = ProductVariant::create([
                'product_id' => $canonicalProduct->id,
                'size' => '',
                'color' => 'Синий',
                'sku' => 'CAP-BLUE-01',
                'stock_store' => 0,
                'stock_warehouse' => 0,
                'position' => 0,
            ]);
        } else {
            $canonicalVariant->size = '';
            $canonicalVariant->color = 'Синий';
            $canonicalVariant->save();
        }

        // 4. Find all duplicate products named "Шапка"
        $duplicateProducts = Product::where('id', '!=', $canonicalProduct->id)
            ->get()
            ->filter(fn($p) => mb_strtolower(trim($p->name)) === 'шапка');

        $duplicateIds = $duplicateProducts->pluck('id')->toArray();

        if (!empty($duplicateIds)) {
            // Re-link sale_items to canonical product and variant
            SaleItem::whereIn('product_id', $duplicateIds)->update([
                'product_id' => $canonicalProduct->id,
                'product_variant_id' => $canonicalVariant->id,
                'product_name' => 'Шапка',
                'product_type' => 'Шапка',
            ]);

            // Re-link stock_movements
            DB::table('stock_movements')->whereIn('product_id', $duplicateIds)->update([
                'product_id' => $canonicalProduct->id,
            ]);

            // Delete duplicate variants and products
            ProductVariant::whereIn('product_id', $duplicateIds)->delete();
            Product::whereIn('id', $duplicateIds)->delete();
        }

        // Also normalize items for canonical product
        SaleItem::where('product_id', $canonicalProduct->id)->update([
            'product_name' => 'Шапка',
            'product_type' => 'Шапка',
            'product_variant_id' => $canonicalVariant->id,
        ]);

        // Normalize branded hats (Шапка мерч, Шапка KIS)
        Product::all()->filter(fn($p) => mb_stripos($p->name, 'шапк') !== false)->each(function ($p) use ($hatCategory) {
            $p->type = 'Шапка';
            $p->category_id = $hatCategory->id;
            $p->save();

            SaleItem::where('product_id', $p->id)->update([
                'product_type' => 'Шапка',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reverse needed for catalog deduplication
    }
};
