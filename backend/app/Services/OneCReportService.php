<?php

namespace App\Services;

use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Сервис формирования профессиональной торговой и управленческой Excel-отчетности
 * магазина детской одежды и школьной формы по стандартам и логике системы «1С:Предприятие».
 */
class OneCReportService
{
    // Цветовая палитра в строгом корпоративном стиле 1С
    private const COLOR_HEADER_BG = 'E2E8F0';       // Нейтральный светло-серый фон шапки таблицы
    private const COLOR_HEADER_TEXT = '1E293B';     // Темный цвет текста заголовков
    private const COLOR_TOTAL_BG = 'F1F5F9';        // Фон строки итогов
    private const COLOR_ZEBRA_BG = 'F8FAFC';        // Чередование строк (зебра)
    private const COLOR_BORDER = 'CBD5E1';          // Границы ячеек таблицы
    private const COLOR_BORDER_DARK = '94A3B8';     // Верхняя граница итоговой строки
    private const FONT_FAMILY = 'Calibri';          // Стандартный шрифт высокой четкости

    /**
     * Создает полную книгу Excel с 7 листами аналитики в стиле 1С.
     */
    public function generateSpreadsheet(Request $request): Spreadsheet
    {
        $sales = $this->querySales($request);

        $params = [
            'date_from' => $request->filled('date_from') ? $request->string('date_from')->toString() : null,
            'date_to' => $request->filled('date_to') ? $request->string('date_to')->toString() : null,
            'status' => $request->filled('status') ? $request->string('status')->toString() : 'approved',
            'order_type' => $request->input('order_type'),
            'category_id' => $request->filled('category_id') ? (int) $request->input('category_id') : null,
            'type' => $request->filled('type') ? trim($request->string('type')->toString()) : null,
            'size' => $request->filled('size') ? trim($request->string('size')->toString()) : null,
            'search' => $request->filled('search') ? trim($request->string('search')->toString()) : null,
        ];

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName(self::FONT_FAMILY)->setSize(9);

        // Лист 1: Реестр продаж (детальный по товарным позициям)
        $sheet1 = $spreadsheet->getActiveSheet();
        $this->buildSalesRegisterSheet($sheet1, $sales, $params);

        // Лист 2: Ведомость по номенклатуре и характеристикам (размерам)
        $sheet2 = $spreadsheet->createSheet();
        $this->buildNomenclatureSheet($sheet2, $sales, $params);

        // Лист 3: Свод по категориям и видам товаров
        $sheet3 = $spreadsheet->createSheet();
        $this->buildCategorySummarySheet($sheet3, $sales, $params);

        // Лист 4: Динамика продаж по периодам (дням и месяцам)
        $sheet4 = $spreadsheet->createSheet();
        $this->buildPeriodDynamicsSheet($sheet4, $sales, $params);

        // Лист 5: Журнал чеков (уровень документов без дублирования)
        $sheet5 = $spreadsheet->createSheet();
        $this->buildReceiptsJournalSheet($sheet5, $sales, $params);

        // Лист 6: Выработка сотрудников (кассиров)
        $sheet6 = $spreadsheet->createSheet();
        $this->buildCashierPerformanceSheet($sheet6, $sales, $params);

        // Лист 7: Справка об учетной политике и источниках данных
        $sheet7 = $spreadsheet->createSheet();
        $this->buildAccountingNoteSheet($sheet7, $params);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * Сохраняет сформированный отчет в файл.
     */
    public function saveToFile(Spreadsheet $spreadsheet, string $filePath): void
    {
        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);
    }

    /**
     * Выборка продаж из БД с оптимизированными связями.
     */
    public function querySales(Request $request): Collection
    {
        $salesQuery = Sale::query()
            ->with([
                'items.product.category',
                'items.productVariant',
                'cashier',
                'approver',
            ]);

        // Ограничение видимости для роли кассира
        if ($request->user() && method_exists($request->user(), 'isCashier') && $request->user()->isCashier()) {
            $salesQuery->where('cashier_id', $request->user()->id);
        }

        // Фильтр по статусу чека (по умолчанию только утвержденные продажи)
        if ($request->filled('status')) {
            $salesQuery->where('status', $request->string('status')->toString());
        } else {
            $salesQuery->where('status', 'approved');
        }

        // Фильтр по типу заказа (в наличии / под заказ)
        if ($request->has('is_on_order') && $request->input('is_on_order') !== null && $request->input('is_on_order') !== '') {
            $isOnOrder = filter_var($request->input('is_on_order'), FILTER_VALIDATE_BOOLEAN);
            $salesQuery->where('is_on_order', $isOnOrder);
        }

        // Фильтр по диапазону дат
        if ($request->filled('date_from')) {
            $from = Carbon::parse($request->input('date_from'))->startOfDay();
            $salesQuery->where(function ($sq) use ($from) {
                $sq->where(function ($d) use ($from) {
                    $d->whereNotNull('approved_at')->where('approved_at', '>=', $from->toDateTimeString());
                })->orWhere(function ($d) use ($from) {
                    $d->whereNull('approved_at')->where('created_at', '>=', $from->toDateTimeString());
                });
            });
        }

        if ($request->filled('date_to')) {
            $to = Carbon::parse($request->input('date_to'))->endOfDay();
            $salesQuery->where(function ($sq) use ($to) {
                $sq->where(function ($d) use ($to) {
                    $d->whereNotNull('approved_at')->where('approved_at', '<=', $to->toDateTimeString());
                })->orWhere(function ($d) use ($to) {
                    $d->whereNull('approved_at')->where('created_at', '<=', $to->toDateTimeString());
                });
            });
        }

        return $salesQuery
            ->orderByDesc('approved_at')
            ->orderByDesc('id')
            ->get();
    }

    // =========================================================================
    // ЛИСТ 1: РЕЕСТР ПРОДАЖ (ДЕТАЛЬНЫЙ ПО ТОВАРНЫМ ПОЗИЦИЯМ)
    // =========================================================================

