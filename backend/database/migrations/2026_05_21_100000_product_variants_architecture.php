<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_variants')) {
            Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('size', 64);
            $table->string('color', 64)->nullable();
            $table->string('sku', 120)->nullable()->unique();
            $table->string('barcode', 64)->nullable()->unique();
            $table->unsignedInteger('stock_warehouse')->default(0);
            $table->unsignedInteger('stock_store')->default(0);
            $table->unsignedInteger('low_stock_threshold')->default(0);
            $table->decimal('sale_price', 12, 2)->default(0);
            $table->decimal('purchase_price', 12, 2)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->index(['product_id', 'position']);
            });
        }

        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'brand')) {
                $table->string('brand')->nullable()->after('photo_path');
            }
            if (! Schema::hasColumn('products', 'base_sale_price')) {
                $table->decimal('base_sale_price', 12, 2)->nullable()->after('purchase_price');
            }
        });

        Schema::table('sale_items', function (Blueprint $table) {
            if (! Schema::hasColumn('sale_items', 'product_variant_id')) {
                $table->foreignId('product_variant_id')->nullable()->after('product_id')->constrained('product_variants')->nullOnDelete();
            }
        });

        if (DB::table('product_variants')->count() === 0) {
            $this->migrateVariantDataFromJson();
        }

        if (Schema::hasColumn('products', 'variants')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('variants');
            });
        }

        $this->migrateSaleItemsVariantFk();

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            foreach (['products_sku_unique', 'products_barcode_unique'] as $indexName) {
                try {
                    DB::statement("DROP INDEX IF EXISTS {$indexName}");
                } catch (\Throwable) {
                    //
                }
            }
        }

        Schema::table('products', function (Blueprint $table) {
            foreach (['sku', 'barcode', 'size'] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        foreach (DB::table('products')->orderBy('id')->cursor() as $productRow) {
            $this->resyncProductAggregates((int) $productRow->id);
        }
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            if (Schema::hasColumn('sale_items', 'product_variant_id')) {
                $table->dropConstrainedForeignId('product_variant_id');
            }
        });

        Schema::dropIfExists('product_variants');

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'brand')) {
                $table->dropColumn('brand');
            }
            if (Schema::hasColumn('products', 'base_sale_price')) {
                $table->dropColumn('base_sale_price');
            }
            if (! Schema::hasColumn('products', 'variants')) {
                $table->json('variants')->nullable();
            }
            if (! Schema::hasColumn('products', 'sku')) {
                $table->string('sku')->nullable()->unique();
            }
            if (! Schema::hasColumn('products', 'barcode')) {
                $table->string('barcode', 64)->nullable()->unique();
            }
            if (! Schema::hasColumn('products', 'size')) {
                $table->string('size', 32)->nullable();
            }
        });
    }

    private function migrateVariantDataFromJson(): void
    {
        $products = DB::table('products')->orderBy('id')->get();

        foreach ($products as $p) {
            $decoded = [];
            if (isset($p->variants) && $p->variants) {
                $decoded = json_decode((string) $p->variants, true);
                if (! is_array($decoded)) {
                    $decoded = [];
                }
            }

            if (! empty($decoded)) {
                $pos = 0;
                foreach ($decoded as $v) {
                    $size = trim((string) ($v['size'] ?? ''));
                    if ($size === '') {
                        $size = '—';
                    }
                    $wh = isset($v['stock_warehouse'])
                        ? (int) $v['stock_warehouse']
                        : (int) ($v['stock_quantity'] ?? $v['quantity'] ?? 0);
                    $store = isset($v['stock_store'])
                        ? (int) $v['stock_store']
                        : (int) ($v['display_quantity'] ?? 0);

                    DB::table('product_variants')->insert([
                        'product_id' => $p->id,
                        'size' => $size,
                        'color' => isset($v['color']) ? trim((string) $v['color']) : null,
                        'sku' => isset($v['sku']) ? trim((string) $v['sku']) ?: null : null,
                        'barcode' => isset($v['barcode']) ? trim((string) $v['barcode']) ?: null : null,
                        'stock_warehouse' => max(0, $wh),
                        'stock_store' => max(0, $store),
                        'low_stock_threshold' => max(0, (int) ($v['low_stock_threshold'] ?? $p->low_stock_threshold ?? 0)),
                        'sale_price' => (float) ($v['sale_price'] ?? $p->sale_price ?? 0),
                        'purchase_price' => isset($v['purchase_price']) ? (float) $v['purchase_price'] : (isset($v['cost_price']) ? (float) $v['cost_price'] : (float) $p->purchase_price),
                        'position' => $pos++,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                continue;
            }

            $size = isset($p->size) ? trim((string) $p->size) : '';
            DB::table('product_variants')->insert([
                'product_id' => $p->id,
                'size' => $size !== '' ? $size : '—',
                'color' => null,
                'sku' => isset($p->sku) ? trim((string) $p->sku) ?: null : null,
                'barcode' => isset($p->barcode) ? trim((string) $p->barcode) ?: null : null,
                'stock_warehouse' => max(0, (int) ($p->stock_quantity ?? 0)),
                'stock_store' => max(0, (int) ($p->display_quantity ?? 0)),
                'low_stock_threshold' => max(0, (int) ($p->low_stock_threshold ?? 0)),
                'sale_price' => (float) ($p->sale_price ?? 0),
                'purchase_price' => (float) ($p->purchase_price ?? 0),
                'position' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('product_variants')->orderBy('id')->chunk(200, function ($rows): void {
            foreach ($rows as $row) {
                if ($row->sku !== null && $row->sku !== '') {
                    continue;
                }
                DB::table('product_variants')->where('id', $row->id)->update(['sku' => 'PV-'.$row->id]);
            }
        });

        DB::table('product_variants')->orderBy('id')->chunk(200, function ($rows): void {
            foreach ($rows as $row) {
                if ($row->barcode !== null && $row->barcode !== '') {
                    continue;
                }
                DB::table('product_variants')->where('id', $row->id)->update([
                    'barcode' => '39'.str_pad((string) $row->id, 11, '0', STR_PAD_LEFT),
                ]);
            }
        });
    }

    private function migrateSaleItemsVariantFk(): void
    {
        foreach (DB::table('sale_items')->orderBy('id')->cursor() as $item) {
            if ($item->product_variant_id ?? null) {
                continue;
            }
            $size = isset($item->variant_size) ? trim((string) $item->variant_size) : '';
            $productId = (int) $item->product_id;
            if ($productId === 0) {
                continue;
            }
            $q = DB::table('product_variants')->where('product_id', $productId);
            if ($size !== '') {
                $variantId = $q->where('size', $size)->value('id');
            } else {
                $variantId = $q->orderBy('position')->orderBy('id')->value('id');
            }
            if ($variantId) {
                DB::table('sale_items')->where('id', $item->id)->update(['product_variant_id' => $variantId]);
            }
        }
    }

    private function resyncProductAggregates(int $productId): void
    {
        $sum = DB::table('product_variants')
            ->where('product_id', $productId)
            ->selectRaw('SUM(stock_warehouse) as wh, SUM(stock_store) as st, MIN(sale_price) as minp, MIN(low_stock_threshold) as minthresh')
            ->first();

        if (! $sum) {
            return;
        }

        $wh = (int) ($sum->wh ?? 0);
        $st = (int) ($sum->st ?? 0);
        $total = $wh + $st;
        $minPrice = $sum->minp !== null ? (float) $sum->minp : 0.0;

        $anyLowVariant = DB::table('product_variants')
            ->where('product_id', $productId)
            ->whereRaw('(stock_warehouse + stock_store) <= low_stock_threshold AND (stock_warehouse + stock_store) > 0')
            ->exists();

        $status = 'active';
        if ($total <= 0) {
            $status = 'out_of_stock';
        } elseif ($anyLowVariant) {
            $status = 'low_stock';
        }

        DB::table('products')->where('id', $productId)->update([
            'stock_quantity' => $wh,
            'display_quantity' => $st,
            'sale_price' => $minPrice,
            'low_stock_threshold' => (int) ($sum->minthresh ?? 0),
            'status' => $status,
        ]);
    }
};
