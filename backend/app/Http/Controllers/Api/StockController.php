<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\ActivityLogger;
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

    public function transfer(Request $request, ActivityLogger $logger)
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'direction' => ['required', Rule::in(['stock_to_display', 'display_to_stock'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $movement = DB::transaction(function () use ($request, $data) {
            $product = Product::query()->lockForUpdate()->findOrFail($data['product_id']);

            if ($data['direction'] === 'stock_to_display') {
                if ($product->stock_quantity < $data['quantity']) {
                    throw ValidationException::withMessages(['quantity' => 'Недостаточно товара на складе.']);
                }
                $product->stock_quantity -= $data['quantity'];
                $product->display_quantity += $data['quantity'];
                $from = 'stock';
                $to = 'display';
            } else {
                if ($product->display_quantity < $data['quantity']) {
                    throw ValidationException::withMessages(['quantity' => 'Недостаточно товара на витрине.']);
                }
                $product->display_quantity -= $data['quantity'];
                $product->stock_quantity += $data['quantity'];
                $from = 'display';
                $to = 'stock';
            }

            $product->refreshStatus();
            $product->save();

            return StockMovement::create([
                'product_id' => $product->id,
                'user_id' => $request->user()->id,
                'type' => 'transfer',
                'from_location' => $from,
                'to_location' => $to,
                'quantity' => $data['quantity'],
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

            $product->stock_quantity += $stockDelta;
            $product->display_quantity += $displayDelta;
            $product->refreshStatus();
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
}
