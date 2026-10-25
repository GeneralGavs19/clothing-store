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
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function salesExcel(Request $request): StreamedResponse
    {
        $fileName = 'sales-report-'.now()->format('Y-m-d-His').'.xlsx';

        $salesQuery = Sale::query()
            ->with(['items.product.category', 'items.productVariant', 'cashier']);

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

        $sales = $salesQuery->get();

        // Подсчет сводных показателей (KPI)
        $totalSalesCount = $sales->count();
        $totalSoldQty = 0;
        $totalRevenue = 0.0;
        $inStockQty = 0;
        $onOrderQty = 0;
        $inStockRev = 0.0;
        $onOrderRev = 0.0;

        foreach ($sales as $sale) {
            $isOnOrder = (bool) $sale->is_on_order;
            foreach ($sale->items as $item) {
                $qty = (int) $item->quantity;
                $tot = (float) $item->line_total;
                $totalSoldQty += $qty;
                $totalRevenue += $tot;
                if ($isOnOrder) {
                    $onOrderQty += $qty;
                    $onOrderRev += $tot;
                } else {
                    $inStockQty += $qty;
                    $inStockRev += $tot;
                }
            }
        }

        $spreadsheet = new Spreadsheet;

        // ==========================================
        // ЛИСТ 1: ПРОДАЖИ ТОВАРОВ (ДЕТАЛЬНО ПО ВЕЩАМ)
        // ==========================================
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Продажи товаров (детально)');
        $sheet->setShowGridlines(true);

        // Заголовок отчета
        $sheet->setCellValue('A1', 'ОТЧЕТ ПО ПРОДАЖАМ И ЗАКАЗАМ ТОВАРОВ');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('1E293B');

        $periodText = $request->filled('date_from') || $request->filled('date_to')
            ? ('Период: ' . ($request->input('date_from') ?: '...') . ' — ' . ($request->input('date_to') ?: '...'))
            : 'Период: Все время';
        $sheet->setCellValue('A2', "Магазин одежды · {$periodText} · Сформирован: " . now()->format('d.m.Y H:i'));
        $sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(10)->getColor()->setRGB('64748B');

        // Сводные карточки KPI сверху таблицы (строки 4 и 5)
        $sheet->mergeCells('A4:C4');
        $sheet->setCellValue('A4', 'ОБЩЕЕ КОЛИЧЕСТВО ПРОДАННЫХ ВЕЩЕЙ');
        $sheet->mergeCells('A5:C5');
        $sheet->setCellValue('A5', $totalSoldQty . ' шт.');

        $sheet->mergeCells('D4:G4');
        $sheet->setCellValue('D4', 'ОБЩАЯ ЦЕНА ПРОДАЖИ (ВЫРУЧКА)');
        $sheet->mergeCells('D5:G5');
        $sheet->setCellValue('D5', number_format($totalRevenue, 0, '.', ' ') . ' ₸');

        $sheet->mergeCells('H4:J4');
        $sheet->setCellValue('H4', 'ИЗ НАЛИЧИЯ (СКЛАД/МАГАЗИН)');
        $sheet->mergeCells('H5:J5');
        $sheet->setCellValue('H5', $inStockQty . ' шт. · ' . number_format($inStockRev, 0, '.', ' ') . ' ₸');

        $sheet->mergeCells('K4:N4');
        $sheet->setCellValue('K4', '📦 НА ЗАКАЗ (ПРЕДЗАКАЗЫ)');
        $sheet->mergeCells('K5:N5');
        $sheet->setCellValue('K5', $onOrderQty . ' шт. · ' . number_format($onOrderRev, 0, '.', ' ') . ' ₸');

        // Стилизация KPI карточек
        $sheet->getStyle('A4:N4')->applyFromArray([
            'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '475569']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F1F5F9']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle('A5:N5')->applyFromArray([
            'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => '0F172A']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8FAFC']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle('A4:N5')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');

        // Заголовки основной таблицы (строка 7)
        $headers1 = [
            '№ чека',
            'Дата продажи',
            'Тип заказа',
            'Название товара (вещи)',
            'Вид товара',
            'Категория',
            'Размер',
            'Цвет',
            'Кол-во (шт.)',
            'Цена за шт., ₸',
            'Общая цена продажи, ₸',
            'Кассир',
            'Примечание',
            'Статус',
        ];
        $sheet->fromArray($headers1, null, 'A7');
        $sheet->getRowDimension(7)->setRowHeight(26);
        $sheet->getStyle('A7:N7')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '107C41']], // Фирменный зеленый Excel
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);

        $r = 8;
        foreach ($sales as $sale) {
            $orderType = $sale->is_on_order ? '📦 На заказ' : 'В наличии';
            // Единая понятная колонка даты продажи (убраны дубли Создана/Оформлена)
            $dateStr = ($sale->approved_at ?? $sale->created_at)?->format('d.m.Y H:i') ?? '—';
            $statusLabel = match ($sale->status) {
                'approved' => 'Оформлен',
                'rejected' => 'Отменён',
                default => 'В ожидании',
            };
            $saleNum = '№' . $sale->id;

            if ($sale->items->isEmpty()) {
                $sheet->fromArray([
                    $saleNum,
                    $dateStr,
                    $orderType,
                    'Без товаров',
                    '—',
                    '—',
                    '—',
                    '—',
                    0,
                    (float) $sale->subtotal,
                    (float) $sale->subtotal,
                    $sale->cashier?->name ?? '—',
                    $sale->cashier_note ?? '—',
                    $statusLabel,
                ], null, 'A' . $r);
                $r++;
                continue;
            }

            foreach ($sale->items as $item) {
                $prodName = $item->product_name ?: $item->product?->name ?: 'Товар';
                $prodType = $item->product_type ?: $item->product?->type ?: '—';
                $catName = $item->product?->category?->name ?: '—';
                $color = $item->product?->color ?: '—';
                $size = $item->variant_size ?: '—';
                $qty = (int) $item->quantity;
                $price = (float) $item->sale_price;
                $total = (float) $item->line_total;

                $sheet->fromArray([
                    $saleNum,
                    $dateStr,
                    $orderType,
                    $prodName,
                    $prodType,
                    $catName,
                    $size,
                    $color,
                    $qty,
                    $price,
                    $total,
                    $sale->cashier?->name ?? '—',
                    $sale->cashier_note ?? '—',
                    $statusLabel,
                ], null, 'A' . $r);

                $r++;
            }
        }

        $dataEndRow = max(8, $r - 1);

        // Строка ИТОГО внизу таблицы
        $totalRow = $r;
        $sheet->mergeCells("A{$totalRow}:H{$totalRow}");
        $sheet->setCellValue("A{$totalRow}", 'ИТОГО ПО ВСЕМ ПРОДАЖАМ:');
        $sheet->setCellValue("I{$totalRow}", "=SUM(I8:I{$dataEndRow})");
        $sheet->setCellValue("J{$totalRow}", '—');
        $sheet->setCellValue("K{$totalRow}", "=SUM(K8:K{$dataEndRow})");
        $sheet->setCellValue("L{$totalRow}", '');
        $sheet->setCellValue("M{$totalRow}", '');
        $sheet->setCellValue("N{$totalRow}", '');

        $sheet->getStyle("A{$totalRow}:N{$totalRow}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => '000000']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
            'borders' => [
                'top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '94A3B8']],
                'bottom' => ['borderStyle' => Border::BORDER_DOUBLE, 'color' => ['rgb' => '000000']],
            ],
        ]);
        $sheet->getStyle("A{$totalRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("I{$totalRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("K{$totalRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        // Стилизация и границы всей таблицы
        $sheet->getStyle("A8:N{$dataEndRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E2E8F0');
        $sheet->getStyle("A8:A{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("B8:B{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("C8:C{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("D8:D{$dataEndRow}")->getFont()->setBold(true);
        $sheet->getStyle("G8:G{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("H8:H{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("I8:I{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("N8:N{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Числовые форматы для штук и валюты
        $sheet->getStyle("I8:I{$totalRow}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("J8:J{$dataEndRow}")->getNumberFormat()->setFormatCode('#,##0 "₸"');
        $sheet->getStyle("K8:K{$totalRow}")->getNumberFormat()->setFormatCode('#,##0 "₸"');

        foreach (range('A', 'N') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // ==========================================
        // ЛИСТ 2: СВОДКА ПО ТОВАРАМ И РАЗМЕРАМ
        // ==========================================
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('Сводка по товарам');
        $sheet2->setShowGridlines(true);

        $sheet2->setCellValue('A1', 'СВОДНАЯ СТАТИСТИКА ПРОДАННЫХ ТОВАРОВ И ОСТАТКОВ');
        $sheet2->getStyle('A1')->getFont()->setBold(true)->setSize(13)->getColor()->setRGB('1E293B');
        $sheet2->setCellValue('A2', 'Агрегированные продажи каждого товара с разбивкой по размерам · Формула: Было = Продано + Остаток');
        $sheet2->getStyle('A2')->getFont()->setItalic(true)->setSize(10)->getColor()->setRGB('64748B');

        $headers2 = [
            'Название товара (вещи)',
            'Вид товара',
            'Категория',
            'Размер',
            'Цвет',
            'Цена продажи, ₸',
            'Продано всего (шт.)',
            '✓ Из наличия (шт.)',
            '📦 На заказ (шт.)',
            'Общая выручка, ₸',
            'Текущий остаток (шт.)',
            'Было учтено (Продано + Остаток)',
        ];
        $sheet2->fromArray($headers2, null, 'A4');
        $sheet2->getRowDimension(4)->setRowHeight(24);
        $sheet2->getStyle('A4:L4')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0284C7']], // Синий оттенок для второй вкладки
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);

        $productsAggregated = [];
        foreach ($sales as $sale) {
            if ($sale->status === 'rejected') {
                continue;
            }
            $isOnOrder = (bool) $sale->is_on_order;
            foreach ($sale->items as $item) {
                $pName = $item->product_name ?: $item->product?->name ?: 'Товар';
                $pType = $item->product_type ?: $item->product?->type ?: '—';
                $pCat = $item->product?->category?->name ?: '—';
                $pSize = $item->variant_size ?: '—';
                $pColor = $item->product?->color ?: '—';
                $key = $pName . '___' . $pSize;

                if (! isset($productsAggregated[$key])) {
                    $stockStore = (int) ($item->productVariant?->stock_store ?? $item->product?->stock_quantity ?? 0);
                    $stockWh = (int) ($item->productVariant?->stock_warehouse ?? 0);
                    $currentStock = $stockStore + $stockWh;

                    $productsAggregated[$key] = [
                        'name' => $pName,
                        'type' => $pType,
                        'category' => $pCat,
                        'size' => $pSize,
                        'color' => $pColor,
                        'sale_price' => (float) $item->sale_price,
                        'sold_quantity' => 0,
                        'in_stock_qty' => 0,
                        'on_order_qty' => 0,
                        'total_revenue' => 0.0,
                        'current_stock' => $currentStock,
                    ];
                }

                $qty = (int) $item->quantity;
                $productsAggregated[$key]['sold_quantity'] += $qty;
                $productsAggregated[$key]['total_revenue'] += (float) $item->line_total;
                if ($isOnOrder) {
                    $productsAggregated[$key]['on_order_qty'] += $qty;
                } else {
                    $productsAggregated[$key]['in_stock_qty'] += $qty;
                }
            }
        }

        $r2 = 5;
        foreach ($productsAggregated as $row) {
            $initial = $row['sold_quantity'] + $row['current_stock'];
            $sheet2->fromArray([
                $row['name'],
                $row['type'],
                $row['category'],
                $row['size'],
                $row['color'],
                $row['sale_price'],
                $row['sold_quantity'],
                $row['in_stock_qty'],
                $row['on_order_qty'],
                $row['total_revenue'],
                $row['current_stock'],
                $initial,
            ], null, 'A' . $r2);
            $r2++;
        }

        $end2 = max(5, $r2 - 1);
        $totalRow2 = $r2;

        $sheet2->mergeCells("A{$totalRow2}:F{$totalRow2}");
        $sheet2->setCellValue("A{$totalRow2}", 'ИТОГО:');
        $sheet2->setCellValue("G{$totalRow2}", "=SUM(G5:G{$end2})");
        $sheet2->setCellValue("H{$totalRow2}", "=SUM(H5:H{$end2})");
        $sheet2->setCellValue("I{$totalRow2}", "=SUM(I5:I{$end2})");
        $sheet2->setCellValue("J{$totalRow2}", "=SUM(J5:J{$end2})");
        $sheet2->setCellValue("K{$totalRow2}", "=SUM(K5:K{$end2})");
        $sheet2->setCellValue("L{$totalRow2}", "=SUM(L5:L{$end2})");

        $sheet2->getStyle("A{$totalRow2}:L{$totalRow2}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => '000000']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
            'borders' => [
                'top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '94A3B8']],
                'bottom' => ['borderStyle' => Border::BORDER_DOUBLE, 'color' => ['rgb' => '000000']],
            ],
        ]);
        $sheet2->getStyle("A{$totalRow2}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $sheet2->getStyle("A5:L{$end2}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E2E8F0');
        $sheet2->getStyle("D5:E{$end2}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet2->getStyle("G5:I{$totalRow2}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet2->getStyle("F5:F{$end2}")->getNumberFormat()->setFormatCode('#,##0 "₸"');
        $sheet2->getStyle("J5:J{$totalRow2}")->getNumberFormat()->setFormatCode('#,##0 "₸"');
        $sheet2->getStyle("K5:L{$totalRow2}")->getNumberFormat()->setFormatCode('#,##0');

        foreach (range('A', 'L') as $col) {
            $sheet2->getColumnDimension($col)->setAutoSize(true);
        }

        // ==========================================
        // ЛИСТ 3: СВОДКА ПО ЧЕКАМ (ПОЛНЫЕ ЗАКАЗЫ)
        // ==========================================
        $sheet3 = $spreadsheet->createSheet();
        $sheet3->setTitle('Сводка по чекам');
        $sheet3->setShowGridlines(true);

        $sheet3->setCellValue('A1', 'СВОДКА ПО ОФОРМЛЕННЫМ ЧЕКАМ');
        $sheet3->getStyle('A1')->getFont()->setBold(true)->setSize(13)->getColor()->setRGB('1E293B');

        $headers3 = [
            '№ чека',
            'Дата продажи',
            'Тип заказа',
            'Состав заказа (Товары и размеры)',
            'Общее кол-во вещей (шт.)',
            'Сумма чека, ₸',
            'Кассир',
            'Примечание',
            'Статус',
        ];
        $sheet3->fromArray($headers3, null, 'A3');
        $sheet3->getRowDimension(3)->setRowHeight(24);
        $sheet3->getStyle('A3:I3')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '475569']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);

        $r3 = 4;
        foreach ($sales as $sale) {
            $orderType = $sale->is_on_order ? '📦 На заказ' : 'В наличии';
            $dateStr = ($sale->approved_at ?? $sale->created_at)?->format('d.m.Y H:i') ?? '—';
            $statusLabel = match ($sale->status) {
                'approved' => 'Оформлен',
                'rejected' => 'Отменён',
                default => 'В ожидании',
            };
            $saleNum = '№' . $sale->id;

            $itemsSummary = $sale->items->map(function ($it) {
                $name = $it->product_name ?: $it->product?->name ?: 'Товар';
                $sz = $it->variant_size ? " ({$it->variant_size})" : '';
                return "{$name}{$sz} × {$it->quantity} шт.";
            })->implode(', ');

            $totalQty = (int) $sale->items->sum('quantity');

            $sheet3->fromArray([
                $saleNum,
                $dateStr,
                $orderType,
                $itemsSummary ?: '—',
                $totalQty,
                (float) $sale->subtotal,
                $sale->cashier?->name ?? '—',
                $sale->cashier_note ?? '—',
                $statusLabel,
            ], null, 'A' . $r3);
            $r3++;
        }

        $end3 = max(4, $r3 - 1);
        $totalRow3 = $r3;

        $sheet3->mergeCells("A{$totalRow3}:D{$totalRow3}");
        $sheet3->setCellValue("A{$totalRow3}", 'ИТОГО ПО ВСЕМ ЧЕКАМ:');
        $sheet3->setCellValue("E{$totalRow3}", "=SUM(E4:E{$end3})");
        $sheet3->setCellValue("F{$totalRow3}", "=SUM(F4:F{$end3})");

        $sheet3->getStyle("A{$totalRow3}:I{$totalRow3}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => '000000']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
            'borders' => [
                'top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '94A3B8']],
                'bottom' => ['borderStyle' => Border::BORDER_DOUBLE, 'color' => ['rgb' => '000000']],
            ],
        ]);
        $sheet3->getStyle("A{$totalRow3}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $sheet3->getStyle("A4:I{$end3}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E2E8F0');
        $sheet3->getStyle("A4:C{$end3}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet3->getStyle("E4:E{$totalRow3}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet3->getStyle("F4:F{$totalRow3}")->getNumberFormat()->setFormatCode('#,##0 "₸"');

        foreach (range('A', 'I') as $col) {
            $sheet3->getColumnDimension($col)->setAutoSize(true);
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

        // 1. BY TYPE WITH SIZES BREAKDOWN (В наличии / На заказ)
        $typesMap = [];
        foreach ($catalogProducts as $prod) {
            $t = trim((string) ($prod->type ?: $prod->category?->name ?: 'Без вида'));
            if (! isset($typesMap[$t])) {
                $typesMap[$t] = [
                    'type' => $t,
                    'sold_quantity' => 0,
                    'in_stock_sold' => 0,
                    'on_order_sold' => 0,
                    'stock_quantity' => 0,
                    'revenue' => 0.0,
                    'in_stock_revenue' => 0.0,
                    'on_order_revenue' => 0.0,
                    'profit' => 0.0,
                    'sizes_map' => [],
                ];
            }
            $typesMap[$t]['stock_quantity'] += (int) $prod->total_quantity;

            foreach ($prod->variants as $variant) {
                $sz = trim((string) ($variant->size ?: '—'));
                if (! isset($typesMap[$t]['sizes_map'][$sz])) {
                    $typesMap[$t]['sizes_map'][$sz] = [
                        'size' => $sz,
                        'sold_quantity' => 0,
                        'in_stock_sold' => 0,
                        'on_order_sold' => 0,
                        'stock_quantity' => 0,
                        'revenue' => 0.0,
                        'in_stock_revenue' => 0.0,
                        'on_order_revenue' => 0.0,
                        'profit' => 0.0,
                    ];
                }
                $typesMap[$t]['sizes_map'][$sz]['stock_quantity'] += (int) $variant->stockTotal();
            }
        }

        foreach ($saleItems as $item) {
            $t = trim((string) ($item->product_type ?: $item->live_product_type ?: $item->live_category_name ?: 'Без вида'));
            $sz = trim((string) ($item->variant_size ?: '—'));
            $qty = (int) $item->quantity;
            $lineTotal = (float) $item->line_total;
            $lineProfit = (float) $item->line_profit;
            $isOnOrder = (bool) $item->sale_is_on_order;

            if (! isset($typesMap[$t])) {
                $typesMap[$t] = [
                    'type' => $t,
                    'sold_quantity' => 0,
                    'in_stock_sold' => 0,
                    'on_order_sold' => 0,
                    'stock_quantity' => 0,
                    'revenue' => 0.0,
                    'in_stock_revenue' => 0.0,
                    'on_order_revenue' => 0.0,
                    'profit' => 0.0,
                    'sizes_map' => [],
                ];
            }

            if (! isset($typesMap[$t]['sizes_map'][$sz])) {
                $typesMap[$t]['sizes_map'][$sz] = [
                    'size' => $sz,
                    'sold_quantity' => 0,
                    'in_stock_sold' => 0,
                    'on_order_sold' => 0,
                    'stock_quantity' => 0,
                    'revenue' => 0.0,
                    'in_stock_revenue' => 0.0,
                    'on_order_revenue' => 0.0,
                    'profit' => 0.0,
                ];
            }

            $typesMap[$t]['sold_quantity'] += $qty;
            $typesMap[$t]['revenue'] += $lineTotal;
            $typesMap[$t]['profit'] += $lineProfit;

            $typesMap[$t]['sizes_map'][$sz]['sold_quantity'] += $qty;
            $typesMap[$t]['sizes_map'][$sz]['revenue'] += $lineTotal;
            $typesMap[$t]['sizes_map'][$sz]['profit'] += $lineProfit;

            if ($isOnOrder) {
                $typesMap[$t]['on_order_sold'] += $qty;
                $typesMap[$t]['on_order_revenue'] += $lineTotal;
                $typesMap[$t]['sizes_map'][$sz]['on_order_sold'] += $qty;
                $typesMap[$t]['sizes_map'][$sz]['on_order_revenue'] += $lineTotal;
            } else {
                $typesMap[$t]['in_stock_sold'] += $qty;
                $typesMap[$t]['in_stock_revenue'] += $lineTotal;
                $typesMap[$t]['sizes_map'][$sz]['in_stock_sold'] += $qty;
                $typesMap[$t]['sizes_map'][$sz]['in_stock_revenue'] += $lineTotal;
            }
        }

        $byType = collect($typesMap)->map(function ($item) use ($totalRevenue, $totalSoldUnits) {
            $item['initial_quantity'] = (int) $item['sold_quantity'] + (int) $item['stock_quantity'];
            $item['revenue'] = round((float) $item['revenue'], 2);
            $item['in_stock_revenue'] = round((float) $item['in_stock_revenue'], 2);
            $item['on_order_revenue'] = round((float) $item['on_order_revenue'], 2);
            $item['profit'] = round((float) $item['profit'], 2);
            $item['revenue_percent'] = $totalRevenue > 0 ? round(($item['revenue'] / $totalRevenue) * 100, 1) : 0;
            $item['units_percent'] = $totalSoldUnits > 0 ? round(($item['sold_quantity'] / $totalSoldUnits) * 100, 1) : 0;

            $sizesList = collect($item['sizes_map'])->map(function ($s) {
                $s['initial_quantity'] = (int) $s['sold_quantity'] + (int) $s['stock_quantity'];
                $s['revenue'] = round((float) $s['revenue'], 2);
                $s['in_stock_revenue'] = round((float) $s['in_stock_revenue'], 2);
                $s['on_order_revenue'] = round((float) $s['on_order_revenue'], 2);
                $s['profit'] = round((float) $s['profit'], 2);
                $s['average_price'] = $s['sold_quantity'] > 0 ? round($s['revenue'] / $s['sold_quantity'], 2) : 0;

                return $s;
            })->values()->all();

            unset($item['sizes_map']);
            $item['sizes'] = $this->sortSizes($sizesList);

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
                    'in_stock_sold' => 0,
                    'on_order_sold' => 0,
                    'stock_quantity' => 0,
                    'revenue' => 0.0,
                    'in_stock_revenue' => 0.0,
                    'on_order_revenue' => 0.0,
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
                    'in_stock_sold' => 0,
                    'on_order_sold' => 0,
                    'stock_quantity' => 0,
                    'revenue' => 0.0,
                    'in_stock_revenue' => 0.0,
                    'on_order_revenue' => 0.0,
                    'profit' => 0.0,
                ];
            }
            $qty = (int) $item->quantity;
            $lineTotal = (float) $item->line_total;
            $lineProfit = (float) $item->line_profit;
            $isOnOrder = (bool) $item->sale_is_on_order;

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
            $item['initial_quantity'] = (int) $item['sold_quantity'] + (int) $item['stock_quantity'];
            $item['revenue'] = round((float) $item['revenue'], 2);
            $item['in_stock_revenue'] = round((float) $item['in_stock_revenue'], 2);
            $item['on_order_revenue'] = round((float) $item['on_order_revenue'], 2);
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
            $prodInStockSold = (int) $prodSales->filter(fn ($s) => ! (bool) $s->sale_is_on_order)->sum('quantity');
            $prodOnOrderSold = (int) $prodSales->filter(fn ($s) => (bool) $s->sale_is_on_order)->sum('quantity');

            $prodRevenue = round((float) $prodSales->sum('line_total'), 2);
            $prodInStockRev = round((float) $prodSales->filter(fn ($s) => ! (bool) $s->sale_is_on_order)->sum('line_total'), 2);
            $prodOnOrderRev = round((float) $prodSales->filter(fn ($s) => (bool) $s->sale_is_on_order)->sum('line_total'), 2);
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
                $varInStockSold = (int) $varSales->filter(fn ($s) => ! (bool) $s->sale_is_on_order)->sum('quantity');
                $varOnOrderSold = (int) $varSales->filter(fn ($s) => (bool) $s->sale_is_on_order)->sum('quantity');

                $varRevenue = round((float) $varSales->sum('line_total'), 2);
                $varInStockRev = round((float) $varSales->filter(fn ($s) => ! (bool) $s->sale_is_on_order)->sum('line_total'), 2);
                $varOnOrderRev = round((float) $varSales->filter(fn ($s) => (bool) $s->sale_is_on_order)->sum('line_total'), 2);
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
                    'in_stock_sold' => $varInStockSold,
                    'on_order_sold' => $varOnOrderSold,
                    'initial_quantity' => $varInitial,
                    'sale_price' => (float) $variant->sale_price,
                    'purchase_price' => (float) ($variant->purchase_price ?? $prod->purchase_price ?? 0),
                    'revenue' => $varRevenue,
                    'in_stock_revenue' => $varInStockRev,
                    'on_order_revenue' => $varOnOrderRev,
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
                'in_stock_sold' => $prodInStockSold,
                'on_order_sold' => $prodOnOrderSold,
                'initial_quantity' => $prodInitial,
                'revenue' => $prodRevenue,
                'in_stock_revenue' => $prodInStockRev,
                'on_order_revenue' => $prodOnOrderRev,
                'profit' => $prodProfit,
                'sizes' => $this->sortSizes($sizes),
            ];
        }

        foreach ($salesByProduct as $prodId => $items) {
            if ($prodId && isset($productsMap[$prodId])) {
                continue;
            }
            $firstName = $items->first()->product_name ?: 'Товар #'.$prodId;
            $soldQty = (int) $items->sum('quantity');
            $inStockSold = (int) $items->filter(fn ($s) => ! (bool) $s->sale_is_on_order)->sum('quantity');
            $onOrderSold = (int) $items->filter(fn ($s) => (bool) $s->sale_is_on_order)->sum('quantity');

            $rev = round((float) $items->sum('line_total'), 2);
            $inStockRev = round((float) $items->filter(fn ($s) => ! (bool) $s->sale_is_on_order)->sum('line_total'), 2);
            $onOrderRev = round((float) $items->filter(fn ($s) => (bool) $s->sale_is_on_order)->sum('line_total'), 2);
            $prof = round((float) $items->sum('line_profit'), 2);

            $sizes = [];
            foreach ($items->groupBy('variant_size') as $sz => $szItems) {
                $szSold = (int) $szItems->sum('quantity');
                $szInStock = (int) $szItems->filter(fn ($s) => ! (bool) $s->sale_is_on_order)->sum('quantity');
                $szOnOrder = (int) $szItems->filter(fn ($s) => (bool) $s->sale_is_on_order)->sum('quantity');

                $szRev = round((float) $szItems->sum('line_total'), 2);
                $szInStockRev = round((float) $szItems->filter(fn ($s) => ! (bool) $s->sale_is_on_order)->sum('line_total'), 2);
                $szOnOrderRev = round((float) $szItems->filter(fn ($s) => (bool) $s->sale_is_on_order)->sum('line_total'), 2);
                $szProf = round((float) $szItems->sum('line_profit'), 2);

                $sizes[] = [
                    'variant_id' => null,
                    'size' => $sz ?: '—',
                    'sku' => null,
                    'stock_store' => 0,
                    'stock_warehouse' => 0,
                    'stock_quantity' => 0,
                    'sold_quantity' => $szSold,
                    'in_stock_sold' => $szInStock,
                    'on_order_sold' => $szOnOrder,
                    'initial_quantity' => $szSold,
                    'sale_price' => $szSold > 0 ? round($szRev / $szSold, 2) : 0,
                    'purchase_price' => 0,
                    'revenue' => $szRev,
                    'in_stock_revenue' => $szInStockRev,
                    'on_order_revenue' => $szOnOrderRev,
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
                'in_stock_sold' => $inStockSold,
                'on_order_sold' => $onOrderSold,
                'initial_quantity' => $soldQty,
                'revenue' => $rev,
                'in_stock_revenue' => $inStockRev,
                'on_order_revenue' => $onOrderRev,
                'profit' => $prof,
                'sizes' => $this->sortSizes($sizes),
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

    private function sortSizes(array $sizes): array
    {
        $order = [
            'xxs' => 1, 'xs' => 2, 's' => 3, 'm' => 4, 'l' => 5,
            'xl' => 6, '2xl' => 7, 'xxl' => 7, '3xl' => 8, 'xxxl' => 8,
            '4xl' => 9, '5xl' => 10, 'one size' => 99, 'универсальный' => 99,
        ];

        usort($sizes, function ($a, $b) use ($order) {
            $sa = mb_strtolower(trim($a['size'] ?? ''));
            $sb = mb_strtolower(trim($b['size'] ?? ''));

            $oa = $order[$sa] ?? null;
            $ob = $order[$sb] ?? null;

            if ($oa !== null && $ob !== null) {
                return $oa <=> $ob;
            }

            if (is_numeric($sa) && is_numeric($sb)) {
                return (float) $sa <=> (float) $sb;
            }

            return strnatcasecmp($sa, $sb);
        });

        return $sizes;
    }
}