    private function buildSalesRegisterSheet($sheet, Collection $sales, array $params): void
    {
        $sheet->setTitle('Реестр продаж');
        $sheet->setShowGridlines(true);

        $this->renderReportHeader(
            $sheet,
            'РЕГИСТР НАКОПЛЕНИЯ: ПРОДАЖИ (ОПЕРАЦИОННЫЙ ЖУРНАЛ)',
            'Детальный попозиционный реестр завершенных продаж и заказов номенклатуры с автоматическим пересчетом итогов',
            $params
        );

        $headers = [
            '№ п/п',
            'Документ (№ чека)',
            'Дата и время',
            'Период (Месяц)',
            'Тип операции',
            'Категория',
            'Вид изделия',
            'Номенклатура (Товар)',
            'Размер (Характеристика)',
            'Артикул (SKU)',
            'Цвет',
            'Кол-во, шт.',
            'Базовая цена ед., ₸',
            'Скидка ед., ₸',
            'Скидка, %',
            'Цена продажи ед., ₸',
            'Базовая сумма, ₸',
            'Сумма скидки, ₸',
            'Сумма выручки, ₸',
            'Себестоимость ед., ₸',
            'Себестоимость общ., ₸',
            'Валовая прибыль, ₸',
            'Рентабельность, %',
            'Кассир (Ответственный)',
            'Статус документа',
            'Статус заказа',
            'Срок исполнения',
            'Примечание кассира',
        ];

        // Строка 7: Заголовки таблицы
        $headerRow = 7;
        $sheet->fromArray($headers, null, "A{$headerRow}");
        $lastCol = Coordinate::stringFromColumnIndex(count($headers)); // AB

        $this->styleTableHeader($sheet, "A{$headerRow}:{$lastCol}{$headerRow}");
        $sheet->getRowDimension($headerRow)->setRowHeight(28);

        $r = 8;
        $seq = 1;

        $typeFilter = $params['type'] ?? null;
        $sizeFilter = $params['size'] ?? null;
        $catFilterId = $params['category_id'] ?? null;
        $searchTerm = $params['search'] ?? null;

        foreach ($sales as $sale) {
            $saleIsOnOrder = (bool) $sale->is_on_order;
            $dateTime = ($sale->approved_at ?? $sale->created_at)?->timezone('Asia/Almaty');
            $dateStr = $dateTime ? $dateTime->format('d.m.Y H:i') : '—';
            $monthStr = $dateTime ? $dateTime->format('Y-m') : '—';
            $docNum = '№' . $sale->id;
            $cashierName = $sale->cashier?->name ?? 'Не указан';
            $docStatus = match ($sale->status) {
                'approved' => 'Оформлен',
                'rejected' => 'Отменён',
                default => 'В ожидании',
            };
            $orderStatus = match ($sale->fulfillment_status) {
                'fulfilled', 'completed' => 'Выдан',
                'ready' => 'Готов',
                'in_progress' => 'В пошиве',
                'cancelled' => 'Отменен',
                default => ($saleIsOnOrder ? 'Новый' : 'Выдан сразу'),
            };
            $dueDateStr = $sale->due_date ? Carbon::parse($sale->due_date)->format('d.m.Y') : '—';
            $note = $sale->cashier_note ?: '—';

            if ($sale->items->isEmpty()) {
                continue;
            }

            foreach ($sale->items as $item) {
                $isItemOnOrder = $item->is_on_order !== null ? (bool) $item->is_on_order : $saleIsOnOrder;
                $orderTypeLabel = $isItemOnOrder ? '📦 Под заказ' : 'В наличии';
                $prodName = $item->product_name ?: $item->product?->name ?: 'Товар без наименования';
                $catName = $item->product?->category?->name ?: 'Без категории';
                $catId = $item->product?->category_id;
                $prodType = $this->resolveProductType(
                    $item->product_type,
                    $item->product?->type,
                    $prodName,
                    $catName
                );

                $isHat = $this->isHatTypeOrName($prodType, $prodName);
                $size = $isHat ? '—' : ($item->variant_size ?: '—');
                $sku = $item->product_sku ?: $item->productVariant?->sku ?: '—';
                $color = $item->productVariant?->color ?: $item->product?->color ?: '—';

                // Применение фильтров
                if ($catFilterId && (int) $catId !== (int) $catFilterId) {
                    continue;
                }
                if ($typeFilter && mb_stripos($prodType, $typeFilter) === false && mb_stripos($prodName, $typeFilter) === false) {
                    continue;
                }
                if ($sizeFilter && mb_strtolower($size) !== mb_strtolower($sizeFilter)) {
                    continue;
                }
                if ($searchTerm && mb_stripos($prodName, $searchTerm) === false && mb_stripos($sku, $searchTerm) === false) {
                    continue;
                }

                $qty = (int) $item->quantity;
                $salePrice = (float) $item->sale_price;
                $discAmtPerUnit = (float) ($item->discount_amount ?: 0);
                $origPrice = (float) ($item->original_price ?: ($salePrice + $discAmtPerUnit));
                $discPct = $origPrice > 0 ? round(($discAmtPerUnit / $origPrice) * 100, 2) : 0;
                $purchasePrice = (float) ($item->purchase_price ?: $item->productVariant?->purchase_price ?: $item->product?->purchase_price ?: 0);

                // Данные строки с формулами Excel для полной математической точности
                $rowData = [
                    $seq++,                                         // A: № п/п
                    $docNum,                                        // B: № чека
                    $dateStr,                                       // C: Дата и время
                    $monthStr,                                      // D: Месяц
                    $orderTypeLabel,                                // E: Тип операции
                    $catName,                                       // F: Категория
                    $prodType,                                      // G: Вид изделия
                    $prodName,                                      // H: Номенклатура
                    $size,                                          // I: Размер
                    $sku,                                           // J: Артикул
                    $color,                                         // K: Цвет
                    $qty,                                           // L: Количество, шт.
                    $origPrice,                                     // M: Базовая цена ед.
                    $discAmtPerUnit,                                // N: Скидка ед.
                    "=IF(M{$r}>0, N{$r}/M{$r}, 0)",                 // O: Скидка, %
                    "=M{$r}-N{$r}",                                 // P: Цена продажи ед.
                    "=L{$r}*M{$r}",                                 // Q: Базовая сумма (общ.)
                    "=L{$r}*N{$r}",                                 // R: Сумма скидки (общ.)
                    "=L{$r}*P{$r}",                                 // S: Сумма выручки
                    $purchasePrice,                                 // T: Себестоимость ед.
                    "=L{$r}*T{$r}",                                 // U: Себестоимость общ.
                    "=S{$r}-U{$r}",                                 // V: Валовая прибыль
                    "=IF(S{$r}>0, V{$r}/S{$r}, 0)",                 // W: Рентабельность %
                    $cashierName,                                   // X: Кассир
                    $docStatus,                                     // Y: Статус чека
                    $orderStatus,                                   // Z: Статус заказа
                    $dueDateStr,                                    // AA: Срок исполнения
                    $note,                                          // AB: Примечание
                ];

                $sheet->fromArray($rowData, null, "A{$r}");

                if ($r % 2 === 0) {
                    $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_ZEBRA_BG);
                }

                $r++;
            }
        }

        $dataEndRow = max(8, $r - 1);

        // Строка 5: ДИНАМИЧЕСКИЙ СВОД ПО ВЫБОРКЕ (АВТОМАТИЧЕСКИ ПЕРЕСЧИТЫВАЕТСЯ ПРИ ФИЛЬТРАЦИИ)
        $sheet->mergeCells('A5:K5');
        $sheet->setCellValue('A5', 'ИТОГО ПО ВЫБОРКЕ (ДИНАМИЧЕСКИЙ ПЕРЕСЧЕТ ПРИ ФИЛЬТРАЦИИ):');
        $sheet->setCellValue('L5', "=SUBTOTAL(109, L8:L{$dataEndRow})");
        $sheet->setCellValue('M5', '—');
        $sheet->setCellValue('N5', '—');
        $sheet->setCellValue('O5', "=IF(Q5>0, R5/Q5, 0)");
        $sheet->setCellValue('P5', '—');
        $sheet->setCellValue('Q5', "=SUBTOTAL(109, Q8:Q{$dataEndRow})");
        $sheet->setCellValue('R5', "=SUBTOTAL(109, R8:R{$dataEndRow})");
        $sheet->setCellValue('S5', "=SUBTOTAL(109, S8:S{$dataEndRow})");
        $sheet->setCellValue('T5', '—');
        $sheet->setCellValue('U5', "=SUBTOTAL(109, U8:U{$dataEndRow})");
        $sheet->setCellValue('V5', "=SUBTOTAL(109, V8:V{$dataEndRow})");
        $sheet->setCellValue('W5', "=IF(S5>0, V5/S5, 0)");
        $sheet->mergeCells("X5:AB5");
        $sheet->setCellValue('X5', "=SUBTOTAL(103, A8:A{$dataEndRow}) & \" поз.\"");

        $this->styleDynamicKpiRow($sheet, "A5:{$lastCol}5");

        // Нижняя бухгалтерская строка итогов (строка $r)
        $totalRow = $r;
        $sheet->mergeCells("A{$totalRow}:K{$totalRow}");
        $sheet->setCellValue("A{$totalRow}", 'ВСЕГО ПО РЕЕСТРУ:');
        $sheet->setCellValue("L{$totalRow}", "=SUBTOTAL(109, L8:L{$dataEndRow})");
        $sheet->setCellValue("M{$totalRow}", '—');
        $sheet->setCellValue("N{$totalRow}", '—');
        $sheet->setCellValue("O{$totalRow}", "=IF(Q{$totalRow}>0, R{$totalRow}/Q{$totalRow}, 0)");
        $sheet->setCellValue("P{$totalRow}", '—');
        $sheet->setCellValue("Q{$totalRow}", "=SUBTOTAL(109, Q8:Q{$dataEndRow})");
        $sheet->setCellValue("R{$totalRow}", "=SUBTOTAL(109, R8:R{$dataEndRow})");
        $sheet->setCellValue("S{$totalRow}", "=SUBTOTAL(109, S8:S{$dataEndRow})");
        $sheet->setCellValue("T{$totalRow}", '—');
        $sheet->setCellValue("U{$totalRow}", "=SUBTOTAL(109, U8:U{$dataEndRow})");
        $sheet->setCellValue("V{$totalRow}", "=SUBTOTAL(109, V8:V{$dataEndRow})");
        $sheet->setCellValue("W{$totalRow}", "=IF(S{$totalRow}>0, V{$totalRow}/S{$totalRow}, 0)");
        $sheet->mergeCells("X{$totalRow}:AB{$totalRow}");
        $sheet->setCellValue("X{$totalRow}", "=SUBTOTAL(103, A8:A{$dataEndRow}) & \" позиций\"");

        $this->styleTotalRow($sheet, "A{$totalRow}:{$lastCol}{$totalRow}");

