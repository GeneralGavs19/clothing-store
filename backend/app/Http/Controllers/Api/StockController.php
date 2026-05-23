<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductItem;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Services\ActivityLogger;
use App\Services\ProductItemService;
use App\Support\ApiPagination;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StockController extends Controller
{
    public function movements(Request $request)
    {
        $query = StockMovement::query()->with(['product.category', 'user'])->latest();

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->integer('product_id'));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->string('type')->toString());
        }

        return response()->json(ApiPagination::format($query->paginate($request->integer('per_page', 20))));
    }

    public function transfer(Request $request, ActivityLogger $logger, ProductItemService $itemService)
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'product_variant_id' => ['required', 'exists:product_variants,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'direction' => ['required', Rule::in(['stock_to_display', 'display_to_stock'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $movement = DB::transaction(function () use ($request, $data) {
            $variant = ProductVariant::query()->lockForUpdate()->findOrFail($data['product_variant_id']);

            if ((int) $variant->product_id !== (int) $data['product_id']) {
                throw ValidationException::withMessages(['product_variant_id' => 'Вариант не принадлежит этому товару.']);
            }

            $qty = $data['quantity'];

            if ($data['direction'] === 'stock_to_display') {
                if ($variant->stock_warehouse < $qty) {
                    throw ValidationException::withMessages(['quantity' => 'Недостаточно товара на складе.']);
                }
                $from = ProductItem::LOCATION_STOCK;
                $to = ProductItem::LOCATION_DISPLAY;
            } else {
                if ($variant->stock_store < $qty) {
                    throw ValidationException::withMessages(['quantity' => 'Недостаточно товара в магазине.']);
                }
                $from = ProductItem::LOCATION_DISPLAY;
                $to = ProductItem::LOCATION_STOCK;
            }

            $itemService->transferItems($variant, $qty, $from, $to);
            $variant->refresh();

            $product = Product::query()->lockForUpdate()->findOrFail($data['product_id']);
            $product->applyAggregatesFromVariants();
            $product->save();

            return StockMovement::create([
                'product_id' => $product->id,
                'user_id' => $request->user()->id,
                'type' => 'transfer',
                'from_location' => $from,
                'to_location' => $to,
                'quantity' => $qty,
                'stock_after' => $product->stock_quantity,
                'display_after' => $product->display_quantity,
                'note' => isset($data['note']) ? strip_tags($data['note']) : null,
            ]);
        });

        $logger->log('stock.transfer', $movement, ['product_id' => $data['product_id']], $request);

        return response()->json($movement->load('product'));
    }

    public function adjust(Request $request, ActivityLogger $logger)
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'stock_delta' => ['sometimes', 'integer'],
            'display_delta' => ['sometimes', 'integer'],
            'note' => ['required', 'string', 'max:1000'],
        ]);

        $movement = DB::transaction(function () use ($request, $data) {
            $product = Product::query()->lockForUpdate()->findOrFail($data['product_id']);
            $stockDelta = (int) ($data['stock_delta'] ?? 0);
            $displayDelta = (int) ($data['display_delta'] ?? 0);

            if ($stockDelta === 0 && $displayDelta === 0) {
                throw ValidationException::withMessages(['quantity' => 'Укажите изменение склада или витрины.']);
            }
            if ($product->stock_quantity + $stockDelta < 0 || $product->display_quantity + $displayDelta < 0) {
                throw ValidationException::withMessages(['quantity' => 'Остатки не могут быть отрицательными.']);
            }

            $variants = ProductVariant::query()
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->orderBy('position')
                ->orderBy('id')
                ->get();

            $this->applyVariantDelta($variants, $stockDelta, 'stock_warehouse');
            $this->applyVariantDelta($variants, $displayDelta, 'stock_store');
            foreach ($variants as $v) {
                $v->save();
            }

            $product->applyAggregatesFromVariants();
            $product->save();

            return StockMovement::create([
                'product_id' => $product->id,
                'user_id' => $request->user()->id,
                'type' => $stockDelta > 0 || $displayDelta > 0 ? 'restock' : 'adjustment',
                'from_location' => $stockDelta > 0 || $displayDelta > 0 ? 'external' : 'system',
                'to_location' => $stockDelta > 0 || $displayDelta > 0 ? 'system' : 'external',
                'quantity' => $stockDelta + $displayDelta,
                'stock_after' => $product->stock_quantity,
                'display_after' => $product->display_quantity,
                'note' => strip_tags($data['note']),
            ]);
        });

        $logger->log('stock.adjust', $movement, ['product_id' => $data['product_id']], $request);

        return response()->json($movement->load('product'));
    }

    private function applyVariantDelta($variants, int $delta, string $column): void
    {
        if ($delta === 0 || $variants->isEmpty()) {
            return;
        }
        if ($delta > 0) {
            $variants->first()->$column += $delta;
            return;
        }
        $left = abs($delta);
        foreach ($variants as $variant) {
            if ($left <= 0) {
                break;
            }
            $value = (int) $variant->{$column};
            if ($value <= 0) {
                continue;
            }
            $cut = min($value, $left);
            $variant->{$column} = $value - $cut;
            $left -= $cut;
        }
    }
}
