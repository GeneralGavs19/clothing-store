<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    protected ?string $token;
    protected ?string $adminChatId;

    public function __construct()
    {
        $this->token = config('services.telegram.bot_token') ?: env('TELEGRAM_BOT_TOKEN');
        $this->adminChatId = config('services.telegram.admin_chat_id') ?: env('TELEGRAM_ADMIN_CHAT_ID');
    }

    /**
     * Проверяет, настроена ли интеграция с Telegram.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->token) && ! empty($this->adminChatId);
    }

    /**
     * Клавиатура главного меню Telegram (Reply Keyboard, прикрепленная к экрану).
     */
    public function getMainMenuKeyboard(): array
    {
        return [
            'keyboard' => [
                [
                    ['text' => '📊 Касса сегодня'],
                    ['text' => '🧵 Заказы на пошив'],
                ],
                [
                    ['text' => '🟢 Здоровье сайта'],
                    ['text' => '📈 Касса вчера'],
                ],
                [
                    ['text' => '💾 Скачать бэкап'],
                    ['text' => '❓ Справка'],
                ],
            ],
            'resize_keyboard' => true,
            'is_persistent' => true,
        ];
    }

    /**
     * Отправка текстового сообщения.
     */
    public function sendMessage(string $text, ?string $chatId = null, string $parseMode = 'HTML', ?array $replyMarkup = null): bool
    {
        if (! $this->isConfigured()) {
            Log::info('Telegram notification skipped: Bot credentials not configured.');
            return false;
        }

        $targetChat = $chatId ?: $this->adminChatId;

        $payload = [
            'chat_id' => $targetChat,
            'text' => $text,
            'parse_mode' => $parseMode,
            'disable_web_page_preview' => true,
        ];

        if ($replyMarkup) {
            $payload['reply_markup'] = $replyMarkup;
        }

        try {
            $response = Http::timeout(10)->post("https://api.telegram.org/bot{$this->token}/sendMessage", $payload);

            if (! $response->successful()) {
                Log::warning('Telegram sendMessage failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Telegram sendMessage exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Отправка документа/файла (для бэкапов базы данных).
     */
    public function sendDocument(string $filePath, ?string $caption = null, ?string $chatId = null): bool
    {
        if (! $this->isConfigured() || ! file_exists($filePath)) {
            return false;
        }

        $targetChat = $chatId ?: $this->adminChatId;

        try {
            $response = Http::timeout(60)
                ->attach('document', file_get_contents($filePath), basename($filePath))
                ->post("https://api.telegram.org/bot{$this->token}/sendDocument", array_filter([
                    'chat_id' => $targetChat,
                    'caption' => $caption,
                    'parse_mode' => 'HTML',
                ]));

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error('Telegram sendDocument exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Получение обновлений через Long Polling.
     */
    public function getUpdates(?int $offset = null, int $timeout = 25): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $params = ['timeout' => $timeout];
        if ($offset !== null) {
            $params['offset'] = $offset;
        }

        try {
            $response = Http::timeout($timeout + 5)->get("https://api.telegram.org/bot{$this->token}/getUpdates", $params);
            if ($response->successful()) {
                $data = $response->json();
                return $data['result'] ?? [];
            }
        } catch (\Throwable $e) {
            Log::warning('Telegram getUpdates failed: ' . $e->getMessage());
        }

        return [];
    }

    /**
     * Обработка входящего сообщения или нажатия кнопки от пользователя в Telegram.
     */
    public function handleIncomingUpdate(array $update): bool
    {
        $message = $update['message'] ?? $update['callback_query']['message'] ?? null;
        if (! $message) {
            return false;
        }

        $chatId = (string) ($message['chat']['id'] ?? '');
        $text = trim((string) ($update['message']['text'] ?? $update['callback_query']['data'] ?? ''));

        // Авторизация: бот отвечает ТОЛЬКО владельцу (adminChatId)
        if ($chatId !== (string) $this->adminChatId) {
            $this->sendMessage("⛔️ <b>Доступ запрещен.</b>\nЭтот бот настроен исключительно для владельца магазина.", $chatId);
            return false;
        }

        $menu = $this->getMainMenuKeyboard();
        $norm = mb_strtolower($text);

        if ($norm === '/start' || $norm === '❓ справка' || $norm === 'справка' || $norm === 'меню' || $norm === '/help') {
            $welcome = "👋 <b>Панель управления магазином детской одежды и школьной формы!</b>\n\n"
                . "Я ваш персональный Telegram-помощник для системы <b>Durability Store</b>.\n\n"
                . "<b>Доступные функции:</b>\n"
                . "📊 <b>Касса сегодня</b> — срез продаж, выручки и среднего чека прямо сейчас.\n"
                . "🧵 <b>Заказы на пошив</b> — контроль статусов и дедлайнов предзаказов формы.\n"
                . "🟢 <b>Здоровье сайта</b> — онлайн-диагностика сервера, базы данных SQLite и диска.\n"
                . "📈 <b>Касса вчера</b> — итоги прошлого дня.\n"
                . "💾 <b>Скачать бэкап</b> — мгновенная выгрузка базы данных файлом сюда в чат.\n\n"
                . "<i>Нажимайте кнопки внизу экрана для быстрого ответа:</i>";

            return $this->sendMessage($welcome, $chatId, 'HTML', $menu);
        }

        if ($norm === '🟢 здоровье сайта' || $norm === '/status' || $norm === '/health' || $norm === 'здоровье') {
            $report = $this->getHealthReport();
            return $this->sendMessage($report, $chatId, 'HTML', $menu);
        }

        if ($norm === '📊 касса сегодня' || $norm === '/today' || $norm === 'касса' || $norm === 'сегодня') {
            $report = $this->buildDailyReportText(now()->toDateString());
            return $this->sendMessage($report, $chatId, 'HTML', $menu);
        }

        if ($norm === '📈 касса вчера' || $norm === '/yesterday' || $norm === 'вчера') {
            $report = $this->buildDailyReportText(now()->subDay()->toDateString());
            return $this->sendMessage($report, $chatId, 'HTML', $menu);
        }

        if ($norm === '🧵 заказы на пошив' || $norm === '/orders' || $norm === 'заказы' || $norm === 'пошив') {
            $report = $this->getOrdersReportText();
            return $this->sendMessage($report, $chatId, 'HTML', $menu);
        }

        if ($norm === '💾 скачать бэкап' || $norm === '/backup' || $norm === 'бэкап') {
            $this->sendMessage("⏳ Формирую резервную копию базы данных SQLite...", $chatId);
            $success = $this->sendDatabaseBackup($chatId);
            if (! $success) {
                $this->sendMessage("❌ Не удалось отправить файл бэкапа.", $chatId, 'HTML', $menu);
            }
            return $success;
        }

        // Нераспознанная команда
        $reply = "❓ Команда <i>«" . htmlspecialchars($text) . "»</i> не распознана.\n\n"
            . "Используйте удобные кнопки меню ниже:";
        return $this->sendMessage($reply, $chatId, 'HTML', $menu);
    }

    /**
     * Формирование отчета о работоспособности (Health & Diagnostic Report).
     */
    public function getHealthReport(): string
    {
        $startTime = microtime(true);
        $dbStatus = 'OK';

        try {
            DB::connection()->getPdo();
            $latency = round((microtime(true) - $startTime) * 1000, 1);
        } catch (\Throwable $e) {
            $dbStatus = 'СБОЙ: ' . $e->getMessage();
            $latency = 0;
        }

        $dbPath = database_path('database.sqlite');
        $dbSizeStr = '—';
        if (file_exists($dbPath)) {
            $bytes = filesize($dbPath);
            $mb = round($bytes / (1024 * 1024), 2);
            $dbSizeStr = "{$mb} МБ";
        }

        $totalSales = Sale::where('status', 'approved')->count();
        $totalProducts = Product::count();
        $totalUsers = User::where('is_active', true)->count();

        $lastSale = Sale::latest('created_at')->first();
        $lastSaleTime = $lastSale ? ($lastSale->created_at?->format('d.m.Y H:i') ?? '—') : '—';
        $lastSaleSum = $lastSale ? number_format((float) $lastSale->subtotal, 0, '.', ' ') . ' ₸' : '—';
        $lastSaleNum = $lastSale ? '№' . $lastSale->id : '—';

        // Свободное место на диске
        $freeSpace = @disk_free_space('.');
        $freeDiskStr = $freeSpace ? round($freeSpace / (1024 * 1024 * 1024), 1) . ' ГБ' : '—';

        // Использование памяти PHP
        $memoryMb = round(memory_get_usage(true) / (1024 * 1024), 1) . ' МБ';

        $now = now()->format('d.m.Y H:i:s');
        $statusHeader = $dbStatus === 'OK'
            ? '🟢 <b>ВСЕ СИСТЕМЫ САЙТА РАБОТАЮТ ШТАТНО</b>'
            : '🔴 <b>ВНИМАНИЕ: ОШИБКА БАЗЫ ДАННЫХ</b>';

        return "{$statusHeader}\n\n"
            . "⏱ <b>Время проверки:</b> {$now}\n"
            . "⚡️ <b>Отклик базы данных:</b> {$latency} мс\n\n"
            . "🗄 <b>База данных (SQLite):</b>\n"
            . " • Состояние: <b>{$dbStatus}</b>\n"
            . " • Размер файла базы: <b>{$dbSizeStr}</b>\n"
            . " • Всего оформленных чеков: <b>{$totalSales}</b>\n"
            . " • Моделей в каталоге: <b>{$totalProducts}</b>\n"
            . " • Активных сотрудников: <b>{$totalUsers}</b>\n\n"
            . "🧾 <b>Последняя операция:</b>\n"
            . " • Чек {$lastSaleNum} на сумму <b>{$lastSaleSum}</b> ({$lastSaleTime})\n\n"
            . "💻 <b>Сервер и ресурсы:</b>\n"
            . " • Свободно на диске: <b>{$freeDiskStr}</b>\n"
            . " • Выделенная память PHP: <b>{$memoryMb}</b>\n"
            . " • Окружение: <b>PHP " . PHP_VERSION . " / Laravel 11</b>\n"
            . " • Мониторинг Telegram: <b>Активен ✅</b>";
    }

    /**
     * Сводка по заказам на пошив формы (дедлайны, просрочки, статусы).
     */
    public function getOrdersReportText(): string
    {
        $today = Carbon::today();

        $activeOrders = Sale::query()
            ->with(['items.product', 'cashier'])
            ->where('status', 'approved')
            ->where('is_on_order', true)
            ->where('fulfillment_status', '!=', 'delivered')
            ->orderBy('due_date')
            ->get();

        $totalCount = $activeOrders->count();
        $overdueCount = 0;
        $dueTodayCount = 0;
        $inProgressCount = 0;
        $readyCount = 0;
        $newCount = 0;

        foreach ($activeOrders as $o) {
            $st = $o->fulfillment_status ?: 'new';
            if ($st === 'in_progress') {
                $inProgressCount++;
            } elseif ($st === 'ready') {
                $readyCount++;
            } else {
                $newCount++;
            }

            if ($o->due_date) {
                $dueDate = Carbon::parse($o->due_date)->startOfDay();
                if ($dueDate->lt($today)) {
                    $overdueCount++;
                } elseif ($dueDate->equalTo($today)) {
                    $dueTodayCount++;
                }
            }
        }

        $ordersLines = [];
        foreach ($activeOrders->take(6) as $order) {
            $statusLabel = match ($order->fulfillment_status) {
                'in_progress' => '✂️ В пошиве',
                'ready' => '✅ Готов к выдаче',
                default => '⏳ Новый',
            };
            $dueStr = $order->due_date ? Carbon::parse($order->due_date)->format('d.m.Y') : 'Без срока';
            $isOverdue = $order->due_date && Carbon::parse($order->due_date)->startOfDay()->lt($today);
            $dueBadge = $isOverdue ? "⚠️ <b>ПРОСРОЧЕН ({$dueStr})</b>" : "до {$dueStr}";
            $totalFormatted = number_format((float) $order->subtotal, 0, '.', ' ');

            $itemsSummary = $order->items->map(function ($it) {
                $sz = $it->variant_size ? " ({$it->variant_size})" : '';
                return ($it->product_name ?: 'Товар') . $sz;
            })->slice(0, 2)->implode(', ');

            $ordersLines[] = "• <b>Чек №{$order->id}</b> на {$totalFormatted} ₸ [{$statusLabel}]\n"
                . "  Срок: {$dueBadge} | Кассир: " . ($order->cashier?->name ?? '—') . "\n"
                . "  <i>{$itemsSummary}</i>";
        }

        $listText = ! empty($ordersLines)
            ? implode("\n\n", $ordersLines)
            : "<i>Активных заказов нет — все выданы покупателям! 🎉</i>";

        $alertHeader = '';
        if ($overdueCount > 0) {
            $alertHeader = "🚨 <b>ВНИМАНИЕ: ЕСТЬ ПРОСРОЧЕННЫЕ ЗАКАЗЫ ({$overdueCount} шт.)!</b>\n\n";
        } elseif ($dueTodayCount > 0) {
            $alertHeader = "⏳ <b>ЕСТЬ ЗАКАЗЫ НА ВЫДАЧУ СЕГОДНЯ ({$dueTodayCount} шт.)!</b>\n\n";
        }

        return "{$alertHeader}🧵 <b>ЗАКАЗЫ ПОД ПОШИВ ШКОЛЬНОЙ ФОРМЫ</b>\n\n"
            . "📦 <b>Всего в работе:</b> <b>{$totalCount} шт.</b>\n"
            . " • ⏳ Новые: <b>{$newCount}</b>\n"
            . " • ✂️ В процессе пошива: <b>{$inProgressCount}</b>\n"
            . " • ✅ Готовы к выдаче: <b>{$readyCount}</b>\n"
            . " • ⚠️ Просрочено: <b>{$overdueCount}</b>\n"
            . " • 📅 Дедлайн сегодня: <b>{$dueTodayCount}</b>\n\n"
            . "<b>Ближайшие заказы:</b>\n\n{$listText}";
    }

    /**
     * Построение текста ежедневной сводки за указанную дату.
     */
    public function buildDailyReportText(?string $targetDate = null): string
    {
        $date = $targetDate ? Carbon::parse($targetDate) : Carbon::today();
        $dateStr = $date->format('d.m.Y');

        $sales = Sale::query()
            ->with(['items.product', 'cashier'])
            ->where('status', 'approved')
            ->whereDate('approved_at', $date)
            ->get();

        $totalSalesCount = $sales->count();
        $totalRevenue = (float) $sales->sum('subtotal');
        $averageCheck = $totalSalesCount > 0 ? round($totalRevenue / $totalSalesCount) : 0;

        $inStockUnits = 0;
        $onOrderUnits = 0;
        $inStockRevenue = 0.0;
        $onOrderRevenue = 0.0;
        $cashiersMap = [];
        $productsMap = [];

        foreach ($sales as $sale) {
            $cName = $sale->cashier?->name ?? 'Касса';
            if (! isset($cashiersMap[$cName])) {
                $cashiersMap[$cName] = ['sales' => 0, 'revenue' => 0.0];
            }
            $cashiersMap[$cName]['sales']++;
            $cashiersMap[$cName]['revenue'] += (float) $sale->subtotal;

            foreach ($sale->items as $it) {
                $isOnOrder = $it->is_on_order !== null ? (bool) $it->is_on_order : (bool) $sale->is_on_order;
                $qty = (int) $it->quantity;
                $tot = (float) $it->line_total;
                $pName = $it->product_name ?: $it->product?->name ?: 'Товар';

                if ($isOnOrder) {
                    $onOrderUnits += $qty;
                    $onOrderRevenue += $tot;
                } else {
                    $inStockUnits += $qty;
                    $inStockRevenue += $tot;
                }

                if (! isset($productsMap[$pName])) {
                    $productsMap[$pName] = 0;
                }
                $productsMap[$pName] += $qty;
            }
        }

        $totalUnits = $inStockUnits + $onOrderUnits;
        $formattedRevenue = number_format($totalRevenue, 0, '.', ' ');
        $formattedAvgCheck = number_format($averageCheck, 0, '.', ' ');
        $formattedInStockRev = number_format($inStockRevenue, 0, '.', ' ');
        $formattedOnOrderRev = number_format($onOrderRevenue, 0, '.', ' ');

        arsort($productsMap);
        $topProductsLines = [];
        $rank = 1;
        foreach (array_slice($productsMap, 0, 3, true) as $pName => $qty) {
            $topProductsLines[] = "  {$rank}. <i>{$pName}</i> — <b>{$qty} шт.</b>";
            $rank++;
        }
        $topText = ! empty($topProductsLines) ? implode("\n", $topProductsLines) : '  <i>Нет продаж</i>';

        $cashiersLines = [];
        foreach ($cashiersMap as $cName => $stats) {
            $cRev = number_format($stats['revenue'], 0, '.', ' ');
            $cashiersLines[] = "  • <b>{$cName}:</b> {$stats['sales']} чек., {$cRev} ₸";
        }
        $cashiersText = ! empty($cashiersLines) ? implode("\n", $cashiersLines) : '  <i>Нет данных</i>';

        return "📊 <b>ИТОГИ СМЕНЫ ЗА {$dateStr}</b>\n\n"
            . "💵 <b>Выручка:</b> <b>{$formattedRevenue} ₸</b>\n"
            . "🧾 <b>Оформлено чеков:</b> <b>{$totalSalesCount}</b>\n"
            . "🎯 <b>Средний чек:</b> {$formattedAvgCheck} ₸\n"
            . "🛍 <b>Всего продано:</b> {$totalUnits} шт.\n\n"
            . "<b>Разделение по типу:</b>\n"
            . "  ✓ <b>В наличии:</b> {$inStockUnits} шт. ({$formattedInStockRev} ₸)\n"
            . "  📦 <b>Под заказ (пошив):</b> {$onOrderUnits} шт. ({$formattedOnOrderRev} ₸)\n\n"
            . "<b>Сотрудники / Кассиры:</b>\n{$cashiersText}\n\n"
            . "<b>🏆 Топ продаваемых моделей:</b>\n{$topText}";
    }

    /**
     * Отправка ежедневного вечернего отчета.
     */
    public function sendDailyReport(?string $targetDate = null): bool
    {
        $message = $this->buildDailyReportText($targetDate);
        return $this->sendMessage($message, null, 'HTML', $this->getMainMenuKeyboard());
    }

    /**
     * Отправка бэкапа базы данных SQLite файлом в Telegram.
     */
    public function sendDatabaseBackup(?string $chatId = null): bool
    {
        $dbPath = database_path('database.sqlite');
        if (! file_exists($dbPath)) {
            Log::warning('Telegram backup: database.sqlite not found');
            return false;
        }

        $now = now();
        $dateStr = $now->format('d.m.Y H:i');
        $fileName = 'backup-store-' . $now->format('Y-m-d-His') . '.sqlite';
        $tempPath = storage_path('app/' . $fileName);

        copy($dbPath, $tempPath);

        $sizeKb = round(filesize($tempPath) / 1024, 1);
        $sizeMb = round($sizeKb / 1024, 2);
        $sizeStr = $sizeMb >= 1.0 ? "{$sizeMb} МБ" : "{$sizeKb} КБ";

        $salesCount = Sale::where('status', 'approved')->count();

        $caption = "💾 <b>Резервная копия базы данных магазина</b>\n\n"
            . "📅 <b>Дата создания:</b> {$dateStr}\n"
            . "📦 <b>Размер:</b> {$sizeStr}\n"
            . "🧾 <b>Всего чеков в базе:</b> {$salesCount}\n\n"
            . "<i>Файл базы данных SQLite прикреплен выше. Сохраните его для надежности.</i>";

        $result = $this->sendDocument($tempPath, $caption, $chatId);

        if (file_exists($tempPath)) {
            @unlink($tempPath);
        }

        return $result;
    }

    /**
     * Уведомление об отмене продажи (чека).
     */
    public function notifySaleCancelled(Sale $sale, ?string $reason = null, ?User $actor = null): bool
    {
        $sale->loadMissing(['items.product', 'cashier']);

        $totalFormatted = number_format((float) ($sale->subtotal ?? 0), 0, '.', ' ');
        $saleNum = '№' . $sale->id;
        $cashierName = $sale->cashier?->name ?? 'Не указан';
        $actorName = $actor?->name ?? 'Система';
        $reasonText = $reason ? htmlspecialchars($reason) : 'Причина не указана';
        $saleDate = ($sale->approved_at ?? $sale->created_at)?->format('d.m.Y H:i') ?? '—';
        $now = now()->format('d.m.Y H:i');

        $itemsLines = [];
        foreach ($sale->items as $it) {
            $pName = $it->product_name ?: $it->product?->name ?: 'Товар';
            $size = $it->variant_size ? " ({$it->variant_size})" : '';
            $qty = (int) $it->quantity;
            $tot = number_format((float) $it->line_total, 0, '.', ' ');
            $itemsLines[] = " • <i>{$pName}{$size}</i> — {$qty} шт. ({$tot} ₸)";
        }
        $itemsText = ! empty($itemsLines) ? implode("\n", array_slice($itemsLines, 0, 5)) : ' • <i>Товары не указаны</i>';
        if (count($itemsLines) > 5) {
            $itemsText .= "\n • <i>...и ещё " . (count($itemsLines) - 5) . " поз.</i>";
        }

        $message = "⚠️ <b>ОТМЕНА ПРОДАЖИ {$saleNum}</b>\n\n"
            . "💰 <b>Сумма чека:</b> {$totalFormatted} ₸\n"
            . "👤 <b>Кассир чека:</b> {$cashierName}\n"
            . "🛑 <b>Кто отменил:</b> {$actorName}\n"
            . "📝 <b>Причина:</b> {$reasonText}\n"
            . "📅 <b>Дата продажи:</b> {$saleDate}\n"
            . "⏰ <b>Время отмены:</b> {$now}\n\n"
            . "📦 <b>Состав отмененного чека:</b>\n{$itemsText}";

        return $this->sendMessage($message, null, 'HTML', $this->getMainMenuKeyboard());
    }

    /**
     * Уведомление о новом предзаказе под пошив формы.
     */
    public function notifyNewPreOrder(Sale $sale): bool
    {
        $sale->loadMissing(['items.product', 'cashier']);

        $totalFormatted = number_format((float) ($sale->subtotal ?? 0), 0, '.', ' ');
        $saleNum = '№' . $sale->id;
        $cashierName = $sale->cashier?->name ?? 'Не указан';
        $dueDate = $sale->due_date ? Carbon::parse($sale->due_date)->format('d.m.Y') : 'Не назначен';
        $note = $sale->cashier_note ? htmlspecialchars($sale->cashier_note) : '—';

        $itemsLines = [];
        foreach ($sale->items as $it) {
            $pName = $it->product_name ?: $it->product?->name ?: 'Товар';
            $size = $it->variant_size ? " ({$it->variant_size})" : '';
            $qty = (int) $it->quantity;
            $tot = number_format((float) $it->line_total, 0, '.', ' ');
            $itemsLines[] = " • <b>{$pName}{$size}</b> — {$qty} шт. ({$tot} ₸)";
        }
        $itemsText = ! empty($itemsLines) ? implode("\n", array_slice($itemsLines, 0, 5)) : ' • <i>Товары не указаны</i>';

        $message = "🧵 <b>НОВЫЙ ЗАКАЗ ПОД ПОШИВ {$saleNum}</b>\n\n"
            . "💰 <b>Сумма заказа:</b> {$totalFormatted} ₸\n"
            . "📅 <b>Срок сдачи:</b> <b>{$dueDate}</b>\n"
            . "👤 <b>Кассир:</b> {$cashierName}\n"
            . "📝 <b>Примечание:</b> {$note}\n\n"
            . "👗 <b>Позиции:</b>\n{$itemsText}";

        return $this->sendMessage($message, null, 'HTML', $this->getMainMenuKeyboard());
    }
}