        // Форматирование диапазонов данных
        $sheet->getStyle("A8:{$lastCol}{$dataEndRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::COLOR_BORDER);

        // Выравнивание
        $sheet->getStyle("A8:E{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("I8:K{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("Y8:AA{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Числовые форматы
        $sheet->getStyle("L8:L{$totalRow}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("M8:N{$dataEndRow}")->getNumberFormat()->setFormatCode('#,##0 [$₸-43F]');
        $sheet->getStyle("O8:O{$totalRow}")->getNumberFormat()->setFormatCode('0.0%');
        $sheet->getStyle("P8:P{$dataEndRow}")->getNumberFormat()->setFormatCode('#,##0 [$₸-43F]');
        $sheet->getStyle("Q8:S{$totalRow}")->getNumberFormat()->setFormatCode('#,##0 [$₸-43F]');
        $sheet->getStyle("T8:T{$dataEndRow}")->getNumberFormat()->setFormatCode('#,##0 [$₸-43F]');
        $sheet->getStyle("U8:V{$totalRow}")->getNumberFormat()->setFormatCode('#,##0 [$₸-43F]');
        $sheet->getStyle("W8:W{$totalRow}")->getNumberFormat()->setFormatCode('0.0%');

        // Автофильтр и фиксация шапки таблицы
        $sheet->setAutoFilter("A{$headerRow}:{$lastCol}{$dataEndRow}");
        $sheet->freezePane('A8');

        $this->autoFitColumns($sheet, 'A', $lastCol);
    }

    // =========================================================================
    // ЛИСТ 2: ВЕДОМОСТЬ ПО НОМЕНКЛАТУРЕ И РАЗМЕРАМ
    // =========================================================================

    private function buildNomenclatureSheet($sheet, Collection $sales, array $params): void
    {
        $sheet->setTitle('По номенклатуре');
        $sheet->setShowGridlines(true);

        $this->renderReportHeader(
            $sheet,
            'ВЕДОМОСТЬ ПРОДАЖ ПО НОМЕНКЛАТУРЕ И ХАРАКТЕРИСТИКАМ (РАЗМЕРАМ)',
            'Аналитический отчет по продажам детской одежды в разрезе товарных позиций и размерной сетки',
            $params
        );

        $headers = [
            '№ п/п',
            'Категория',
            'Вид изделия',
            'Номенклатура (Товар)',
            'Размер (Характеристика)',
            'Продано всего, шт.',
            '✓ Из наличия, шт.',
            '📦 Под заказ, шт.',
            'Базовая сумма, ₸',
            'Скидки, ₸',
            'Выручка (Фактически), ₸',
            'Себестоимость общ., ₸',
            'Валовая прибыль, ₸',
            'Средняя цена шт., ₸',
            'Рентабельность, %',
            'Доля в выручке вида, %',
        ];

        $headerRow = 7;
        $sheet->fromArray($headers, null, "A{$headerRow}");
        $lastCol = Coordinate::stringFromColumnIndex(count($headers)); // P

        $this->styleTableHeader($sheet, "A{$headerRow}:{$lastCol}{$headerRow}");
        $sheet->getRowDimension($headerRow)->setRowHeight(28);

        // Агрегация данных по комбинации [Категория, Вид, Товар, Размер]
        $aggregated = [];
        $typeTotals = [];

        foreach ($sales as $sale) {
            if ($sale->status === 'rejected') {
                continue;
            }
            $saleIsOnOrder = (bool) $sale->is_on_order;

            foreach ($sale->items as $item) {
                $prodName = $item->product_name ?: $item->product?->name ?: 'Товар без наименования';
                $catName = $item->product?->category?->name ?: 'Без категории';
                $prodType = $this->resolveProductType($item->product_type, $item->product?->type, $prodName, $catName);
                $isHat = $this->isHatTypeOrName($prodType, $prodName);
                $size = $isHat ? '—' : ($item->variant_size ?: '—');
                $isItemOnOrder = $item->is_on_order !== null ? (bool) $item->is_on_order : $saleIsOnOrder;

                $qty = (int) $item->quantity;
                $lineTotal = (float) $item->line_total;
                $discAmt = (float) (($item->discount_amount ?: 0) * $qty);
                $origSubtotal = (float) (($item->original_price ?: ($item->sale_price + ($item->discount_amount ?: 0))) * $qty);
                $unitCost = (float) ($item->purchase_price ?: $item->productVariant?->purchase_price ?: $item->product?->purchase_price ?: 0);
                $costTotal = $qty * $unitCost;
                $profitTotal = $lineTotal - $costTotal;

                $key = "{$catName}___{$prodType}___{$prodName}___{$size}";

                if (! isset($aggregated[$key])) {
                    $aggregated[$key] = [
                        'category' => $catName,
                        'type' => $prodType,
                        'name' => $prodName,
                        'size' => $size,
                        'qty' => 0,
                        'in_stock_qty' => 0,
                        'on_order_qty' => 0,
                        'orig_total' => 0.0,
                        'discount_total' => 0.0,
                        'revenue_total' => 0.0,
                        'cost_total' => 0.0,
                        'profit_total' => 0.0,
                    ];
                }

                $aggregated[$key]['qty'] += $qty;
                $aggregated[$key]['orig_total'] += $origSubtotal;
                $aggregated[$key]['discount_total'] += $discAmt;
                $aggregated[$key]['revenue_total'] += $lineTotal;
                $aggregated[$key]['cost_total'] += $costTotal;
                $aggregated[$key]['profit_total'] += $profitTotal;

                if ($isItemOnOrder) {
                    $aggregated[$key]['on_order_qty'] += $qty;
                } else {
                    $aggregated[$key]['in_stock_qty'] += $qty;
                }

                $typeTotals[$prodType] = ($typeTotals[$prodType] ?? 0.0) + $lineTotal;
            }
        }

        // Сортировка: Категория -> Вид -> Наименование -> Размер
        $rows = array_values($aggregated);
        usort($rows, function ($a, $b) {
            $cmp = strcmp($a['category'], $b['category']);
            if ($cmp !== 0) return $cmp;
            $cmp = strcmp($a['type'], $b['type']);
            if ($cmp !== 0) return $cmp;
            $cmp = strcmp($a['name'], $b['name']);
            if ($cmp !== 0) return $cmp;
            return $this->compareSizes($a['size'], $b['size']);
        });

        $r = 8;
        $seq = 1;

        foreach ($rows as $row) {
            $typeRev = $typeTotals[$row['type']] ?? 0.0;
            $share = $typeRev > 0 ? round(($row['revenue_total'] / $typeRev) * 100, 1) . '%' : '0.0%';

            $sheet->fromArray([
                $seq++,                                         // A: №
                $row['category'],                               // B: Категория
                $row['type'],                                   // C: Вид изделия
                $row['name'],                                   // D: Номенклатура
                $row['size'],                                   // E: Размер
                $row['qty'],                                    // F: Продано шт.
                $row['in_stock_qty'],                           // G: Из наличия
                $row['on_order_qty'],                           // H: Под заказ
                $row['orig_total'],                             // I: Базовая сумма
                $row['discount_total'],                         // J: Скидки
                $row['revenue_total'],                          // K: Выручка
                $row['cost_total'],                             // L: Себестоимость
                $row['profit_total'],                           // M: Валовая прибыль
                "=IF(F{$r}>0, K{$r}/F{$r}, 0)",                 // N: Ср. цена шт.
                "=IF(K{$r}>0, M{$r}/K{$r}, 0)",                 // O: Рентабельность %
                $share,                                         // P: Доля вида
            ], null, "A{$r}");

            if ($r % 2 === 0) {
                $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_ZEBRA_BG);
            }

            $r++;
        }

        $dataEndRow = max(8, $r - 1);

        // Строка 5: Динамический верхний свод
        $sheet->mergeCells('A5:E5');
        $sheet->setCellValue('A5', 'ИТОГО ПО ВЫБОРКЕ (ДИНАМИЧЕСКИЙ ПЕРЕСЧЕТ ПРИ ФИЛЬТРАЦИИ):');
        $sheet->setCellValue('F5', "=SUBTOTAL(109, F8:F{$dataEndRow})");
        $sheet->setCellValue('G5', "=SUBTOTAL(109, G8:G{$dataEndRow})");
        $sheet->setCellValue('H5', "=SUBTOTAL(109, H8:H{$dataEndRow})");
        $sheet->setCellValue('I5', "=SUBTOTAL(109, I8:I{$dataEndRow})");
        $sheet->setCellValue('J5', "=SUBTOTAL(109, J8:J{$dataEndRow})");
        $sheet->setCellValue('K5', "=SUBTOTAL(109, K8:K{$dataEndRow})");
        $sheet->setCellValue('L5', "=SUBTOTAL(109, L8:L{$dataEndRow})");
        $sheet->setCellValue('M5', "=SUBTOTAL(109, M8:M{$dataEndRow})");
        $sheet->setCellValue('N5', "=IF(F5>0, K5/F5, 0)");
        $sheet->setCellValue('O5', "=IF(K5>0, M5/K5, 0)");
        $sheet->setCellValue('P5', '100.0%');

        $this->styleDynamicKpiRow($sheet, "A5:{$lastCol}5");

        // Нижняя итоговая строка
        $totalRow = $r;
        $sheet->mergeCells("A{$totalRow}:E{$totalRow}");
        $sheet->setCellValue("A{$totalRow}", 'ВСЕГО ПО НОМЕНКЛАТУРЕ:');
        $sheet->setCellValue("F{$totalRow}", "=SUBTOTAL(109, F8:F{$dataEndRow})");
        $sheet->setCellValue("G{$totalRow}", "=SUBTOTAL(109, G8:G{$dataEndRow})");
        $sheet->setCellValue("H{$totalRow}", "=SUBTOTAL(109, H8:H{$dataEndRow})");
        $sheet->setCellValue("I{$totalRow}", "=SUBTOTAL(109, I8:I{$dataEndRow})");
        $sheet->setCellValue("J{$totalRow}", "=SUBTOTAL(109, J8:J{$dataEndRow})");
        $sheet->setCellValue("K{$totalRow}", "=SUBTOTAL(109, K8:K{$dataEndRow})");
        $sheet->setCellValue("L{$totalRow}", "=SUBTOTAL(109, L8:L{$dataEndRow})");
        $sheet->setCellValue("M{$totalRow}", "=SUBTOTAL(109, M8:M{$dataEndRow})");
        $sheet->setCellValue("N{$totalRow}", "=IF(F{$totalRow}>0, K{$totalRow}/F{$totalRow}, 0)");
        $sheet->setCellValue("O{$totalRow}", "=IF(K{$totalRow}>0, M{$totalRow}/K{$totalRow}, 0)");
        $sheet->setCellValue("P{$totalRow}", '100.0%');

        $this->styleTotalRow($sheet, "A{$totalRow}:{$lastCol}{$totalRow}");

        $sheet->getStyle("A8:{$lastCol}{$dataEndRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::COLOR_BORDER);
        $sheet->getStyle("A8:A{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("E8:E{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("P8:P{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $sheet->getStyle("F8:H{$totalRow}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("I8:N{$totalRow}")->getNumberFormat()->setFormatCode('#,##0 [$₸-43F]');
        $sheet->getStyle("O8:O{$totalRow}")->getNumberFormat()->setFormatCode('0.0%');

        $sheet->setAutoFilter("A{$headerRow}:{$lastCol}{$dataEndRow}");
        $sheet->freezePane('A8');

        $this->autoFitColumns($sheet, 'A', $lastCol);
    }

    // =========================================================================
    // ЛИСТ 3: СВОД ПО КАТЕГОРИЯМ И ВИДАМ ТОВАРОВ
    // =========================================================================

    private function buildCategorySummarySheet($sheet, Collection $sales, array $params): void
    {
        $sheet->setTitle('Категории и виды');
        $sheet->setShowGridlines(true);

        $this->renderReportHeader(
            $sheet,
            'СВОДКА ПРОДАЖ ПО КАТЕГОРИЯМ И ВИДАМ ИЗДЕЛИЙ',
            'Группировка продаж по товарным группам, видам одежды и долям в общей выручке',
            $params
        );

        $headers = [
            '№ п/п',
            'Категория номенклатуры',
            'Вид изделия',
            'Продано всего, шт.',
            '✓ В наличии, шт.',
            '📦 Под заказ, шт.',
            'Базовая сумма, ₸',
            'Сумма скидки, ₸',
            'Выручка (Фактически), ₸',
            'Себестоимость общ., ₸',
            'Валовая прибыль, ₸',
            'Средняя цена шт., ₸',
            'Рентабельность, %',
            'Доля в выручке магазина, %',
        ];

        $headerRow = 7;
        $sheet->fromArray($headers, null, "A{$headerRow}");
        $lastCol = Coordinate::stringFromColumnIndex(count($headers)); // N

        $this->styleTableHeader($sheet, "A{$headerRow}:{$lastCol}{$headerRow}");
        $sheet->getRowDimension($headerRow)->setRowHeight(28);

        $groups = [];
        $grandTotalRev = 0.0;

        foreach ($sales as $sale) {
            if ($sale->status === 'rejected') {
                continue;
            }
            $saleIsOnOrder = (bool) $sale->is_on_order;

            foreach ($sale->items as $item) {
                $pName = $item->product_name ?: $item->product?->name ?: 'Товар';
                $catName = $item->product?->category?->name ?: 'Без категории';
                $pType = $this->resolveProductType($item->product_type, $item->product?->type, $pName, $catName);
                $isItemOnOrder = $item->is_on_order !== null ? (bool) $item->is_on_order : $saleIsOnOrder;

                $qty = (int) $item->quantity;
                $tot = (float) $item->line_total;
                $discAmt = (float) (($item->discount_amount ?: 0) * $qty);
                $origTot = (float) (($item->original_price ?: ($item->sale_price + ($item->discount_amount ?: 0))) * $qty);
                $unitCost = (float) ($item->purchase_price ?: $item->productVariant?->purchase_price ?: $item->product?->purchase_price ?: 0);
                $costTot = $qty * $unitCost;
                $profitTot = $tot - $costTot;

                $key = "{$catName}___{$pType}";

                if (! isset($groups[$key])) {
                    $groups[$key] = [
                        'category' => $catName,
                        'type' => $pType,
                        'qty' => 0,
                        'in_stock' => 0,
                        'on_order' => 0,
                        'orig_total' => 0.0,
                        'discount_total' => 0.0,
                        'revenue_total' => 0.0,
                        'cost_total' => 0.0,
                        'profit_total' => 0.0,
                    ];
                }

                $groups[$key]['qty'] += $qty;
                $groups[$key]['orig_total'] += $origTot;
                $groups[$key]['discount_total'] += $discAmt;
                $groups[$key]['revenue_total'] += $tot;
                $groups[$key]['cost_total'] += $costTot;
                $groups[$key]['profit_total'] += $profitTot;
                $grandTotalRev += $tot;

                if ($isItemOnOrder) {
                    $groups[$key]['on_order'] += $qty;
                } else {
                    $groups[$key]['in_stock'] += $qty;
                }
            }
        }

        // Сортировка по выручке по убыванию
        $rows = array_values($groups);
        usort($rows, fn ($a, $b) => $b['revenue_total'] <=> $a['revenue_total']);

        $r = 8;
        $seq = 1;

        foreach ($rows as $row) {
            $sheet->fromArray([
                $seq++,                                         // A
                $row['category'],                               // B
                $row['type'],                                   // C
                $row['qty'],                                    // D
                $row['in_stock'],                               // E
                $row['on_order'],                               // F
                $row['orig_total'],                             // G
                $row['discount_total'],                         // H
                $row['revenue_total'],                          // I
                $row['cost_total'],                             // J
                $row['profit_total'],                           // K
                "=IF(D{$r}>0, I{$r}/D{$r}, 0)",                 // L
                "=IF(I{$r}>0, K{$r}/I{$r}, 0)",                 // M
                "=IF(I$5>0, I{$r}/I$5, 0)",                     // N: Доля в общей выборке
            ], null, "A{$r}");

            if ($r % 2 === 0) {
                $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_ZEBRA_BG);
            }

            $r++;
        }

        $dataEndRow = max(8, $r - 1);

        // Строка 5: Верхний свод
        $sheet->mergeCells('A5:C5');
        $sheet->setCellValue('A5', 'ИТОГО ПО ВЫБОРКЕ (ДИНАМИЧЕСКИЙ ПЕРЕСЧЕТ ПРИ ФИЛЬТРАЦИИ):');
        $sheet->setCellValue('D5', "=SUBTOTAL(109, D8:D{$dataEndRow})");
        $sheet->setCellValue('E5', "=SUBTOTAL(109, E8:E{$dataEndRow})");
        $sheet->setCellValue('F5', "=SUBTOTAL(109, F8:F{$dataEndRow})");
        $sheet->setCellValue('G5', "=SUBTOTAL(109, G8:G{$dataEndRow})");
        $sheet->setCellValue('H5', "=SUBTOTAL(109, H8:H{$dataEndRow})");
        $sheet->setCellValue('I5', "=SUBTOTAL(109, I8:I{$dataEndRow})");
        $sheet->setCellValue('J5', "=SUBTOTAL(109, J8:J{$dataEndRow})");
        $sheet->setCellValue('K5', "=SUBTOTAL(109, K8:K{$dataEndRow})");
        $sheet->setCellValue('L5', "=IF(D5>0, I5/D5, 0)");
        $sheet->setCellValue('M5', "=IF(I5>0, K5/I5, 0)");
        $sheet->setCellValue('N5', '100.0%');

        $this->styleDynamicKpiRow($sheet, "A5:{$lastCol}5");

        // Нижняя итоговая строка
        $totalRow = $r;
        $sheet->mergeCells("A{$totalRow}:C{$totalRow}");
        $sheet->setCellValue("A{$totalRow}", 'ВСЕГО ПО КАТЕГОРИЯМ:');
        $sheet->setCellValue("D{$totalRow}", "=SUBTOTAL(109, D8:D{$dataEndRow})");
        $sheet->setCellValue("E{$totalRow}", "=SUBTOTAL(109, E8:E{$dataEndRow})");
        $sheet->setCellValue("F{$totalRow}", "=SUBTOTAL(109, F8:F{$dataEndRow})");
        $sheet->setCellValue("G{$totalRow}", "=SUBTOTAL(109, G8:G{$dataEndRow})");
        $sheet->setCellValue("H{$totalRow}", "=SUBTOTAL(109, H8:H{$dataEndRow})");
        $sheet->setCellValue("I{$totalRow}", "=SUBTOTAL(109, I8:I{$dataEndRow})");
        $sheet->setCellValue("J{$totalRow}", "=SUBTOTAL(109, J8:J{$dataEndRow})");
        $sheet->setCellValue("K{$totalRow}", "=SUBTOTAL(109, K8:K{$dataEndRow})");
        $sheet->setCellValue("L{$totalRow}", "=IF(D{$totalRow}>0, I{$totalRow}/D{$totalRow}, 0)");
        $sheet->setCellValue("M{$totalRow}", "=IF(I{$totalRow}>0, K{$totalRow}/I{$totalRow}, 0)");
        $sheet->setCellValue("N{$totalRow}", '100.0%');

        $this->styleTotalRow($sheet, "A{$totalRow}:{$lastCol}{$totalRow}");

        $sheet->getStyle("A8:{$lastCol}{$dataEndRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::COLOR_BORDER);
        $sheet->getStyle("A8:A{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->getStyle("D8:F{$totalRow}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("G8:L{$totalRow}")->getNumberFormat()->setFormatCode('#,##0 [$₸-43F]');
        $sheet->getStyle("M8:N{$totalRow}")->getNumberFormat()->setFormatCode('0.0%');

        $sheet->setAutoFilter("A{$headerRow}:{$lastCol}{$dataEndRow}");
        $sheet->freezePane('A8');

        $this->autoFitColumns($sheet, 'A', $lastCol);
    }

    // =========================================================================
    // ЛИСТ 4: ДИНАМИКА ПО ПЕРИОДАМ (ДНЯМ И МЕСЯЦАМ)
    // =========================================================================

    private function buildPeriodDynamicsSheet($sheet, Collection $sales, array $params): void
    {
        $sheet->setTitle('Динамика по дням');
        $sheet->setShowGridlines(true);

        $this->renderReportHeader(
            $sheet,
            'ДИНАМИКА ПРОДАЖ ПО КАЛЕНДАРНЫМ ПЕРИОДАМ (ДНЯМ И МЕСЯЦАМ)',
            'Посуточный учет операций, выручки, структуры чеков и среднего чека',
            $params
        );

        $headers = [
            '№ п/п',
            'Месяц (ГГГГ-ММ)',
            'Дата',
            'День недели',
            'Количество чеков',
            'Продано вещей, шт.',
            '✓ Из наличия, шт.',
            '📦 Под заказ, шт.',
            'Базовая сумма, ₸',
            'Скидки, ₸',
            'Выручка (Фактически), ₸',
            'Себестоимость, ₸',
            'Валовая прибыль, ₸',
            'Средний чек дня, ₸',
            'Вещей в чеке, шт.',
        ];

        $headerRow = 7;
        $sheet->fromArray($headers, null, "A{$headerRow}");
        $lastCol = Coordinate::stringFromColumnIndex(count($headers)); // O

        $this->styleTableHeader($sheet, "A{$headerRow}:{$lastCol}{$headerRow}");
        $sheet->getRowDimension($headerRow)->setRowHeight(28);

        $days = [];

        foreach ($sales as $sale) {
            if ($sale->status === 'rejected') {
                continue;
            }
            $dateTime = ($sale->approved_at ?? $sale->created_at)?->timezone('Asia/Almaty');
            $dateKey = $dateTime ? $dateTime->format('Y-m-d') : 'Без даты';
            $monthKey = $dateTime ? $dateTime->format('Y-m') : '—';
            $dayName = match ($dateTime?->dayOfWeekIso) {
                1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс', default => '—'
            };

            if (! isset($days[$dateKey])) {
                $days[$dateKey] = [
                    'date_key' => $dateKey,
                    'date_formatted' => $dateTime ? $dateTime->format('d.m.Y') : 'Без даты',
                    'month' => $monthKey,
                    'day_name' => $dayName,
                    'checks_count' => 0,
                    'total_qty' => 0,
                    'in_stock_qty' => 0,
                    'on_order_qty' => 0,
                    'orig_total' => 0.0,
                    'discount_total' => 0.0,
                    'revenue_total' => 0.0,
                    'cost_total' => 0.0,
                    'profit_total' => 0.0,
                ];
            }

            $days[$dateKey]['checks_count']++;
            $saleIsOnOrder = (bool) $sale->is_on_order;

            foreach ($sale->items as $it) {
                $qty = (int) $it->quantity;
                $tot = (float) $it->line_total;
                $discAmt = (float) (($it->discount_amount ?: 0) * $qty);
                $origTot = (float) (($it->original_price ?: ($it->sale_price + ($it->discount_amount ?: 0))) * $qty);
                $unitCost = (float) ($it->purchase_price ?: $it->productVariant?->purchase_price ?: $it->product?->purchase_price ?: 0);
                $costTot = $qty * $unitCost;

                $days[$dateKey]['total_qty'] += $qty;
                $days[$dateKey]['orig_total'] += $origTot;
                $days[$dateKey]['discount_total'] += $discAmt;
                $days[$dateKey]['revenue_total'] += $tot;
                $days[$dateKey]['cost_total'] += $costTot;
                $days[$dateKey]['profit_total'] += ($tot - $costTot);

                $isOnOrder = $it->is_on_order !== null ? (bool) $it->is_on_order : $saleIsOnOrder;
                if ($isOnOrder) {
                    $days[$dateKey]['on_order_qty'] += $qty;
                } else {
                    $days[$dateKey]['in_stock_qty'] += $qty;
                }
            }
        }

        krsort($days); // От новых дней к более ранним

        $r = 8;
        $seq = 1;

        foreach ($days as $dayRow) {
            $sheet->fromArray([
                $seq++,                                         // A
                $dayRow['month'],                               // B
                $dayRow['date_formatted'],                      // C
                $dayRow['day_name'],                            // D
                $dayRow['checks_count'],                        // E
                $dayRow['total_qty'],                           // F
                $dayRow['in_stock_qty'],                        // G
                $dayRow['on_order_qty'],                        // H
                $dayRow['orig_total'],                          // I
                $dayRow['discount_total'],                      // J
                $dayRow['revenue_total'],                       // K
                $dayRow['cost_total'],                          // L
                $dayRow['profit_total'],                        // M
                "=IF(E{$r}>0, K{$r}/E{$r}, 0)",                 // N: Ср. чек дня
                "=IF(E{$r}>0, F{$r}/E{$r}, 0)",                 // O: Вещей в чеке
            ], null, "A{$r}");

            if ($r % 2 === 0) {
                $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_ZEBRA_BG);
            }

            $r++;
        }

        $dataEndRow = max(8, $r - 1);

        // Строка 5: Верхний динамический свод
        $sheet->mergeCells('A5:D5');
        $sheet->setCellValue('A5', 'ИТОГО ПО ВЫБОРКЕ (ДИНАМИЧЕСКИЙ ПЕРЕСЧЕТ ПРИ ФИЛЬТРАЦИИ):');
        $sheet->setCellValue('E5', "=SUBTOTAL(109, E8:E{$dataEndRow})");
        $sheet->setCellValue('F5', "=SUBTOTAL(109, F8:F{$dataEndRow})");
        $sheet->setCellValue('G5', "=SUBTOTAL(109, G8:G{$dataEndRow})");
        $sheet->setCellValue('H5', "=SUBTOTAL(109, H8:H{$dataEndRow})");
        $sheet->setCellValue('I5', "=SUBTOTAL(109, I8:I{$dataEndRow})");
        $sheet->setCellValue('J5', "=SUBTOTAL(109, J8:J{$dataEndRow})");
        $sheet->setCellValue('K5', "=SUBTOTAL(109, K8:K{$dataEndRow})");
        $sheet->setCellValue('L5', "=SUBTOTAL(109, L8:L{$dataEndRow})");
        $sheet->setCellValue('M5', "=SUBTOTAL(109, M8:M{$dataEndRow})");
        $sheet->setCellValue('N5', "=IF(E5>0, K5/E5, 0)");
        $sheet->setCellValue('O5', "=IF(E5>0, F5/E5, 0)");

        $this->styleDynamicKpiRow($sheet, "A5:{$lastCol}5");

        // Нижняя итоговая строка
        $totalRow = $r;
        $sheet->mergeCells("A{$totalRow}:D{$totalRow}");
        $sheet->setCellValue("A{$totalRow}", 'ВСЕГО ЗА ПЕРИОД:');
        $sheet->setCellValue("E{$totalRow}", "=SUBTOTAL(109, E8:E{$dataEndRow})");
        $sheet->setCellValue("F{$totalRow}", "=SUBTOTAL(109, F8:F{$dataEndRow})");
        $sheet->setCellValue("G{$totalRow}", "=SUBTOTAL(109, G8:G{$dataEndRow})");
        $sheet->setCellValue("H{$totalRow}", "=SUBTOTAL(109, H8:H{$dataEndRow})");
        $sheet->setCellValue("I{$totalRow}", "=SUBTOTAL(109, I8:I{$dataEndRow})");
        $sheet->setCellValue("J{$totalRow}", "=SUBTOTAL(109, J8:J{$dataEndRow})");
        $sheet->setCellValue("K{$totalRow}", "=SUBTOTAL(109, K8:K{$dataEndRow})");
        $sheet->setCellValue("L{$totalRow}", "=SUBTOTAL(109, L8:L{$dataEndRow})");
        $sheet->setCellValue("M{$totalRow}", "=SUBTOTAL(109, M8:M{$dataEndRow})");
        $sheet->setCellValue("N{$totalRow}", "=IF(E{$totalRow}>0, K{$totalRow}/E{$totalRow}, 0)");
        $sheet->setCellValue("O{$totalRow}", "=IF(E{$totalRow}>0, F{$totalRow}/E{$totalRow}, 0)");

        $this->styleTotalRow($sheet, "A{$totalRow}:{$lastCol}{$totalRow}");

        $sheet->getStyle("A8:{$lastCol}{$dataEndRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::COLOR_BORDER);
        $sheet->getStyle("A8:D{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->getStyle("E8:H{$totalRow}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("I8:N{$totalRow}")->getNumberFormat()->setFormatCode('#,##0 [$₸-43F]');
        $sheet->getStyle("O8:O{$totalRow}")->getNumberFormat()->setFormatCode('0.0');

        $sheet->setAutoFilter("A{$headerRow}:{$lastCol}{$dataEndRow}");
        $sheet->freezePane('A8');

        $this->autoFitColumns($sheet, 'A', $lastCol);
    }

    // =========================================================================
    // ЛИСТ 5: ЖУРНАЛ ЧЕКОВ (УРОВЕНЬ ДОКУМЕНТОВ БЕЗ ДУБЛИРОВАНИЯ)
    // =========================================================================

    private function buildReceiptsJournalSheet($sheet, Collection $sales, array $params): void
    {
        $sheet->setTitle('Журнал чеков');
        $sheet->setShowGridlines(true);

        $this->renderReportHeader(
            $sheet,
            'ЖУРНАЛ ДОКУМЕНТОВ: ЧЕКИ ККМ И ЗАКАЗЫ ПОКУПАТЕЛЕЙ',
            'Реестр первичных торговых документов (1 строка = 1 чек, без повторного счета сумм)',
            $params
        );

        $headers = [
            '№ п/п',
            'Документ (№ чека)',
            'Дата и время',
            'Месяц',
            'Тип операции',
            'Кассир (Ответственный)',
            'Позиций в чеке',
            'Всего вещей, шт.',
            'Сумма без скидки, ₸',
            'Скидка по чеку, ₸',
            'Скидка, %',
            'Итого сумма (Выручка), ₸',
            'Себестоимость чека, ₸',
            'Прибыль чека, ₸',
            'Статус чека',
            'Статус заказа',
            'Срок исполнения',
            'Краткий состав чека',
            'Примечание кассира',
        ];

        $headerRow = 7;
        $sheet->fromArray($headers, null, "A{$headerRow}");
        $lastCol = Coordinate::stringFromColumnIndex(count($headers)); // S

        $this->styleTableHeader($sheet, "A{$headerRow}:{$lastCol}{$headerRow}");
        $sheet->getRowDimension($headerRow)->setRowHeight(28);

        $r = 8;
        $seq = 1;

        foreach ($sales as $sale) {
            $dateTime = ($sale->approved_at ?? $sale->created_at)?->timezone('Asia/Almaty');
            $dateStr = $dateTime ? $dateTime->format('d.m.Y H:i') : '—';
            $monthStr = $dateTime ? $dateTime->format('Y-m') : '—';
            $docNum = '№' . $sale->id;
            $saleIsOnOrder = (bool) $sale->is_on_order;
            $orderTypeLabel = $saleIsOnOrder ? '📦 Под заказ' : 'В наличии';
            $cashierName = $sale->cashier?->name ?? 'Не указан';

            $docStatus = match ($sale->status) {
                'approved' => 'Оформлен',
                'rejected' => 'Отменён',
                default => 'В ожидании',
            };

            $orderStatus = match ($sale->fulfillment_status) {
                'fulfilled', 'completed' => 'Выдан',
                'ready' => 'Готов',
                'in_progress' => 'В пошиве',
                'cancelled' => 'Отменен',
                default => ($saleIsOnOrder ? 'Новый' : 'Выдан сразу'),
            };

            $dueDateStr = $sale->due_date ? Carbon::parse($sale->due_date)->format('d.m.Y') : '—';

            $itemsSummary = $sale->items->map(function ($it) {
                $pName = $it->product_name ?: $it->product?->name ?: 'Товар';
                $isHat = $this->isHatTypeOrName($it->product_type ?: $it->product?->type, $pName);
                $sz = ($it->variant_size && $it->variant_size !== '—' && ! $isHat) ? " ({$it->variant_size})" : '';
                return "{$pName}{$sz} × {$it->quantity}";
            })->implode('; ');

            $itemsCount = $sale->items->count();
            $totalUnits = (int) $sale->items->sum('quantity');

            // Расчет себестоимости чека
            $saleCost = 0.0;
            foreach ($sale->items as $it) {
                $c = (float) ($it->purchase_price ?: $it->productVariant?->purchase_price ?: $it->product?->purchase_price ?: 0);
                $saleCost += ($c * (int) $it->quantity);
            }

            $revenue = (float) $sale->subtotal;
            $discAmt = (float) ($sale->discount_amount ?: 0);
            $origTotal = (float) ($sale->original_subtotal ?: ($revenue + $discAmt));

            $sheet->fromArray([
                $seq++,                                         // A
                $docNum,                                        // B
                $dateStr,                                       // C
                $monthStr,                                      // D
                $orderTypeLabel,                                // E
                $cashierName,                                   // F
                $itemsCount,                                    // G
                $totalUnits,                                    // H
                $origTotal,                                     // I
                $discAmt,                                       // J
                "=IF(I{$r}>0, J{$r}/I{$r}, 0)",                 // K
                $revenue,                                       // L
                $saleCost,                                      // M
                "=L{$r}-M{$r}",                                 // N: Прибыль чека
                $docStatus,                                     // O
                $orderStatus,                                   // P
                $dueDateStr,                                    // Q
                $itemsSummary ?: '—',                           // R
                $sale->cashier_note ?: '—',                     // S
            ], null, "A{$r}");

            if ($r % 2 === 0) {
                $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_ZEBRA_BG);
            }

            $r++;
        }

        $dataEndRow = max(8, $r - 1);

        // Строка 5: Верхний динамический свод
        $sheet->mergeCells('A5:F5');
        $sheet->setCellValue('A5', 'ИТОГО ПО ВЫБОРКЕ (ДИНАМИЧЕСКИЙ ПЕРЕСЧЕТ ПРИ ФИЛЬТРАЦИИ):');
        $sheet->setCellValue('G5', "=SUBTOTAL(109, G8:G{$dataEndRow})");
        $sheet->setCellValue('H5', "=SUBTOTAL(109, H8:H{$dataEndRow})");
        $sheet->setCellValue('I5', "=SUBTOTAL(109, I8:I{$dataEndRow})");
        $sheet->setCellValue('J5', "=SUBTOTAL(109, J8:J{$dataEndRow})");
        $sheet->setCellValue('K5', "=IF(I5>0, J5/I5, 0)");
        $sheet->setCellValue('L5', "=SUBTOTAL(109, L8:L{$dataEndRow})");
        $sheet->setCellValue('M5', "=SUBTOTAL(109, M8:M{$dataEndRow})");
        $sheet->setCellValue('N5', "=SUBTOTAL(109, N8:N{$dataEndRow})");
        $sheet->mergeCells("O5:S5");
        $sheet->setCellValue('O5', "=SUBTOTAL(103, A8:A{$dataEndRow}) & \" чеков\"");

        $this->styleDynamicKpiRow($sheet, "A5:{$lastCol}5");

        // Нижняя итоговая строка
        $totalRow = $r;
        $sheet->mergeCells("A{$totalRow}:F{$totalRow}");
        $sheet->setCellValue("A{$totalRow}", 'ВСЕГО ЧЕКОВ ПО ЖУРНАЛУ:');
        $sheet->setCellValue("G{$totalRow}", "=SUBTOTAL(109, G8:G{$dataEndRow})");
        $sheet->setCellValue("H{$totalRow}", "=SUBTOTAL(109, H8:H{$dataEndRow})");
        $sheet->setCellValue("I{$totalRow}", "=SUBTOTAL(109, I8:I{$dataEndRow})");
        $sheet->setCellValue("J{$totalRow}", "=SUBTOTAL(109, J8:J{$dataEndRow})");
        $sheet->setCellValue("K{$totalRow}", "=IF(I{$totalRow}>0, J{$totalRow}/I{$totalRow}, 0)");
        $sheet->setCellValue("L{$totalRow}", "=SUBTOTAL(109, L8:L{$dataEndRow})");
        $sheet->setCellValue("M{$totalRow}", "=SUBTOTAL(109, M8:M{$dataEndRow})");
        $sheet->setCellValue("N{$totalRow}", "=SUBTOTAL(109, N8:N{$dataEndRow})");
        $sheet->mergeCells("O{$totalRow}:S{$totalRow}");
        $sheet->setCellValue("O{$totalRow}", "=SUBTOTAL(103, A8:A{$dataEndRow}) & \" чеков\"");

        $this->styleTotalRow($sheet, "A{$totalRow}:{$lastCol}{$totalRow}");

        $sheet->getStyle("A8:{$lastCol}{$dataEndRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::COLOR_BORDER);
        $sheet->getStyle("A8:E{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("O8:Q{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->getStyle("G8:H{$totalRow}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("I8:J{$totalRow}")->getNumberFormat()->setFormatCode('#,##0 [$₸-43F]');
        $sheet->getStyle("K8:K{$totalRow}")->getNumberFormat()->setFormatCode('0.0%');
        $sheet->getStyle("L8:N{$totalRow}")->getNumberFormat()->setFormatCode('#,##0 [$₸-43F]');

        $sheet->setAutoFilter("A{$headerRow}:{$lastCol}{$dataEndRow}");
        $sheet->freezePane('A8');

        $this->autoFitColumns($sheet, 'A', $lastCol);
    }

    // =========================================================================
    // ЛИСТ 6: ВЫРАБОТКА СОТРУДНИКОВ (КАССИРОВ)
    // =========================================================================

    private function buildCashierPerformanceSheet($sheet, Collection $sales, array $params): void
    {
        $sheet->setTitle('Выработка сотрудников');
        $sheet->setShowGridlines(true);

        $this->renderReportHeader(
            $sheet,
            'ОТЧЕТ ПО ВЫРАБОТКЕ СОТРУДНИКОВ И КАССИРОВ',
            'Анализ торговой активности, среднего чека и скидочной политики сотрудников',
            $params
        );

        $headers = [
            '№ п/п',
            'Сотрудник (ФИО)',
            'Роль в системе',
            'Количество чеков',
            'Продано единиц, шт.',
            'Базовая сумма, ₸',
            'Предоставлено скидок, ₸',
            'Итоговая выручка, ₸',
            'Себестоимость, ₸',
            'Валовая прибыль, ₸',
            'Средний чек сотрудника, ₸',
            'Среднее число вещей в чеке',
            'Доля в выручке магазина, %',
        ];

        $headerRow = 7;
        $sheet->fromArray($headers, null, "A{$headerRow}");
        $lastCol = Coordinate::stringFromColumnIndex(count($headers)); // M

        $this->styleTableHeader($sheet, "A{$headerRow}:{$lastCol}{$headerRow}");
        $sheet->getRowDimension($headerRow)->setRowHeight(28);

        $cashiers = [];

        foreach ($sales as $sale) {
            if ($sale->status === 'rejected') {
                continue;
            }
            $userId = $sale->cashier_id ?: 0;
            $uName = $sale->cashier?->name ?: 'Без указания кассира';
            $uRole = $sale->cashier?->role ?? 'cashier';
            $roleLabel = match ($uRole) {
                'admin_programmer', 'admin' => 'Администратор',
                default => 'Кассир',
            };

            if (! isset($cashiers[$userId])) {
                $cashiers[$userId] = [
                    'name' => $uName,
                    'role' => $roleLabel,
                    'checks_count' => 0,
                    'units_count' => 0,
                    'orig_total' => 0.0,
                    'discount_total' => 0.0,
                    'revenue_total' => 0.0,
                    'cost_total' => 0.0,
                    'profit_total' => 0.0,
                ];
            }

            $cashiers[$userId]['checks_count']++;

            foreach ($sale->items as $it) {
                $q = (int) $it->quantity;
                $tot = (float) $it->line_total;
                $disc = (float) (($it->discount_amount ?: 0) * $q);
                $orig = (float) (($it->original_price ?: ($it->sale_price + ($it->discount_amount ?: 0))) * $q);
                $unitCost = (float) ($it->purchase_price ?: $it->productVariant?->purchase_price ?: $it->product?->purchase_price ?: 0);
                $costTot = $q * $unitCost;

                $cashiers[$userId]['units_count'] += $q;
                $cashiers[$userId]['orig_total'] += $orig;
                $cashiers[$userId]['discount_total'] += $disc;
                $cashiers[$userId]['revenue_total'] += $tot;
                $cashiers[$userId]['cost_total'] += $costTot;
                $cashiers[$userId]['profit_total'] += ($tot - $costTot);
            }
        }

        $rows = array_values($cashiers);
        usort($rows, fn ($a, $b) => $b['revenue_total'] <=> $a['revenue_total']);

        $r = 8;
        $seq = 1;

        foreach ($rows as $row) {
            $sheet->fromArray([
                $seq++,                                         // A
                $row['name'],                                   // B
                $row['role'],                                   // C
                $row['checks_count'],                           // D
                $row['units_count'],                            // E
                $row['orig_total'],                             // F
                $row['discount_total'],                         // G
                $row['revenue_total'],                          // H
                $row['cost_total'],                             // I
                $row['profit_total'],                           // J
                "=IF(D{$r}>0, H{$r}/D{$r}, 0)",                 // K
                "=IF(D{$r}>0, E{$r}/D{$r}, 0)",                 // L
                "=IF(H$5>0, H{$r}/H$5, 0)",                     // M
            ], null, "A{$r}");

            if ($r % 2 === 0) {
                $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_ZEBRA_BG);
            }

            $r++;
        }

        $dataEndRow = max(8, $r - 1);

        // Строка 5: Верхний свод
        $sheet->mergeCells('A5:C5');
        $sheet->setCellValue('A5', 'ИТОГО ПО ВЫБОРКЕ (ДИНАМИЧЕСКИЙ ПЕРЕСЧЕТ ПРИ ФИЛЬТРАЦИИ):');
        $sheet->setCellValue('D5', "=SUBTOTAL(109, D8:D{$dataEndRow})");
        $sheet->setCellValue('E5', "=SUBTOTAL(109, E8:E{$dataEndRow})");
        $sheet->setCellValue('F5', "=SUBTOTAL(109, F8:F{$dataEndRow})");
        $sheet->setCellValue('G5', "=SUBTOTAL(109, G8:G{$dataEndRow})");
        $sheet->setCellValue('H5', "=SUBTOTAL(109, H8:H{$dataEndRow})");
        $sheet->setCellValue('I5', "=SUBTOTAL(109, I8:I{$dataEndRow})");
        $sheet->setCellValue('J5', "=SUBTOTAL(109, J8:J{$dataEndRow})");
        $sheet->setCellValue('K5', "=IF(D5>0, H5/D5, 0)");
        $sheet->setCellValue('L5', "=IF(D5>0, E5/D5, 0)");
        $sheet->setCellValue('M5', '100.0%');

        $this->styleDynamicKpiRow($sheet, "A5:{$lastCol}5");

        // Нижняя строка итогов
        $totalRow = $r;
        $sheet->mergeCells("A{$totalRow}:C{$totalRow}");
        $sheet->setCellValue("A{$totalRow}", 'ВСЕГО ПО СОТРУДНИКАМ:');
        $sheet->setCellValue("D{$totalRow}", "=SUBTOTAL(109, D8:D{$dataEndRow})");
        $sheet->setCellValue("E{$totalRow}", "=SUBTOTAL(109, E8:E{$dataEndRow})");
        $sheet->setCellValue("F{$totalRow}", "=SUBTOTAL(109, F8:F{$dataEndRow})");
        $sheet->setCellValue("G{$totalRow}", "=SUBTOTAL(109, G8:G{$dataEndRow})");
        $sheet->setCellValue("H{$totalRow}", "=SUBTOTAL(109, H8:H{$dataEndRow})");
        $sheet->setCellValue("I{$totalRow}", "=SUBTOTAL(109, I8:I{$dataEndRow})");
        $sheet->setCellValue("J{$totalRow}", "=SUBTOTAL(109, J8:J{$dataEndRow})");
        $sheet->setCellValue("K{$totalRow}", "=IF(D{$totalRow}>0, H{$totalRow}/D{$totalRow}, 0)");
        $sheet->setCellValue("L{$totalRow}", "=IF(D{$totalRow}>0, E{$totalRow}/D{$totalRow}, 0)");
        $sheet->setCellValue("M{$totalRow}", '100.0%');

        $this->styleTotalRow($sheet, "A{$totalRow}:{$lastCol}{$totalRow}");

        $sheet->getStyle("A8:{$lastCol}{$dataEndRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::COLOR_BORDER);
        $sheet->getStyle("A8:A{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("C8:C{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->getStyle("D8:E{$totalRow}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("F8:K{$totalRow}")->getNumberFormat()->setFormatCode('#,##0 [$₸-43F]');
        $sheet->getStyle("L8:L{$totalRow}")->getNumberFormat()->setFormatCode('0.0');
        $sheet->getStyle("M8:M{$totalRow}")->getNumberFormat()->setFormatCode('0.0%');

        $sheet->setAutoFilter("A{$headerRow}:{$lastCol}{$dataEndRow}");
        $sheet->freezePane('A8');

        $this->autoFitColumns($sheet, 'A', $lastCol);
    }

    // =========================================================================
    // ЛИСТ 7: СПРАВКА ОБ УЧЕТНОЙ ПОЛИТИКЕ И ИСТОЧНИКАХ ДАННЫХ
    // =========================================================================

    private function buildAccountingNoteSheet($sheet, array $params): void
    {
        $sheet->setTitle('Справка об учете');
        $sheet->setShowGridlines(true);

        $sheet->setCellValue('A2', 'ПОЯСНИТЕЛЬНАЯ ЗАПИСКА К ЭКСПОРТУ ОТЧЕТНОСТИ (МЕТОДОЛОГИЯ 1С)');
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12)->getColor()->setRGB(self::COLOR_HEADER_TEXT);

        $sheet->setCellValue('A3', 'Магазин детской одежды и школьной формы · Сформирован: ' . now()->timezone('Asia/Almaty')->format('d.m.Y H:i'));
        $sheet->getStyle('A3')->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB('64748B');

        $notes = [
            ['1. Достоверность данных', 'Отчетность строго опирается на фактически завершенные операции (статус: Оформлен / Approved). Не содержит расчетных или предположительных экстраполяций.'],
            ['2. Складской учет остатков', 'Магазин на данном этапе НЕ ведет складской учет фактических физических остатков. В соответствии с учетной политикой проекта вымышленные остатки и формулы («Было = Продано + Остаток») исключены из всех форм.'],
            ['3. Разделение типов операций', 'Все продажи строго разделены на два независимых потока: «В наличии» (товар выдан непосредственно из торгового зала) и «Под заказ» (индивидуальный пошив школьной формы).'],
            ['4. Учет размеров детской одежды', 'Размеры фиксируются по первичным чекам и сортируются в естественном порядке (числовой рост: 110, 116, 122... либо буквенные: XXS..XXL). Для головных уборов размер не требуется.'],
            ['5. Себестоимость и прибыль', 'Себестоимость рассчитывается по полю закупочной цены (purchase_price). Если закупочная цена не была заполнена при создании товара, она принимается равной 0.00 без искажения учетных записей.'],
            ['6. Поставщики и закупки', 'Справочник поставщиков и входящие приходные накладные в текущей версии базы данных не предусмотрены. Учет ведется по факту расходных операций продажи.'],
            ['7. Формулы и автофильтры', 'Во всех таблицах используются стандартные формулы Excel SUBTOTAL(109, ...) и SUBTOTAL(103, ...). При наложении любого фильтра (по дате, кассиру, категории) все итоги сверху и снизу автоматически пересчитываются.'],
        ];

        $r = 5;
        foreach ($notes as $item) {
            $sheet->setCellValue("A{$r}", $item[0]);
            $sheet->setCellValue("B{$r}", $item[1]);

            $sheet->getStyle("A{$r}")->getFont()->setBold(true)->setSize(9);
            $sheet->getStyle("B{$r}")->getAlignment()->setWrapText(true);

            if ($r % 2 === 1) {
                $sheet->getStyle("A{$r}:B{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_ZEBRA_BG);
            }

            $sheet->getRowDimension($r)->setRowHeight(24);
            $r++;
        }

        $sheet->getStyle('A5:B' . ($r - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::COLOR_BORDER);

        $sheet->getColumnDimension('A')->setWidth(30);
        $sheet->getColumnDimension('B')->setWidth(90);
    }

    // =========================================================================
    // СЛУЖЕБНЫЕ МЕТОДЫ ОФОРМЛЕНИЯ В СТИЛЕ 1С
    // =========================================================================

    /**
     * Рендерит заголовочную часть листа в классическом стиле 1С.
     */
    private function renderReportHeader($sheet, string $title, string $subtitle, array $params): void
    {
        $periodText = $params['date_from'] || $params['date_to']
            ? ('Период: с ' . ($params['date_from'] ?: '...') . ' по ' . ($params['date_to'] ?: '...'))
            : 'Период: Все время';

        $sheet->setCellValue('A2', $title);
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12)->getColor()->setRGB('0F172A');

        $generatedAt = now()->timezone('Asia/Almaty')->format('d.m.Y H:i:s');
        $sheet->setCellValue('A3', "Организация: Магазин детской одежды и школьной формы · {$periodText} · Сформирован: {$generatedAt}");
        $sheet->getStyle('A3')->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB('64748B');
    }

    /**
     * Оформление шапки таблицы в нейтральном строгом стиле 1С.
     */
    private function styleTableHeader($sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => self::COLOR_HEADER_TEXT],
                'size' => 9,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => self::COLOR_HEADER_BG],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => self::COLOR_BORDER_DARK],
                ],
            ],
        ]);
    }

    /**
     * Оформление верхней динамической KPI-строки (пересчет по автофильтрам).
     */
    private function styleDynamicKpiRow($sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 9,
                'color' => ['rgb' => '0F172A'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => self::COLOR_TOTAL_BG],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::COLOR_BORDER_DARK]],
                'bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::COLOR_BORDER_DARK]],
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::COLOR_BORDER]],
            ],
        ]);
        $sheet->getRowDimension(5)->setRowHeight(22);
    }

    /**
     * Оформление бухгалтерской итоговой строки (двойная нижняя граница 1С).
     */
    private function styleTotalRow($sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 10,
                'color' => ['rgb' => '000000'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => self::COLOR_TOTAL_BG],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::COLOR_BORDER_DARK]],
                'bottom' => ['borderStyle' => Border::BORDER_DOUBLE, 'color' => ['rgb' => '000000']],
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::COLOR_BORDER]],
            ],
        ]);
    }

    /**
     * Автоподбор ширины столбцов с безопасным минимумом и максимумом.
     */
    private function autoFitColumns($sheet, string $startCol, string $endCol): void
    {
        $startIndex = Coordinate::columnIndexFromString($startCol);
        $endIndex = Coordinate::columnIndexFromString($endCol);

        for ($i = $startIndex; $i <= $endIndex; $i++) {
            $colLetter = Coordinate::stringFromColumnIndex($i);
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
        }
    }

    /**
     * Определение вида изделия (Рубашка, Брюки, Сарафан...).
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
     * Проверка, является ли товар головным убором (где размер не выводится).
     */
    private function isHatTypeOrName(?string $type, ?string $name): bool
    {
        $t = mb_strtolower((string) $type);
        $n = mb_strtolower((string) $name);
        return str_contains($t, 'шапк') || str_contains($n, 'шапк');
    }

    /**
     * Сравнение размеров в естественном порядке (110, 116, 122... или XXS..XXL).
     */
    private function compareSizes(string $a, string $b): int
    {
        $order = [
            'xxs' => 1, 'xs' => 2, 's' => 3, 'm' => 4, 'l' => 5,
            'xl' => 6, '2xl' => 7, 'xxl' => 7, '3xl' => 8, 'xxxl' => 8,
            '4xl' => 9, '5xl' => 10, 'one size' => 99, 'универсальный' => 99, 'без размера' => 100, '—' => 101,
        ];

        $valA = mb_strtolower(trim($a));
        $valB = mb_strtolower(trim($b));

        if (is_numeric($valA) && is_numeric($valB)) {
            return (float) $valA <=> (float) $valB;
        }

        $rankA = $order[$valA] ?? 50;
        $rankB = $order[$valB] ?? 50;

        if ($rankA !== $rankB) {
            return $rankA <=> $rankB;
        }

        return strnatcasecmp($a, $b);
    }
}
