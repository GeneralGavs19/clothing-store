<?php

namespace App\Services;

use App\Models\PendingSale;
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
    public function createPending(User $cashier, array $items, ?string $note = null): Sale
    {
        return DB::transaction(function () use ($cashier, $items, $note) {
            $productIds = collect($items)->pluck('product_id')->unique()->values();
            $products = Product::query()->whereIn('id', $productIds)->lockForUpdate()->get()->keyBy('id');

            $this->assertDisplayAvailability($items, $products);

            $sale = Sale::create([
                'number' => 'S-'.now()->format('YmdHis').'-'.random_int(100, 999),
                'cashier_id' => $cashier->id,
                'status' => 'pending',
                'cashier_note' => $note,
                'submitted_at' => now(),
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

                $subtotal += $lineTotal;
                $profit += $lineProfit;
            }

            $sale->update(['subtotal' => $subtotal, 'profit' => $profit]);

            PendingSale::create([
                'sale_id' => $sale->id,
                'cashier_id' => $cashier->id,
                'status' => 'pending',
                'submitted_at' => now(),
                'note' => $note,
            ]);

            return $sale->load(['items.product.category', 'cashier']);
        });
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

            // Update materialized daily stats incrementally (guard if table not present)
            try {
                $date = $approvedAt->toDateString();
                $itemsSold = $sale->items->sum('quantity');

                // If a row exists, increment; otherwise create initial totals
                $updated = DailyStat::where('date', $date)->exists();

                if ($updated) {
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

                // Update cached dashboard daily stats in-place (if cached) to avoid a cache miss storm
                if (Cache::has('daily_stats_last_14')) {
                    $cached = Cache::get('daily_stats_last_14');
                    $collection = $cached instanceof \Illuminate\Support\Collection ? $cached : collect($cached);
                    $found = false;

                    $collection = $collection->map(function ($row) use ($date, $sale, $itemsSold, &$found) {
                        $rowDate = null;
                        if (is_array($row)) $rowDate = $row['date'] ?? null;
                        elseif ($row instanceof \Illuminate\Support\Collection) $rowDate = $row->get('date');
                        elseif (is_object($row)) $rowDate = $row->date ?? null;

                        if ($rowDate instanceof \Illuminate\Support\Carbon) {
                            $rowDate = $rowDate->toDateString();
                        }

                        if ($rowDate == $date) {
                            $found = true;
                            $total_sales = ((int) ($row['total_sales'] ?? $row->total_sales ?? 0)) + 1;
                            $revenue = ((float) ($row['revenue'] ?? $row->revenue ?? 0)) + (float) $sale->subtotal;
                            $profit = ((float) ($row['profit'] ?? $row->profit ?? 0)) + (float) $sale->profit;
                            $items_sold = ((int) ($row['items_sold'] ?? $row->items_sold ?? 0)) + $itemsSold;

                            return [
                                'date' => $date,
                                'label' => \Illuminate\Support\Carbon::parse($date)->format('d.m'),
                                'total_sales' => $total_sales,
                                'revenue' => $revenue,
                                'profit' => $profit,
                                'items_sold' => $items_sold,
                            ];
                        }

                        // normalize existing row
                        return [
                            'date' => $row->date ?? ($row['date'] ?? null),
                            'label' => isset($row->date) ? \Illuminate\Support\Carbon::parse($row->date)->format('d.m') : ($row['label'] ?? null),
                            'total_sales' => (int) ($row['total_sales'] ?? $row->total_sales ?? 0),
                            'revenue' => (float) ($row['revenue'] ?? $row->revenue ?? 0),
                            'profit' => (float) ($row['profit'] ?? $row->profit ?? 0),
                            'items_sold' => (int) ($row['items_sold'] ?? $row->items_sold ?? 0),
                        ];
                    });

                    if (! $found) {
                        $collection->push([
                            'date' => $date,
                            'label' => \Illuminate\Support\Carbon::parse($date)->format('d.m'),
                            'total_sales' => 1,
                            'revenue' => (float) $sale->subtotal,
                            'profit' => (float) $sale->profit,
                            'items_sold' => $itemsSold,
                        ]);
                    }

                    // keep only the last 14 days within window
                    $start = now()->subDays(13)->toDateString();
                    $end = now()->toDateString();
                    $collection = $collection->sortBy('date')->values()->filter(fn ($r) => $r['date'] >= $start && $r['date'] <= $end)->values();

                    Cache::put('daily_stats_last_14', $collection, 3600);
                }
            } catch (\Throwable $e) {
                // If the daily_stats table does not exist (migrations not run) or any DB error,
                // don't fail the approval — just continue. Optionally log the error.
            }

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

    private function assertDisplayAvailability(array $items, $products): void
    {
        $requested = collect($items)->groupBy('product_id')->map(fn ($rows) => $rows->sum('quantity'));
        $pendingByProduct = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', 'pending')
            ->whereIn('sale_items.product_id', $requested->keys())
            ->groupBy('sale_items.product_id')
            ->selectRaw('sale_items.product_id, SUM(sale_items.quantity) as quantity')
            ->pluck('quantity', 'product_id');

        foreach ($requested as $productId => $quantity) {
            $product = $products->get((int) $productId);

            if (! $product || $product->status === 'archived') {
                throw ValidationException::withMessages(['items' => 'Product is unavailable.']);
            }

            $available = $product->display_quantity - (int) ($pendingByProduct[$productId] ?? 0);
            if ($quantity > $available) {
                throw ValidationException::withMessages([
                    'items' => "Only {$available} display units are available for {$product->name}.",
                ]);
            }
        }
    }
}
