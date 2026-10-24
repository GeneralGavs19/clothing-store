<?php

namespace App\Services;

use App\Models\Category;
use App\Models\DailyStat;
use App\Models\Product;
use App\Models\ProductItem;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalesService
{
    public function __construct(private ProductItemService $itemService) {}

    public function createCompleted(User $cashier, array $items, ?string $note = null): Sale
    {
        return DB::transaction(function () use ($cashier, $items, $note) {
            $itemIds = collect($items)->pluck('product_item_id')->filter()->unique()->values();
            $productItems = ProductItem::query()->whereIn('id', $itemIds)->lockForUpdate()->get()->keyBy('id');

            $variantIds = collect($items)->pluck('product_variant_id')->filter()->unique()->values();
            foreach ($productItems as $pi) {
                $variantIds->push($pi->product_variant_id);
            }
            $variants = ProductVariant::query()->whereIn('id', $variantIds->unique())->lockForUpdate()->get()->keyBy('id');
            $productIds = $variants->pluck('product_id')->unique()->values();
            $products = Product::query()->whereIn('id', $productIds)->lockForUpdate()->get()->keyBy('id');

            $this->assertAvailability($items, $productItems, $variants, $products);

            $soldAt = now();

            $sale = Sale::create([
                'number' => 'draft-'.Str::uuid()->toString(),
                'cashier_id' => $cashier->id,
                'status' => 'approved',
                'approved_by' => $cashier->id,
                'approved_at' => $soldAt,
                'submitted_at' => $soldAt,
                'cashier_note' => $note,
            ]);

            $subtotal = 0;
            $profit = 0;
            $totalQty = 0;

            foreach ($items as $item) {
                $productItem = isset($item['product_item_id'])
                    ? $productItems->get((int) $item['product_item_id'])
                    : null;

                if ($productItem) {
                    $this->processItemSale($sale, $cashier, $productItem, $item, $variants, $products, $subtotal, $profit, $totalQty);
                    continue;
                }

                $variant = $variants->get((int) $item['product_variant_id']);
                $product = $products->get((int) $variant->product_id);
                $quantity = (int) $item['quantity'];
                $source = ($item['source'] ?? 'display') === 'stock' ? 'stock' : 'display';

                $purchase = (float) ($product->purchase_price ?? 0);
                $salePrice = (float) $product->sale_price;
                $lineTotal = round($salePrice * $quantity, 2);
                $lineProfit = round(($salePrice - $purchase) * $quantity, 2);

                $variantLabel = $variant->size;

                $sale->items()->create([
                    'product_id' => $product->id,
                    'product_variant_id' => $variant->id,
                    'product_name' => $product->name,
                    'product_type' => $product->type,
                    'product_sku' => $variant->sku,
                    'quantity' => $quantity,
                    'source_location' => $source,
                    'variant_size' => $variantLabel,
                    'purchase_price' => $purchase,
                    'sale_price' => $salePrice,
                    'line_total' => $lineTotal,
                    'line_profit' => $lineProfit,
                ]);

                $this->sellVariantQuantity($variant, $quantity, $source);
                $variant->save();

                $product->applyAggregatesFromVariants();
                $product->save();

                $this->recordStockMovement($sale, $cashier, $product, -1 * $quantity);

                $subtotal += $lineTotal;
                $profit += $lineProfit;
                $totalQty += $quantity;
            }

            $sale->update([
                'number' => $this->humanNumber($sale->id, $soldAt, $totalQty, $subtotal),
                'subtotal' => $subtotal,
                'profit' => $profit,
            ]);

            $this->recordDailyStats($sale->fresh(['items']), $soldAt);

            return $sale->load(['items.product.category', 'cashier', 'approver']);
        });
    }

    private function processItemSale(
        Sale $sale,
        User $cashier,
        ProductItem $productItem,
        array $item,
        $variantsById,
        $productsById,
        float &$subtotal,
        float &$profit,
        int &$totalQty,
    ): void {
        $variant = $variantsById->get((int) $productItem->product_variant_id);
        $product = $productsById->get((int) $variant->product_id);
        $source = $productItem->location === ProductItem::LOCATION_STOCK ? 'stock' : 'display';

        $purchase = (float) ($product->purchase_price ?? 0);
        $salePrice = (float) $product->sale_price;
        $lineTotal = round($salePrice, 2);
        $lineProfit = round($salePrice - $purchase, 2);

        $sale->items()->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'product_item_id' => $productItem->id,
            'product_item_code' => $productItem->code,
            'product_name' => $product->name,
            'product_type' => $product->type,
            'product_sku' => $variant->sku,
            'quantity' => 1,
            'source_location' => $source,
            'variant_size' => $variant->size,
            'purchase_price' => $purchase,
            'sale_price' => $salePrice,
            'line_total' => $lineTotal,
            'line_profit' => $lineProfit,
        ]);

        $this->itemService->markSold($productItem, $sale, $source);
        $variant->refresh();
        $product->applyAggregatesFromVariants();
        $product->save();

        $this->recordStockMovement($sale, $cashier, $product, -1);

        $subtotal += $lineTotal;
        $profit += $lineProfit;
        $totalQty += 1;
    }

    public function createPending(User $cashier, array $items, ?string $note = null): Sale
    {
        return $this->createCompleted($cashier, $items, $note);
    }

    public function approve(Sale $sale, User $admin, ?string $note = null): Sale
    {
        return DB::transaction(function () use ($sale, $admin, $note) {
            $sale = Sale::query()->with('items')->lockForUpdate()->findOrFail($sale->id);

            if ($sale->status !== 'pending') {
                throw ValidationException::withMessages(['sale' => 'Продажа уже обработана.']);
            }

            $itemIds = $sale->items->pluck('product_item_id')->filter()->unique();
            $productItems = ProductItem::query()->whereIn('id', $itemIds)->lockForUpdate()->get()->keyBy('id');

            $variantIds = $sale->items->pluck('product_variant_id')->filter()->unique();
            foreach ($productItems as $pi) {
                $variantIds->push($pi->product_variant_id);
            }
            $variants = ProductVariant::query()->whereIn('id', $variantIds->unique())->lockForUpdate()->get()->keyBy('id');
            $products = Product::query()
                ->whereIn('id', $variants->pluck('product_id')->unique())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($sale->items as $item) {
                if ($item->product_item_id) {
                    $pi = $productItems->get((int) $item->product_item_id);
                    if (! $pi || ! $pi->isAvailable()) {
                        throw ValidationException::withMessages([
                            'stock' => "Код «{$item->product_item_code}» недоступен для продажи.",
                        ]);
                    }
                    continue;
                }

                $variantId = (int) $item->product_variant_id;
                $variant = $variants->get($variantId);
                $product = $variant ? $products->get($variant->product_id) : null;
                $source = $item->source_location ?? 'display';

                if (! $variant || ! $product) {
                    throw ValidationException::withMessages([
                        'stock' => 'Недостаточно данных по варианту для одобрения.',
                    ]);
                }
            }

            foreach ($sale->items as $item) {
                if ($item->product_item_id) {
                    $pi = $productItems->get((int) $item->product_item_id);
                    $variant = $variants->get((int) $pi->product_variant_id);
                    $product = $products->get((int) $variant->product_id);
                    $this->itemService->markSold($pi, $sale, $item->source_location ?? 'display');
                    $variant->refresh();
                    $product->applyAggregatesFromVariants();
                    $product->save();
                    $this->recordStockMovement($sale, $admin, $product, -1);
                    continue;
                }

                $variant = $variants->get((int) $item->product_variant_id);
                $product = $products->get((int) $variant->product_id);
                $source = $item->source_location ?? 'display';

                $this->sellVariantQuantity($variant, (int) $item->quantity, $source);
                $variant->save();

                $product->applyAggregatesFromVariants();
                $product->save();

                $this->recordStockMovement($sale, $admin, $product, -1 * (int) $item->quantity);
            }

            $approvedAt = now();
            $totalQty = (int) $sale->items->sum('quantity');

            $sale->update([
                'status' => 'approved',
                'approved_by' => $admin->id,
                'approved_at' => $approvedAt,
                'admin_note' => $note,
                'number' => $this->humanNumber($sale->id, $approvedAt, $totalQty, (float) $sale->subtotal),
            ]);

            $sale->pendingSale?->update([
                'status' => 'approved',
                'reviewed_by' => $admin->id,
                'reviewed_at' => $approvedAt,
            ]);

            $this->recordDailyStats($sale->fresh(['items']), $approvedAt);

            return $sale->load(['items.product.category', 'cashier', 'approver']);
        });
    }

    public function deleteSale(Sale $sale, User $admin): void
    {
        DB::transaction(function () use ($sale, $admin) {
            $sale = Sale::query()->with('items')->lockForUpdate()->findOrFail($sale->id);

            if ($sale->status === 'approved') {
                $itemIds = $sale->items->pluck('product_item_id')->filter()->unique()->values();
                $productItems = ProductItem::query()->whereIn('id', $itemIds)->lockForUpdate()->get()->keyBy('id');

                $variantIds = $sale->items->pluck('product_variant_id')->filter()->unique()->values();
                if ($variantIds->isNotEmpty() || $productItems->isNotEmpty()) {
                    $variants = ProductVariant::query()
                        ->whereIn('id', $variantIds->merge($productItems->pluck('product_variant_id'))->unique())
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');
                    $products = Product::query()
                        ->whereIn('id', $variants->pluck('product_id')->unique())
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');

                    foreach ($sale->items as $item) {
                        if ($item->product_item_id) {
                            $pi = $productItems->get((int) $item->product_item_id);
                            if ($pi) {
                                $variant = $variants->get((int) $pi->product_variant_id);
                                $product = $products->get((int) $variant->product_id);
                                $location = ($item->source_location ?? 'display') === 'stock'
                                    ? ProductItem::LOCATION_STOCK
                                    : ProductItem::LOCATION_DISPLAY;
                                $this->itemService->restoreSold($pi, $location);
                                $variant->refresh();
                                $product->applyAggregatesFromVariants();
                                $product->save();
                                $this->recordStockMovement(null, $admin, $product, 1, 'Отмена продажи №'.$sale->id);
                            }
                            continue;
                        }

                        $variant = $variants->get((int) $item->product_variant_id);
                        if (! $variant) {
                            continue;
                        }
                        $product = $products->get((int) $variant->product_id);
                        if (! $product) {
                            continue;
                        }

                        $source = $item->source_location ?? 'display';
                        $this->restoreVariantSale($variant, (int) $item->quantity, $source);
                        $variant->save();

                        $product->applyAggregatesFromVariants();
                        $product->save();

                        $this->recordStockMovement(null, $admin, $product, (int) $item->quantity, 'Отмена продажи №'.$sale->id);
                    }
                }

                $this->reverseDailyStats($sale);
            }

            StockMovement::query()->where('sale_id', $sale->id)->delete();
            $sale->delete();
        });
    }

    public function reject(Sale $sale, User $admin, ?string $note = null): Sale
    {
        return DB::transaction(function () use ($sale, $admin, $note) {
            $sale = Sale::query()->lockForUpdate()->findOrFail($sale->id);

            if ($sale->status !== 'pending') {
                throw ValidationException::withMessages(['sale' => 'Продажа уже обработана.']);
            }

            $sale->update([
                'status' => 'rejected',
                'approved_by' => $admin->id,
                'rejected_at' => now(),
                'admin_note' => $note,
            ]);

            $sale->pendingSale?->update([
                'status' => 'rejected',
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

            return $sale->load(['items.product.category', 'cashier', 'approver']);
        });
    }

    private function humanNumber(int $id, $soldAt, int $totalQty, float $subtotal): string
    {
        $date = $soldAt->format('d.m.Y H:i');

        return sprintf('№%d · %s · %d шт. · %s ₸', $id, $date, $totalQty, number_format($subtotal, 0, '.', ' '));
    }

    private function recordStockMovement(?Sale $sale, User $user, Product $product, int $quantity, ?string $note = null): void
    {
        StockMovement::create([
            'product_id' => $product->id,
            'sale_id' => $sale?->id,
            'user_id' => $user->id,
            'type' => 'sale',
            'from_location' => $quantity < 0 ? 'display' : 'external',
            'to_location' => $quantity < 0 ? 'external' : 'display',
            'quantity' => $quantity,
            'stock_after' => $product->stock_quantity,
            'display_after' => $product->display_quantity,
            'note' => $note ?? ($sale ? 'Продажа №'.$sale->id : null),
        ]);
    }

    private function recordDailyStats(Sale $sale, $soldAt): void
    {
        try {
            $date = $soldAt->toDateString();
            $itemsSold = (int) $sale->items->sum('quantity');

            if (DailyStat::where('date', $date)->exists()) {
                DailyStat::where('date', $date)->increment('total_sales', 1);
                DailyStat::where('date', $date)->increment('revenue', $sale->subtotal);
                DailyStat::where('date', $date)->increment('profit', $sale->profit);
                DailyStat::where('date', $date)->increment('items_sold', $itemsSold);
            } else {
                DailyStat::create([
                    'date' => $date,
                    'total_sales' => 1,
                    'revenue' => $sale->subtotal,
                    'profit' => $sale->profit,
                    'items_sold' => $itemsSold,
                ]);
            }

            Cache::forget('daily_stats_last_14');
        } catch (\Throwable) {
            //
        }
    }

    private function reverseDailyStats(Sale $sale): void
    {
        try {
            $date = ($sale->approved_at ?? $sale->created_at)->toDateString();
            $itemsSold = (int) $sale->items->sum('quantity');
            $stat = DailyStat::query()->where('date', $date)->first();

            if (! $stat) {
                return;
            }

            $stat->total_sales = max(0, (int) $stat->total_sales - 1);
            $stat->revenue = max(0, (float) $stat->revenue - (float) $sale->subtotal);
            $stat->profit = max(0, (float) $stat->profit - (float) $sale->profit);
            $stat->items_sold = max(0, (int) $stat->items_sold - $itemsSold);

            if ($stat->total_sales === 0 && (float) $stat->revenue <= 0 && (int) $stat->items_sold === 0) {
                $stat->delete();
            } else {
                $stat->save();
            }

            Cache::forget('daily_stats_last_14');
        } catch (\Throwable) {
            //
        }
    }

    private function assertAvailability(array $items, $productItemsById, $variantsById, $productsById): void
    {
        foreach ($items as $row) {
            $productItemId = (int) ($row['product_item_id'] ?? 0);
            if ($productItemId) {
                $productItem = $productItemsById->get($productItemId);
                if (! $productItem || ! $productItem->isAvailable()) {
                    throw ValidationException::withMessages(['items' => 'Единица товара недоступна для продажи.']);
                }
                $variant = $variantsById->get((int) $productItem->product_variant_id);
                $product = $productsById->get((int) $variant?->product_id);
                if (! $variant || ! $product) {
                    throw ValidationException::withMessages(['items' => 'Товар недоступен для продажи.']);
                }

                continue;
            }

            $vid = (int) ($row['product_variant_id'] ?? 0);
            $pid = (int) ($row['product_id'] ?? 0);
            $quantity = (int) ($row['quantity'] ?? 0);
            $source = ($row['source'] ?? 'display') === 'stock' ? 'stock' : 'display';

            $variant = $variantsById->get($vid);
            if (! $variant) {
                throw ValidationException::withMessages(['items' => 'Вариант недоступен.']);
            }

            if ($pid && (int) $variant->product_id !== $pid) {
                throw ValidationException::withMessages(['items' => 'Вариант не принадлежит выбранному товару.']);
            }

            $product = $productsById->get((int) $variant->product_id);
            if (! $product) {
                throw ValidationException::withMessages(['items' => 'Товар недоступен для продажи.']);
            }
        }
    }

    private function sellVariantQuantity(ProductVariant $variant, int $quantity, string $source): void
    {
        $location = $source === 'stock' ? ProductItem::LOCATION_STOCK : ProductItem::LOCATION_DISPLAY;

        $items = ProductItem::query()
            ->where('product_variant_id', $variant->id)
            ->available()
            ->where('location', $location)
            ->orderBy('id')
            ->limit($quantity)
            ->lockForUpdate()
            ->get();

        if ($items->count() < $quantity) {
            if ($source === 'stock') {
                $variant->stock_warehouse = max(0, (int) $variant->stock_warehouse - $quantity);
            } else {
                $variant->stock_store = max(0, (int) $variant->stock_store - $quantity);
            }

            return;
        }

        foreach ($items as $item) {
            $item->status = ProductItem::STATUS_SOLD;
            $item->sold_at = now();
            $item->save();
        }

        $this->itemService->resyncVariantStockFromItems($variant);
    }

    private function restoreVariantSale(ProductVariant $variant, int $quantity, string $source): void
    {
        $location = $source === 'stock' ? ProductItem::LOCATION_STOCK : ProductItem::LOCATION_DISPLAY;

        $soldItems = ProductItem::query()
            ->where('product_variant_id', $variant->id)
            ->where('status', ProductItem::STATUS_SOLD)
            ->orderByDesc('sold_at')
            ->limit($quantity)
            ->lockForUpdate()
            ->get();

        if ($soldItems->count() >= $quantity) {
            foreach ($soldItems as $item) {
                $this->itemService->restoreSold($item, $location);
            }

            return;
        }

        if ($source === 'stock') {
            $variant->stock_warehouse += $quantity;
        } else {
            $variant->stock_store += $quantity;
        }
    }

    public function createQuickSale(User $cashier, array $data): array
    {
        return DB::transaction(function () use ($cashier, $data) {
            $name = trim(strip_tags((string) ($data['name'] ?? '')));
            $type = isset($data['type']) && trim((string) $data['type']) !== ''
                ? trim(strip_tags((string) $data['type']))
                : null;
            $size = trim(strip_tags((string) ($data['size'] ?? '')));
            if ($size === '') {
                $size = 'Универсальный';
            }
            $color = isset($data['color']) && trim((string) $data['color']) !== ''
                ? trim(strip_tags((string) $data['color']))
                : null;

            $categoryId = ! empty($data['category_id']) ? (int) $data['category_id'] : null;
            if (! $categoryId && ! empty($data['category_name'])) {
                $catName = trim(strip_tags((string) $data['category_name']));
                $category = Category::firstOrCreate(
                    ['name' => $catName],
                    ['slug' => Str::slug($catName) ?: 'cat-'.Str::random(6), 'created_by' => $cashier->id]
                );
                $categoryId = $category->id;
            }

            $salePrice = max(0, (float) ($data['sale_price'] ?? 0));
            $purchasePrice = max(0, (float) ($data['purchase_price'] ?? 0));
            $quantity = max(1, (int) ($data['quantity'] ?? 1));
            $remainingStock = max(0, (int) ($data['remaining_stock'] ?? 0));

            // Check if product exists
            $productQuery = Product::query()->where('name', $name);
            if ($categoryId) {
                $productQuery->where('category_id', $categoryId);
            }
            if ($type) {
                $productQuery->where('type', $type);
            }
            $product = $productQuery->lockForUpdate()->first();

            if (! $product) {
                $product = Product::create([
                    'category_id' => $categoryId,
                    'type' => $type,
                    'name' => $name,
                    'color' => $color,
                    'sale_price' => $salePrice,
                    'base_sale_price' => $salePrice,
                    'purchase_price' => $purchasePrice,
                    'stock_quantity' => 0,
                    'display_quantity' => $remainingStock,
                    'status' => 'active',
                    'created_by' => $cashier->id,
                ]);
            } else {
                if ($type && empty($product->type)) {
                    $product->type = $type;
                }
                if ($color && empty($product->color)) {
                    $product->color = $color;
                }
                if ($salePrice > 0 && (float) $product->sale_price <= 0) {
                    $product->sale_price = $salePrice;
                }
                $product->save();
            }

            // Check if variant exists
            $variant = ProductVariant::query()
                ->where('product_id', $product->id)
                ->where('size', $size)
                ->lockForUpdate()
                ->first();

            if (! $variant) {
                $category = $product->category_id ? Category::find($product->category_id) : null;
                $sku = $this->generateSku($category?->name, $product->name, $size);

                $variant = ProductVariant::create([
                    'product_id' => $product->id,
                    'size' => $size,
                    'color' => $product->color,
                    'sku' => $sku,
                    'stock_warehouse' => 0,
                    'stock_store' => $remainingStock,
                    'low_stock_threshold' => 0,
                    'sale_price' => $salePrice > 0 ? $salePrice : (float) $product->sale_price,
                    'purchase_price' => $purchasePrice > 0 ? $purchasePrice : (float) $product->purchase_price,
                    'position' => (int) $product->variants()->count(),
                ]);
            } else {
                if ($remainingStock > 0) {
                    $variant->stock_store = $remainingStock;
                    $variant->save();
                }
            }

            $product->applyAggregatesFromVariants();
            $product->save();

            // Record sale
            $soldAt = now();
            $finalSalePrice = $salePrice > 0 ? $salePrice : (float) $variant->sale_price;
            $finalPurchasePrice = $purchasePrice > 0 ? $purchasePrice : (float) $variant->purchase_price;
            $lineTotal = round($finalSalePrice * $quantity, 2);
            $lineProfit = round(($finalSalePrice - $finalPurchasePrice) * $quantity, 2);

            $sale = Sale::create([
                'number' => 'draft-'.Str::uuid()->toString(),
                'cashier_id' => $cashier->id,
                'status' => 'approved',
                'approved_by' => $cashier->id,
                'approved_at' => $soldAt,
                'submitted_at' => $soldAt,
                'subtotal' => $lineTotal,
                'profit' => $lineProfit,
                'cashier_note' => isset($data['cashier_note']) ? strip_tags($data['cashier_note']) : null,
            ]);

            $sale->items()->create([
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'product_name' => $product->name,
                'product_type' => $product->type,
                'product_sku' => $variant->sku,
                'quantity' => $quantity,
                'source_location' => 'display',
                'variant_size' => $variant->size,
                'purchase_price' => $finalPurchasePrice,
                'sale_price' => $finalSalePrice,
                'line_total' => $lineTotal,
                'line_profit' => $lineProfit,
            ]);

            $variant->stock_store = $remainingStock;
            $variant->save();

            $product->applyAggregatesFromVariants();
            $product->save();

            $sale->update([
                'number' => $this->humanNumber($sale->id, $soldAt, $quantity, $lineTotal),
            ]);

            $this->recordStockMovement($sale, $cashier, $product, -1 * $quantity, 'Быстрая продажа №'.$sale->id);
            $this->recordDailyStats($sale->fresh(['items']), $soldAt);

            return [
                'sale' => $sale->load(['items.product.category', 'cashier']),
                'product' => $product->fresh(['category', 'variants']),
            ];
        });
    }

    private function generateSku(?string $categoryName, string $productName, string $size): string
    {
        $slug = Str::slug($productName.'-'.$size) ?: 'item';
        $base = strtoupper(substr($slug, 0, 24));
        $candidate = $base;
        $i = 1;

        while (ProductVariant::query()->where('sku', $candidate)->exists()) {
            $candidate = $base.'-'.$i;
            $i++;
        }

        return $candidate;
    }
}
