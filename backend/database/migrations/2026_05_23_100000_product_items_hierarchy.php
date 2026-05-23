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
            if (! Schema::hasColumn('products', 'color')) {
                $table->string('color', 64)->nullable()->after('brand');
            }
        });

        Schema::create('product_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->string('code', 64)->unique();
            $table->enum('status', ['available', 'sold'])->default('available')->index();
            $table->enum('location', ['stock', 'display'])->default('display')->index();
            $table->timestamp('sold_at')->nullable();
            $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->timestamps();
            $table->index(['product_variant_id', 'status', 'location']);
        });

        Schema::table('sale_items', function (Blueprint $table) {
            if (! Schema::hasColumn('sale_items', 'product_item_id')) {
                $table->foreignId('product_item_id')->nullable()->after('product_variant_id')->constrained('product_items')->nullOnDelete();
            }
            if (! Schema::hasColumn('sale_items', 'product_item_code')) {
                $table->string('product_item_code', 64)->nullable()->after('product_item_id');
            }
        });

        $this->migrateExistingStockToItems();
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            if (Schema::hasColumn('sale_items', 'product_item_code')) {
                $table->dropColumn('product_item_code');
            }
            if (Schema::hasColumn('sale_items', 'product_item_id')) {
                $table->dropConstrainedForeignId('product_item_id');
            }
        });

        Schema::dropIfExists('product_items');

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'color')) {
                $table->dropColumn('color');
            }
        });
    }

    private function migrateExistingStockToItems(): void
    {
        if (! Schema::hasTable('product_variants')) {
            return;
        }

        foreach (DB::table('product_variants')->orderBy('id')->cursor() as $variant) {
            $product = DB::table('products')->where('id', $variant->product_id)->first();
            $productName = $product->name ?? 'ITEM';

            $wh = max(0, (int) $variant->stock_warehouse);
            $st = max(0, (int) $variant->stock_store);
            $seq = 1;

            for ($i = 0; $i < $wh; $i++) {
                $this->insertItem((int) $variant->id, $productName, (string) $variant->size, $seq++, 'stock');
            }
            for ($i = 0; $i < $st; $i++) {
                $this->insertItem((int) $variant->id, $productName, (string) $variant->size, $seq++, 'display');
            }
        }
    }

    private function insertItem(int $variantId, string $productName, string $size, int $seq, string $location): void
    {
        $code = $this->buildCode($variantId, $productName, $size, $seq);
        while (DB::table('product_items')->where('code', $code)->exists()) {
            $seq++;
            $code = $this->buildCode($variantId, $productName, $size, $seq);
        }

        DB::table('product_items')->insert([
            'product_variant_id' => $variantId,
            'code' => $code,
            'status' => 'available',
            'location' => $location,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function buildCode(int $variantId, string $productName, string $size, int $seq): string
    {
        $slug = strtoupper(preg_replace('/[^A-Z0-9]/', '', substr(md5($productName.$size), 0, 4)) ?: 'ITM');
        $sizePart = strtoupper(preg_replace('/\s+/u', '', $size !== '' && $size !== '—' ? $size : 'ONE'));

        return sprintf('%s-%d-%s-%03d', $slug, $variantId, $sizePart, $seq);
    }
};
