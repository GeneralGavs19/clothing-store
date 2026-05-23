<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductItem;
use App\Models\ProductVariant;
use App\Models\Sale;
use Illuminate\Support\Str;

class ProductItemService
{
    public function syncVariantInventory(ProductVariant $variant, int $warehouseTarget, int $storeTarget): void
    {
        $warehouseTarget = max(0, $warehouseTarget);
        $storeTarget = max(0, $storeTarget);

        $variant->loadMissing('product');
        $product = $variant->product;

        $this->syncLocationCount($variant, $product, ProductItem::LOCATION_STOCK, $warehouseTarget);
        $this->syncLocationCount($variant, $product, ProductItem::LOCATION_DISPLAY, $storeTarget);

        $this->resyncVariantStockFromItems($variant);
    }

    public function resyncVariantStockFromItems(ProductVariant $variant): void
    {
        $variant->stock_warehouse = ProductItem::query()
            ->where('product_variant_id', $variant->id)
            ->available()
            ->where('location', ProductItem::LOCATION_STOCK)
            ->count();

        $variant->stock_store = ProductItem::query()
            ->where('product_variant_id', $variant->id)
            ->available()
            ->where('location', ProductItem::LOCATION_DISPLAY)
            ->count();

        $variant->save();
    }

    public function transferItems(ProductVariant $variant, int $quantity, string $from, string $to): void
    {
        $items = ProductItem::query()
            ->where('product_variant_id', $variant->id)
            ->available()
            ->where('location', $from)
            ->orderBy('id')
            ->limit($quantity)
            ->lockForUpdate()
            ->get();

        foreach ($items as $item) {
            $item->location = $to;
            $item->save();
        }

        $this->resyncVariantStockFromItems($variant);
    }

    public function markSold(ProductItem $item, Sale $sale, string $sourceLocation): void
    {
        $item->status = ProductItem::STATUS_SOLD;
        $item->sold_at = now();
        $item->sale_id = $sale->id;
        $item->save();

        $variant = ProductVariant::query()->lockForUpdate()->findOrFail($item->product_variant_id);
        $this->resyncVariantStockFromItems($variant);
    }

    public function restoreSold(ProductItem $item, string $location): void
    {
        $item->status = ProductItem::STATUS_AVAILABLE;
        $item->sold_at = null;
        $item->sale_id = null;
        $item->location = $location;
        $item->save();

        $variant = ProductVariant::query()->lockForUpdate()->findOrFail($item->product_variant_id);
        $this->resyncVariantStockFromItems($variant);
    }

    public function findAvailableByCode(string $code): ?ProductItem
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        return ProductItem::query()
            ->available()
            ->where('code', $code)
            ->with(['variant.product.category'])
            ->first();
    }

    private function syncLocationCount(ProductVariant $variant, Product $product, string $location, int $target): void
    {
        $current = ProductItem::query()
            ->where('product_variant_id', $variant->id)
            ->available()
            ->where('location', $location)
            ->count();

        if ($target > $current) {
            $toCreate = $target - $current;
            $seq = (int) ProductItem::query()->where('product_variant_id', $variant->id)->count() + 1;

            for ($i = 0; $i < $toCreate; $i++) {
                ProductItem::query()->create([
                    'product_variant_id' => $variant->id,
                    'code' => $this->allocateUniqueCode($product, $variant, $seq + $i),
                    'status' => ProductItem::STATUS_AVAILABLE,
                    'location' => $location,
                ]);
            }

            return;
        }

        if ($target < $current) {
            $toRemove = $current - $target;
            ProductItem::query()
                ->where('product_variant_id', $variant->id)
                ->available()
                ->where('location', $location)
                ->orderByDesc('id')
                ->limit($toRemove)
                ->delete();
        }
    }

    public function allocateUniqueCode(Product $product, ProductVariant $variant, int $seq): string
    {
        $attempts = 0;
        do {
            $code = $this->buildCode($product, $variant, $seq + $attempts);
            $attempts++;
        } while (ProductItem::query()->where('code', $code)->exists() && $attempts < 500);

        return $code;
    }

    private function buildCode(Product $product, ProductVariant $variant, int $seq): string
    {
        $namePart = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', Str::ascii($product->name)) ?: 'ITM', 0, 4));
        $sizePart = strtoupper(preg_replace('/\s+/u', '', $variant->size !== '' && $variant->size !== '—' ? $variant->size : 'ONE'));

        return sprintf('%s-%d-%s-%03d', $namePart, $variant->id, $sizePart, max(1, $seq));
    }
}
