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
        $sheet->setTitle('Продажи');

        $headers = ['№ продажи', 'Статус', 'Кассир', 'Выручка, ₸', 'Прибыль, ₸', 'Создана', 'Оформлена'];
        $sheet->fromArray($headers, null, 'A1');

        $rowIndex = 2;
        Sale::query()
            ->with('cashier')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('date_to')))
            ->orderByDesc('created_at')
            ->chunk(200, function ($sales) use ($sheet, &$rowIndex) {
                foreach ($sales as $sale) {
                    $sheet->fromArray([
                        $sale->number,
                        $sale->status,
                        $sale->cashier?->name,
                        (float) $sale->subtotal,
                        (float) $sale->profit,
                        optional($sale->submitted_at)?->format('d.m.Y H:i'),
                        optional($sale->approved_at)?->format('d.m.Y H:i'),
                    ], null, 'A'.$rowIndex);
                    $rowIndex++;
                }
            });

        foreach (range('A', 'G') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

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
            ->selectRaw('DATE(sales.approved_at) as date, COALESCE(categories.name, "Без категории") as category, SUM(sale_items.line_total) as revenue')
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
}
