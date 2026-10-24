<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Statistic;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function salesExcel(Request $request): StreamedResponse
    {
        $fileName = 'sales-report-'.now()->format('Y-m-d-His').'.xlsx';

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Товары в заказах');

        $headers = [
            '№ продажи',
            'Дата',
            'Тип заказа',
            'Название товара',
            'Вид товара',
            'Размер',
            'Кол-во, шт.',
            'Цена за шт., ₸',
            'Сумма, ₸',
            'Кассир',
            'Примечание',
            'Статус',
        ];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:L1')->getFont()->setBold(true);

        $rowIndex = 2;
        $salesQuery = Sale::query()
            ->with(['items.product', 'cashier']);

        if ($request->user()->isCashier()) {
            $salesQuery->where('cashier_id', $request->user()->id);
        }

        $salesQuery
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('is_on_order'), fn ($q) => $q->where('is_on_order', $request->boolean('is_on_order')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('approved_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('approved_at', '<=', $request->date('date_to')))
            ->orderByDesc('approved_at')
            ->orderByDesc('id');

        $salesQuery->chunk(150, function ($sales) use ($sheet, &$rowIndex) {
            foreach ($sales as $sale) {
                $orderType = $sale->is_on_order ? 'На заказ' : 'В наличии';
                $dateStr = ($sale->approved_at ?? $sale->created_at)?->format('d.m.Y H:i') ?? '—';
                $statusLabel = match ($sale->status) {
                    'approved' => 'Оформлен',
                    'rejected' => 'Отменён',
                    default => 'В ожидании',
                };
                $saleNum = '№'.$sale->id;

                if ($sale->items->isEmpty()) {
                    $sheet->fromArray([
                        $saleNum,
                        $dateStr,
                        $orderType,
                        'Без товаров',
                        '—',
                        '—',
                        0,
                        (float) $sale->subtotal,
                        (float) $sale->subtotal,
                        $sale->cashier?->name ?? '—',
                        $sale->cashier_note ?? '—',
                        $statusLabel,
                    ], null, 'A'.$rowIndex);
                    $rowIndex++;
                    continue;
                }

                foreach ($sale->items as $item) {
                    $sheet->fromArray([
                        $saleNum,
                        $dateStr,
                        $orderType,
                        $item->product_name ?: $item->product?->name ?: 'Удалённый товар',
                        $item->product_type ?: $item->product?->type ?: '—',
                        $item->variant_size ?: '—',
                        (int) $item->quantity,
                        (float) $item->sale_price,
                        (float) $item->line_total,
                        $sale->cashier?->name ?? '—',
                        $sale->cashier_note ?? '—',
                        $statusLabel,
                    ], null, 'A'.$rowIndex);
                    $rowIndex++;
                }
            }
        });

        foreach (range('A', 'L') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        // Вторая вкладка: Сводка по чекам
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('Сводка по чекам');
        $headers2 = [
            '№ продажи',
            'Дата',
            'Тип заказа',
            'Состав заказа (Товары и размеры)',
            'Всего шт.',
            'Сумма чека, ₸',
            'Кассир',
            'Примечание',
            'Статус',
        ];
        $sheet2->fromArray($headers2, null, 'A1');
        $sheet2->getStyle('A1:I1')->getFont()->setBold(true);

        $r2 = 2;
        $salesQuery->chunk(150, function ($sales) use ($sheet2, &$r2) {
            foreach ($sales as $sale) {
                $orderType = $sale->is_on_order ? 'На заказ' : 'В наличии';
                $dateStr = ($sale->approved_at ?? $sale->created_at)?->format('d.m.Y H:i') ?? '—';
                $statusLabel = match ($sale->status) {
                    'approved' => 'Оформлен',
                    'rejected' => 'Отменён',
                    default => 'В ожидании',
                };
                $saleNum = '№'.$sale->id;

                $itemsSummary = $sale->items->map(function ($it) {
                    $name = $it->product_name ?: $it->product?->name ?: 'Товар';
                    $sz = $it->variant_size ? " ({$it->variant_size})" : '';

                    return "{$name}{$sz} × {$it->quantity} шт.";
                })->implode(', ');

                $totalQty = (int) $sale->items->sum('quantity');

                $sheet2->fromArray([
                    $saleNum,
                    $dateStr,
                    $orderType,
                    $itemsSummary ?: '—',
                    $totalQty,
                    (float) $sale->subtotal,
                    $sale->cashier?->name ?? '—',
                    $sale->cashier_note ?? '—',
                    $statusLabel,
                ], null, 'A'.$r2);
                $r2++;
            }
        });

        foreach (range('A', 'I') as $column) {
            $sheet2->getColumnDimension($column)->setAutoSize(true);
        }

        $spreadsheet->setActiveSheetIndex(0);

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function backup(Request $request, ActivityLogger $logger)
    {
        $payload = [
            'generated_at' => now()->toISOString(),
            'tables' => [
                'users' => User::query()->select('id', 'name', 'email', 'role', 'is_active', 'created_at', 'updated_at')->get(),
                'categories' => Category::all(),
                'products' => Product::with('category')->get(),
                'sales' => Sale::with(['items.product', 'cashier', 'approver'])->get(),
                'stock_movements' => StockMovement::with(['product', 'user'])->get(),
                'logs' => ActivityLog::with('user:id,name,email,role')->latest()->limit(1000)->get(),
                'statistics' => Statistic::all(),
            ],
        ];

        $logger->log('reports.backup_generated', null, ['tables' => array_keys($payload['tables'])], $request);

        return response()->json($payload)->header(
            'Content-Disposition',
            'attachment; filename="store-backup-'.now()->format('Y-m-d-His').'.json"'
        );
    }

    public function rebuildStatistics(Request $request, ActivityLogger $logger)
    {
        Statistic::query()->delete();

        $rows = Sale::query()
            ->where('status', 'approved')
            ->selectRaw('DATE(approved_at) as date, COUNT(*) as sales_count, SUM(subtotal) as revenue, SUM(profit) as profit')
            ->groupBy('date')
            ->get();

        foreach ($rows as $row) {
            foreach (['sales_count' => $row->sales_count, 'revenue' => $row->revenue, 'profit' => $row->profit] as $metric => $value) {
                Statistic::updateOrCreate(
                    ['date' => $row->date, 'metric' => $metric],
                    ['value' => $value, 'payload' => null]
                );
            }
        }

        $categoryRows = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('sales.status', 'approved')
            ->selectRaw("DATE(sales.approved_at) as date, COALESCE(categories.name, 'Без категории') as category, SUM(sale_items.line_total) as revenue")
            ->groupBy('date', 'category')
            ->get()
            ->groupBy('date');

        foreach ($categoryRows as $date => $items) {
            Statistic::updateOrCreate(
                ['date' => $date, 'metric' => 'category_revenue'],
                ['value' => $items->sum('revenue'), 'payload' => $items->values()]
            );
        }

        $logger->log('reports.statistics_rebuilt', null, ['days' => $rows->count()], $request);

        return response()->json(['message' => 'Statistics rebuilt.', 'days' => $rows->count()]);
    }

    public function analytics(Request $request)
    {
        $dateFrom = $request->filled('date_from') ? $request->date('date_from') : null;
        $dateTo = $request->filled('date_to') ? $request->date('date_to') : null;

        $salesQuery = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'sale_items.product_variant_id')
            ->where('sales.status', 'approved');

        if ($dateFrom) {
            $salesQuery->whereDate('sales.approved_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $salesQuery->whereDate('sales.approved_at', '<=', $dateTo);
        }
        if ($request->has('is_on_order') && $request->input('is_on_order') !== null && $request->input('is_on_order') !== '') {
            $salesQuery->where('sales.is_on_order', $request->boolean('is_on_order'));
        }

        $saleItems = $salesQuery->get([
            'sale_items.id as sale_item_id',
            'sale_items.sale_id',
            'sale_items.product_id',
            'sale_items.product_variant_id',
            'sale_items.product_name',
            'sale_items.product_type',
            'sale_items.variant_size',
            'sale_items.quantity',
            'sale_items.purchase_price',
            'sale_items.sale_price',
            'sale_items.line_total',
            'sale_items.line_profit',
            'sales.is_on_order as sale_is_on_order',
            'sales.approved_at',
            'products.name as live_product_name',
            'products.type as live_product_type',
            'products.category_id',
            'categories.name as live_category_name',
        ]);

        $catalogProducts = Product::query()
            ->with(['category', 'variants'])
            ->get();

        $totalRevenue = (float) $saleItems->sum('line_total');
        $totalProfit = (float) $saleItems->sum('line_profit');
        $totalSoldUnits = (int) $saleItems->sum('quantity');
        $uniqueSalesCount = (int) $saleItems->pluck('sale_id')->unique()->count();
        $averageCheck = $uniqueSalesCount > 0 ? round($totalRevenue / $uniqueSalesCount, 2) : 0;

        $totalStockUnits = 0;
        foreach ($catalogProducts as $prod) {
            $totalStockUnits += (int) $prod->total_quantity;
        }

        $totalInitialUnits = $totalSoldUnits + $totalStockUnits;

        $salesByProduct = $saleItems->groupBy(fn ($item) => (int) ($item->product_id ?: 0));
        $salesByVariant = $saleItems->groupBy(fn ($item) => (int) ($item->product_variant_id ?: 0));

        // 1. BY TYPE
        $typesMap = [];
        foreach ($catalogProducts as $prod) {
            $t = trim((string) ($prod->type ?: $prod->category?->name ?: 'Без вида'));
            if (! isset($typesMap[$t])) {
                $typesMap[$t] = [
                    'type' => $t,
                    'sold_quantity' => 0,
                    'stock_quantity' => 0,
                    'revenue' => 0.0,
                    'profit' => 0.0,
                ];
            }
            $typesMap[$t]['stock_quantity'] += (int) $prod->total_quantity;
        }

        foreach ($saleItems as $item) {
            $t = trim((string) ($item->product_type ?: $item->live_product_type ?: $item->live_category_name ?: 'Без вида'));
            if (! isset($typesMap[$t])) {
                $typesMap[$t] = [
                    'type' => $t,
                    'sold_quantity' => 0,
                    'stock_quantity' => 0,
                    'revenue' => 0.0,
                    'profit' => 0.0,
                ];
            }
            $typesMap[$t]['sold_quantity'] += (int) $item->quantity;
            $typesMap[$t]['revenue'] += (float) $item->line_total;
            $typesMap[$t]['profit'] += (float) $item->line_profit;
        }

        $byType = collect($typesMap)->map(function ($item) use ($totalRevenue, $totalSoldUnits) {
            $item['initial_quantity'] = (int) $item['sold_quantity'] + (int) $item['stock_quantity'];
            $item['revenue'] = round((float) $item['revenue'], 2);
            $item['profit'] = round((float) $item['profit'], 2);
            $item['revenue_percent'] = $totalRevenue > 0 ? round(($item['revenue'] / $totalRevenue) * 100, 1) : 0;
            $item['units_percent'] = $totalSoldUnits > 0 ? round(($item['sold_quantity'] / $totalSoldUnits) * 100, 1) : 0;

            return $item;
        })->sortByDesc('sold_quantity')->values()->all();

        // 2. BY CATEGORY
        $categoriesMap = [];
        foreach ($catalogProducts as $prod) {
            $catId = $prod->category_id ?: 0;
            $catName = $prod->category?->name ?: 'Без категории';
            if (! isset($categoriesMap[$catId])) {
                $categoriesMap[$catId] = [
                    'category_id' => $catId,
                    'category_name' => $catName,
                    'sold_quantity' => 0,
                    'stock_quantity' => 0,
                    'revenue' => 0.0,
                    'profit' => 0.0,
                ];
            }
            $categoriesMap[$catId]['stock_quantity'] += (int) $prod->total_quantity;
        }

        foreach ($saleItems as $item) {
            $catId = (int) ($item->category_id ?: 0);
            $catName = $item->live_category_name ?: 'Без категории';
            if (! isset($categoriesMap[$catId])) {
                $categoriesMap[$catId] = [
                    'category_id' => $catId,
                    'category_name' => $catName,
                    'sold_quantity' => 0,
                    'stock_quantity' => 0,
                    'revenue' => 0.0,
                    'profit' => 0.0,
                ];
            }
            $categoriesMap[$catId]['sold_quantity'] += (int) $item->quantity;
            $categoriesMap[$catId]['revenue'] += (float) $item->line_total;
            $categoriesMap[$catId]['profit'] += (float) $item->line_profit;
        }

        $byCategory = collect($categoriesMap)->map(function ($item) use ($totalRevenue, $totalSoldUnits) {
            $item['initial_quantity'] = (int) $item['sold_quantity'] + (int) $item['stock_quantity'];
            $item['revenue'] = round((float) $item['revenue'], 2);
            $item['profit'] = round((float) $item['profit'], 2);
            $item['revenue_percent'] = $totalRevenue > 0 ? round(($item['revenue'] / $totalRevenue) * 100, 1) : 0;
            $item['units_percent'] = $totalSoldUnits > 0 ? round(($item['sold_quantity'] / $totalSoldUnits) * 100, 1) : 0;

            return $item;
        })->sortByDesc('sold_quantity')->values()->all();

        // 3. BY PRODUCT & NESTED SIZES
        $productsMap = [];
        foreach ($catalogProducts as $prod) {
            $prodSales = $salesByProduct->get($prod->id, collect());
            $prodSoldQty = (int) $prodSales->sum('quantity');
            $prodRevenue = round((float) $prodSales->sum('line_total'), 2);
            $prodProfit = round((float) $prodSales->sum('line_profit'), 2);
            $prodStock = (int) $prod->total_quantity;
            $prodInitial = $prodSoldQty + $prodStock;

            $sizes = [];
            foreach ($prod->variants as $variant) {
                $varSales = $salesByVariant->get($variant->id, collect());
                if ($varSales->isEmpty()) {
                    $varSales = $prodSales->filter(fn ($s) => $s->variant_size === $variant->size);
                }

                $varSoldQty = (int) $varSales->sum('quantity');
                $varRevenue = round((float) $varSales->sum('line_total'), 2);
                $varProfit = round((float) $varSales->sum('line_profit'), 2);
                $varStock = (int) $variant->stockTotal();
                $varInitial = $varSoldQty + $varStock;

                $sizes[] = [
                    'variant_id' => $variant->id,
                    'size' => $variant->size,
                    'sku' => $variant->sku,
                    'stock_store' => (int) $variant->stock_store,
                    'stock_warehouse' => (int) $variant->stock_warehouse,
                    'stock_quantity' => $varStock,
                    'sold_quantity' => $varSoldQty,
                    'initial_quantity' => $varInitial,
                    'sale_price' => (float) $variant->sale_price,
                    'purchase_price' => (float) ($variant->purchase_price ?? $prod->purchase_price ?? 0),
                    'revenue' => $varRevenue,
                    'profit' => $varProfit,
                ];
            }

            $productsMap[$prod->id] = [
                'product_id' => $prod->id,
                'name' => $prod->name,
                'type' => $prod->type ?: $prod->category?->name ?: 'Без вида',
                'category_name' => $prod->category?->name ?: 'Без категории',
                'color' => $prod->color,
                'sale_price' => (float) $prod->sale_price,
                'purchase_price' => (float) $prod->purchase_price,
                'stock_quantity' => $prodStock,
                'sold_quantity' => $prodSoldQty,
                'initial_quantity' => $prodInitial,
                'revenue' => $prodRevenue,
                'profit' => $prodProfit,
                'sizes' => $sizes,
            ];
        }

        foreach ($salesByProduct as $prodId => $items) {
            if ($prodId && isset($productsMap[$prodId])) {
                continue;
            }
            $firstName = $items->first()->product_name ?: 'Товар #'.$prodId;
            $soldQty = (int) $items->sum('quantity');
            $rev = round((float) $items->sum('line_total'), 2);
            $prof = round((float) $items->sum('line_profit'), 2);

            $sizes = [];
            foreach ($items->groupBy('variant_size') as $sz => $szItems) {
                $szSold = (int) $szItems->sum('quantity');
                $szRev = round((float) $szItems->sum('line_total'), 2);
                $szProf = round((float) $szItems->sum('line_profit'), 2);
                $sizes[] = [
                    'variant_id' => null,
                    'size' => $sz ?: '—',
                    'sku' => null,
                    'stock_store' => 0,
                    'stock_warehouse' => 0,
                    'stock_quantity' => 0,
                    'sold_quantity' => $szSold,
                    'initial_quantity' => $szSold,
                    'sale_price' => $szSold > 0 ? round($szRev / $szSold, 2) : 0,
                    'purchase_price' => 0,
                    'revenue' => $szRev,
                    'profit' => $szProf,
                ];
            }

            $productsMap['del_'.$prodId.'_'.$firstName] = [
                'product_id' => $prodId ?: null,
                'name' => $firstName.' (архивный)',
                'type' => $items->first()->product_type ?: 'Архив',
                'category_name' => 'Архив',
                'color' => null,
                'sale_price' => 0,
                'purchase_price' => 0,
                'stock_quantity' => 0,
                'sold_quantity' => $soldQty,
                'initial_quantity' => $soldQty,
                'revenue' => $rev,
                'profit' => $prof,
                'sizes' => $sizes,
            ];
        }

        $byProduct = collect($productsMap)->sortByDesc('sold_quantity')->values()->all();

        $onOrderUnits = (int) $saleItems->filter(fn ($it) => (bool) $it->sale_is_on_order)->sum('quantity');
        $inStockUnits = (int) $saleItems->filter(fn ($it) => ! (bool) $it->sale_is_on_order)->sum('quantity');
        $onOrderRevenue = round((float) $saleItems->filter(fn ($it) => (bool) $it->sale_is_on_order)->sum('line_total'), 2);
        $inStockRevenue = round((float) $saleItems->filter(fn ($it) => ! (bool) $it->sale_is_on_order)->sum('line_total'), 2);

        return response()->json([
            'summary' => [
                'total_initial_items' => $totalInitialUnits,
                'total_sold_items' => $totalSoldUnits,
                'total_stock_items' => $totalStockUnits,
                'total_revenue' => round($totalRevenue, 2),
                'total_profit' => round($totalProfit, 2),
                'sales_count' => $uniqueSalesCount,
                'average_check' => $averageCheck,
                'on_order_units' => $onOrderUnits,
                'in_stock_units' => $inStockUnits,
                'on_order_revenue' => $onOrderRevenue,
                'in_stock_revenue' => $inStockRevenue,
            ],
            'by_type' => $byType,
            'by_category' => $byCategory,
            'by_product' => $byProduct,
            'filters' => [
                'date_from' => $dateFrom?->toDateString(),
                'date_to' => $dateTo?->toDateString(),
            ],
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
