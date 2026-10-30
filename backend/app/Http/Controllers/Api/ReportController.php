<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\Statistic;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\OneCReportService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /**
     * Экспорт отчета по продажам в Excel в методологии и структуре системы «1С:Предприятие».
     * 7 листов: Реестр продаж, По номенклатуре, Категории и виды, Динамика по дням, Журнал чеков, Выработка сотрудников, Справка об учете.
     * Не содержит вымышленных остатков склада (складской учет пока не ведется).
     */
    public function salesExcel(Request $request, OneCReportService $reportService): StreamedResponse
    {
        $fileName = '1C-sales-report-'.now()->timezone('Asia/Almaty')->format('Y-m-d-His').'.xlsx';

        $spreadsheet = $reportService->generateSpreadsheet($request);

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

        \App\Services\AnalyticsCacheService::invalidate();

        return response()->json(['message' => 'Statistics rebuilt.', 'days' => $rows->count()]);
    }

    /**
     * Основной аналитический метод для панели «Отчёты».
     * Строго опирается на фактические продажи. Кэширует частые агрегаты и мгновенно сбрасывает кэш при новых продажах.
     */
    public function analytics(Request $request)
    {
        $cacheParams = [
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'order_type' => $request->input('order_type'),
            'is_on_order' => $request->input('is_on_order'),
            'category_id' => $request->input('category_id'),
            'type' => $request->input('type'),
            'size' => $request->input('size'),
            'search' => $request->input('search'),
        ];

        $data = \App\Services\AnalyticsCacheService::remember($cacheParams, function () use ($request) {
            return $this->computeAnalyticsData($request);
        });

        return response()->json($data);
    }

    private function computeAnalyticsData(Request $request): array
    {
        $dateFromStr = $request->filled('date_from') ? $request->string('date_from')->toString() : null;
        $dateToStr = $request->filled('date_to') ? $request->string('date_to')->toString() : null;

        $dateFrom = $dateFromStr ? Carbon::parse($dateFromStr)->startOfDay() : null;
        $dateTo = $dateToStr ? Carbon::parse($dateToStr)->endOfDay() : null;

        $orderType = $request->input('order_type'); // 'in_stock' | 'on_order' | null
        if (! $orderType && $request->has('is_on_order') && $request->input('is_on_order') !== null && $request->input('is_on_order') !== '') {
            $orderType = filter_var($request->input('is_on_order'), FILTER_VALIDATE_BOOLEAN) ? 'on_order' : 'in_stock';
        }

        $categoryId = $request->filled('category_id') ? (int) $request->input('category_id') : null;
        $searchTerm = $request->filled('search') ? trim($request->string('search')->toString()) : null;
        $typeFilter = $request->filled('type') ? trim($request->string('type')->toString()) : null;
        $sizeFilter = $request->filled('size') ? trim($request->string('size')->toString()) : null;

        // 1. Построение запроса к позициям продаж (sale_items)
        $salesQuery = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'sale_items.product_variant_id')
            ->leftJoin('users as cashiers', 'cashiers.id', '=', 'sales.cashier_id')
            ->where('sales.status', 'approved');

        if ($dateFrom) {
            $salesQuery->where(function ($q) use ($dateFrom) {
                $q->where('sales.approved_at', '>=', $dateFrom->toIso8601String())
                  ->orWhere('sales.approved_at', '>=', $dateFrom->toDateTimeString());
            });
        }
        if ($dateTo) {
            $salesQuery->where(function ($q) use ($dateTo) {
                $q->where('sales.approved_at', '<=', $dateTo->toIso8601String())
                  ->orWhere('sales.approved_at', '<=', $dateTo->toDateTimeString());
            });
        }

        if ($orderType === 'on_order') {
            $salesQuery->where(function ($q) {
                $q->where('sales.is_on_order', true)
                  ->orWhere('sale_items.is_on_order', true);
            });
        } elseif ($orderType === 'in_stock') {
            $salesQuery->where(function ($q) {
                $q->where(function ($sq) {
                    $sq->whereNull('sales.is_on_order')->orWhere('sales.is_on_order', false);
                })->where(function ($sq) {
                    $sq->whereNull('sale_items.is_on_order')->orWhere('sale_items.is_on_order', false);
                });
            });
        }

        if ($categoryId) {
            $salesQuery->where('products.category_id', $categoryId);
        }

        if ($sizeFilter) {
            $salesQuery->where('sale_items.variant_size', $sizeFilter);
        }

        if ($typeFilter) {
            $salesQuery->where(function ($q) use ($typeFilter) {
                $q->where('sale_items.product_type', 'like', "%{$typeFilter}%")
                  ->orWhere('products.type', 'like', "%{$typeFilter}%")
                  ->orWhere('sale_items.product_name', 'like', "%{$typeFilter}%")
                  ->orWhere('products.name', 'like', "%{$typeFilter}%");
            });
        }

        if ($searchTerm) {
            $salesQuery->where(function ($q) use ($searchTerm) {
                $q->where('sale_items.product_name', 'like', "%{$searchTerm}%")
                  ->orWhere('products.name', 'like', "%{$searchTerm}%")
                  ->orWhere('sale_items.product_type', 'like', "%{$searchTerm}%")
                  ->orWhere('products.type', 'like', "%{$searchTerm}%")
                  ->orWhere('sale_items.variant_size', 'like', "%{$searchTerm}%")
                  ->orWhere('sale_items.product_sku', 'like', "%{$searchTerm}%");
            });
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
            'sale_items.is_on_order as item_is_on_order',
            'sale_items.purchase_price',
            'sale_items.sale_price',
            'sale_items.line_total',
            'sale_items.line_profit',
            'sales.is_on_order as sale_is_on_order',
            'sales.approved_at',
            'sales.cashier_id',
            'cashiers.name as cashier_name',
            'products.name as live_product_name',
            'products.type as live_product_type',
            'products.color as live_product_color',
            'products.category_id',
            'categories.name as live_category_name',
        ]);

        // Сводные KPI
        $totalRevenue = (float) $saleItems->sum('line_total');
        $totalProfit = (float) $saleItems->sum('line_profit');
        $totalSoldUnits = (int) $saleItems->sum('quantity');
        $uniqueSalesCount = (int) $saleItems->pluck('sale_id')->unique()->count();
        $averageCheck = $uniqueSalesCount > 0 ? round($totalRevenue / $uniqueSalesCount, 2) : 0;

        $inStockUnits = 0;
        $onOrderUnits = 0;
        $inStockRevenue = 0.0;
        $onOrderRevenue = 0.0;

        foreach ($saleItems as $it) {
            $isOnOrder = $it->item_is_on_order !== null ? (bool) $it->item_is_on_order : (bool) $it->sale_is_on_order;
            $qty = (int) $it->quantity;
            $tot = (float) $it->line_total;

            if ($isOnOrder) {
                $onOrderUnits += $qty;
                $onOrderRevenue += $tot;
            } else {
                $inStockUnits += $qty;
                $inStockRevenue += $tot;
            }
        }

        // ========================================================
        // 2. СРАВНЕНИЕ С ПРЕДЫДУЩИМ АНАЛОГИЧНЫМ ПЕРИОДОМ
        // ========================================================
        $comparison = [
            'has_previous_period' => false,
            'prev_revenue' => 0.0,
            'prev_sold_units' => 0,
            'prev_sales_count' => 0,
            'revenue_diff' => 0.0,
            'revenue_diff_percent' => null,
            'units_diff' => 0,
            'units_diff_percent' => null,
            'period_label' => '',
        ];

        if ($dateFrom) {
            $effectiveDateTo = $dateTo ?: now();
            $durationDays = max(1, $dateFrom->diffInDays($effectiveDateTo) + 1);

            $prevPeriodTo = (clone $dateFrom)->subSecond();
            $prevPeriodFrom = (clone $prevPeriodTo)->subDays($durationDays - 1)->startOfDay();

            $prevSalesQuery = DB::table('sale_items')
                ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
                ->where('sales.status', 'approved')
                ->where(function ($q) use ($prevPeriodFrom, $prevPeriodTo) {
                    $q->whereBetween('sales.approved_at', [$prevPeriodFrom->toIso8601String(), $prevPeriodTo->toIso8601String()])
                      ->orWhereBetween('sales.approved_at', [$prevPeriodFrom->toDateTimeString(), $prevPeriodTo->toDateTimeString()]);
                });

            if ($orderType === 'on_order') {
                $prevSalesQuery->where(function ($q) {
                    $q->where('sales.is_on_order', true)->orWhere('sale_items.is_on_order', true);
                });
            } elseif ($orderType === 'in_stock') {
                $prevSalesQuery->where(function ($q) {
                    $q->where(function ($sq) {
                        $sq->whereNull('sales.is_on_order')->orWhere('sales.is_on_order', false);
                    })->where(function ($sq) {
                        $sq->whereNull('sale_items.is_on_order')->orWhere('sale_items.is_on_order', false);
                    });
                });
            }

            if ($categoryId) {
                $prevSalesQuery->where('products.category_id', $categoryId);
            }

            $prevRevenue = (float) $prevSalesQuery->sum('sale_items.line_total');
            $prevSoldUnits = (int) $prevSalesQuery->sum('sale_items.quantity');
            $prevSalesCount = (int) $prevSalesQuery->pluck('sales.id')->unique()->count();

            $revDiff = round($totalRevenue - $prevRevenue, 2);
            $revDiffPct = $prevRevenue > 0 ? round(($revDiff / $prevRevenue) * 100, 1) : null;

            $unitsDiff = $totalSoldUnits - $prevSoldUnits;
            $unitsDiffPct = $prevSoldUnits > 0 ? round(($unitsDiff / $prevSoldUnits) * 100, 1) : null;

            $comparison = [
                'has_previous_period' => true,
                'prev_revenue' => $prevRevenue,
                'prev_sold_units' => $prevSoldUnits,
                'prev_sales_count' => $prevSalesCount,
                'revenue_diff' => $revDiff,
                'revenue_diff_percent' => $revDiffPct,
                'units_diff' => $unitsDiff,
                'units_diff_percent' => $unitsDiffPct,
                'period_label' => $prevPeriodFrom->format('d.m') . ' — ' . $prevPeriodTo->format('d.m'),
            ];
        }

        // ========================================================
        // 3. ДИНАМИКА ПРОДАЖ ПО ДНЯМ ДЛЯ ГРАФИКА И АНАЛИТИКИ
        // ========================================================
        $dynamicsMap = [];

        // Если задан диапазон дат, инициализируем каждый день нулями для непрерывного ряда
        if ($dateFrom && $dateTo) {
            $cursor = (clone $dateFrom)->startOfDay();
            $limit = (clone $dateTo)->startOfDay();
            while ($cursor->lte($limit)) {
                $dKey = $cursor->format('Y-m-d');
                $dynamicsMap[$dKey] = [
                    'date' => $dKey,
                    'day_label' => $cursor->format('d.m'),
                    'day_name' => match ($cursor->dayOfWeekIso) {
                        1 => 'Пн',
                        2 => 'Вт',
                        3 => 'Ср',
                        4 => 'Чт',
                        5 => 'Пт',
                        6 => 'Сб',
                        7 => 'Вс',
                        default => '',
                    },
                    'revenue' => 0.0,
                    'units' => 0,
                    'in_stock_units' => 0,
                    'on_order_units' => 0,
                    'discount_amount' => 0.0,
                    'sales_count' => 0,
                    'average_check' => 0,
                    '_sale_ids' => [],
                ];
                $cursor->addDay();
            }
        }

        foreach ($saleItems as $it) {
            // Важно: переводим дату и время утверждения продажи в часовой пояс магазина Asia/Almaty (UTC+5),
            // чтобы вечерние продажи чеков не попадали в чужой календарный день
            $approvedTime = Carbon::parse($it->approved_at)->timezone('Asia/Almaty');
            $dKey = $approvedTime->format('Y-m-d');

            if (! isset($dynamicsMap[$dKey])) {
                $dynamicsMap[$dKey] = [
                    'date' => $dKey,
                    'day_label' => $approvedTime->format('d.m'),
                    'day_name' => match ($approvedTime->dayOfWeekIso) {
                        1 => 'Пн',
                        2 => 'Вт',
                        3 => 'Ср',
                        4 => 'Чт',
                        5 => 'Пт',
                        6 => 'Сб',
                        7 => 'Вс',
                        default => '',
                    },
                    'revenue' => 0.0,
                    'units' => 0,
                    'in_stock_units' => 0,
                    'on_order_units' => 0,
                    'discount_amount' => 0.0,
                    'sales_count' => 0,
                    'average_check' => 0,
                    '_sale_ids' => [],
                ];
            }

            $isOnOrder = $it->item_is_on_order !== null ? (bool) $it->item_is_on_order : (bool) $it->sale_is_on_order;
            $qty = (int) $it->quantity;
            $tot = (float) $it->line_total;
            $disc = (float) ($it->discount_amount ?? 0);

            $dynamicsMap[$dKey]['revenue'] += $tot;
            $dynamicsMap[$dKey]['units'] += $qty;
            $dynamicsMap[$dKey]['discount_amount'] += $disc;
            if ($isOnOrder) {
                $dynamicsMap[$dKey]['on_order_units'] += $qty;
            } else {
                $dynamicsMap[$dKey]['in_stock_units'] += $qty;
            }

            $dynamicsMap[$dKey]['_sale_ids'][$it->sale_id] = true;
        }

        ksort($dynamicsMap);

        $salesDynamics = array_values(array_map(function ($day) {
            $cnt = count($day['_sale_ids']);
            $rev = round((float) $day['revenue'], 2);
            $day['sales_count'] = $cnt;
            $day['revenue'] = $rev;
            $day['discount_amount'] = round((float) $day['discount_amount'], 2);
            $day['average_check'] = $cnt > 0 ? round($rev / $cnt) : 0;
            unset($day['_sale_ids']);
            return $day;
        }, $dynamicsMap));

        // Добавляем расчет изменений относительно предыдущего дня
        $prevDay = null;
        foreach ($salesDynamics as &$day) {
            if ($prevDay !== null) {
                $pRev = (float) $prevDay['revenue'];
                $pCnt = (int) $prevDay['sales_count'];
                $revDiff = round($day['revenue'] - $pRev, 2);
                $day['prev_revenue'] = $pRev;
                $day['revenue_diff'] = $revDiff;
                $day['revenue_diff_percent'] = $pRev > 0
                    ? round(($revDiff / $pRev) * 100, 1)
                    : ($day['revenue'] > 0 ? 100.0 : 0.0);
                $day['sales_count_diff'] = $day['sales_count'] - $pCnt;
            } else {
                $day['prev_revenue'] = null;
                $day['revenue_diff'] = 0;
                $day['revenue_diff_percent'] = null;
                $day['sales_count_diff'] = 0;
            }
            $prevDay = $day;
        }
        unset($day);

        // ========================================================
        // 4. ГРУППИРОВКА 1: ПО ВИДАМ И РАЗМЕРАМ (ОСНОВНОЙ ВИД)
        // ========================================================
        $typesMap = [];
        $topSizesMap = [];
        $topProductsMap = [];

        foreach ($saleItems as $item) {
            $prodName = $item->product_name ?: $item->live_product_name ?: 'Товар';
            $t = $this->resolveProductType(
                $item->product_type,
                $item->live_product_type,
                $prodName,
                $item->live_category_name
            );

            $sz = trim((string) ($item->variant_size ?: 'Без размера'));
            if ($sz === '' || $sz === '-' || $sz === '—') {
                $sz = 'Без размера';
            }

            $qty = (int) $item->quantity;
            $lineTotal = (float) $item->line_total;
            $lineProfit = (float) $item->line_profit;
            $isOnOrder = $item->item_is_on_order !== null ? (bool) $item->item_is_on_order : (bool) $item->sale_is_on_order;

            // Накопление по виду
            if (! isset($typesMap[$t])) {
                $typesMap[$t] = [
                    'type' => $t,
                    'sold_quantity' => 0,
                    'in_stock_sold' => 0,
                    'on_order_sold' => 0,
                    'revenue' => 0.0,
                    'in_stock_revenue' => 0.0,
                    'on_order_revenue' => 0.0,
                    'profit' => 0.0,
                    'sizes_map' => [],
                ];
            }

            $typesMap[$t]['sold_quantity'] += $qty;
            $typesMap[$t]['revenue'] += $lineTotal;
            $typesMap[$t]['profit'] += $lineProfit;

            if ($isOnOrder) {
                $typesMap[$t]['on_order_sold'] += $qty;
                $typesMap[$t]['on_order_revenue'] += $lineTotal;
            } else {
                $typesMap[$t]['in_stock_sold'] += $qty;
                $typesMap[$t]['in_stock_revenue'] += $lineTotal;
            }

            // Накопление по размеру внутри вида
            if (! isset($typesMap[$t]['sizes_map'][$sz])) {
                $typesMap[$t]['sizes_map'][$sz] = [
                    'size' => $sz,
                    'sold_quantity' => 0,
                    'in_stock_sold' => 0,
                    'on_order_sold' => 0,
                    'revenue' => 0.0,
                    'in_stock_revenue' => 0.0,
                    'on_order_revenue' => 0.0,
                    'profit' => 0.0,
                ];
            }

            $typesMap[$t]['sizes_map'][$sz]['sold_quantity'] += $qty;
            $typesMap[$t]['sizes_map'][$sz]['revenue'] += $lineTotal;
            $typesMap[$t]['sizes_map'][$sz]['profit'] += $lineProfit;

            if ($isOnOrder) {
                $typesMap[$t]['sizes_map'][$sz]['on_order_sold'] += $qty;
                $typesMap[$t]['sizes_map'][$sz]['on_order_revenue'] += $lineTotal;
            } else {
                $typesMap[$t]['sizes_map'][$sz]['in_stock_sold'] += $qty;
                $typesMap[$t]['sizes_map'][$sz]['in_stock_revenue'] += $lineTotal;
            }

            // Топ размеров глобально
            if (! isset($topSizesMap[$sz])) {
                $topSizesMap[$sz] = ['size' => $sz, 'sold_quantity' => 0, 'revenue' => 0.0];
            }
            $topSizesMap[$sz]['sold_quantity'] += $qty;
            $topSizesMap[$sz]['revenue'] += $lineTotal;

            // Топ товаров
            $pKey = $item->product_id ?: $prodName;
            if (! isset($topProductsMap[$pKey])) {
                $topProductsMap[$pKey] = [
                    'product_id' => $item->product_id,
                    'name' => $prodName,
                    'type' => $t,
                    'sold_quantity' => 0,
                    'revenue' => 0.0,
                ];
            }
            $topProductsMap[$pKey]['sold_quantity'] += $qty;
            $topProductsMap[$pKey]['revenue'] += $lineTotal;
        }

        // Преобразование типов в итоговый список
        $byType = collect($typesMap)->map(function ($item) use ($totalRevenue, $totalSoldUnits) {
            $item['revenue'] = round((float) $item['revenue'], 2);
            $item['in_stock_revenue'] = round((float) $item['in_stock_revenue'], 2);
            $item['on_order_revenue'] = round((float) $item['on_order_revenue'], 2);
            $item['profit'] = round((float) $item['profit'], 2);
            $item['revenue_percent'] = $totalRevenue > 0 ? round(($item['revenue'] / $totalRevenue) * 100, 1) : 0;
            $item['units_percent'] = $totalSoldUnits > 0 ? round(($item['sold_quantity'] / $totalSoldUnits) * 100, 1) : 0;

            $typeSoldTotal = $item['sold_quantity'];

            $sizesList = collect($item['sizes_map'])->map(function ($s) use ($typeSoldTotal) {
                $s['revenue'] = round((float) $s['revenue'], 2);
                $s['in_stock_revenue'] = round((float) $s['in_stock_revenue'], 2);
                $s['on_order_revenue'] = round((float) $s['on_order_revenue'], 2);
                $s['profit'] = round((float) $s['profit'], 2);
                $s['average_price'] = $s['sold_quantity'] > 0 ? round($s['revenue'] / $s['sold_quantity'], 2) : 0;
                $s['share_in_type'] = $typeSoldTotal > 0 ? round(($s['sold_quantity'] / $typeSoldTotal) * 100, 1) : 0;

                return $s;
            })->values()->all();

            unset($item['sizes_map']);
            $item['sizes'] = $this->sortSizes($sizesList);

            return $item;
        })->sortByDesc('sold_quantity')->values()->all();

        // ========================================================
        // 5. ГРУППИРОВКА 2: ПО КАТЕГОРИЯМ
        // ========================================================
        $categoriesMap = [];
        foreach ($saleItems as $item) {
            $catId = (int) ($item->category_id ?: 0);
            $catName = trim((string) ($item->live_category_name ?: 'Без категории'));

            if (! isset($categoriesMap[$catId])) {
                $categoriesMap[$catId] = [
                    'category_id' => $catId,
                    'category_name' => $catName,
                    'sold_quantity' => 0,
                    'in_stock_sold' => 0,
                    'on_order_sold' => 0,
                    'revenue' => 0.0,
                    'in_stock_revenue' => 0.0,
                    'on_order_revenue' => 0.0,
                    'profit' => 0.0,
                ];
            }

            $qty = (int) $item->quantity;
            $lineTotal = (float) $item->line_total;
            $lineProfit = (float) $item->line_profit;
            $isOnOrder = $item->item_is_on_order !== null ? (bool) $item->item_is_on_order : (bool) $item->sale_is_on_order;

            $categoriesMap[$catId]['sold_quantity'] += $qty;
            $categoriesMap[$catId]['revenue'] += $lineTotal;
            $categoriesMap[$catId]['profit'] += $lineProfit;

            if ($isOnOrder) {
                $categoriesMap[$catId]['on_order_sold'] += $qty;
                $categoriesMap[$catId]['on_order_revenue'] += $lineTotal;
            } else {
                $categoriesMap[$catId]['in_stock_sold'] += $qty;
                $categoriesMap[$catId]['in_stock_revenue'] += $lineTotal;
            }
        }

        $byCategory = collect($categoriesMap)->map(function ($item) use ($totalRevenue, $totalSoldUnits) {
            $item['revenue'] = round((float) $item['revenue'], 2);
            $item['in_stock_revenue'] = round((float) $item['in_stock_revenue'], 2);
            $item['on_order_revenue'] = round((float) $item['on_order_revenue'], 2);
            $item['profit'] = round((float) $item['profit'], 2);
            $item['revenue_percent'] = $totalRevenue > 0 ? round(($item['revenue'] / $totalRevenue) * 100, 1) : 0;
            $item['units_percent'] = $totalSoldUnits > 0 ? round(($item['sold_quantity'] / $totalSoldUnits) * 100, 1) : 0;

            return $item;
        })->sortByDesc('sold_quantity')->values()->all();

        // ========================================================
        // 6. ГРУППИРОВКА 3: ПО НАИМЕНОВАНИЯМ ТОВАРОВ И МОДЕЛЕЙ
        // ========================================================
        $productsGrouped = [];
        foreach ($saleItems as $item) {
            $prodId = $item->product_id ?: 0;
            $prodName = $item->product_name ?: $item->live_product_name ?: 'Товар #' . $prodId;
            $pKey = $prodId ? 'id_' . $prodId : 'name_' . $prodName;

            $pType = $this->resolveProductType(
                $item->product_type,
                $item->live_product_type,
                $prodName,
                $item->live_category_name
            );

            $sz = trim((string) ($item->variant_size ?: 'Без размера'));
            if ($sz === '' || $sz === '-' || $sz === '—') {
                $sz = 'Без размера';
            }

            $qty = (int) $item->quantity;
            $lineTotal = (float) $item->line_total;
            $lineProfit = (float) $item->line_profit;
            $isOnOrder = $item->item_is_on_order !== null ? (bool) $item->item_is_on_order : (bool) $item->sale_is_on_order;

            if (! isset($productsGrouped[$pKey])) {
                $productsGrouped[$pKey] = [
                    'product_id' => $prodId ?: null,
                    'name' => $prodName,
                    'type' => $pType,
                    'category_name' => $item->live_category_name ?: 'Без категории',
                    'color' => $item->live_product_color,
                    'sold_quantity' => 0,
                    'in_stock_sold' => 0,
                    'on_order_sold' => 0,
                    'revenue' => 0.0,
                    'in_stock_revenue' => 0.0,
                    'on_order_revenue' => 0.0,
                    'profit' => 0.0,
                    'sizes_map' => [],
                ];
            }

            $productsGrouped[$pKey]['sold_quantity'] += $qty;
            $productsGrouped[$pKey]['revenue'] += $lineTotal;
            $productsGrouped[$pKey]['profit'] += $lineProfit;

            if ($isOnOrder) {
                $productsGrouped[$pKey]['on_order_sold'] += $qty;
                $productsGrouped[$pKey]['on_order_revenue'] += $lineTotal;
            } else {
                $productsGrouped[$pKey]['in_stock_sold'] += $qty;
                $productsGrouped[$pKey]['in_stock_revenue'] += $lineTotal;
            }

            if (! isset($productsGrouped[$pKey]['sizes_map'][$sz])) {
                $productsGrouped[$pKey]['sizes_map'][$sz] = [
                    'size' => $sz,
                    'sold_quantity' => 0,
                    'in_stock_sold' => 0,
                    'on_order_sold' => 0,
                    'revenue' => 0.0,
                    'in_stock_revenue' => 0.0,
                    'on_order_revenue' => 0.0,
                ];
            }

            $productsGrouped[$pKey]['sizes_map'][$sz]['sold_quantity'] += $qty;
            $productsGrouped[$pKey]['sizes_map'][$sz]['revenue'] += $lineTotal;

            if ($isOnOrder) {
                $productsGrouped[$pKey]['sizes_map'][$sz]['on_order_sold'] += $qty;
                $productsGrouped[$pKey]['sizes_map'][$sz]['on_order_revenue'] += $lineTotal;
            } else {
                $productsGrouped[$pKey]['sizes_map'][$sz]['in_stock_sold'] += $qty;
                $productsGrouped[$pKey]['sizes_map'][$sz]['in_stock_revenue'] += $lineTotal;
            }
        }

        $byProduct = collect($productsGrouped)->map(function ($p) use ($totalRevenue) {
            $p['revenue'] = round((float) $p['revenue'], 2);
            $p['in_stock_revenue'] = round((float) $p['in_stock_revenue'], 2);
            $p['on_order_revenue'] = round((float) $p['on_order_revenue'], 2);
            $p['profit'] = round((float) $p['profit'], 2);
            $p['revenue_percent'] = $totalRevenue > 0 ? round(($p['revenue'] / $totalRevenue) * 100, 1) : 0;

            $sizes = collect($p['sizes_map'])->map(function ($sz) {
                $sz['revenue'] = round((float) $sz['revenue'], 2);
                $sz['in_stock_revenue'] = round((float) $sz['in_stock_revenue'], 2);
                $sz['on_order_revenue'] = round((float) $sz['on_order_revenue'], 2);
                $sz['average_price'] = $sz['sold_quantity'] > 0 ? round($sz['revenue'] / $sz['sold_quantity'], 2) : 0;
                return $sz;
            })->values()->all();

            unset($p['sizes_map']);
            $p['sizes'] = $this->sortSizes($sizes);

            return $p;
        })->sortByDesc('sold_quantity')->values()->all();

        // ========================================================
        // 7. ГРУППИРОВКА 4: ПО КАССИРАМ И СМЕНАМ СОТРУДНИКОВ
        // ========================================================
        $cashiersMap = [];
        foreach ($saleItems as $item) {
            $cId = (int) ($item->cashier_id ?: 0);
            $cName = trim((string) ($item->cashier_name ?: 'Без кассира'));
            $sId = $item->sale_id;

            if (! isset($cashiersMap[$cId])) {
                $cashiersMap[$cId] = [
                    'cashier_id' => $cId ?: null,
                    'cashier_name' => $cName,
                    'sales_ids' => [],
                    'sold_quantity' => 0,
                    'in_stock_sold' => 0,
                    'on_order_sold' => 0,
                    'revenue' => 0.0,
                    'in_stock_revenue' => 0.0,
                    'on_order_revenue' => 0.0,
                    'profit' => 0.0,
                ];
            }

            $cashiersMap[$cId]['sales_ids'][$sId] = true;
            $qty = (int) $item->quantity;
            $lineTotal = (float) $item->line_total;
            $lineProfit = (float) $item->line_profit;
            $isOnOrder = $item->item_is_on_order !== null ? (bool) $item->item_is_on_order : (bool) $item->sale_is_on_order;

            $cashiersMap[$cId]['sold_quantity'] += $qty;
            $cashiersMap[$cId]['revenue'] += $lineTotal;
            $cashiersMap[$cId]['profit'] += $lineProfit;

            if ($isOnOrder) {
                $cashiersMap[$cId]['on_order_sold'] += $qty;
                $cashiersMap[$cId]['on_order_revenue'] += $lineTotal;
            } else {
                $cashiersMap[$cId]['in_stock_sold'] += $qty;
                $cashiersMap[$cId]['in_stock_revenue'] += $lineTotal;
            }
        }

        $byCashier = collect($cashiersMap)->map(function ($c) use ($totalRevenue, $totalSoldUnits) {
            $salesCount = count($c['sales_ids']);
            $revenue = round((float) $c['revenue'], 2);
            $c['sales_count'] = $salesCount;
            $c['average_check'] = $salesCount > 0 ? round($revenue / $salesCount, 2) : 0;
            $c['revenue'] = $revenue;
            $c['in_stock_revenue'] = round((float) $c['in_stock_revenue'], 2);
            $c['on_order_revenue'] = round((float) $c['on_order_revenue'], 2);
            $c['profit'] = round((float) $c['profit'], 2);
            $c['revenue_percent'] = $totalRevenue > 0 ? round(($revenue / $totalRevenue) * 100, 1) : 0;
            $c['units_percent'] = $totalSoldUnits > 0 ? round(($c['sold_quantity'] / $totalSoldUnits) * 100, 1) : 0;
            unset($c['sales_ids']);

            return $c;
        })->sortByDesc('revenue')->values()->all();

        // ТОП-5 товаров и ТОП-5 размеров
        $topProducts = collect($topProductsMap)
            ->sortByDesc('sold_quantity')
            ->take(5)
            ->values()
            ->all();

        $topSizes = collect($topSizesMap)
            ->sortByDesc('sold_quantity')
            ->take(5)
            ->values()
            ->all();

        // Справочники всех существующих видов и размеров для быстрых чип-фильтров (как в Excel)
        $knownApparelTypes = [
            'Поло', 'Брюки', 'Юбка', 'Рубашка', 'Жилет', 'Пиджак', 'Сарафан', 'Блузка',
            'Шорты', 'Платье', 'Галстук', 'Фартук', 'Костюм', 'Спортивная форма', 'Кроссовки', 'Обувь', 'Шапки'
        ];
        $dbTypes = DB::table('sale_items')->whereNotNull('product_type')->where('product_type', '!=', '')->distinct()->pluck('product_type');
        $prodTypes = DB::table('products')->whereNotNull('type')->where('type', '!=', '')->distinct()->pluck('type');
        $availableTypes = collect($knownApparelTypes)
            ->merge($dbTypes)
            ->merge($prodTypes)
            ->map(fn ($t) => trim((string) $t))
            ->filter(fn ($t) => $t !== '' && $t !== '—' && $t !== 'Без вида')
            ->unique()
            ->values()
            ->all();

        $dbSizes = DB::table('sale_items')->whereNotNull('variant_size')->where('variant_size', '!=', '')->distinct()->pluck('variant_size');
        $varSizes = DB::table('product_variants')->whereNotNull('size')->where('size', '!=', '')->distinct()->pluck('size');
        $rawSizes = $dbSizes->merge($varSizes)
            ->map(fn ($s) => trim((string) $s))
            ->filter(fn ($s) => $s !== '' && $s !== '—' && $s !== '-')
            ->unique()
            ->map(fn ($s) => ['size' => $s])
            ->values()
            ->all();
        $sortedSizes = $this->sortSizes($rawSizes);
        $availableSizes = array_map(fn ($s) => $s['size'], $sortedSizes);

        return [
            'summary' => [
                'total_sold_items' => $totalSoldUnits,
                'in_stock_units' => $inStockUnits,
                'on_order_units' => $onOrderUnits,
                'total_revenue' => round($totalRevenue, 2),
                'in_stock_revenue' => round($inStockRevenue, 2),
                'on_order_revenue' => round($onOrderRevenue, 2),
                'total_profit' => round($totalProfit, 2),
                'sales_count' => $uniqueSalesCount,
                'average_check' => $averageCheck,
                'comparison' => $comparison,
            ],
            'sales_dynamics' => $salesDynamics,
            'top_products' => $topProducts,
            'top_sizes' => $topSizes,
            'available_types' => $availableTypes,
            'available_sizes' => $availableSizes,
            'by_type' => $byType,
            'by_category' => $byCategory,
            'by_product' => $byProduct,
            'by_cashier' => $byCashier,
            'filters' => [
                'date_from' => $dateFrom?->toDateString(),
                'date_to' => $dateTo?->toDateString(),
                'order_type' => $orderType ?: '',
                'type' => $typeFilter,
                'size' => $sizeFilter,
                'category_id' => $categoryId,
                'search' => $searchTerm,
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Надежно определяет вид товара детской одежды / формы, если поле type не заполнено явно.
     */
    private function resolveProductType(?string $itemType, ?string $productType, ?string $productName, ?string $categoryName): string
    {
        $itemType = trim((string) $itemType);
        if ($itemType !== '' && $itemType !== '—' && $itemType !== '-') {
            return $itemType;
        }

        $productType = trim((string) $productType);
        if ($productType !== '' && $productType !== '—' && $productType !== '-') {
            return $productType;
        }

        $name = trim((string) $productName);
        if ($name !== '') {
            if (mb_stripos($name, 'шапк') !== false) {
                return 'Шапки';
            }
            $knownTypes = [
                'Поло', 'Футболка', 'Брюки', 'Юбка', 'Жилет', 'Пиджак', 'Жакет',
                'Рубашка', 'Блузка', 'Сарафан', 'Шорты', 'Платье', 'Галстук',
                'Фартук', 'Костюм', 'Свитшот', 'Худи', 'Толстовка', 'Бомбер',
                'Кардиган', 'Водолазка', 'Куртка', 'Ветровка', 'Кроссовки',
                'Туфли', 'Обувь', 'Ремень', 'Носки', 'Колготки', 'Банты', 'Шеврон', 'Форма', 'Шапки'
            ];
            foreach ($knownTypes as $kt) {
                if (mb_stripos($name, $kt) !== false) {
                    return $kt;
                }
            }

            // Если название короткое (1-2 слова), используем его как вид
            $parts = preg_split('/\s+/u', $name);
            if (count($parts) <= 2) {
                return $name;
            }
        }

        $cat = trim((string) $categoryName);
        if ($cat !== '' && mb_stripos($cat, 'категори') === false) {
            return $cat;
        }

        return $name ?: 'Без вида';
    }

    /**
     * Сортирует список размеров в естественном порядке (XXS, XS, S, M, L... или по числовому росту: 110, 116, 122...).
     */
    private function sortSizes(array $sizes): array
    {
        $order = [
            'xxs' => 1, 'xs' => 2, 's' => 3, 'm' => 4, 'l' => 5,
            'xl' => 6, '2xl' => 7, 'xxl' => 7, '3xl' => 8, 'xxxl' => 8,
            '4xl' => 9, '5xl' => 10, 'one size' => 99, 'универсальный' => 99, 'без размера' => 100,
        ];

        usort($sizes, function ($a, $b) use ($order) {
            $sa = mb_strtolower(trim($a['size'] ?? ''));
            $sb = mb_strtolower(trim($b['size'] ?? ''));

            $oa = $order[$sa] ?? null;
            $ob = $order[$sb] ?? null;

            if ($oa !== null && $ob !== null) {
                return $oa <=> $ob;
            }

            // Извлечение первого числа для размеров типа "140-146" или "142"
            $numA = null;
            $numB = null;
            if (preg_match('/^(\d+)/', $sa, $mA)) {
                $numA = (float) $mA[1];
            }
            if (preg_match('/^(\d+)/', $sb, $mB)) {
                $numB = (float) $mB[1];
            }

            if ($numA !== null && $numB !== null) {
                return $numA <=> $numB;
            }

            return strnatcasecmp($sa, $sb);
        });

        return $sizes;
    }

    /**
     * Проверка, относится ли позиция к виду «Шапки» по типу или наименованию.
     */
    private function isHatTypeOrName(?string $type, ?string $name): bool
    {
        $t = mb_strtolower(trim((string) $type));
        $n = mb_strtolower(trim((string) $name));

        return $t === 'шапки' || $t === 'шапка' || mb_strpos($t, 'шапк') !== false || mb_strpos($n, 'шапк') !== false;
    }

    /**
     * Детализация продаж и состава чеков за конкретный день (Day Drill-down).
     */
    public function dayDetails(Request $request): JsonResponse
    {
        $dateStr = $request->string('date')->toString();
        if (! $dateStr) {
            return response()->json(['error' => 'Укажите дату в формате YYYY-MM-DD'], 400);
        }

        $start = Carbon::parse($dateStr, 'Asia/Almaty')->startOfDay();
        $end = Carbon::parse($dateStr, 'Asia/Almaty')->endOfDay();
        $startUtc = $start->copy()->utc();
        $endUtc = $end->copy()->utc();

        $sales = Sale::query()
            ->where('status', 'approved')
            ->where(function ($q) use ($startUtc, $endUtc) {
                $q->whereBetween('approved_at', [$startUtc->toIso8601String(), $endUtc->toIso8601String()])
                  ->orWhereBetween('approved_at', [$startUtc->toDateTimeString(), $endUtc->toDateTimeString()]);
            })
            ->with(['items.product.category', 'cashier'])
            ->orderBy('approved_at')
            ->get();

        $totalRevenue = 0.0;
        $totalUnits = 0;
        $inStockUnits = 0;
        $onOrderUnits = 0;
        $itemsMap = [];

        $checks = $sales->map(function ($s) use (&$totalRevenue, &$totalUnits, &$inStockUnits, &$onOrderUnits, &$itemsMap) {
            $totalRevenue += (float) $s->subtotal;
            $checkItems = $s->items->map(function ($it) use ($s, &$totalUnits, &$inStockUnits, &$onOrderUnits, &$itemsMap) {
                $qty = (int) $it->quantity;
                $totalUnits += $qty;
                $isOnOrder = $it->is_on_order !== null ? (bool) $it->is_on_order : (bool) $s->is_on_order;
                if ($isOnOrder) {
                    $onOrderUnits += $qty;
                } else {
                    $inStockUnits += $qty;
                }

                $name = $it->product_name ?: $it->product?->name ?: 'Товар';
                $type = $it->product_type ?: $it->product?->type ?: 'Товар';
                $isHat = $this->isHatTypeOrName($type, $name);
                $size = ($it->variant_size && $it->variant_size !== '—' && ! $isHat) ? $it->variant_size : '—';
                $key = "{$name}___{$size}";

                if (! isset($itemsMap[$key])) {
                    $itemsMap[$key] = [
                        'name' => $name,
                        'type' => $type,
                        'size' => $size,
                        'quantity' => 0,
                        'revenue' => 0.0,
                    ];
                }
                $itemsMap[$key]['quantity'] += $qty;
                $itemsMap[$key]['revenue'] += (float) $it->line_total;

                return [
                    'id' => $it->id,
                    'name' => $name,
                    'type' => $type,
                    'size' => $size,
                    'quantity' => $qty,
                    'sale_price' => (float) $it->sale_price,
                    'line_total' => (float) $it->line_total,
                ];
            });

            return [
                'id' => $s->id,
                'number' => $s->number,
                'time' => Carbon::parse($s->approved_at)->timezone('Asia/Almaty')->format('H:i'),
                'is_on_order' => (bool) $s->is_on_order,
                'subtotal' => (float) $s->subtotal,
                'discount_percent' => (float) ($s->discount_percent ?? 0),
                'cashier_name' => $s->cashier?->name ?? '—',
                'cashier_note' => $s->cashier_note,
                'items' => $checkItems,
            ];
        });

        $sortedItems = array_values($itemsMap);
        usort($sortedItems, fn ($a, $b) => $b['quantity'] <=> $a['quantity']);

        return response()->json([
            'date' => $dateStr,
            'summary' => [
                'sales_count' => $sales->count(),
                'revenue' => round($totalRevenue, 2),
                'total_units' => $totalUnits,
                'in_stock_units' => $inStockUnits,
                'on_order_units' => $onOrderUnits,
                'average_check' => $sales->count() > 0 ? round($totalRevenue / $sales->count()) : 0,
            ],
            'checks' => $checks,
            'top_items' => $sortedItems,
        ]);
    }
}
