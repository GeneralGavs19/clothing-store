<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\DailyStat;

class AggregateDailyStats extends Command
{
    protected $signature = 'stats:aggregate-daily';
    protected $description = 'Aggregate approved sales into daily_stats';

    public function handle(): int
    {
        $this->info('Aggregating daily stats...');

        // Aggregate sales (by approved_at date)
        $sales = Sale::query()
            ->where('status', 'approved')
            ->whereNotNull('approved_at')
            ->selectRaw("DATE(approved_at) as date, COUNT(*) as total_sales, SUM(subtotal) as revenue, SUM(profit) as profit")
            ->groupBy('date')
            ->get()
            ->keyBy('date');

        // Aggregate items sold per day
        $items = SaleItem::query()
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.status', 'approved')
            ->whereNotNull('sales.approved_at')
            ->selectRaw("DATE(sales.approved_at) as date, SUM(sale_items.quantity) as items_sold")
            ->groupBy('date')
            ->get()
            ->keyBy('date');

        DB::transaction(function () use ($sales, $items) {
            foreach ($sales as $date => $row) {
                $items_sold = $items->has($date) ? (int)$items[$date]->items_sold : 0;

                DailyStat::updateOrCreate(
                    ['date' => $date],
                    [
                        'total_sales' => (int)$row->total_sales,
                        'revenue' => $row->revenue ?? 0,
                        'profit' => $row->profit ?? 0,
                        'items_sold' => $items_sold,
                    ]
                );
            }
        });

        $this->info('Daily stats aggregated.');

        return 0;
    }
}
