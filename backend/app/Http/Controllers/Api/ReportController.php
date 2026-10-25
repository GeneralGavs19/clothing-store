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
use Carbon\Carbon;
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
     * Экспорт отчета по продажам в Excel (3 листа: детально по позициям, сводка по видам и размерам, сводка по чекам).
     * Не содержит вымышленных остатков склада, так как складской учет пока не ведется.
     */
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
            ->when($request->has('is_on_order') && $request->input('is_on_order') !== null && $request->input('is_on_order') !== '', function ($q) use ($request) {
                $isOnOrder = filter_var($request->input('is_on_order'), FILTER_VALIDATE_BOOLEAN);
                $q->where('is_on_order', $isOnOrder);
            })
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('approved_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('approved_at', '<=', $request->date('date_to')))
            ->orderByDesc('approved_at')
            ->orderByDesc('id');

        $sales = $salesQuery->get();

        // Сводные показатели
        $totalSalesCount = $sales->count();
        $totalSoldQty = 0;
        $totalRevenue = 0.0;
        $inStockQty = 0;
        $onOrderQty = 0;
        $inStockRev = 0.0;
        $onOrderRev = 0.0;

        foreach ($sales as $sale) {
            $saleIsOnOrder = (bool) $sale->is_on_order;
            foreach ($sale->items as $item) {
                $qty = (int) $item->quantity;
                $tot = (float) $item->line_total;
                $isItemOnOrder = $item->is_on_order !== null ? (bool) $item->is_on_order : $saleIsOnOrder;

                $totalSoldQty += $qty;
                $totalRevenue += $tot;
                if ($isItemOnOrder) {
                    $onOrderQty += $qty;
                    $onOrderRev += $tot;
                } else {
                    $inStockQty += $qty;
                    $inStockRev += $tot;
                }
            }
        }

        $averageCheck = $totalSalesCount > 0 ? round($totalRevenue / $totalSalesCount, 2) : 0;

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
        $sheet->setCellValue('A2', "Магазин детской одежды и школьной формы · {$periodText} · Сформирован: " . now()->format('d.m.Y H:i'));
        $sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(10)->getColor()->setRGB('64748B');

        // Сводные карточки KPI сверху таблицы (строки 4 и 5)
        $sheet->mergeCells('A4:B4');
        $sheet->setCellValue('A4', 'ПРОДАНО ВЕЩЕЙ');
        $sheet->mergeCells('A5:B5');
        $sheet->setCellValue('A5', $totalSoldQty . ' шт.');

        $sheet->mergeCells('C4:E4');
        $sheet->setCellValue('C4', 'ОБЩАЯ ВЫРУЧКА');
        $sheet->mergeCells('C5:E5');
        $sheet->setCellValue('C5', number_format($totalRevenue, 0, '.', ' ') . ' ₸');

        $sheet->mergeCells('F4:H4');
        $sheet->setCellValue('F4', '✓ ИЗ НАЛИЧИЯ');
        $sheet->mergeCells('F5:H5');
        $sheet->setCellValue('F5', $inStockQty . ' шт. · ' . number_format($inStockRev, 0, '.', ' ') . ' ₸');

        $sheet->mergeCells('I4:K4');
        $sheet->setCellValue('I4', '📦 НА ЗАКАЗ');
        $sheet->mergeCells('I5:K5');
        $sheet->setCellValue('I5', $onOrderQty . ' шт. · ' . number_format($onOrderRev, 0, '.', ' ') . ' ₸');

        $sheet->mergeCells('L4:N4');
        $sheet->setCellValue('L4', 'ЧЕКОВ / СРЕДНИЙ ЧЕК');
        $sheet->mergeCells('L5:N5');
        $sheet->setCellValue('L5', $totalSalesCount . ' чеков · ' . number_format($averageCheck, 0, '.', ' ') . ' ₸');

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

        // Заголовки таблицы (строка 7)
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
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '059669']], // Изумрудно-зеленый
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);

        $r = 8;
        foreach ($sales as $sale) {
            $saleIsOnOrder = (bool) $sale->is_on_order;
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
                    $saleIsOnOrder ? '📦 На заказ' : 'В наличии',
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
                $isItemOnOrder = $item->is_on_order !== null ? (bool) $item->is_on_order : $saleIsOnOrder;
                $orderType = $isItemOnOrder ? '📦 На заказ' : 'В наличии';
                $prodName = $item->product_name ?: $item->product?->name ?: 'Товар';
                $prodType = $this->resolveProductType(
                    $item->product_type,
                    $item->product?->type,
                    $prodName,
                    $item->product?->category?->name
                );
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

        // Строка ИТОГО
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

        $sheet->getStyle("A8:N{$dataEndRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E2E8F0');
        $sheet->getStyle("A8:A{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("B8:B{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("C8:C{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("D8:D{$dataEndRow}")->getFont()->setBold(true);
        $sheet->getStyle("G8:G{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("H8:H{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("I8:I{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("N8:N{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->getStyle("I8:I{$totalRow}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("J8:J{$dataEndRow}")->getNumberFormat()->setFormatCode('#,##0 "₸"');
        $sheet->getStyle("K8:K{$totalRow}")->getNumberFormat()->setFormatCode('#,##0 "₸"');

        foreach (range('A', 'N') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // ==========================================
        // ЛИСТ 2: СВОДКА ПО ВИДАМ И РАЗМЕРАМ
        // ==========================================
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('Сводка по видам и размерам');
        $sheet2->setShowGridlines(true);

        $sheet2->setCellValue('A1', 'СВОДНАЯ СТАТИСТИКА ПРОДАЖ ПО ВИДАМ И РАЗМЕРАМ');
        $sheet2->getStyle('A1')->getFont()->setBold(true)->setSize(13)->getColor()->setRGB('1E293B');
        $sheet2->setCellValue('A2', 'Агрегированные продажи детской одежды по видам товаров и по каждому размеру (В наличии / На заказ)');
        $sheet2->getStyle('A2')->getFont()->setItalic(true)->setSize(10)->getColor()->setRGB('64748B');

        $headers2 = [
            'Вид товара',
            'Размер',
            'Продано всего (шт.)',
            '✓ Из наличия (шт.)',
            '📦 На заказ (шт.)',
            'Общая выручка, ₸',
            'Ср. цена шт., ₸',
            'Доля в выручке вида',
        ];
        $sheet2->fromArray($headers2, null, 'A4');
        $sheet2->getRowDimension(4)->setRowHeight(24);
        $sheet2->getStyle('A4:H4')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0284C7']], // Синий
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);

        $typeSizesAggregated = [];
        foreach ($sales as $sale) {
            if ($sale->status === 'rejected') {
                continue;
            }
            $saleIsOnOrder = (bool) $sale->is_on_order;
            foreach ($sale->items as $item) {
                $pName = $item->product_name ?: $item->product?->name ?: 'Товар';
                $pType = $this->resolveProductType(
                    $item->product_type,
                    $item->product?->type,
                    $pName,
                    $item->product?->category?->name
                );
                $pSize = trim((string) ($item->variant_size ?: 'Без размера'));
                if ($pSize === '' || $pSize === '-' || $pSize === '—') {
                    $pSize = 'Без размера';
                }

                $key = $pType . '___' . $pSize;
                $isItemOnOrder = $item->is_on_order !== null ? (bool) $item->is_on_order : $saleIsOnOrder;
                $qty = (int) $item->quantity;
                $tot = (float) $item->line_total;

                if (! isset($typeSizesAggregated[$key])) {
                    $typeSizesAggregated[$key] = [
                        'type' => $pType,
                        'size' => $pSize,
                        'sold_quantity' => 0,
                        'in_stock_qty' => 0,
                        'on_order_qty' => 0,
                        'total_revenue' => 0.0,
                    ];
                }

                $typeSizesAggregated[$key]['sold_quantity'] += $qty;
                $typeSizesAggregated[$key]['total_revenue'] += $tot;
                if ($isItemOnOrder) {
                    $typeSizesAggregated[$key]['on_order_qty'] += $qty;
                } else {
                    $typeSizesAggregated[$key]['in_stock_qty'] += $qty;
                }
            }
        }

        // Подсчет выручки по виду для доли
        $typeRevenues = [];
        foreach ($typeSizesAggregated as $row) {
            $t = $row['type'];
            $typeRevenues[$t] = ($typeRevenues[$t] ?? 0.0) + $row['total_revenue'];
        }

        // Сортировка по типу и размерам
        $sortedRows = array_values($typeSizesAggregated);
        usort($sortedRows, function ($a, $b) {
            $cmp = strcmp($a['type'], $b['type']);
            if ($cmp !== 0) {
                return $cmp;
            }
            return $b['sold_quantity'] <=> $a['sold_quantity'];
        });

        $r2 = 5;
        foreach ($sortedRows as $row) {
            $typeTotalRev = $typeRevenues[$row['type']] ?? 0.0;
            $share = $typeTotalRev > 0 ? round(($row['total_revenue'] / $typeTotalRev) * 100, 1) . '%' : '0%';
            $avgPrice = $row['sold_quantity'] > 0 ? round($row['total_revenue'] / $row['sold_quantity'], 2) : 0;

            $sheet2->fromArray([
                $row['type'],
                $row['size'],
                $row['sold_quantity'],
                $row['in_stock_qty'],
                $row['on_order_qty'],
                $row['total_revenue'],
                $avgPrice,
                $share,
            ], null, 'A' . $r2);
            $r2++;
        }

        $end2 = max(5, $r2 - 1);
        $totalRow2 = $r2;

        $sheet2->mergeCells("A{$totalRow2}:B{$totalRow2}");
        $sheet2->setCellValue("A{$totalRow2}", 'ИТОГО:');
        $sheet2->setCellValue("C{$totalRow2}", "=SUM(C5:C{$end2})");
        $sheet2->setCellValue("D{$totalRow2}", "=SUM(D5:D{$end2})");
        $sheet2->setCellValue("E{$totalRow2}", "=SUM(E5:E{$end2})");
        $sheet2->setCellValue("F{$totalRow2}", "=SUM(F5:F{$end2})");
        $sheet2->setCellValue("G{$totalRow2}", '—');
        $sheet2->setCellValue("H{$totalRow2}", '100%');

        $sheet2->getStyle("A{$totalRow2}:H{$totalRow2}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => '000000']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
            'borders' => [
                'top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '94A3B8']],
                'bottom' => ['borderStyle' => Border::BORDER_DOUBLE, 'color' => ['rgb' => '000000']],
            ],
        ]);
        $sheet2->getStyle("A{$totalRow2}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet2->getStyle("A5:H{$end2}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E2E8F0');
        $sheet2->getStyle("B5:B{$end2}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet2->getStyle("C5:E{$totalRow2}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet2->getStyle("F5:G{$totalRow2}")->getNumberFormat()->setFormatCode('#,##0 "₸"');
        $sheet2->getStyle("H5:H{$totalRow2}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        foreach (range('A', 'H') as $col) {
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

    /**
     * Основной аналитический метод для панели «Отчёты».
     * Строго опирается на фактические продажи. Не генерирует вымышленные остатки склада.
     */
    public function analytics(Request $request)
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

        // 1. Построение запроса к позициям продаж (sale_items)
        $salesQuery = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'sale_items.product_variant_id')
            ->where('sales.status', 'approved');

        if ($dateFrom) {
            $salesQuery->where('sales.approved_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $salesQuery->where('sales.approved_at', '<=', $dateTo);
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
                ->where('sales.approved_at', '>=', $prevPeriodFrom)
                ->where('sales.approved_at', '<=', $prevPeriodTo);

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
        // 3. ДИНАМИКА ПРОДАЖ ПО ДНЯМ ДЛЯ ГРАФИКА
        // ========================================================
        $dynamicsMap = [];

        // Если задан диапазон дат, инициализируем каждый день нулями для непрерывного графика
        if ($dateFrom && $dateTo && $dateFrom->diffInDays($dateTo) <= 60) {
            $cursor = clone $dateFrom;
            while ($cursor->lte($dateTo)) {
                $dKey = $cursor->format('Y-m-d');
                $dynamicsMap[$dKey] = [
                    'date' => $dKey,
                    'day_label' => $cursor->translatedFormat('d M') ?: $cursor->format('d.m'),
                    'revenue' => 0.0,
                    'units' => 0,
                    'in_stock_units' => 0,
                    'on_order_units' => 0,
                    'sales_count' => 0,
                    '_sale_ids' => [],
                ];
                $cursor->addDay();
            }
        }

        foreach ($saleItems as $it) {
            $dKey = Carbon::parse($it->approved_at)->format('Y-m-d');
            if (! isset($dynamicsMap[$dKey])) {
                $c = Carbon::parse($it->approved_at);
                $dynamicsMap[$dKey] = [
                    'date' => $dKey,
                    'day_label' => $c->format('d.m'),
                    'revenue' => 0.0,
                    'units' => 0,
                    'in_stock_units' => 0,
                    'on_order_units' => 0,
                    'sales_count' => 0,
                    '_sale_ids' => [],
                ];
            }

            $isOnOrder = $it->item_is_on_order !== null ? (bool) $it->item_is_on_order : (bool) $it->sale_is_on_order;
            $qty = (int) $it->quantity;
            $tot = (float) $it->line_total;

            $dynamicsMap[$dKey]['revenue'] += $tot;
            $dynamicsMap[$dKey]['units'] += $qty;
            if ($isOnOrder) {
                $dynamicsMap[$dKey]['on_order_units'] += $qty;
            } else {
                $dynamicsMap[$dKey]['in_stock_units'] += $qty;
            }

            $dynamicsMap[$dKey]['_sale_ids'][$it->sale_id] = true;
        }

        ksort($dynamicsMap);
        $salesDynamics = array_values(array_map(function ($day) {
            $day['sales_count'] = count($day['_sale_ids']);
            $day['revenue'] = round((float) $day['revenue'], 2);
            unset($day['_sale_ids']);
            return $day;
        }, $dynamicsMap));

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

        return response()->json([
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
            'by_type' => $byType,
            'by_category' => $byCategory,
            'by_product' => $byProduct,
            'filters' => [
                'date_from' => $dateFrom?->toDateString(),
                'date_to' => $dateTo?->toDateString(),
                'order_type' => $orderType ?: '',
                'category_id' => $categoryId,
                'search' => $searchTerm,
            ],
            'generated_at' => now()->toIso8601String(),
        ]);
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
            $knownTypes = [
                'Поло', 'Футболка', 'Брюки', 'Юбка', 'Жилет', 'Пиджак', 'Жакет',
                'Рубашка', 'Блузка', 'Сарафан', 'Шорты', 'Платье', 'Галстук',
                'Фартук', 'Костюм', 'Свитшот', 'Худи', 'Толстовка', 'Бомбер',
                'Кардиган', 'Водолазка', 'Куртка', 'Ветровка', 'Кроссовки',
                'Туфли', 'Обувь', 'Ремень', 'Носки', 'Колготки', 'Банты', 'Шеврон', 'Форма'
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
}
