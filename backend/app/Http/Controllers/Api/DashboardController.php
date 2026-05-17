<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    private const CHART_DAYS = 14;

    public function __invoke()
    {
        $range = $this->chartRange();
        $chartStartUtc = $range['start_utc'];
        $approved = Sale::query()->where('status', 'approved');

        $summary = [
            'total_profit' => (float) (clone $approved)->sum('profit'),
            'total_revenue' => (float) (clone $approved)->sum('subtotal'),
            'approved_sales' => (int) (clone $approved)->count(),
            'pending_sales' => (int) Sale::query()->where('status', 'pending')->count(),
            'today_sales' => (int) (clone $approved)->whereDate('approved_at', today())->count(),
            'today_revenue' => (float) (clone $approved)->whereDate('approved_at', today())->sum('subtotal'),
            'today_profit' => (float) (clone $approved)->whereDate('approved_at', today())->sum('profit'),
            'today_items_sold' => $this->todayItemsSold(),
            'week_revenue' => (float) (clone $approved)->whereBetween('approved_at', [now()->startOfWeek(), now()->endOfWeek()])->sum('subtotal'),
            'month_revenue' => (float) (clone $approved)->whereMonth('approved_at', now()->month)->whereYear('approved_at', now()->year)->sum('subtotal'),
            'products' => (int) Product::query()->where('status', '!=', 'archived')->count(),
            'low_stock' => (int) Product::query()->whereRaw('(stock_quantity + display_quantity) <= low_stock_threshold')->count(),
            'stock_units' => (int) Product::query()->sum('stock_quantity'),
            'display_units' => (int) Product::query()->sum('display_quantity'),
        ];

        $salesByDay = $this->aggregateSalesByLocalDay($chartStartUtc);
        $itemsByDay = $this->aggregateItemsByLocalDay($chartStartUtc);

        $topProducts = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.status', 'approved')
            ->where('sales.approved_at', '>=', $chartStartUtc)
            ->groupBy('products.id', 'products.name', 'products.sku', 'products.photo_path')
            ->orderByDesc(DB::raw('SUM(sale_items.quantity)'))
            ->limit(10)
            ->get([
                'products.id',
                'products.name',
                'products.sku',
                'products.photo_path',
                DB::raw('SUM(sale_items.quantity) as quantity'),
                DB::raw('SUM(sale_items.line_total) as revenue'),
                DB::raw('SUM(sale_items.line_profit) as profit'),
            ])
            ->map(function ($row) {
                $row->photo_url = $row->photo_path
                    ? \Illuminate\Support\Facades\Storage::url($row->photo_path)
                    : null;

                return $row;
            });

        $categoryRevenue = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('sales.status', 'approved')
            ->where('sales.approved_at', '>=', $chartStartUtc)
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc(DB::raw('SUM(sale_items.line_total)'))
            ->limit(8)
            ->get([
                DB::raw('COALESCE(categories.name, "Без категории") as name'),
                DB::raw('SUM(sale_items.line_total) as revenue'),
                DB::raw('SUM(sale_items.line_profit) as profit'),
                DB::raw('SUM(sale_items.quantity) as quantity'),
            ]);

        $cashierActivity = User::query()
            ->where('role', 'cashier')
            ->withCount([
                'sales as pending_sales_count' => fn ($q) => $q->where('status', 'pending'),
                'sales as approved_sales_count' => fn ($q) => $q->where('status', 'approved'),
            ])
            ->orderByDesc('last_login_at')
            ->limit(8)
            ->get(['id', 'name', 'email', 'last_login_at', 'is_active']);

        return response()->json([
            'summary' => $summary,
            'chart_days' => self::CHART_DAYS,
            'store_timezone' => config('app.timezone'),
            'sales_by_day' => $this->fillDays($salesByDay, $itemsByDay, $range),
            'top_products' => $topProducts,
            'category_revenue' => $categoryRevenue,
            'low_stock_products' => Product::query()
                ->with('category')
                ->whereRaw('(stock_quantity + display_quantity) <= low_stock_threshold')
                ->orderByRaw('(stock_quantity + display_quantity) asc')
                ->limit(8)
                ->get(),
            'cashier_activity' => $cashierActivity,
            'recent_activity' => ActivityLog::query()->with('user:id,name,email,role')->latest()->limit(12)->get(),
            'generated_at' => now(),
        ]);
    }

    private function storeTimezone(): string
    {
        return config('app.timezone', 'Asia/Almaty');
    }

    /** @return array{start_local: Carbon, end_local: Carbon, start_utc: Carbon} */
    private function chartRange(): array
    {
        $tz = $this->storeTimezone();
        $endLocal = now($tz)->startOfDay();
        $startLocal = $endLocal->copy()->subDays(self::CHART_DAYS - 1);

        return [
            'start_local' => $startLocal,
            'end_local' => $endLocal,
            'start_utc' => $startLocal->copy()->utc(),
        ];
    }

    private function localDateKey($approvedAt): string
    {
        return Carbon::parse($approvedAt)->timezone($this->storeTimezone())->toDateString();
    }

    private function aggregateSalesByLocalDay(Carbon $chartStartUtc)
    {
        return Sale::query()
            ->where('status', 'approved')
            ->where('approved_at', '>=', $chartStartUtc)
            ->get(['approved_at', 'subtotal', 'profit'])
            ->groupBy(fn (Sale $sale) => $this->localDateKey($sale->approved_at))
            ->map(fn ($group, $day) => (object) [
                'day' => $day,
                'sales' => $group->count(),
                'revenue' => (float) $group->sum('subtotal'),
                'profit' => (float) $group->sum('profit'),
            ]);
    }

    private function aggregateItemsByLocalDay(Carbon $chartStartUtc): array
    {
        return DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', 'approved')
            ->where('sales.approved_at', '>=', $chartStartUtc)
            ->get(['sale_items.quantity', 'sales.approved_at'])
            ->groupBy(fn ($row) => $this->localDateKey($row->approved_at))
            ->map(fn ($group) => (int) $group->sum('quantity'))
            ->all();
    }

    private function todayItemsSold(): int
    {
        return (int) DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', 'approved')
            ->whereDate('sales.approved_at', today())
            ->sum('sale_items.quantity');
    }

    private function fillDays($rows, array $itemsByDay, array $range): array
    {
        $indexed = $rows->keyBy('day');
        $days = [];
        $date = $range['start_local']->copy();

        while ($date->lte($range['end_local'])) {
            $key = $date->toDateString();
            $row = $indexed->get($key);
            $sales = (int) ($row->sales ?? 0);
            $revenue = (float) ($row->revenue ?? 0);
            $profit = (float) ($row->profit ?? 0);
            $itemsSold = (int) ($itemsByDay[$key] ?? 0);

            $days[] = [
                'day' => $key,
                'label' => $date->format('d.m'),
                'sales' => $sales,
                'revenue' => $revenue,
                'profit' => $profit,
                'items_sold' => $itemsSold,
                'chart_value' => $revenue > 0 ? $revenue : ($itemsSold > 0 ? $itemsSold : ($sales > 0 ? $sales : 0)),
            ];

            $date->addDay();
        }

        return $days;
    }
}
