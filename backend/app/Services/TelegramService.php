<?php

namespace App\Services;

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
     * Отправка текстового сообщения.
     */
    public function sendMessage(string $text, ?string $chatId = null, string $parseMode = 'HTML'): bool
    {
        if (! $this->isConfigured()) {
            Log::info('Telegram notification skipped: Bot credentials not configured.');
            return false;
        }

        $targetChat = $chatId ?: $this->adminChatId;

        try {
            $response = Http::timeout(10)->post("https://api.telegram.org/bot{$this->token}/sendMessage", [
                'chat_id' => $targetChat,
                'text' => $text,
                'parse_mode' => $parseMode,
                'disable_web_page_preview' => true,
            ]);

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

        return $this->sendMessage($message);
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

        return $this->sendMessage($message);
    }

    /**
     * Отправка ежедневного вечернего отчета по смене / кассе.
     */
    public function sendDailyReport(?string $targetDate = null): bool
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

        // Топ-3 товара дня
        arsort($productsMap);
        $topProductsLines = [];
        $rank = 1;
        foreach (array_slice($productsMap, 0, 3, true) as $pName => $qty) {
            $topProductsLines[] = "  {$rank}. <i>{$pName}</i> — <b>{$qty} шт.</b>";
            $rank++;
        }
        $topText = ! empty($topProductsLines) ? implode("\n", $topProductsLines) : '  <i>Нет продаж за день</i>';

        // Продажи по кассирам
        $cashiersLines = [];
        foreach ($cashiersMap as $cName => $stats) {
            $cRev = number_format($stats['revenue'], 0, '.', ' ');
            $cashiersLines[] = "  • <b>{$cName}:</b> {$stats['sales']} чек., {$cRev} ₸";
        }
        $cashiersText = ! empty($cashiersLines) ? implode("\n", $cashiersLines) : '  <i>Нет данных</i>';

        $message = "📊 <b>ИТОГИ СМЕНЫ ЗА {$dateStr}</b>\n\n"
            . "💵 <b>Выручка:</b> <b>{$formattedRevenue} ₸</b>\n"
            . "🧾 <b>Оформлено чеков:</b> <b>{$totalSalesCount}</b>\n"
            . "🎯 <b>Средний чек:</b> {$formattedAvgCheck} ₸\n"
            . "🛍 <b>Всего продано:</b> {$totalUnits} шт.\n\n"
            . "<b>Разделение по типу:</b>\n"
            . "  ✓ <b>В наличии:</b> {$inStockUnits} шт. ({$formattedInStockRev} ₸)\n"
            . "  📦 <b>Под заказ (пошив):</b> {$onOrderUnits} шт. ({$formattedOnOrderRev} ₸)\n\n"
            . "<b>Сотрудники / Кассиры:</b>\n{$cashiersText}\n\n"
            . "<b>🏆 Топ продаваемых моделей:</b>\n{$topText}";

        return $this->sendMessage($message);
    }

    /**
     * Отправка бэкапа базы данных SQLite файлом в Telegram.
     */
    public function sendDatabaseBackup(): bool
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

        // Создаем локальную копию для безопасной передачи без блокировки SQLite
        copy($dbPath, $tempPath);

        $sizeKb = round(filesize($tempPath) / 1024, 1);
        $sizeMb = round($sizeKb / 1024, 2);
        $sizeStr = $sizeMb >= 1.0 ? "{$sizeMb} МБ" : "{$sizeKb} КБ";

        $salesCount = Sale::where('status', 'approved')->count();

        $caption = "💾 <b>Автоматический бэкап базы данных магазина</b>\n\n"
            . "📅 <b>Дата создания:</b> {$dateStr}\n"
            . "📦 <b>Размер:</b> {$sizeStr}\n"
            . "🧾 <b>Всего чеков в базе:</b> {$salesCount}\n\n"
            . "<i>Файл базы данных SQLite прикреплен выше. Сохраните его для надежности.</i>";

        $result = $this->sendDocument($tempPath, $caption);

        if (file_exists($tempPath)) {
            @unlink($tempPath);
        }

        return $result;
    }
}
