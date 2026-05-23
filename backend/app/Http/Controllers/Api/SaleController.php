<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Services\ActivityLogger;
use App\Services\SalesService;
use App\Support\ApiPagination;
use Illuminate\Http\Request;

class SaleController extends Controller
{
    public function index(Request $request)
    {
        $query = Sale::query()->with(['items.product.category', 'cashier', 'approver'])->latest();

        if ($request->user()->isCashier()) {
            $query->where('cashier_id', $request->user()->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }
        if ($request->filled('cashier_id') && $request->user()->isAdmin()) {
            $query->where('cashier_id', $request->integer('cashier_id'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('approved_at', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('approved_at', '<=', $request->date('date_to'));
        }

        $query->orderByDesc('approved_at')->orderByDesc('id');

        return response()->json(ApiPagination::format($query->paginate($request->integer('per_page', 15))));
    }

    public function store(Request $request, SalesService $sales, ActivityLogger $logger)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'exists:products,id'],
            'items.*.product_variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.product_item_id' => ['nullable', 'exists:product_items,id'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'items.*.source' => ['nullable', 'in:display,stock'],
            'cashier_note' => ['nullable', 'string', 'max:1000'],
        ]);

        foreach ($data['items'] as $index => $row) {
            if (! empty($row['product_item_id'])) {
                continue;
            }
            if (empty($row['product_variant_id']) || empty($row['quantity'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "items.{$index}" => 'Укажите вариант и количество или выберите код товара.',
                ]);
            }
        }

        $sale = $sales->createCompleted(
            $request->user(),
            $data['items'],
            isset($data['cashier_note']) ? strip_tags($data['cashier_note']) : null
        );

        $logger->log('sales.created', $sale, ['number' => $sale->number], $request);

        return response()->json($sale, 201);
    }

    public function pending(Request $request)
    {
        $query = Sale::query()
            ->where('status', 'pending')
            ->with(['items.product.category', 'cashier'])
            ->oldest('submitted_at');

        return response()->json(ApiPagination::format($query->paginate($request->integer('per_page', 15))));
    }

    public function approve(Request $request, Sale $sale, SalesService $sales, ActivityLogger $logger)
    {
        $data = $request->validate(['admin_note' => ['nullable', 'string', 'max:1000']]);
        $sale = $sales->approve($sale, $request->user(), isset($data['admin_note']) ? strip_tags($data['admin_note']) : null);

        $logger->log('sales.approved', $sale, ['number' => $sale->number], $request);

        return response()->json($sale);
    }

    public function reject(Request $request, Sale $sale, SalesService $sales, ActivityLogger $logger)
    {
        $data = $request->validate(['admin_note' => ['nullable', 'string', 'max:1000']]);
        $sale = $sales->reject($sale, $request->user(), isset($data['admin_note']) ? strip_tags($data['admin_note']) : null);

        $logger->log('sales.rejected', $sale, ['number' => $sale->number], $request);

        return response()->json($sale);
    }

    public function destroy(Request $request, Sale $sale, SalesService $sales, ActivityLogger $logger)
    {
        $sale->load(['items', 'cashier']);

        $meta = [
            'sale_id' => $sale->id,
            'number' => $sale->number,
            'display_title' => $sale->display_title,
            'status' => $sale->status,
            'subtotal' => (float) $sale->subtotal,
            'profit' => (float) $sale->profit,
            'cashier' => $sale->cashier?->only(['id', 'name']),
            'items' => $sale->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'name' => $item->product_name ?: $item->product?->name,
                'sku' => $item->product_sku,
                'quantity' => (int) $item->quantity,
                'source_location' => $item->source_location,
                'line_total' => (float) $item->line_total,
            ])->values()->all(),
            'deleted_by' => $request->user()->only(['id', 'name', 'email', 'role']),
        ];

        $statusBeforeDelete = $sale->status;

        $sales->deleteSale($sale, $request->user());

        $logger->log('sales.deleted', null, $meta, $request);

        $message = $statusBeforeDelete === 'approved'
            ? 'Продажа удалена. Остатки товаров возвращены.'
            : 'Продажа удалена.';

        return response()->json(['message' => $message]);
    }
}
