<?php

namespace App\Services;

use App\Models\DailyStat;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalesService
{
    public function createCompleted(User $cashier, array $items, ?string $note = null): Sale
    {
        return DB::transaction(function () use ($cashier, $items, $note) {
            $productIds = collect($items)->pluck('product_id')->unique()->values();
            $products = Product::query()->whereIn('id', $productIds)->lockForUpdate()->get()->keyBy('id');

            $this->assertAvailability($items, $products);

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
                $product = $products->get((int) $item['product_id']);
                $quantity = (int) $item['quantity'];
                $source = ($item['source'] ?? 'display') === 'stock' ? 'stock' : 'display';
                $variantSize = isset($item['variant_size']) ? trim((string) $item['variant_size']) : null;
                $purchase = 0;
                $salePrice = (float) $product->sale_price;
                $lineTotal = round($salePrice * $quantity, 2);
                $lineProfit = round(($salePrice - $purchase) * $quantity, 2);

                $sale->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_sku' => $product->sku,
                    'quantity' => $quantity,
                    'source_location' => $source,
                    'variant_size' => $variantSize ?: null,
                    'purchase_price' => $purchase,
                    'sale_price' => $salePrice,
                    'line_total' => $lineTotal,
                    'line_profit' => $lineProfit,
                ]);

                $this->applyVariantSale($product, $quantity, $source, $variantSize);

                $product->refreshStatus();
                $product->save();

                StockMovement::create([
                    'product_id' => $product->id,
                    'sale_id' => $sale->id,
                    'user_id' => $cashier->id,
                    'type' => 'sale',
                    'from_location' => $source,
                    'to_location' => 'external',
                    'quantity' => -1 * $quantity,
                    'stock_after' => $product->stock_quantity,
                    'display_after' => $product->display_quantity,
                    'note' => 'Продажа №'.$sale->id,
                ]);

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

            $products = Product::query()
                ->whereIn('id', $sale->items->pluck('product_id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($sale->items as $item) {
                $product = $products->get($item->product_id);
                $source = $item->source_location ?? 'display';
                $available = $source === 'stock' ? $product->stock_quantity : $product->display_quantity;

                if (! $product || $available < $item->quantity) {
                    throw ValidationException::withMessages([
                        'stock' => "Недостаточно остатка для {$product?->name}.",
                    ]);
                }
            }

            foreach ($sale->items as $item) {
                $product = $products->get($item->product_id);
                $source = $item->source_location ?? 'display';

                if ($source === 'stock') {
                    $product->stock_quantity -= $item->quantity;
                } else {
                    $product->display_quantity -= $item->quantity;
                }

                $product->refreshStatus();
                $product->save();

                StockMovement::create([
                    'product_id' => $product->id,
                    'sale_id' => $sale->id,
                    'user_id' => $admin->id,
                    'type' => 'sale',
                    'from_location' => $source,
                    'to_location' => 'external',
                    'quantity' => -1 * (int) $item->quantity,
                    'stock_after' => $product->stock_quantity,
                    'display_after' => $product->display_quantity,
                    'note' => 'Продажа №'.$sale->id,
                ]);
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
                $productIds = $sale->items->pluck('product_id')->filter()->unique()->values();

                if ($productIds->isNotEmpty()) {
                    $products = Product::query()
                        ->whereIn('id', $productIds)
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');

                    foreach ($sale->items as $item) {
                        if (! $item->product_id) {
                            continue;
                        }

                        $product = $products->get($item->product_id);

                        if (! $product) {
                            continue;
                        }

                        $source = $item->source_location ?? 'display';
                        $this->restoreVariantQuantity($product, (int) $item->quantity, $source, $item->variant_size);

                        $product->refreshStatus();
                        $product->save();

                        StockMovement::create([
                            'product_id' => $product->id,
                            'sale_id' => null,
                            'user_id' => $admin->id,
                            'type' => 'adjustment',
                            'from_location' => 'external',
                            'to_location' => $source,
                            'quantity' => (int) $item->quantity,
                            'stock_after' => $product->stock_quantity,
                            'display_after' => $product->display_quantity,
                            'note' => 'Отмена продажи №'.$sale->id,
                        ]);
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

    private function assertAvailability(array $items, $products): void
    {
        $requested = collect($items)->map(function ($row) {
            return [
                'product_id' => (int) $row['product_id'],
                'quantity' => (int) $row['quantity'],
                'source' => ($row['source'] ?? 'display') === 'stock' ? 'stock' : 'display',
                'variant_size' => isset($row['variant_size']) ? trim((string) $row['variant_size']) : null,
            ];
        });

        foreach ($requested as $row) {
            $product = $products->get($row['product_id']);

            if (! $product) {
                throw ValidationException::withMessages(['items' => 'Товар недоступен для продажи.']);
            }

            $available = $this->availableForSource($product, $row['source'], $row['variant_size']);

            if ($row['quantity'] > $available) {
                $place = $row['source'] === 'stock' ? 'складе' : 'витрине';
                $sizeLabel = $row['variant_size'] ? ' (размер '.$row['variant_size'].')' : '';
                throw ValidationException::withMessages([
                    'items' => "На {$place} только {$available} шт.{$sizeLabel} для «{$product->name}».",
                ]);
            }
        }
    }

    private function availableForSource(Product $product, string $source, ?string $variantSize = null): int
    {
        if (!$variantSize) {
            return $source === 'stock' ? (int) $product->stock_quantity : (int) $product->display_quantity;
        }
        $variants = is_array($product->variants) ? $product->variants : [];
        foreach ($variants as $variant) {
            if (trim((string) ($variant['size'] ?? '')) !== $variantSize) {
                continue;
            }
            return $source === 'stock'
                ? (int) ($variant['stock_quantity'] ?? $variant['quantity'] ?? 0)
                : (int) ($variant['display_quantity'] ?? 0);
        }
        return 0;
    }

    private function applyVariantSale(Product $product, int $quantity, string $source, ?string $variantSize): void
    {
        $variants = is_array($product->variants) ? $product->variants : [];
        if (!$variantSize || empty($variants)) {
            if ($source === 'stock') {
                $product->stock_quantity = max(0, (int) $product->stock_quantity - $quantity);
            } else {
                $product->display_quantity = max(0, (int) $product->display_quantity - $quantity);
            }
            return;
        }

        $key = $source === 'stock' ? 'stock_quantity' : 'display_quantity';
        foreach ($variants as &$variant) {
            if (trim((string) ($variant['size'] ?? '')) !== $variantSize) {
                continue;
            }
            $current = (int) ($variant[$key] ?? 0);
            $variant[$key] = max(0, $current - $quantity);
            break;
        }
        $product->variants = $variants;
        $product->stock_quantity = (int) collect($variants)->sum(fn ($v) => (int) ($v['stock_quantity'] ?? $v['quantity'] ?? 0));
        $product->display_quantity = (int) collect($variants)->sum(fn ($v) => (int) ($v['display_quantity'] ?? 0));
    }

    private function restoreVariantQuantity(Product $product, int $quantity, string $source, ?string $variantSize): void
    {
        $variants = is_array($product->variants) ? $product->variants : [];
        if (!$variantSize || empty($variants)) {
            if ($source === 'stock') {
                $product->stock_quantity += $quantity;
            } else {
                $product->display_quantity += $quantity;
            }
            return;
        }
        $key = $source === 'stock' ? 'stock_quantity' : 'display_quantity';
        $found = false;
        foreach ($variants as &$variant) {
            if (trim((string) ($variant['size'] ?? '')) !== $variantSize) {
                continue;
            }
            $variant[$key] = (int) ($variant[$key] ?? 0) + $quantity;
            $found = true;
            break;
        }
        if (!$found) {
            $variants[] = ['size' => $variantSize, 'stock_quantity' => 0, 'display_quantity' => 0, $key => $quantity];
        }
        $product->variants = $variants;
        $product->stock_quantity = (int) collect($variants)->sum(fn ($v) => (int) ($v['stock_quantity'] ?? $v['quantity'] ?? 0));
        $product->display_quantity = (int) collect($variants)->sum(fn ($v) => (int) ($v['display_quantity'] ?? 0));
    }
}
