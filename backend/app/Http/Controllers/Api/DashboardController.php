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
        $chartStart = now()->subDays(self::CHART_DAYS - 1)->startOfDay();
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

        $salesByDay = Sale::query()
            ->where('status', 'approved')
            ->where('approved_at', '>=', $chartStart)
            ->selectRaw('DATE(approved_at) as day, COUNT(*) as sales, SUM(subtotal) as revenue, SUM(profit) as profit')
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $itemsByDay = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', 'approved')
            ->where('sales.approved_at', '>=', $chartStart)
            ->groupBy(DB::raw('DATE(sales.approved_at)'))
            ->orderBy(DB::raw('DATE(sales.approved_at)'))
            ->selectRaw('DATE(sales.approved_at) as day, SUM(sale_items.quantity) as items_sold')
            ->get()
            ->mapWithKeys(fn ($row) => [Carbon::parse($row->day)->toDateString() => (int) $row->items_sold]);

        $topProducts = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.status', 'approved')
            ->where('sales.approved_at', '>=', $chartStart)
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
            ->where('sales.approved_at', '>=', $chartStart)
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
            'sales_by_day' => $this->fillDays($salesByDay, $itemsByDay),
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

    private function todayItemsSold(): int
    {
        return (int) DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', 'approved')
            ->whereDate('sales.approved_at', today())
            ->sum('sale_items.quantity');
    }

    private function fillDays($rows, $itemsByDay): array
    {
        $indexed = $rows->keyBy(fn ($row) => Carbon::parse($row->day)->toDateString());
        $days = [];

        for ($date = now()->subDays(self::CHART_DAYS - 1)->startOfDay(); $date <= now()->startOfDay(); $date->addDay()) {
            $key = $date->toDateString();
            $row = $indexed->get($key);
            $sales = (int) ($row->sales ?? 0);
            $revenue = (float) ($row->revenue ?? 0);
            $profit = (float) ($row->profit ?? 0);
            $itemsSold = (int) ($itemsByDay[$key] ?? 0);

            $days[] = [
                'day' => $key,
                'label' => Carbon::parse($key)->format('d.m'),
                'sales' => $sales,
                'revenue' => $revenue,
                'profit' => $profit,
                'items_sold' => $itemsSold,
                'chart_value' => $revenue > 0 ? $revenue : ($itemsSold > 0 ? $itemsSold : ($sales > 0 ? $sales : 0)),
            ];
        }

        return $days;
    }
}
