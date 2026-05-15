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

        if (! $request->user()->isAdmin()) {
            $query->where('cashier_id', $request->user()->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }
        if ($request->filled('cashier_id') && $request->user()->isAdmin()) {
            $query->where('cashier_id', $request->integer('cashier_id'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to'));
        }

        return response()->json(ApiPagination::format($query->paginate($request->integer('per_page', 15))));
    }

    public function store(Request $request, SalesService $sales, ActivityLogger $logger)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'cashier_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $sale = $sales->createPending(
            $request->user(),
            $data['items'],
            isset($data['cashier_note']) ? strip_tags($data['cashier_note']) : null
        );

        $logger->log('sales.pending_created', $sale, ['number' => $sale->number], $request);

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

    public function destroy(Request $request, Sale $sale, ActivityLogger $logger)
    {
        if ($sale->status === 'approved') {
            return response()->json(['message' => 'Approved sales cannot be deleted.'], 422);
        }

        // remove items and delete sale
        $sale->items()->delete();

        $logger->log('sales.deleted', $sale, ['number' => $sale->number], $request);
        $sale->delete();

        return response()->json(['message' => 'Sale deleted.']);
    }
}
