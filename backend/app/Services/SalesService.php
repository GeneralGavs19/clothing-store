<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\DailyStat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesService
{
    /**
     * Create a sale and apply stock / statistics immediately (no admin approval).
     */
    public function createCompleted(User $cashier, array $items, ?string $note = null): Sale
    {
        return DB::transaction(function () use ($cashier, $items, $note) {
            $productIds = collect($items)->pluck('product_id')->unique()->values();
            $products = Product::query()->whereIn('id', $productIds)->lockForUpdate()->get()->keyBy('id');

            $this->assertDisplayAvailability($items, $products);

            $completedAt = now();

            $sale = Sale::create([
                'number' => 'S-'.now()->format('YmdHis').'-'.random_int(100, 999),
                'cashier_id' => $cashier->id,
                'status' => 'approved',
                'approved_by' => $cashier->id,
                'approved_at' => $completedAt,
                'submitted_at' => $completedAt,
                'cashier_note' => $note,
            ]);

            $subtotal = 0;
            $profit = 0;

            foreach ($items as $item) {
                $product = $products->get((int) $item['product_id']);
                $quantity = (int) $item['quantity'];
                $lineTotal = (float) $product->sale_price * $quantity;
                $lineProfit = ((float) $product->sale_price - (float) $product->purchase_price) * $quantity;

                $sale->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'purchase_price' => $product->purchase_price,
                    'sale_price' => $product->sale_price,
                    'line_total' => $lineTotal,
                    'line_profit' => $lineProfit,
                ]);

                $product->display_quantity -= $quantity;
                $product->refreshStatus();
                $product->save();

                StockMovement::create([
                    'product_id' => $product->id,
                    'sale_id' => $sale->id,
                    'user_id' => $cashier->id,
                    'type' => 'sale',
                    'from_location' => 'display',
                    'to_location' => 'external',
                    'quantity' => -1 * $quantity,
                    'stock_after' => $product->stock_quantity,
                    'display_after' => $product->display_quantity,
                    'note' => 'Sale '.$sale->number,
                ]);

                $subtotal += $lineTotal;
                $profit += $lineProfit;
            }

            $sale->update(['subtotal' => $subtotal, 'profit' => $profit]);

            $this->recordDailyStats($sale, $completedAt);

            return $sale->load(['items.product.category', 'cashier', 'approver']);
        });
    }

    /** @deprecated Use createCompleted — kept for legacy pending rows in DB */
    public function createPending(User $cashier, array $items, ?string $note = null): Sale
    {
        return $this->createCompleted($cashier, $items, $note);
    }

    public function approve(Sale $sale, User $admin, ?string $note = null): Sale
    {
        return DB::transaction(function () use ($sale, $admin, $note) {
            $sale = Sale::query()->with('items')->lockForUpdate()->findOrFail($sale->id);

            if ($sale->status !== 'pending') {
                throw ValidationException::withMessages(['sale' => 'Sale is already reviewed.']);
            }

            $products = Product::query()
                ->whereIn('id', $sale->items->pluck('product_id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($sale->items as $item) {
                $product = $products->get($item->product_id);
                if (! $product || $product->display_quantity < $item->quantity) {
                    throw ValidationException::withMessages([
                        'stock' => "Not enough display stock for {$product?->name}.",
                    ]);
                }
            }

            foreach ($sale->items as $item) {
                $product = $products->get($item->product_id);
                $product->display_quantity -= $item->quantity;
                $product->refreshStatus();
                $product->save();

                StockMovement::create([
                    'product_id' => $product->id,
                    'sale_id' => $sale->id,
                    'user_id' => $admin->id,
                    'type' => 'sale',
                    'from_location' => 'display',
                    'to_location' => 'external',
                    'quantity' => -1 * (int) $item->quantity,
                    'stock_after' => $product->stock_quantity,
                    'display_after' => $product->display_quantity,
                    'note' => 'Approved sale '.$sale->number,
                ]);
            }

            $approvedAt = now();

            $sale->update([
                'status' => 'approved',
                'approved_by' => $admin->id,
                'approved_at' => $approvedAt,
                'admin_note' => $note,
            ]);

            $sale->pendingSale?->update([
                'status' => 'approved',
                'reviewed_by' => $admin->id,
                'reviewed_at' => $approvedAt,
            ]);

            $this->recordDailyStats($sale, $approvedAt);

            return $sale->load(['items.product.category', 'cashier', 'approver']);
        });
    }

    public function reject(Sale $sale, User $admin, ?string $note = null): Sale
    {
        return DB::transaction(function () use ($sale, $admin, $note) {
            $sale = Sale::query()->lockForUpdate()->findOrFail($sale->id);

            if ($sale->status !== 'pending') {
                throw ValidationException::withMessages(['sale' => 'Sale is already reviewed.']);
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

    private function recordDailyStats(Sale $sale, $completedAt): void
    {
        try {
            $date = $completedAt->toDateString();
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
            // daily_stats table may be missing on first deploy
        }
    }

    private function assertDisplayAvailability(array $items, $products): void
    {
        $requested = collect($items)->groupBy('product_id')->map(fn ($rows) => $rows->sum('quantity'));

        foreach ($requested as $productId => $quantity) {
            $product = $products->get((int) $productId);

            if (! $product || $product->status === 'archived') {
                throw ValidationException::withMessages(['items' => 'Product is unavailable.']);
            }

            if ($quantity > $product->display_quantity) {
                throw ValidationException::withMessages([
                    'items' => "Only {$product->display_quantity} display units are available for {$product->name}.",
                ]);
            }
        }
    }
}
