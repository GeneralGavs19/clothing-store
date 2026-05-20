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
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.source' => ['nullable', 'in:display,stock'],
            'items.*.variant_size' => ['nullable', 'string', 'max:32'],
            'cashier_note' => ['nullable', 'string', 'max:1000'],
        ]);

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

        $sales->deleteSale($sale, $request->user());

        $logger->log('sales.deleted', null, $meta, $request);

        $message = $sale->status === 'approved'
            ? 'Продажа удалена. Остатки товаров возвращены.'
            : 'Продажа удалена.';

        return response()->json(['message' => $message]);
    }
}
