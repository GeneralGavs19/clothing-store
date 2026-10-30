<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Services\ActivityLogger;
use App\Services\SalesService;
use App\Services\TelegramService;
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

        if ($search = $request->string('search')->trim()->toString()) {
            $digits = preg_replace('/\D/', '', $search);
            $query->where(function ($q) use ($search, $digits) {
                if ($digits !== '') {
                    $q->where('id', (int) $digits);
                }
                $q->orWhere('number', 'like', "%{$search}%")
                    ->orWhere('cashier_note', 'like', "%{$search}%")
                    ->orWhereHas('items', function ($iq) use ($search) {
                        $iq->where('product_name', 'like', "%{$search}%")
                            ->orWhere('variant_size', 'like', "%{$search}%")
                            ->orWhere('product_type', 'like', "%{$search}%");
                    })
                    ->orWhereHas('cashier', fn ($cq) => $cq->where('name', 'like', "%{$search}%"));
            });
        }

        if ($request->has('is_on_order') && $request->input('is_on_order') !== null && $request->input('is_on_order') !== '') {
            $query->where('is_on_order', $request->boolean('is_on_order'));
        }

        if ($request->filled('fulfillment_status')) {
            $query->where('fulfillment_status', $request->string('fulfillment_status')->toString());
        }

        $query->orderByDesc('approved_at')->orderByDesc('id');

        return response()->json(ApiPagination::format($query->paginate($request->integer('per_page', 15))));
    }

    public function store(Request $request, SalesService $sales, ActivityLogger $logger, TelegramService $telegram)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'exists:products,id'],
            'items.*.product_variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.product_item_id' => ['nullable', 'exists:product_items,id'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'items.*.original_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.sale_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'in:0,10,15,100'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.source' => ['nullable', 'in:display,stock'],
            'discount_percent' => ['nullable', 'numeric', 'in:0,10,15,100'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'original_subtotal' => ['nullable', 'numeric', 'min:0'],
            'is_on_order' => ['nullable', 'boolean'],
            'cashier_note' => ['nullable', 'string', 'max:1000'],
            'sold_at' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'fulfillment_status' => ['nullable', 'string', 'in:new,in_progress,ready,delivered,cancelled'],
        ]);

        $saleDiscountPercent = (float) ($data['discount_percent'] ?? 0);

        foreach ($data['items'] as $index => $row) {
            if (empty($row['product_item_id']) && (empty($row['product_variant_id']) || empty($row['quantity']))) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "items.{$index}" => 'Укажите вариант и количество или выберите код товара.',
                ]);
            }

            $itemDiscountPercent = isset($row['discount_percent']) ? (float) $row['discount_percent'] : $saleDiscountPercent;
            $salePrice = isset($row['sale_price']) ? (float) $row['sale_price'] : null;
            $originalPrice = isset($row['original_price']) ? (float) $row['original_price'] : null;

            // Итоговая цена 0 ₸ разрешена исключительно при активной скидке 100%
            if ($salePrice !== null && $salePrice <= 0.0 && $itemDiscountPercent != 100) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "items.{$index}.sale_price" => 'Итоговая цена 0 ₸ разрешена исключительно при активной скидке 100% (Бесплатно).',
                ]);
            }

            // При скидках 10% и 15% итоговая цена не должна становиться нулевой для товара с положительной исходной стоимостью
            if (in_array($itemDiscountPercent, [10, 15]) && $originalPrice !== null && $originalPrice > 0 && $salePrice !== null && $salePrice <= 0.0) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "items.{$index}.sale_price" => "При скидке {$itemDiscountPercent}% цена товара не может быть нулевой.",
                ]);
            }
        }

        $sale = $sales->createCompleted(
            $request->user(),
            $data['items'],
            isset($data['cashier_note']) ? strip_tags($data['cashier_note']) : null,
            (bool) ($data['is_on_order'] ?? false),
            $data['sold_at'] ?? null,
            $data['due_date'] ?? null,
            $data['fulfillment_status'] ?? null,
            (float) ($data['discount_percent'] ?? 0),
            isset($data['discount_amount']) ? (float) $data['discount_amount'] : null,
            isset($data['original_subtotal']) ? (float) $data['original_subtotal'] : null
        );

        $logger->log('sales.created', $sale, ['number' => $sale->number], $request);

        if ($sale->is_on_order) {
            $telegram->notifyNewPreOrder($sale);
        }

        return response()->json($sale, 201);
    }

    public function quickSale(Request $request, SalesService $sales, ActivityLogger $logger, TelegramService $telegram)
    {
        $isHat = false;
        if (! empty($request->type) && mb_stripos((string) $request->type, 'шапк') !== false) {
            $isHat = true;
        } elseif (! empty($request->name) && mb_stripos((string) $request->name, 'шапк') !== false) {
            $isHat = true;
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'type' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'category_name' => ['nullable', 'string', 'max:100'],
            'size' => [$isHat ? 'nullable' : 'required', 'string', 'max:64'],
            'color' => ['nullable', 'string', 'max:64'],
            'original_price' => ['nullable', 'numeric', 'min:0'],
            'sale_price' => ['required', 'numeric', 'min:0'],
            'discount_percent' => ['nullable', 'numeric', 'in:0,10,15,100'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'quantity' => ['required', 'integer', 'min:1'],
            'remaining_stock' => ['nullable', 'integer', 'min:0'],
            'is_on_order' => ['nullable', 'boolean'],
            'cashier_note' => ['nullable', 'string', 'max:1000'],
            'sold_at' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'fulfillment_status' => ['nullable', 'string', 'in:new,in_progress,ready,delivered,cancelled'],
        ]);

        if ($isHat && empty($data['size'])) {
            $data['size'] = '';
        }

        $discountPercent = (float) ($data['discount_percent'] ?? 0);
        $salePrice = (float) $data['sale_price'];
        $originalPrice = isset($data['original_price']) ? (float) $data['original_price'] : $salePrice;

        // Итоговая цена 0 ₸ разрешена исключительно при активной скидке 100%
        if ($salePrice <= 0.0 && $discountPercent != 100) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'sale_price' => 'Итоговая цена 0 ₸ разрешена исключительно при активной скидке 100% (Бесплатно).',
            ]);
        }

        // При скидках 10% и 15% итоговая цена не должна быть нулевой для товара с положительной стоимостью
        if (in_array($discountPercent, [10, 15]) && $originalPrice > 0 && $salePrice <= 0.0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'sale_price' => "При скидке {$discountPercent}% цена товара не может быть нулевой.",
            ]);
        }

        $result = $sales->createQuickSale($request->user(), $data);

        $logger->log('sales.quick_created', $result['sale'], [
            'number' => $result['sale']->number,
            'product_name' => $result['product']->name,
        ], $request);

        if (! empty($result['sale']->is_on_order)) {
            $telegram->notifyNewPreOrder($result['sale']);
        }

        return response()->json($result, 201);
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

    public function cancel(Request $request, Sale $sale, SalesService $sales, ActivityLogger $logger, TelegramService $telegram)
    {
        $data = $request->validate([
            'cancel_reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($request->user()->isCashier() && (int) $sale->cashier_id !== (int) $request->user()->id) {
            abort(403, 'Вы можете отменять только свои продажи.');
        }

        $sale->load(['items', 'cashier']);
        $metaBefore = [
            'sale_id' => $sale->id,
            'number' => $sale->number,
            'subtotal' => (float) $sale->subtotal,
            'reason' => $data['cancel_reason'] ?? 'Отмена операции',
            'cancelled_by' => $request->user()->only(['id', 'name', 'role']),
            'items' => $sale->items->map(fn ($i) => [
                'name' => $i->product_name,
                'size' => $i->variant_size,
                'quantity' => (int) $i->quantity,
                'sale_price' => (float) $i->sale_price,
                'line_total' => (float) $i->line_total,
            ])->all(),
        ];

        $cancelledSale = $sales->cancelSale($sale, $request->user(), $data['cancel_reason'] ?? null);

        $logger->log('sales.cancelled', $cancelledSale, $metaBefore, $request);

        $telegram->notifySaleCancelled($cancelledSale, $data['cancel_reason'] ?? null, $request->user());

        return response()->json($cancelledSale);
    }

    public function update(Request $request, Sale $sale, SalesService $sales, ActivityLogger $logger)
    {
        $data = $request->validate([
            'is_on_order' => ['nullable', 'boolean'],
            'due_date' => ['nullable', 'date'],
            'fulfillment_status' => ['nullable', 'string', 'in:new,in_progress,ready,delivered,cancelled'],
            'sold_at' => ['nullable', 'date'],
            'cashier_note' => ['nullable', 'string', 'max:1000'],
            'discount_percent' => ['nullable', 'numeric', 'in:0,10,15,100'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'original_subtotal' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'exists:products,id'],
            'items.*.product_variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.product_name' => ['nullable', 'string', 'max:180'],
            'items.*.product_type' => ['nullable', 'string', 'max:100'],
            'items.*.variant_size' => ['nullable', 'string', 'max:64'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.original_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'in:0,10,15,100'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.sale_price' => ['required', 'numeric', 'min:0'],
            'items.*.source' => ['nullable', 'in:display,stock'],
        ]);

        $saleDiscountPercent = (float) ($data['discount_percent'] ?? $sale->discount_percent ?? 0);

        foreach ($data['items'] as $index => $row) {
            $itemDiscountPercent = isset($row['discount_percent']) ? (float) $row['discount_percent'] : $saleDiscountPercent;
            $salePrice = (float) $row['sale_price'];
            $originalPrice = isset($row['original_price']) ? (float) $row['original_price'] : null;

            if ($salePrice <= 0.0 && $itemDiscountPercent != 100) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "items.{$index}.sale_price" => 'Итоговая цена 0 ₸ разрешена исключительно при активной скидке 100% (Бесплатно).',
                ]);
            }

            if (in_array($itemDiscountPercent, [10, 15]) && $originalPrice !== null && $originalPrice > 0 && $salePrice <= 0.0) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "items.{$index}.sale_price" => "При скидке {$itemDiscountPercent}% цена товара не может быть нулевой.",
                ]);
            }
        }

        $sale->load(['items']);
        $oldItems = $sale->items->map(fn ($i) => [
            'name' => $i->product_name,
            'size' => $i->variant_size,
            'quantity' => (int) $i->quantity,
            'sale_price' => (float) $i->sale_price,
            'line_total' => (float) $i->line_total,
        ])->all();

        $updatedSale = $sales->updateSale($sale, $data, $request->user());

        $logger->log('sales.updated', $updatedSale, [
            'number' => $updatedSale->number,
            'old_subtotal' => (float) $sale->subtotal,
            'new_subtotal' => (float) $updatedSale->subtotal,
            'old_items' => $oldItems,
            'new_items' => $data['items'],
        ], $request);

        return response()->json($updatedSale);
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
