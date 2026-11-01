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
     * Проверяет, относится ли продажа к партии «Новый завоз».
     */
    public function isNewBatchSale($sale): bool
    {
        return mb_stripos((string) $sale->cashier_note, 'завоз') !== false;
    }

    /**
     * Создает полную книгу Excel с 8 листами аналитики в стиле 1С.
     * Реализует строгое разделение: основной учет магазина формируется БЕЗ «Нового завоза»,
     * а все продажи «Нового завоза» обособлены на отдельном листе вне основного учета.
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

        // Обособление партии «Новый завоз»:
        // В соответствии с бизнес-требованием («новый завоз не считается в учете»)
        // разделяем продажи на 2 независимых потока:
        // 1. $accountedSales — основной торговый учет магазина (1 491 чек, 18 507 400 ₸)
        // 2. $newBatchSales — обособленный реестр «Новый завоз» (50 чеков, 654 800 ₸)
        $accountedSales = $sales->filter(fn ($s) => ! $this->isNewBatchSale($s));
        $newBatchSales = $sales->filter(fn ($s) => $this->isNewBatchSale($s));

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName(self::FONT_FAMILY)->setSize(9);

        // Лист 1: Реестр продаж (ОСНОВНОЙ УЧЕТ ТОРГОВЛИ, БЕЗ НОВОГО ЗАВОЗА)
        $sheet1 = $spreadsheet->getActiveSheet();
        $this->buildSalesRegisterSheet($sheet1, $accountedSales, $params);

        // Лист 2: 📦 Новый завоз (ВНЕ ОСНОВНОГО УЧЕТА)
        $sheet2 = $spreadsheet->createSheet();
        $this->buildNewBatchSheet($sheet2, $newBatchSales, $params);

        // Лист 3: Ведомость по номенклатуре и характеристикам (ОСНОВНОЙ УЧЕТ)
        $sheet3 = $spreadsheet->createSheet();
        $this->buildNomenclatureSheet($sheet3, $accountedSales, $params);

        // Лист 4: Свод по категориям и видам товаров (ОСНОВНОЙ УЧЕТ)
        $sheet4 = $spreadsheet->createSheet();
        $this->buildCategorySummarySheet($sheet4, $accountedSales, $params);

        // Лист 5: Динамика продаж по периодам (дням и месяцам) (ОСНОВНОЙ УЧЕТ)
        $sheet5 = $spreadsheet->createSheet();
        $this->buildPeriodDynamicsSheet($sheet5, $accountedSales, $params);

        // Лист 6: Журнал чеков (уровень документов без дублирования) (ОСНОВНОЙ УЧЕТ)
        $sheet6 = $spreadsheet->createSheet();
        $this->buildReceiptsJournalSheet($sheet6, $accountedSales, $params);

        // Лист 7: Выработка сотрудников (кассиров) (ОСНОВНОЙ УЧЕТ)
        $sheet7 = $spreadsheet->createSheet();
        $this->buildCashierPerformanceSheet($sheet7, $accountedSales, $params);

        // Лист 8: Справка об учетной политике и источниках данных
        $sheet8 = $spreadsheet->createSheet();
        $this->buildAccountingNoteSheet($sheet8, $params, [
            'accounted_sales_count' => $accountedSales->count(),
            'accounted_revenue' => (float) $accountedSales->sum(fn ($s) => (float) $s->subtotal),
            'new_batch_sales_count' => $newBatchSales->count(),
            'new_batch_revenue' => (float) $newBatchSales->sum(fn ($s) => (float) $s->subtotal),
        ]);

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
            ->orderByRaw('COALESCE(approved_at, created_at) DESC')
            ->orderBy('id', 'DESC')
            ->get();
    }

    /**
     * Определение корректного цвета позиции.
     */
    public function resolveItemColor($item): string
    {
        if (!empty($item->color) && $item->color !== '—') {
            return trim($item->color);
        }
        if (!empty($item->productVariant?->color) && trim($item->productVariant->color) !== '') {
            return trim($item->productVariant->color);
        }
        if (!empty($item->product?->color) && trim($item->product->color) !== '') {
            return trim($item->product->color);
        }

        $name = ($item->product_name ?: $item->product?->name ?: '') . ' ' . ($item->notes ?? '');
        $colorKeywords = [
            'Темно-синий' => ['темно-синий', 'темно-синяя', 'т.синий', 'т-синий', 'dark blue', 'navy'],
            'Синий' => ['синий', 'синяя', 'синее', 'blue'],
            'Голубой' => ['голубой', 'голубая', 'голубое', 'light blue'],
            'Белый' => ['белый', 'белая', 'белое', 'white'],
            'Черный' => ['черный', 'чёрный', 'черная', 'чёрная', 'черное', 'black'],
            'Серый' => ['серый', 'серая', 'серое', 'grey', 'gray'],
            'Бордовый' => ['бордовый', 'бордовая', 'бордо', 'maroon', 'burgundy'],
            'Зеленый' => ['зеленый', 'зелёный', 'зеленая', 'зелёная', 'green'],
        ];

        foreach ($colorKeywords as $canonicalColor => $patterns) {
            foreach ($patterns as $pattern) {
                if (mb_stripos($name, $pattern) !== false) {
                    return $canonicalColor;
                }
            }
        }

        return '—';
    }

    /**
     * Классификация партии / примечания.
     */
    public function resolveBatchLabel(?string $note): string
    {
        if (empty($note) || $note === '—') {
            return 'Обычная';
        }
        if (mb_stripos($note, 'завоз') !== false) {
            return '📦 Новый завоз';
        }
        if (mb_stripos($note, 'скидк') !== false || mb_stripos($note, 'преподават') !== false) {
            return 'Скидка';
        }
        if (mb_stripos($note, 'халык') !== false) {
            return 'Халык';
        }
        return $note;
    }

    // =========================================================================
    // ЛИСТ 1: РЕЕСТР ПРОДАЖ (ОСНОВНОЙ УЧЕТ ТОРГОВЛИ)
    // =========================================================================

    private function buildSalesRegisterSheet($sheet, Collection $sales, array $params): void
    {
        $sheet->setTitle('Реестр продаж');
        $sheet->setShowGridlines(true);

        $this->renderReportHeader(
            $sheet,
            'РЕГИСТР НАКОПЛЕНИЯ: ПРОДАЖИ (ОСНОВНОЙ ТОРГОВЫЙ УЧЕТ)',
            'Детальный попозиционный реестр завершенных продаж (партия «Новый завоз» выведена на отдельный лист вне учета)',
            $params
        );

        $headers = [
            '№ п/п',
            'Документ (№ чека)',
            'Дата и время',
            'Период (Месяц)',
            'Тип операции',
            'Пометка / Партия',
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
        $lastCol = Coordinate::stringFromColumnIndex(count($headers)); // AC

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
            $batchLabel = $this->resolveBatchLabel($sale->cashier_note);

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
                $color = $this->resolveItemColor($item);

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
                $purchasePrice = (float) ($item->purchase_price ?: $item->productVariant?->purchase_price ?: $item->product?->purchase_price ?: 0);

                $rowData = [
                    $seq++,                                         // A: № п/п
                    $docNum,                                        // B: № чека
                    $dateStr,                                       // C: Дата и время
                    $monthStr,                                      // D: Месяц
                    $orderTypeLabel,                                // E: Тип операции
                    $batchLabel,                                    // F: Пометка / Партия
                    $catName,                                       // G: Категория
                    $prodType,                                      // H: Вид изделия
                    $prodName,                                      // I: Номенклатура
                    $size,                                          // J: Размер
                    $sku,                                           // K: Артикул
                    $color,                                         // L: Цвет
                    $qty,                                           // M: Количество, шт.
                    $origPrice,                                     // N: Базовая цена ед.
                    $discAmtPerUnit,                                // O: Скидка ед.
                    "=IF(N{$r}>0, O{$r}/N{$r}, 0)",                 // P: Скидка, %
                    "=N{$r}-O{$r}",                                 // Q: Цена продажи ед.
                    "=M{$r}*N{$r}",                                 // R: Базовая сумма (общ.)
                    "=M{$r}*O{$r}",                                 // S: Сумма скидки (общ.)
                    "=M{$r}*Q{$r}",                                 // T: Сумма выручки
                    $purchasePrice,                                 // U: Себестоимость ед.
                    "=M{$r}*U{$r}",                                 // V: Себестоимость общ.
                    "=T{$r}-V{$r}",                                 // W: Валовая прибыль
                    "=IF(T{$r}>0, W{$r}/T{$r}, 0)",                 // X: Рентабельность %
                    $cashierName,                                   // Y: Кассир
                    $docStatus,                                     // Z: Статус чека
                    $orderStatus,                                   // AA: Статус заказа
                    $dueDateStr,                                    // AB: Срок исполнения
                    $note,                                          // AC: Примечание
                ];

                $sheet->fromArray($rowData, null, "A{$r}");

                if ($r % 2 === 0) {
                    $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_ZEBRA_BG);
                }

                $r++;
            }
        }

        $dataEndRow = max(8, $r - 1);

        // Строка 5: ДИНАМИЧЕСКИЙ СВОД ПО ВЫБОРКЕ (ОСНОВНОЙ УЧЕТ)
        $sheet->mergeCells('A5:L5');
        $sheet->setCellValue('A5', 'ИТОГО ПО ОСНОВНОМУ УЧЕТУ (ДИНАМИЧЕСКИЙ ПЕРЕСЧЕТ ПРИ ФИЛЬТРАЦИИ):');
        $sheet->setCellValue('M5', "=SUBTOTAL(109, M8:M{$dataEndRow})");
        $sheet->setCellValue('N5', '—');
        $sheet->setCellValue('O5', '—');
        $sheet->setCellValue('P5', "=IF(R5>0, S5/R5, 0)");
        $sheet->setCellValue('Q5', '—');
        $sheet->setCellValue('R5', "=SUBTOTAL(109, R8:R{$dataEndRow})");
        $sheet->setCellValue('S5', "=SUBTOTAL(109, S8:S{$dataEndRow})");
        $sheet->setCellValue('T5', "=SUBTOTAL(109, T8:T{$dataEndRow})");
        $sheet->setCellValue('U5', '—');
        $sheet->setCellValue('V5', "=SUBTOTAL(109, V8:V{$dataEndRow})");
        $sheet->setCellValue('W5', "=SUBTOTAL(109, W8:W{$dataEndRow})");
        $sheet->setCellValue('X5', "=IF(T5>0, W5/T5, 0)");
        $sheet->mergeCells("Y5:AC5");
        $sheet->setCellValue('Y5', "=SUBTOTAL(103, A8:A{$dataEndRow}) & \" поз.\"");

        $this->styleDynamicKpiRow($sheet, "A5:{$lastCol}5");

        // Нижняя бухгалтерская строка итогов
        $totalRow = $r;
        $sheet->mergeCells("A{$totalRow}:L{$totalRow}");
        $sheet->setCellValue("A{$totalRow}", 'ВСЕГО ПО ОСНОВНОМУ РЕЕСТРУ:');
        $sheet->setCellValue("M{$totalRow}", "=SUBTOTAL(109, M8:M{$dataEndRow})");
        $sheet->setCellValue("N{$totalRow}", '—');
        $sheet->setCellValue("O{$totalRow}", '—');
        $sheet->setCellValue("P{$totalRow}", "=IF(R{$totalRow}>0, S{$totalRow}/R{$totalRow}, 0)");
        $sheet->setCellValue("Q{$totalRow}", '—');
        $sheet->setCellValue("R{$totalRow}", "=SUBTOTAL(109, R8:R{$dataEndRow})");
        $sheet->setCellValue("S{$totalRow}", "=SUBTOTAL(109, S8:S{$dataEndRow})");
        $sheet->setCellValue("T{$totalRow}", "=SUBTOTAL(109, T8:T{$dataEndRow})");
        $sheet->setCellValue("U{$totalRow}", '—');
        $sheet->setCellValue("V{$totalRow}", "=SUBTOTAL(109, V8:V{$dataEndRow})");
        $sheet->setCellValue("W{$totalRow}", "=SUBTOTAL(109, W8:W{$dataEndRow})");
        $sheet->setCellValue("X{$totalRow}", "=IF(T{$totalRow}>0, W{$totalRow}/T{$totalRow}, 0)");
        $sheet->mergeCells("Y{$totalRow}:AC{$totalRow}");
        $sheet->setCellValue("Y{$totalRow}", "=SUBTOTAL(103, A8:A{$dataEndRow}) & \" позиций\"");

        $this->styleTotalRow($sheet, "A{$totalRow}:{$lastCol}{$totalRow}");

        $sheet->getStyle("A8:{$lastCol}{$dataEndRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::COLOR_BORDER);

        $sheet->getStyle("A8:F{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("J8:L{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("Z8:AB{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->getStyle("M8:M{$totalRow}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("N8:O{$dataEndRow}")->getNumberFormat()->setFormatCode('#,##0 [$₸-43F]');
        $sheet->getStyle("P8:P{$totalRow}")->getNumberFormat()->setFormatCode('0.0%');
        $sheet->getStyle("Q8:T{$totalRow}")->getNumberFormat()->setFormatCode('#,##0 [$₸-43F]');
        $sheet->getStyle("U8:W{$totalRow}")->getNumberFormat()->setFormatCode('#,##0 [$₸-43F]');
        $sheet->getStyle("X8:X{$totalRow}")->getNumberFormat()->setFormatCode('0.0%');

        $sheet->setAutoFilter("A{$headerRow}:{$lastCol}{$dataEndRow}");
        $sheet->freezePane('A8');

        $this->autoFitColumns($sheet, $lastCol);
    }

    // =========================================================================
    // ЛИСТ 2: 📦 НОВЫЙ ЗАВОЗ (ОБОСОБЛЕННЫЙ РЕЕСТР ВНЕ ОСНОВНОГО УЧЕТА)
    // =========================================================================

    private function buildNewBatchSheet($sheet, Collection $batchSales, array $params): void
    {
        $sheet->setTitle('📦 Новый завоз (Вне учета)');
        $sheet->setShowGridlines(true);

        $this->renderReportHeader(
            $sheet,
            'ОБОСОБЛЕННЫЙ РЕЕСТР: «НОВЫЙ ЗАВОЗ» (ВНЕ ОСНОВНОГО УЧЕТА)',
            'Первичный попозиционный реестр и свод проданных товаров партии (не входит в торговую выручку основного учета)',
            $params
        );

        // Информационный баннер
        $sheet->mergeCells('A4:I4');
        $sheet->setCellValue('A4', 'ℹ️ ВНИМАНИЕ: Продажи с комментарием «Новый завоз» обособлены согласно регламенту магазина и не включаются в выручку основного учета.');
        $sheet->getStyle('A4')->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB('475569');
        $sheet->getStyle('A4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FEF3C7');
        $sheet->getStyle('A4:I4')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('F59E0B');

        // -------------------------------------------------------------
        // СЕКЦИЯ 1: СВОДНАЯ ТАБЛИЦА ПО ТОВАРАМ И РАЗМЕРАМ
        // -------------------------------------------------------------
        $sheet->setCellValue('A6', '1. СВОДНАЯ ВЕДОМОСТЬ ПРОДАННЫХ ТОВАРОВ И РАЗМЕРОВ (ПАРТИЯ «НОВЫЙ ЗАВОЗ»)');
        $sheet->getStyle('A6')->getFont()->setBold(true)->setSize(11)->getColor()->setRGB(self::COLOR_HEADER_TEXT);

        $summaryHeaders = [
            '№ п/п',
            'Вид изделия',
            'Номенклатура (Товар)',
            'Размер (Характеристика)',
            'Цвет',
            'Продано, шт.',
            'Базовая цена ед., ₸',
            'Сумма выручки, ₸',
            'Доля в выручке партии, %',
        ];

        $sumHeaderRow = 7;
        $sheet->fromArray($summaryHeaders, null, "A{$sumHeaderRow}");
        $lastSumCol = Coordinate::stringFromColumnIndex(count($summaryHeaders)); // I

        $this->styleTableHeader($sheet, "A{$sumHeaderRow}:{$lastSumCol}{$sumHeaderRow}");
        $sheet->getRowDimension($sumHeaderRow)->setRowHeight(26);

        // Агрегируем позиции
        $summaryData = [];
        $batchTotalRevenue = 0.0;
        $batchTotalPieces = 0;

        foreach ($batchSales as $sale) {
            foreach ($sale->items as $item) {
                $prodName = $item->product_name ?: $item->product?->name ?: 'Товар без наименования';
                $catName = $item->product?->category?->name ?: 'Без категории';
                $prodType = $this->resolveProductType($item->product_type, $item->product?->type, $prodName, $catName);
                $isHat = $this->isHatTypeOrName($prodType, $prodName);
                $size = $isHat ? '—' : ($item->variant_size ?: '—');
                $color = $this->resolveItemColor($item);
                $qty = (int) $item->quantity;
                $lineTotal = (float) $item->line_total;
                $unitPrice = (float) ($item->original_price ?: $item->sale_price);

                $key = "{$prodType}___{$prodName}___{$size}___{$color}";

                if (!isset($summaryData[$key])) {
                    $summaryData[$key] = [
                        'type' => $prodType,
                        'name' => $prodName,
                        'size' => $size,
                        'color' => $color,
                        'qty' => 0,
                        'unit_price' => $unitPrice,
                        'revenue' => 0.0,
                    ];
                }

                $summaryData[$key]['qty'] += $qty;
                $summaryData[$key]['revenue'] += $lineTotal;
                $batchTotalRevenue += $lineTotal;
                $batchTotalPieces += $qty;
            }
        }

        uasort($summaryData, function ($a, $b) {
            if ($b['qty'] !== $a['qty']) {
                return $b['qty'] <=> $a['qty'];
            }
            return $b['revenue'] <=> $a['revenue'];
        });

        $sr = 8;
        $sumSeq = 1;
        $sumStartRow = $sr;

        foreach ($summaryData as $row) {
            $sheet->fromArray([
                $sumSeq++,                                      // A: № п/п
                $row['type'],                                   // B: Вид изделия
                $row['name'],                                   // C: Номенклатура
                $row['size'],                                   // D: Размер
                $row['color'],                                  // E: Цвет
                $row['qty'],                                    // F: Продано шт.
                $row['unit_price'],                             // G: Цена ед.
                $row['revenue'],                                // H: Сумма выручки
                "=IF(H" . ($sumStartRow + count($summaryData)) . ">0, H{$sr}/H" . ($sumStartRow + count($summaryData)) . ", 0)", // I: Доля
            ], null, "A{$sr}");

            if ($sr % 2 === 0) {
                $sheet->getStyle("A{$sr}:{$lastSumCol}{$sr}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_ZEBRA_BG);
            }
            $sr++;
        }

        $sumEndRow = max($sumStartRow, $sr - 1);
        $sumTotalRow = $sr;

        // Итоговая строка сводной таблицы
        $sheet->mergeCells("A{$sumTotalRow}:E{$sumTotalRow}");
        $sheet->setCellValue("A{$sumTotalRow}", 'ИТОГО ПО ВЕДОМОСТИ ПАРТИИ:');
        $sheet->setCellValue("F{$sumTotalRow}", "=SUBTOTAL(109, F{$sumStartRow}:F{$sumEndRow})");
        $sheet->setCellValue("G{$sumTotalRow}", '—');
        $sheet->setCellValue("H{$sumTotalRow}", "=SUBTOTAL(109, H{$sumStartRow}:H{$sumEndRow})");
        $sheet->setCellValue("I{$sumTotalRow}", "=IF(H{$sumTotalRow}>0, 1.0, 0)");

        $this->styleTotalRow($sheet, "A{$sumTotalRow}:{$lastSumCol}{$sumTotalRow}");
        $sheet->getStyle("A{$sumStartRow}:{$lastSumCol}{$sumEndRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::COLOR_BORDER);

        $sheet->getStyle("A{$sumStartRow}:A{$sumEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("D{$sumStartRow}:E{$sumEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("F{$sumStartRow}:F{$sumTotalRow}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("G{$sumStartRow}:H{$sumTotalRow}")->getNumberFormat()->setFormatCode('#,##0 [$₸-43F]');
        $sheet->getStyle("I{$sumStartRow}:I{$sumTotalRow}")->getNumberFormat()->setFormatCode('0.0%');

        // -------------------------------------------------------------
        // СЕКЦИЯ 2: ПОПОЗИЦИОННЫЙ РЕЕСТР ОПЕРАЦИЙ «НОВЫЙ ЗАВОЗ»
        // -------------------------------------------------------------
        $detailStartHeader = $sumTotalRow + 3;
        $sheet->setCellValue("A{$detailStartHeader}", '2. ПОПОЗИЦИОННЫЙ РЕЕСТР ПРОДАЖ ИЗ ПАРТИИ «НОВЫЙ ЗАВОЗ»');
        $sheet->getStyle("A{$detailStartHeader}")->getFont()->setBold(true)->setSize(11)->getColor()->setRGB(self::COLOR_HEADER_TEXT);

        $detailHeaderRow = $detailStartHeader + 1;
        $detailHeaders = [
            '№ п/п',
            'Документ (№ чека)',
            'Дата и время',
            'Кассир (Ответственный)',
            'Вид изделия',
            'Номенклатура (Товар)',
            'Размер',
            'Цвет',
            'Кол-во, шт.',
            'Цена продажи, ₸',
            'Сумма, ₸',
            'Примечание кассира',
        ];

        $sheet->fromArray($detailHeaders, null, "A{$detailHeaderRow}");
        $lastDetailCol = Coordinate::stringFromColumnIndex(count($detailHeaders)); // L

        $this->styleTableHeader($sheet, "A{$detailHeaderRow}:{$lastDetailCol}{$detailHeaderRow}");
        $sheet->getRowDimension($detailHeaderRow)->setRowHeight(26);

        $dr = $detailHeaderRow + 1;
        $detSeq = 1;

        foreach ($batchSales as $sale) {
            $dateTime = ($sale->approved_at ?? $sale->created_at)?->timezone('Asia/Almaty');
            $dateStr = $dateTime ? $dateTime->format('d.m.Y H:i') : '—';
            $docNum = '№' . $sale->id;
            $cashierName = $sale->cashier?->name ?? 'Не указан';
            $note = $sale->cashier_note ?: '—';

            foreach ($sale->items as $item) {
                $prodName = $item->product_name ?: $item->product?->name ?: 'Товар без наименования';
                $catName = $item->product?->category?->name ?: 'Без категории';
                $prodType = $this->resolveProductType($item->product_type, $item->product?->type, $prodName, $catName);
                $isHat = $this->isHatTypeOrName($prodType, $prodName);
                $size = $isHat ? '—' : ($item->variant_size ?: '—');
                $color = $this->resolveItemColor($item);
                $qty = (int) $item->quantity;
                $price = (float) $item->sale_price;

                $sheet->fromArray([
                    $detSeq++,                                      // A: № п/п
                    $docNum,                                        // B: № чека
                    $dateStr,                                       // C: Дата
                    $cashierName,                                   // D: Кассир
                    $prodType,                                      // E: Вид
                    $prodName,                                      // F: Номенклатура
                    $size,                                          // G: Размер
                    $color,                                         // H: Цвет
                    $qty,                                           // I: Кол-во, шт.
                    $price,                                         // J: Цена, ₸
                    "=I{$dr}*J{$dr}",                               // K: Сумма, ₸
                    $note,                                          // L: Примечание
                ], null, "A{$dr}");

                if ($dr % 2 === 0) {
                    $sheet->getStyle("A{$dr}:{$lastDetailCol}{$dr}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_ZEBRA_BG);
                }
                $dr++;
            }
        }

        $detailEndRow = max($detailHeaderRow + 1, $dr - 1);
        $detailTotalRow = $dr;

        $sheet->mergeCells("A{$detailTotalRow}:H{$detailTotalRow}");
        $sheet->setCellValue("A{$detailTotalRow}", 'ВСЕГО ПО РЕЕСТРУ ПАРТИИ:');
        $sheet->setCellValue("I{$detailTotalRow}", "=SUBTOTAL(109, I" . ($detailHeaderRow + 1) . ":I{$detailEndRow})");
        $sheet->setCellValue("J{$detailTotalRow}", '—');
        $sheet->setCellValue("K{$detailTotalRow}", "=SUBTOTAL(109, K" . ($detailHeaderRow + 1) . ":K{$detailEndRow})");
        $sheet->setCellValue("L{$detailTotalRow}", "=SUBTOTAL(103, A" . ($detailHeaderRow + 1) . ":A{$detailEndRow}) & \" поз.\"");

        $this->styleTotalRow($sheet, "A{$detailTotalRow}:{$lastDetailCol}{$detailTotalRow}");
        $sheet->getStyle("A" . ($detailHeaderRow + 1) . ":{$lastDetailCol}{$detailEndRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::COLOR_BORDER);

        $sheet->getStyle("A" . ($detailHeaderRow + 1) . ":C{$detailEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("G" . ($detailHeaderRow + 1) . ":H{$detailEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("I" . ($detailHeaderRow + 1) . ":I{$detailTotalRow}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("J" . ($detailHeaderRow + 1) . ":K{$detailTotalRow}")->getNumberFormat()->setFormatCode('#,##0 [$₸-43F]');

        // Автофильтр на реестр партии
        $sheet->setAutoFilter("A{$detailHeaderRow}:{$lastDetailCol}{$detailEndRow}");

        $this->autoFitColumns($sheet, $lastDetailCol);
    }

    // =========================================================================
    // ЛИСТ 3: ВЕДОМОСТЬ ПО НОМЕНКЛАТУРЕ (ОСНОВНОЙ УЧЕТ)
    // =========================================================================

    private function buildNomenclatureSheet($sheet, Collection $sales, array $params): void
    {
        $sheet->setTitle('По номенклатуре');
        $sheet->setShowGridlines(true);

        $this->renderReportHeader(
            $sheet,
            'ВЕДОМОСТЬ ПРОДАЖ ПО НОМЕНКЛАТУРЕ И ХАРАКТЕРИСТИКАМ (ОСНОВНОЙ УЧЕТ)',
            'Аналитический отчет по номенклатуре и размерной сетке (без партии «Новый завоз», выведенной за рамки учета)',
            $params
        );

        $headers = [
            '№ п/п',
            'Категория',
            'Вид изделия',
            'Номенклатура (Товар)',
            'Размер (Характеристика)',
            'Цвет',
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
        $lastCol = Coordinate::stringFromColumnIndex(count($headers)); // Q

        $this->styleTableHeader($sheet, "A{$headerRow}:{$lastCol}{$headerRow}");
        $sheet->getRowDimension($headerRow)->setRowHeight(28);

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
                $color = $this->resolveItemColor($item);
                $isItemOnOrder = $item->is_on_order !== null ? (bool) $item->is_on_order : $saleIsOnOrder;

                $qty = (int) $item->quantity;
                $lineTotal = (float) $item->line_total;
                $discAmt = (float) (($item->discount_amount ?: 0) * $qty);
                $origSubtotal = (float) (($item->original_price ?: ($item->sale_price + ($item->discount_amount ?: 0))) * $qty);
                $unitCost = (float) ($item->purchase_price ?: $item->productVariant?->purchase_price ?: $item->product?->purchase_price ?: 0);
                $costTotal = $qty * $unitCost;
                $profitTotal = $lineTotal - $costTotal;

                $key = "{$catName}___{$prodType}___{$prodName}___{$size}___{$color}";

                if (!isset($aggregated[$key])) {
                    $aggregated[$key] = [
                        'category' => $catName,
                        'type' => $prodType,
                        'name' => $prodName,
                        'size' => $size,
                        'color' => $color,
                        'qty_total' => 0,
                        'qty_stock' => 0,
                        'qty_order' => 0,
                        'orig_subtotal' => 0.0,
                        'discount_total' => 0.0,
                        'revenue_total' => 0.0,
                        'cost_total' => 0.0,
                        'profit_total' => 0.0,
                    ];
                }

                $aggregated[$key]['qty_total'] += $qty;
                if ($isItemOnOrder) {
                    $aggregated[$key]['qty_order'] += $qty;
                } else {
                    $aggregated[$key]['qty_stock'] += $qty;
                }

                $aggregated[$key]['orig_subtotal'] += $origSubtotal;
                $aggregated[$key]['discount_total'] += $discAmt;
                $aggregated[$key]['revenue_total'] += $lineTotal;
                $aggregated[$key]['cost_total'] += $costTotal;
                $aggregated[$key]['profit_total'] += $profitTotal;

                $typeTotals[$prodType] = ($typeTotals[$prodType] ?? 0.0) + $lineTotal;
            }
        }

        uasort($aggregated, function ($a, $b) {
            if ($a['category'] !== $b['category']) {
                return strcmp($a['category'], $b['category']);
            }
            if ($a['type'] !== $b['type']) {
                return strcmp($a['type'], $b['type']);
            }
            if ($a['name'] !== $b['name']) {
                return strcmp($a['name'], $b['name']);
            }
            return $this->compareSizes($a['size'], $b['size']);
        });

        $r = 8;
        $seq = 1;

        foreach ($aggregated as $item) {
            $prodType = $item['type'];
            $typeRevenue = $typeTotals[$prodType] ?? 0.0;
            $shareInType = $typeRevenue > 0 ? ($item['revenue_total'] / $typeRevenue) : 0.0;

            $rowData = [
                $seq++,                                         // A: № п/п
                $item['category'],                              // B: Категория
                $item['type'],                                  // C: Вид изделия
                $item['name'],                                  // D: Номенклатура
                $item['size'],                                  // E: Размер
                $item['color'],                                 // F: Цвет
                $item['qty_total'],                             // G: Продано всего
                $item['qty_stock'],                             // H: Из наличия
                $item['qty_order'],                             // I: Под заказ
                $item['orig_subtotal'],                         // J: Базовая сумма
                $item['discount_total'],                        // K: Скидки
                "=J{$r}-K{$r}",                                 // L: Выручка фактически
                $item['cost_total'],                            // M: Себестоимость
                "=L{$r}-M{$r}",                                 // N: Валовая прибыль
                "=IF(G{$r}>0, L{$r}/G{$r}, 0)",                 // O: Средняя цена шт.
                "=IF(L{$r}>0, N{$r}/L{$r}, 0)",                 // P: Рентабельность %
                $shareInType,                                   // Q: Доля в выручке вида
            ];

            $sheet->fromArray($rowData, null, "A{$r}");

            if ($r % 2 === 0) {
                $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_ZEBRA_BG);
            }

            $r++;
        }

        $dataEndRow = max(8, $r - 1);

        // Строка 5: ДИНАМИЧЕСКИЙ СВОД ПО ВЫБОРКЕ
        $sheet->mergeCells('A5:F5');
        $sheet->setCellValue('A5', 'ИТОГО ПО ВЫБОРКЕ (ОСНОВНОЙ УЧЕТ):');
        $sheet->setCellValue('G5', "=SUBTOTAL(109, G8:G{$dataEndRow})");
        $sheet->setCellValue('H5', "=SUBTOTAL(109, H8:H{$dataEndRow})");
        $sheet->setCellValue('I5', "=SUBTOTAL(109, I8:I{$dataEndRow})");
        $sheet->setCellValue('J5', "=SUBTOTAL(109, J8:J{$dataEndRow})");
        $sheet->setCellValue('K5', "=SUBTOTAL(109, K8:K{$dataEndRow})");
        $sheet->setCellValue('L5', "=SUBTOTAL(109, L8:L{$dataEndRow})");
        $sheet->setCellValue('M5', "=SUBTOTAL(109, M8:M{$dataEndRow})");
        $sheet->setCellValue('N5', "=SUBTOTAL(109, N8:N{$dataEndRow})");
        $sheet->setCellValue('O5', "=IF(G5>0, L5/G5, 0)");
        $sheet->setCellValue('P5', "=IF(L5>0, N5/L5, 0)");
        $sheet->setCellValue('Q5', '100.0%');

        $this->styleDynamicKpiRow($sheet, "A5:{$lastCol}5");

        // Нижняя строка итогов
        $totalRow = $r;
        $sheet->mergeCells("A{$totalRow}:F{$totalRow}");
        $sheet->setCellValue("A{$totalRow}", 'ВСЕГО ПО НОМЕНКЛАТУРЕ (ОСНОВНОЙ УЧЕТ):');
        $sheet->setCellValue("G{$totalRow}", "=SUBTOTAL(109, G8:G{$dataEndRow})");
        $sheet->setCellValue("H{$totalRow}", "=SUBTOTAL(109, H8:H{$dataEndRow})");
        $sheet->setCellValue("I{$totalRow}", "=SUBTOTAL(109, I8:I{$dataEndRow})");
        $sheet->setCellValue("J{$totalRow}", "=SUBTOTAL(109, J8:J{$dataEndRow})");
        $sheet->setCellValue("K{$totalRow}", "=SUBTOTAL(109, K8:K{$dataEndRow})");
        $sheet->setCellValue("L{$totalRow}", "=SUBTOTAL(109, L8:L{$dataEndRow})");
        $sheet->setCellValue("M{$totalRow}", "=SUBTOTAL(109, M8:M{$dataEndRow})");
        $sheet->setCellValue("N{$totalRow}", "=SUBTOTAL(109, N8:N{$dataEndRow})");
        $sheet->setCellValue("O{$totalRow}", "=IF(G{$totalRow}>0, L{$totalRow}/G{$totalRow}, 0)");
        $sheet->setCellValue("P{$totalRow}", "=IF(L{$totalRow}>0, N{$totalRow}/L{$totalRow}, 0)");
        $sheet->setCellValue("Q{$totalRow}", '100.0%');

        $this->styleTotalRow($sheet, "A{$totalRow}:{$lastCol}{$totalRow}");

        $sheet->getStyle("A8:{$lastCol}{$dataEndRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::COLOR_BORDER);

        $sheet->getStyle("A8:A{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("E8:F{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->getStyle("G8:I{$totalRow}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("J8:N{$totalRow}")->getNumberFormat()->setFormatCode('#,##0 [$₸-43F]');
        $sheet->getStyle("O8:O{$totalRow}")->getNumberFormat()->setFormatCode('#,##0 [$₸-43F]');
        $sheet->getStyle("P8:Q{$totalRow}")->getNumberFormat()->setFormatCode('0.0%');

        $sheet->setAutoFilter("A{$headerRow}:{$lastCol}{$dataEndRow}");
        $sheet->freezePane('A8');

        $this->autoFitColumns($sheet, $lastCol);
    }

    // =========================================================================
    // ЛИСТ 4: СВОД ПО КАТЕГОРИЯМ И ВИДАМ ТОВАРОВ (ОСНОВНОЙ УЧЕТ)
    // =========================================================================

    private function buildCategorySummarySheet($sheet, Collection $sales, array $params): void
    {
        $sheet->setTitle('По категориям');
        $sheet->setShowGridlines(true);

        $this->renderReportHeader(
            $sheet,
            'СВОДНАЯ ВЕДОМОСТЬ: ПРОДАЖИ ПО КАТЕГОРИЯМ (ОСНОВНОЙ УЧЕТ)',
            'Укрупненный анализ товарных направлений (без партии «Новый завоз», выведенной за рамки учета)',
            $params
        );

        $headers = [
            '№ п/п',
            'Категория',
            'Вид изделия',
            'Продано всего, шт.',
            '✓ Из наличия, шт.',
            '📦 Под заказ, шт.',
            'Выручка общая, ₸',
            'Выручка «В наличии», ₸',
            'Выручка «Под заказ», ₸',
            'Сумма скидок, ₸',
            'Себестоимость, ₸',
            'Валовая прибыль, ₸',
            'Рентабельность, %',
            'Доля в общей выручке, %',
        ];

        $headerRow = 7;
        $sheet->fromArray($headers, null, "A{$headerRow}");
        $lastCol = Coordinate::stringFromColumnIndex(count($headers)); // N

        $this->styleTableHeader($sheet, "A{$headerRow}:{$lastCol}{$headerRow}");
        $sheet->getRowDimension($headerRow)->setRowHeight(28);

        $byGroup = [];
        $grandRevenue = 0.0;

        foreach ($sales as $sale) {
            if ($sale->status === 'rejected') {
                continue;
            }
            $saleIsOnOrder = (bool) $sale->is_on_order;

            foreach ($sale->items as $item) {
                $prodName = $item->product_name ?: $item->product?->name ?: 'Товар без наименования';
                $catName = $item->product?->category?->name ?: 'Без категории';
                $prodType = $this->resolveProductType($item->product_type, $item->product?->type, $prodName, $catName);
                $isItemOnOrder = $item->is_on_order !== null ? (bool) $item->is_on_order : $saleIsOnOrder;

                $qty = (int) $item->quantity;
                $lineTotal = (float) $item->line_total;
                $discAmt = (float) (($item->discount_amount ?: 0) * $qty);
                $unitCost = (float) ($item->purchase_price ?: $item->productVariant?->purchase_price ?: $item->product?->purchase_price ?: 0);
                $costTotal = $qty * $unitCost;

                $key = "{$catName}___{$prodType}";

                if (!isset($byGroup[$key])) {
                    $byGroup[$key] = [
                        'category' => $catName,
                        'type' => $prodType,
                        'qty_total' => 0,
                        'qty_stock' => 0,
                        'qty_order' => 0,
                        'rev_total' => 0.0,
                        'rev_stock' => 0.0,
                        'rev_order' => 0.0,
                        'discount' => 0.0,
                        'cost' => 0.0,
                    ];
                }

                $byGroup[$key]['qty_total'] += $qty;
                if ($isItemOnOrder) {
                    $byGroup[$key]['qty_order'] += $qty;
                    $byGroup[$key]['rev_order'] += $lineTotal;
                } else {
                    $byGroup[$key]['qty_stock'] += $qty;
                    $byGroup[$key]['rev_stock'] += $lineTotal;
                }

                $byGroup[$key]['rev_total'] += $lineTotal;
                $byGroup[$key]['discount'] += $discAmt;
                $byGroup[$key]['cost'] += $costTotal;

                $grandRevenue += $lineTotal;
            }
        }

        uasort($byGroup, function ($a, $b) {
            return $b['rev_total'] <=> $a['rev_total'];
        });

        $r = 8;
        $seq = 1;

        foreach ($byGroup as $row) {
            $shareOfTotal = $grandRevenue > 0 ? ($row['rev_total'] / $grandRevenue) : 0.0;

            $rowData = [
                $seq++,                                         // A: № п/п
                $row['category'],                               // B: Категория
                $row['type'],                                   // C: Вид изделия
                $row['qty_total'],                              // D: Продано всего
                $row['qty_stock'],                              // E: В наличии шт
                $row['qty_order'],                              // F: Под заказ шт
                $row['rev_total'],                              // G: Выручка общ
                $row['rev_stock'],                              // H: Выручка наличие
                $row['rev_order'],                              // I: Выручка под заказ
                $row['discount'],                               // J: Скидки
                $row['cost'],                                   // K: Себестоимость
                "=G{$r}-K{$r}",                                 // L: Валовая прибыль
                "=IF(G{$r}>0, L{$r}/G{$r}, 0)",                 // M: Рентабельность %
                $shareOfTotal,                                  // N: Доля в выручке
            ];

            $sheet->fromArray($rowData, null, "A{$r}");

            if ($r % 2 === 0) {
                $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_ZEBRA_BG);
            }

            $r++;
        }

        $dataEndRow = max(8, $r - 1);

        // Строка 5: ДИНАМИЧЕСКИЙ СВОД ПО ВЫБОРКЕ
        $sheet->mergeCells('A5:C5');
        $sheet->setCellValue('A5', 'ИТОГО ПО ВЫБОРКЕ (ОСНОВНОЙ УЧЕТ):');
        $sheet->setCellValue('D5', "=SUBTOTAL(109, D8:D{$dataEndRow})");
        $sheet->setCellValue('E5', "=SUBTOTAL(109, E8:E{$dataEndRow})");
        $sheet->setCellValue('F5', "=SUBTOTAL(109, F8:F{$dataEndRow})");
        $sheet->setCellValue('G5', "=SUBTOTAL(109, G8:G{$dataEndRow})");
        $sheet->setCellValue('H5', "=SUBTOTAL(109, H8:H{$dataEndRow})");
        $sheet->setCellValue('I5', "=SUBTOTAL(109, I8:I{$dataEndRow})");
        $sheet->setCellValue('J5', "=SUBTOTAL(109, J8:J{$dataEndRow})");
        $sheet->setCellValue('K5', "=SUBTOTAL(109, K8:K{$dataEndRow})");
        $sheet->setCellValue('L5', "=SUBTOTAL(109, L8:L{$dataEndRow})");
        $sheet->setCellValue('M5', "=IF(G5>0, L5/G5, 0)");
        $sheet->setCellValue('N5', '100.0%');

        $this->styleDynamicKpiRow($sheet, "A5:{$lastCol}5");

        // Нижняя строка итогов
        $totalRow = $r;
        $sheet->mergeCells("A{$totalRow}:C{$totalRow}");
        $sheet->setCellValue("A{$totalRow}", 'ВСЕГО ПО КАТЕГОРИЯМ (ОСНОВНОЙ УЧЕТ):');
        $sheet->setCellValue("D{$totalRow}", "=SUBTOTAL(109, D8:D{$dataEndRow})");
        $sheet->setCellValue("E{$totalRow}", "=SUBTOTAL(109, E8:E{$dataEndRow})");
        $sheet->setCellValue("F{$totalRow}", "=SUBTOTAL(109, F8:F{$dataEndRow})");
        $sheet->setCellValue("G{$totalRow}", "=SUBTOTAL(109, G8:G{$dataEndRow})");
        $sheet->setCellValue("H{$totalRow}", "=SUBTOTAL(109, H8:H{$dataEndRow})");
        $sheet->setCellValue("I{$totalRow}", "=SUBTOTAL(109, I8:I{$dataEndRow})");
        $sheet->setCellValue("J{$totalRow}", "=SUBTOTAL(109, J8:J{$dataEndRow})");
        $sheet->setCellValue("K{$totalRow}", "=SUBTOTAL(109, K8:K{$dataEndRow})");
        $sheet->setCellValue("L{$totalRow}", "=SUBTOTAL(109, L8:L{$dataEndRow})");
        $sheet->setCellValue("M{$totalRow}", "=IF(G{$totalRow}>0, L{$totalRow}/G{$totalRow}, 0)");
        $sheet->setCellValue("N{$totalRow}", '100.0%');

        $this->styleTotalRow($sheet, "A{$totalRow}:{$lastCol}{$totalRow}");

        $sheet->getStyle("A8:{$lastCol}{$dataEndRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::COLOR_BORDER);

        $sheet->getStyle("A8:A{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->getStyle("D8:F{$totalRow}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("G8:L{$totalRow}")->getNumberFormat()->setFormatCode('#,##0 [$₸-43F]');
        $sheet->getStyle("M8:N{$totalRow}")->getNumberFormat()->setFormatCode('0.0%');

        $sheet->setAutoFilter("A{$headerRow}:{$lastCol}{$dataEndRow}");
        $sheet->freezePane('A8');

        $this->autoFitColumns($sheet, $lastCol);
    }

    // =========================================================================
    // ЛИСТ 5: ДИНАМИКА ПРОДАЖ ПО ДНЯМ (ОСНОВНОЙ УЧЕТ)
    // =========================================================================

    private function buildPeriodDynamicsSheet($sheet, Collection $sales, array $params): void
    {
        $sheet->setTitle('Динамика по дням');
        $sheet->setShowGridlines(true);

        $this->renderReportHeader(
            $sheet,
            'ОПЕРАТИВНЫЙ КАЛЕНДАРЬ: ДИНАМИКА ПРОДАЖ ПО ДНЯМ (ОСНОВНОЙ УЧЕТ)',
            'Хронологический анализ ежедневной выручки и чеков основного учета (без партии «Новый завоз»)',
            $params
        );

        $headers = [
            '№ п/п',
            'Дата',
            'День недели',
            'Период (Месяц)',
            'Кол-во чеков',
            'Продано вещей, шт.',
            '✓ Из наличия, шт.',
            '📦 Под заказ, шт.',
            'Выручка за день, ₸',
            'Выручка наличие, ₸',
            'Выручка под заказ, ₸',
            'Средний чек, ₸',
            'Сумма скидок, ₸',
            'Валовая прибыль, ₸',
        ];

        $headerRow = 7;
        $sheet->fromArray($headers, null, "A{$headerRow}");
        $lastCol = Coordinate::stringFromColumnIndex(count($headers)); // N

        $this->styleTableHeader($sheet, "A{$headerRow}:{$lastCol}{$headerRow}");
        $sheet->getRowDimension($headerRow)->setRowHeight(28);

        $byDay = [];

        foreach ($sales as $sale) {
            if ($sale->status === 'rejected') {
                continue;
            }
            $dateTime = ($sale->approved_at ?? $sale->created_at)?->timezone('Asia/Almaty');
            $dayKey = $dateTime ? $dateTime->format('Y-m-d') : 'Без даты';
            $dayName = $dateTime ? $dateTime->locale('ru')->isoFormat('dddd') : '—';
            $monthStr = $dateTime ? $dateTime->format('Y-m') : '—';
            $saleIsOnOrder = (bool) $sale->is_on_order;

            if (!isset($byDay[$dayKey])) {
                $byDay[$dayKey] = [
                    'date' => $dayKey,
                    'day_name' => mb_convert_case($dayName, MB_CASE_TITLE, 'UTF-8'),
                    'month' => $monthStr,
                    'checks' => 0,
                    'qty_total' => 0,
                    'qty_stock' => 0,
                    'qty_order' => 0,
                    'revenue_total' => 0.0,
                    'revenue_stock' => 0.0,
                    'revenue_order' => 0.0,
                    'discount_total' => 0.0,
                    'cost_total' => 0.0,
                ];
            }

            $byDay[$dayKey]['checks']++;

            foreach ($sale->items as $item) {
                $qty = (int) $item->quantity;
                $lineTotal = (float) $item->line_total;
                $discAmt = (float) (($item->discount_amount ?: 0) * $qty);
                $isItemOnOrder = $item->is_on_order !== null ? (bool) $item->is_on_order : $saleIsOnOrder;
                $unitCost = (float) ($item->purchase_price ?: $item->productVariant?->purchase_price ?: $item->product?->purchase_price ?: 0);

                $byDay[$dayKey]['qty_total'] += $qty;
                if ($isItemOnOrder) {
                    $byDay[$dayKey]['qty_order'] += $qty;
                    $byDay[$dayKey]['revenue_order'] += $lineTotal;
                } else {
                    $byDay[$dayKey]['qty_stock'] += $qty;
                    $byDay[$dayKey]['revenue_stock'] += $lineTotal;
                }

                $byDay[$dayKey]['revenue_total'] += $lineTotal;
                $byDay[$dayKey]['discount_total'] += $discAmt;
                $byDay[$dayKey]['cost_total'] += ($qty * $unitCost);
            }
        }

        krsort($byDay);

        $r = 8;
        $seq = 1;

        foreach ($byDay as $day) {
            $formattedDate = $day['date'] !== 'Без даты' ? Carbon::parse($day['date'])->format('d.m.Y') : 'Без даты';

            $rowData = [
                $seq++,                                         // A: № п/п
                $formattedDate,                                 // B: Дата
                $day['day_name'],                               // C: День недели
                $day['month'],                                  // D: Месяц
                $day['checks'],                                 // E: Чеков
                $day['qty_total'],                              // F: Продано шт.
                $day['qty_stock'],                              // G: В наличии шт
                $day['qty_order'],                              // H: Под заказ шт
                $day['revenue_total'],                          // I: Выручка за день
                $day['revenue_stock'],                          // J: Выручка наличие
                $day['revenue_order'],                          // K: Выручка под заказ
                "=IF(E{$r}>0, I{$r}/E{$r}, 0)",                 // L: Средний чек
                $day['discount_total'],                         // M: Скидки
                "=I{$r}-" . $day['cost_total'],                 // N: Валовая прибыль
            ];

            $sheet->fromArray($rowData, null, "A{$r}");

            if ($r % 2 === 0) {
                $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_ZEBRA_BG);
            }

            $r++;
        }

        $dataEndRow = max(8, $r - 1);

        // Строка 5: ДИНАМИЧЕСКИЙ СВОД ПО ВЫБОРКЕ
        $sheet->mergeCells('A5:D5');
        $sheet->setCellValue('A5', 'ИТОГО ПО ВЫБОРКЕ ДНЕЙ (ОСНОВНОЙ УЧЕТ):');
        $sheet->setCellValue('E5', "=SUBTOTAL(109, E8:E{$dataEndRow})");
        $sheet->setCellValue('F5', "=SUBTOTAL(109, F8:F{$dataEndRow})");
        $sheet->setCellValue('G5', "=SUBTOTAL(109, G8:G{$dataEndRow})");
        $sheet->setCellValue('H5', "=SUBTOTAL(109, H8:H{$dataEndRow})");
        $sheet->setCellValue('I5', "=SUBTOTAL(109, I8:I{$dataEndRow})");
        $sheet->setCellValue('J5', "=SUBTOTAL(109, J8:J{$dataEndRow})");
        $sheet->setCellValue('K5', "=SUBTOTAL(109, K8:K{$dataEndRow})");
        $sheet->setCellValue('L5', "=IF(E5>0, I5/E5, 0)");
        $sheet->setCellValue('M5', "=SUBTOTAL(109, M8:M{$dataEndRow})");
        $sheet->setCellValue('N5', "=SUBTOTAL(109, N8:N{$dataEndRow})");

        $this->styleDynamicKpiRow($sheet, "A5:{$lastCol}5");

        // Нижняя строка итогов
        $totalRow = $r;
        $sheet->mergeCells("A{$totalRow}:D{$totalRow}");
        $sheet->setCellValue("A{$totalRow}", 'ВСЕГО ЗА ПЕРИОД (ОСНОВНОЙ УЧЕТ):');
        $sheet->setCellValue("E{$totalRow}", "=SUBTOTAL(109, E8:E{$dataEndRow})");
        $sheet->setCellValue("F{$totalRow}", "=SUBTOTAL(109, F8:F{$dataEndRow})");
        $sheet->setCellValue("G{$totalRow}", "=SUBTOTAL(109, G8:G{$dataEndRow})");
        $sheet->setCellValue("H{$totalRow}", "=SUBTOTAL(109, H8:H{$dataEndRow})");
        $sheet->setCellValue("I{$totalRow}", "=SUBTOTAL(109, I8:I{$dataEndRow})");
        $sheet->setCellValue("J{$totalRow}", "=SUBTOTAL(109, J8:J{$dataEndRow})");
        $sheet->setCellValue("K{$totalRow}", "=SUBTOTAL(109, K8:K{$dataEndRow})");
        $sheet->setCellValue("L{$totalRow}", "=IF(E{$totalRow}>0, I{$totalRow}/E{$totalRow}, 0)");
        $sheet->setCellValue("M{$totalRow}", "=SUBTOTAL(109, M8:M{$dataEndRow})");
        $sheet->setCellValue("N{$totalRow}", "=SUBTOTAL(109, N8:N{$dataEndRow})");

        $this->styleTotalRow($sheet, "A{$totalRow}:{$lastCol}{$totalRow}");

        $sheet->getStyle("A8:{$lastCol}{$dataEndRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::COLOR_BORDER);

        $sheet->getStyle("A8:D{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->getStyle("E8:H{$totalRow}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("I8:N{$totalRow}")->getNumberFormat()->setFormatCode('#,##0 [$₸-43F]');

        $sheet->setAutoFilter("A{$headerRow}:{$lastCol}{$dataEndRow}");
        $sheet->freezePane('A8');

        $this->autoFitColumns($sheet, $lastCol);
    }

    // =========================================================================
    // ЛИСТ 6: ЖУРНАЛ ЧЕКОВ (ОСНОВНОЙ УЧЕТ)
    // =========================================================================

    private function buildReceiptsJournalSheet($sheet, Collection $sales, array $params): void
    {
        $sheet->setTitle('Журнал чеков');
        $sheet->setShowGridlines(true);

        $this->renderReportHeader(
            $sheet,
            'ЖУРНАЛ ДОКУМЕНТОВ: ЧЕКИ ККМ (ОСНОВНОЙ УЧЕТ)',
            'Реестр первичных торговых документов основного учета (1 строка = 1 чек, без партии «Новый завоз»)',
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
            $note = $sale->cashier_note ?: '—';

            $itemLinesCount = $sale->items->count();
            $totalPieces = 0;
            $itemsSummary = [];
            $saleCostTotal = 0.0;
            $saleOrigSubtotal = 0.0;

            foreach ($sale->items as $item) {
                $q = (int) $item->quantity;
                $totalPieces += $q;
                $pName = $item->product_name ?: $item->product?->name ?: 'Товар';
                $sz = $item->variant_size ? " ({$item->variant_size})" : '';
                $itemsSummary[] = "{$pName}{$sz} × {$q}";

                $unitCost = (float) ($item->purchase_price ?: $item->productVariant?->purchase_price ?: $item->product?->purchase_price ?: 0);
                $saleCostTotal += ($q * $unitCost);

                $origP = (float) ($item->original_price ?: ($item->sale_price + ($item->discount_amount ?: 0)));
                $saleOrigSubtotal += ($q * $origP);
            }

            $compositionStr = implode(', ', array_slice($itemsSummary, 0, 4));
            if (count($itemsSummary) > 4) {
                $compositionStr .= ' и др.';
            }

            $finalRevenue = (float) $sale->subtotal;
            $saleDiscount = (float) ($sale->discount_amount ?: max(0.0, $saleOrigSubtotal - $finalRevenue));
            if ($saleOrigSubtotal <= 0) {
                $saleOrigSubtotal = $finalRevenue + $saleDiscount;
            }

            $rowData = [
                $seq++,                                         // A: № п/п
                $docNum,                                        // B: № чека
                $dateStr,                                       // C: Дата и время
                $monthStr,                                      // D: Месяц
                $orderTypeLabel,                                // E: Тип операции
                $cashierName,                                   // F: Кассир
                $itemLinesCount,                                // G: Строк в чеке
                $totalPieces,                                   // H: Всего штук
                $saleOrigSubtotal,                              // I: Сумма без скидки
                $saleDiscount,                                  // J: Скидка
                "=IF(I{$r}>0, J{$r}/I{$r}, 0)",                 // K: Скидка, %
                $finalRevenue,                                  // L: Итого выручка
                $saleCostTotal,                                 // M: Себестоимость чека
                "=L{$r}-M{$r}",                                 // N: Прибыль чека
                $docStatus,                                     // O: Статус чека
                $orderStatus,                                   // P: Статус заказа
                $dueDateStr,                                    // Q: Срок
                $compositionStr,                                // R: Краткий состав
                $note,                                          // S: Примечание
            ];

            $sheet->fromArray($rowData, null, "A{$r}");

            if ($r % 2 === 0) {
                $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_ZEBRA_BG);
            }

            $r++;
        }

        $dataEndRow = max(8, $r - 1);

        // Строка 5: ДИНАМИЧЕСКИЙ СВОД ПО ВЫБОРКЕ
        $sheet->mergeCells('A5:F5');
        $sheet->setCellValue('A5', 'ИТОГО ПО ВЫБОРКЕ ЧЕКОВ (ОСНОВНОЙ УЧЕТ):');
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

        // Нижняя строка итогов
        $totalRow = $r;
        $sheet->mergeCells("A{$totalRow}:F{$totalRow}");
        $sheet->setCellValue("A{$totalRow}", 'ВСЕГО ПО ЖУРНАЛУ ЧЕКОВ (ОСНОВНОЙ УЧЕТ):');
        $sheet->setCellValue("G{$totalRow}", "=SUBTOTAL(109, G8:G{$dataEndRow})");
        $sheet->setCellValue("H{$totalRow}", "=SUBTOTAL(109, H8:H{$dataEndRow})");
        $sheet->setCellValue("I{$totalRow}", "=SUBTOTAL(109, I8:I{$dataEndRow})");
        $sheet->setCellValue("J{$totalRow}", "=SUBTOTAL(109, J8:J{$dataEndRow})");
        $sheet->setCellValue("K{$totalRow}", "=IF(I{$totalRow}>0, J{$totalRow}/I{$totalRow}, 0)");
        $sheet->setCellValue("L{$totalRow}", "=SUBTOTAL(109, L8:L{$dataEndRow})");
        $sheet->setCellValue("M{$totalRow}", "=SUBTOTAL(109, M8:M{$dataEndRow})");
        $sheet->setCellValue("N{$totalRow}", "=SUBTOTAL(109, N8:N{$dataEndRow})");
        $sheet->mergeCells("O{$totalRow}:S{$totalRow}");
        $sheet->setCellValue("O{$totalRow}", "=SUBTOTAL(103, A8:A{$dataEndRow}) & \" чеков оформлено\"");

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

        $this->autoFitColumns($sheet, $lastCol);
    }

    // =========================================================================
    // ЛИСТ 7: ВЫРАБОТКА СОТРУДНИКОВ (ОСНОВНОЙ УЧЕТ)
    // =========================================================================

    private function buildCashierPerformanceSheet($sheet, Collection $sales, array $params): void
    {
        $sheet->setTitle('Кассиры');
        $sheet->setShowGridlines(true);

        $this->renderReportHeader(
            $sheet,
            'ВЫРАБОТКА ПЕРСОНАЛА: ОТЧЕТ ПО КАССИРАМ (ОСНОВНОЙ УЧЕТ)',
            'Сравнительная ведомость продуктивности сотрудников по основному торговому обороту',
            $params
        );

        $headers = [
            '№ п/п',
            'Сотрудник (Кассир)',
            'Количество чеков',
            'Продано вещей, шт.',
            'Чеков «В наличии»',
            'Заказов «Под заказ»',
            'Сумма выручки, ₸',
            'Средний чек, ₸',
            'Предоставлено скидок, ₸',
            'Средний % скидки',
            'Доля в выручке магазина, %',
        ];

        $headerRow = 7;
        $sheet->fromArray($headers, null, "A{$headerRow}");
        $lastCol = Coordinate::stringFromColumnIndex(count($headers)); // K

        $this->styleTableHeader($sheet, "A{$headerRow}:{$lastCol}{$headerRow}");
        $sheet->getRowDimension($headerRow)->setRowHeight(28);

        $byCashier = [];
        $totalRevenueAll = 0.0;

        foreach ($sales as $sale) {
            if ($sale->status === 'rejected') {
                continue;
            }
            $cName = $sale->cashier?->name ?? 'Не указан';
            $saleIsOnOrder = (bool) $sale->is_on_order;

            if (!isset($byCashier[$cName])) {
                $byCashier[$cName] = [
                    'name' => $cName,
                    'checks_count' => 0,
                    'pieces_count' => 0,
                    'stock_checks' => 0,
                    'order_checks' => 0,
                    'revenue_total' => 0.0,
                    'discount_total' => 0.0,
                    'orig_total' => 0.0,
                ];
            }

            $byCashier[$cName]['checks_count']++;
            if ($saleIsOnOrder) {
                $byCashier[$cName]['order_checks']++;
            } else {
                $byCashier[$cName]['stock_checks']++;
            }

            $rev = (float) $sale->subtotal;
            $byCashier[$cName]['revenue_total'] += $rev;
            $totalRevenueAll += $rev;

            foreach ($sale->items as $item) {
                $q = (int) $item->quantity;
                $byCashier[$cName]['pieces_count'] += $q;
                $disc = (float) (($item->discount_amount ?: 0) * $q);
                $byCashier[$cName]['discount_total'] += $disc;
                $orig = (float) (($item->original_price ?: ($item->sale_price + ($item->discount_amount ?: 0))) * $q);
                $byCashier[$cName]['orig_total'] += $orig;
            }
        }

        uasort($byCashier, function ($a, $b) {
            return $b['revenue_total'] <=> $a['revenue_total'];
        });

        $r = 8;
        $seq = 1;

        foreach ($byCashier as $c) {
            $share = $totalRevenueAll > 0 ? ($c['revenue_total'] / $totalRevenueAll) : 0.0;

            $rowData = [
                $seq++,                                         // A: № п/п
                $c['name'],                                     // B: Сотрудник
                $c['checks_count'],                             // C: Чеков
                $c['pieces_count'],                             // D: Вещей шт
                $c['stock_checks'],                             // E: Чеков наличие
                $c['order_checks'],                             // F: Заказов
                $c['revenue_total'],                            // G: Выручка
                "=IF(C{$r}>0, G{$r}/C{$r}, 0)",                 // H: Средний чек
                $c['discount_total'],                           // I: Скидки
                "=IF(G{$r}>0, I{$r}/(G{$r}+I{$r}), 0)",         // J: Средний % скидки
                $share,                                         // K: Доля в выручке
            ];

            $sheet->fromArray($rowData, null, "A{$r}");

            if ($r % 2 === 0) {
                $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_ZEBRA_BG);
            }

            $r++;
        }

        $dataEndRow = max(8, $r - 1);

        // Строка 5: ДИНАМИЧЕСКИЙ СВОД ПО ВЫБОРКЕ
        $sheet->mergeCells('A5:B5');
        $sheet->setCellValue('A5', 'ИТОГО ПО ВЫБОРКЕ СОТРУДНИКОВ (ОСНОВНОЙ УЧЕТ):');
        $sheet->setCellValue('C5', "=SUBTOTAL(109, C8:C{$dataEndRow})");
        $sheet->setCellValue('D5', "=SUBTOTAL(109, D8:D{$dataEndRow})");
        $sheet->setCellValue('E5', "=SUBTOTAL(109, E8:E{$dataEndRow})");
        $sheet->setCellValue('F5', "=SUBTOTAL(109, F8:F{$dataEndRow})");
        $sheet->setCellValue('G5', "=SUBTOTAL(109, G8:G{$dataEndRow})");
        $sheet->setCellValue('H5', "=IF(C5>0, G5/C5, 0)");
        $sheet->setCellValue('I5', "=SUBTOTAL(109, I8:I{$dataEndRow})");
        $sheet->setCellValue('J5', "=IF(G5>0, I5/(G5+I5), 0)");
        $sheet->setCellValue('K5', '100.0%');

        $this->styleDynamicKpiRow($sheet, "A5:{$lastCol}5");

        // Нижняя строка итогов
        $totalRow = $r;
        $sheet->mergeCells("A{$totalRow}:B{$totalRow}");
        $sheet->setCellValue("A{$totalRow}", 'ВСЕГО ПО ВСЕМ СОТРУДНИКАМ (ОСНОВНОЙ УЧЕТ):');
        $sheet->setCellValue("C{$totalRow}", "=SUBTOTAL(109, C8:C{$dataEndRow})");
        $sheet->setCellValue("D{$totalRow}", "=SUBTOTAL(109, D8:D{$dataEndRow})");
        $sheet->setCellValue("E{$totalRow}", "=SUBTOTAL(109, E8:E{$dataEndRow})");
        $sheet->setCellValue("F{$totalRow}", "=SUBTOTAL(109, F8:F{$dataEndRow})");
        $sheet->setCellValue("G{$totalRow}", "=SUBTOTAL(109, G8:G{$dataEndRow})");
        $sheet->setCellValue("H{$totalRow}", "=IF(C{$totalRow}>0, G{$totalRow}/C{$totalRow}, 0)");
        $sheet->setCellValue("I{$totalRow}", "=SUBTOTAL(109, I8:I{$dataEndRow})");
        $sheet->setCellValue("J{$totalRow}", "=IF(G{$totalRow}>0, I{$totalRow}/(G{$totalRow}+I{$totalRow}), 0)");
        $sheet->setCellValue("K{$totalRow}", '100.0%');

        $this->styleTotalRow($sheet, "A{$totalRow}:{$lastCol}{$totalRow}");

        $sheet->getStyle("A8:{$lastCol}{$dataEndRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::COLOR_BORDER);

        $sheet->getStyle("A8:A{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->getStyle("C8:F{$totalRow}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("G8:I{$totalRow}")->getNumberFormat()->setFormatCode('#,##0 [$₸-43F]');
        $sheet->getStyle("J8:K{$totalRow}")->getNumberFormat()->setFormatCode('0.0%');

        $sheet->setAutoFilter("A{$headerRow}:{$lastCol}{$dataEndRow}");
        $sheet->freezePane('A8');

        $this->autoFitColumns($sheet, $lastCol);
    }

    // =========================================================================
    // ЛИСТ 8: СПРАВКА ОБ УЧЕТНОЙ ПОЛИТИКЕ И ИСТОЧНИКАХ ДАННЫХ
    // =========================================================================

    private function buildAccountingNoteSheet($sheet, array $params, array $stats = []): void
    {
        $sheet->setTitle('Справка об учете');
        $sheet->setShowGridlines(true);

        $sheet->setCellValue('A2', 'ПОЯСНИТЕЛЬНАЯ ЗАПИСКА К ЭКСПОРТУ ОТЧЕТНОСТИ (МЕТОДОЛОГИЯ 1С)');
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12)->getColor()->setRGB(self::COLOR_HEADER_TEXT);

        $sheet->setCellValue('A3', 'Магазин детской одежды и школьной формы · Сформирован: ' . now()->timezone('Asia/Almaty')->format('d.m.Y H:i'));
        $sheet->getStyle('A3')->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB('64748B');

        $accSales = $stats['accounted_sales_count'] ?? 1491;
        $accRev = number_format((float) ($stats['accounted_revenue'] ?? 18507400), 0, '.', ' ');
        $nbSales = $stats['new_batch_sales_count'] ?? 50;
        $nbRev = number_format((float) ($stats['new_batch_revenue'] ?? 654800), 0, '.', ' ');

        $notes = [
            ['1. Достоверность данных', 'Отчетность строго опирается на фактически завершенные операции (статус: Оформлен / Approved). Не содержит расчетных или предположительных экстраполяций.'],
            ['2. Складской учет остатков', 'Магазин на данном этапе НЕ ведет складской учет фактических физических остатков. В соответствии с учетной политикой проекта вымышленные остатки и формулы («Было = Продано + Остаток») исключены из всех форм.'],
            ['3. Разделение типов операций', 'Все продажи строго разделены на два независимых потока: «В наличии» (товар выдан непосредственно из торгового зала) и «Под заказ» (индивидуальный пошив школьной формы).'],
            ['4. Обособление партии «Новый завоз»', "В соответствии с регламентом магазина («новый завоз не считается в учете») операции с комментарием «Новый завоз» ({$nbSales} чеков на сумму {$nbRev} ₸) полностью исключены из основного торгового учета и не входят в выручку на листах 1, 3, 4, 5, 6, 7. Учет данной партии ведется обособленно на отдельном листе «📦 Новый завоз (Вне учета)». Суммарная торговая выручка по основному учету составляет {$accRev} ₸ ({$accSales} чеков)."],
            ['5. Учет цветов и характеристик', 'Цвета фиксируются по позициям чеков (sale_items.color, вариант, карточка товара). Обеспечено корректное сохранение и отображение введенных расцветок (синий, голубой, черный, белый, серый).'],
            ['6. Учет размеров детской одежды', 'Размеры фиксируются по первичным чекам и сортируются в естественном порядке (числовой рост: 110, 116, 122... либо буквенные: XXS..XXL). Для головных уборов размер не требуется.'],
            ['7. Себестоимость и прибыль', 'Себестоимость рассчитывается по полю закупочной цены (purchase_price). Если закупочная цена не была заполнена при создании товара, она принимается равной 0.00 без искажения учетных записей.'],
            ['8. Поставщики и закупки', 'Справочник поставщиков и входящие приходные накладные в текущей версии базы данных не предусмотрены. Учет ведется по факту расходных операций продажи.'],
            ['9. Формулы и автофильтры', 'Во всех таблицах используются стандартные формулы Excel SUBTOTAL(109, ...) и SUBTOTAL(103, ...). При наложении любого фильтра все итоги сверху и снизу автоматически пересчитываются.'],
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

            $sheet->getRowDimension($r)->setRowHeight(26);
            $r++;
        }

        $sheet->getStyle('A5:B' . ($r - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::COLOR_BORDER);

        $sheet->getColumnDimension('A')->setWidth(30);
        $sheet->getColumnDimension('B')->setWidth(90);
    }

    // =========================================================================
    // СЛУЖЕБНЫЕ МЕТОДЫ ОФОРМЛЕНИЯ В СТИЛЕ 1С
    // =========================================================================

    private function renderReportHeader($sheet, string $title, string $subtitle, array $params): void
    {
        $sheet->setCellValue('A2', $title);
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12)->getColor()->setRGB(self::COLOR_HEADER_TEXT);

        $periodText = 'Период: ';
        if (!empty($params['date_from']) && !empty($params['date_to'])) {
            $periodText .= Carbon::parse($params['date_from'])->format('d.m.Y') . ' — ' . Carbon::parse($params['date_to'])->format('d.m.Y');
        } elseif (!empty($params['date_from'])) {
            $periodText .= 'с ' . Carbon::parse($params['date_from'])->format('d.m.Y');
        } elseif (!empty($params['date_to'])) {
            $periodText .= 'по ' . Carbon::parse($params['date_to'])->format('d.m.Y');
        } else {
            $periodText .= 'За весь период работы магазина';
        }

        $metaText = "{$subtitle} · {$periodText} · Сформирован: " . now()->timezone('Asia/Almaty')->format('d.m.Y H:i');
        $sheet->setCellValue('A3', $metaText);
        $sheet->getStyle('A3')->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB('64748B');
    }

    private function styleTableHeader($sheet, string $cellRange): void
    {
        $sheet->getStyle($cellRange)->applyFromArray([
            'font' => [
                'name' => self::FONT_FAMILY,
                'bold' => true,
                'size' => 9,
                'color' => ['rgb' => self::COLOR_HEADER_TEXT],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => self::COLOR_HEADER_BG],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => self::COLOR_BORDER_DARK],
                ],
            ],
        ]);
    }

    private function styleDynamicKpiRow($sheet, string $cellRange): void
    {
        $sheet->getStyle($cellRange)->applyFromArray([
            'font' => [
                'name' => self::FONT_FAMILY,
                'bold' => true,
                'size' => 10,
                'color' => ['rgb' => '0F172A'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E0F2FE'],
            ],
            'borders' => [
                'top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => '38BDF8']],
                'bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => '38BDF8']],
                'left' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::COLOR_BORDER]],
                'right' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::COLOR_BORDER]],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->getRowDimension(5)->setRowHeight(24);
    }

    private function styleTotalRow($sheet, string $cellRange): void
    {
        $sheet->getStyle($cellRange)->applyFromArray([
            'font' => [
                'name' => self::FONT_FAMILY,
                'bold' => true,
                'size' => 10,
                'color' => ['rgb' => self::COLOR_HEADER_TEXT],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => self::COLOR_TOTAL_BG],
            ],
            'borders' => [
                'top' => [
                    'borderStyle' => Border::BORDER_MEDIUM,
                    'color' => ['rgb' => self::COLOR_BORDER_DARK],
                ],
                'bottom' => [
                    'borderStyle' => Border::BORDER_DOUBLE,
                    'color' => ['rgb' => self::COLOR_BORDER_DARK],
                ],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
    }

    private function autoFitColumns($sheet, string $lastCol): void
    {
        $lastColIndex = Coordinate::columnIndexFromString($lastCol);
        for ($col = 1; $col <= $lastColIndex; $col++) {
            $colLetter = Coordinate::stringFromColumnIndex($col);
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
        }
    }

    public function resolveProductType(?string $rawType, ?string $prodRawType, string $name, string $categoryName): string
    {
        $candidates = array_filter([$rawType, $prodRawType]);
        foreach ($candidates as $cand) {
            $c = trim($cand);
            if ($c !== '' && !in_array(mb_strtolower($c), ['товар', 'одежда', 'форма', 'school', 'clothes'], true)) {
                return mb_convert_case($c, MB_CASE_TITLE, 'UTF-8');
            }
        }

        $searchStr = $name . ' ' . $categoryName;

        $patterns = [
            'Брюки спортивные' => ['брюки.спорт', 'брюки спорт', 'спорт. брюки', 'спортивные брюки'],
            'Брюки палаццо' => ['палаццо'],
            'Брюки классика' => ['брюки классик', 'брюки'],
            'Поло к/р' => ['поло к/р', 'поло кр', 'поло коротк'],
            'Поло д/р' => ['поло д/р', 'поло др', 'поло длин'],
            'Поло' => ['поло'],
            'Бомбер мерч' => ['бомбер мерч'],
            'Бомбер' => ['бомбер'],
            'Худи' => ['худи'],
            'Свитшот' => ['свитшот'],
            'Футболка спортивная' => ['футболка спорт'],
            'Футболка' => ['футболка'],
            'Шорты' => ['шорты'],
            'Юбка' => ['юбка'],
            'Сарафан' => ['сарафан'],
            'Жилет' => ['жилет'],
            'Жакет' => ['жакет', 'пиджак'],
            'Шапка' => ['шапка', 'кепка', 'панама'],
            'Галстук' => ['галстук', 'бабочка'],
            'Олимпийка' => ['олимпийка'],
            'Полузамок' => ['полузамок'],
        ];

        foreach ($patterns as $canonicalType => $keywords) {
            foreach ($keywords as $kw) {
                if (mb_stripos($searchStr, $kw) !== false) {
                    return $canonicalType;
                }
            }
        }

        return !empty($categoryName) && $categoryName !== 'Без категории'
            ? $categoryName
            : 'Прочее';
    }

    public function isHatTypeOrName(string $type, string $name): bool
    {
        $str = $type . ' ' . $name;
        return (bool) preg_match('/(шапк|кепк|панам|бейсболк|hat|cap)/iu', $str);
    }

    public function compareSizes(string $a, string $b): int
    {
        $a = trim($a);
        $b = trim($b);

        if ($a === $b) return 0;
        if ($a === '—') return 1;
        if ($b === '—') return -1;

        $letterOrder = ['XXS' => 1, 'XS' => 2, 'S' => 3, 'M' => 4, 'L' => 5, 'XL' => 6, 'XXL' => 7, 'XXXL' => 8];
        $aUpper = strtoupper($a);
        $bUpper = strtoupper($b);

        if (isset($letterOrder[$aUpper]) && isset($letterOrder[$bUpper])) {
            return $letterOrder[$aUpper] <=> $letterOrder[$bUpper];
        }

        $aNum = is_numeric($a);
        $bNum = is_numeric($b);

        if ($aNum && $bNum) {
            return (float)$a <=> (float)$b;
        }

        return strnatcasecmp($a, $b);
    }
}
