<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('products')->orderBy('id')->chunkById(200, function ($products) {
            foreach ($products as $product) {
                $raw = $product->variants;
                if (!$raw) {
                    continue;
                }
                $variants = json_decode((string) $raw, true);
                if (!is_array($variants) || empty($variants)) {
                    continue;
                }

                $normalized = [];
                $remainingDisplay = max(0, (int) ($product->display_quantity ?? 0));
                foreach ($variants as $index => $variant) {
                    $size = trim((string) ($variant['size'] ?? ''));
                    if ($size === '') {
                        continue;
                    }
                    $stock = array_key_exists('stock_quantity', $variant)
                        ? (int) $variant['stock_quantity']
                        : (int) ($variant['quantity'] ?? 0);
                    $display = array_key_exists('display_quantity', $variant) ? (int) $variant['display_quantity'] : 0;
                    if ($display === 0 && $remainingDisplay > 0 && $index === 0) {
                        $display = $remainingDisplay;
                    }
                    $remainingDisplay = max(0, $remainingDisplay - $display);
                    $normalized[] = [
                        'size' => $size,
                        'stock_quantity' => max(0, $stock),
                        'display_quantity' => max(0, $display),
                    ];
                }

                if (!empty($normalized)) {
                    DB::table('products')->where('id', $product->id)->update([
                        'variants' => json_encode($normalized, JSON_UNESCAPED_UNICODE),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        DB::table('products')->orderBy('id')->chunkById(200, function ($products) {
            foreach ($products as $product) {
                $raw = $product->variants;
                if (!$raw) {
                    continue;
                }
                $variants = json_decode((string) $raw, true);
                if (!is_array($variants) || empty($variants)) {
                    continue;
                }
                $legacy = [];
                foreach ($variants as $variant) {
                    $size = trim((string) ($variant['size'] ?? ''));
                    if ($size === '') {
                        continue;
                    }
                    $legacy[] = [
                        'size' => $size,
                        'quantity' => (int) ($variant['stock_quantity'] ?? 0),
                    ];
                }
                DB::table('products')->where('id', $product->id)->update([
                    'variants' => json_encode($legacy, JSON_UNESCAPED_UNICODE),
                ]);
            }
        });
    }
};
