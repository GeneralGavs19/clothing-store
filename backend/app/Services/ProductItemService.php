<?php

namespace App\Services;

use App\Models\ProductItem;
use App\Models\ProductVariant;
use App\Models\Sale;
use Illuminate\Validation\ValidationException;

class ProductItemService
{
    public function syncVariantItems(ProductVariant $variant, array $codes): void
    {
        $codes = array_values(array_unique(array_filter(array_map(
            fn ($c) => trim((string) $c),
            $codes
        ))));

        foreach ($codes as $code) {
            $taken = ProductItem::query()
                ->where('code', $code)
                ->where('product_variant_id', '!=', $variant->id)
                ->exists();
            if ($taken) {
                throw ValidationException::withMessages([
                    'variants' => "Код «{$code}» уже используется.",
                ]);
            }
        }

        $byCode = ProductItem::query()
            ->where('product_variant_id', $variant->id)
            ->get()
            ->keyBy('code');

        $keptIds = [];

        foreach ($codes as $code) {
            $item = $byCode->get($code);
            if ($item) {
                if ($item->status === ProductItem::STATUS_SOLD) {
                    continue;
                }
                $item->location = ProductItem::LOCATION_DISPLAY;
                $item->save();
                $keptIds[] = $item->id;
                continue;
            }

            $item = ProductItem::query()->create([
                'product_variant_id' => $variant->id,
                'code' => $code,
                'status' => ProductItem::STATUS_AVAILABLE,
                'location' => ProductItem::LOCATION_DISPLAY,
            ]);
            $keptIds[] = $item->id;
        }

        ProductItem::query()
            ->where('product_variant_id', $variant->id)
            ->where('status', ProductItem::STATUS_AVAILABLE)
            ->when($keptIds !== [], fn ($q) => $q->whereNotIn('id', $keptIds))
            ->delete();

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
}
