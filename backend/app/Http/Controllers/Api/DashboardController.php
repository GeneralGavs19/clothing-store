<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\DailyStat;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $approved = Sale::query()->where('status', 'approved');

        $summary = [
            'total_profit' => (float) (clone $approved)->sum('profit'),
            'total_revenue' => (float) (clone $approved)->sum('subtotal'),
            'approved_sales' => (int) (clone $approved)->count(),
            'pending_sales' => (int) Sale::query()->where('status', 'pending')->count(),
            'today_sales' => (int) (clone $approved)->whereDate('approved_at', today())->count(),
            'today_revenue' => (float) (clone $approved)->whereDate('approved_at', today())->sum('subtotal'),
            'week_revenue' => (float) (clone $approved)->whereBetween('approved_at', [now()->startOfWeek(), now()->endOfWeek()])->sum('subtotal'),
            'month_revenue' => (float) (clone $approved)->whereMonth('approved_at', now()->month)->whereYear('approved_at', now()->year)->sum('subtotal'),
            'products' => (int) Product::query()->where('status', '!=', 'archived')->count(),
            'low_stock' => (int) Product::query()->whereRaw('(stock_quantity + display_quantity) <= low_stock_threshold')->count(),
            'stock_units' => (int) Product::query()->sum('stock_quantity'),
            'display_units' => (int) Product::query()->sum('display_quantity'),
        ];

        $salesByDay = Sale::query()
            ->where('status', 'approved')
            ->where('approved_at', '>=', now()->subDays(13)->startOfDay())
            ->selectRaw('DATE(approved_at) as day, COUNT(*) as sales, SUM(subtotal) as revenue, SUM(profit) as profit')
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        // materialized daily stats (cached)
        $dailyStats = collect();
        if (Schema::hasTable('daily_stats')) {
            $dailyStats = Cache::remember('daily_stats_last_14', 3600, function () {
                return DailyStat::query()
                    ->whereBetween('date', [now()->subDays(13)->toDateString(), now()->toDateString()])
                    ->orderBy('date')
                    ->get();
            });
        }

        $topProducts = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.status', 'approved')
            ->groupBy('products.id', 'products.name', 'products.sku')
            ->orderByDesc(DB::raw('SUM(sale_items.quantity)'))
            ->limit(8)
            ->get([
                'products.id',
                'products.name',
                'products.sku',
                DB::raw('SUM(sale_items.quantity) as quantity'),
                DB::raw('SUM(sale_items.line_total) as revenue'),
            ]);

        $categoryRevenue = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('sales.status', 'approved')
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc(DB::raw('SUM(sale_items.line_total)'))
            ->limit(8)
            ->get([
                DB::raw('COALESCE(categories.name, "Без категории") as name'),
                DB::raw('SUM(sale_items.line_total) as revenue'),
                DB::raw('SUM(sale_items.line_profit) as profit'),
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
            'sales_by_day' => $this->fillDays($salesByDay),
            'daily_stats' => $dailyStats->map(fn($r) => [
                'date' => $r->date->toDateString(),
                'label' => $r->date->format('d.m'),
                'total_sales' => (int) $r->total_sales,
                'revenue' => (float) $r->revenue,
                'profit' => (float) $r->profit,
                'items_sold' => (int) $r->items_sold,
            ])->values(),
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

    private function fillDays($rows): array
    {
        $indexed = $rows->keyBy('day');
        $days = [];

        for ($date = now()->subDays(13)->startOfDay(); $date <= now()->startOfDay(); $date->addDay()) {
            $key = $date->toDateString();
            $row = $indexed->get($key);
            $days[] = [
                'day' => $key,
                'label' => Carbon::parse($key)->format('d.m'),
                'sales' => (int) ($row->sales ?? 0),
                'revenue' => (float) ($row->revenue ?? 0),
                'profit' => (float) ($row->profit ?? 0),
            ];
        }

        return $days;
    }
}
