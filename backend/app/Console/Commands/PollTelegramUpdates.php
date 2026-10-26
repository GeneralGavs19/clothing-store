<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class PollTelegramUpdates extends Command
{
    protected $signature = 'telegram:poll {--once : Выполнить один цикл проверки}';
    protected $description = 'Запуск слушателя входящих команд и кнопок Telegram бота (Long Polling)';

    public function handle(TelegramService $telegram): int
    {
        $this->info('Слушатель Telegram бота запущен. Ожидание сообщений от владельца...');

        if (! $telegram->isConfigured()) {
            $this->error('Telegram бот не настроен! Проверьте TELEGRAM_BOT_TOKEN и TELEGRAM_ADMIN_CHAT_ID в .env.');
            return self::FAILURE;
        }

        $offset = Cache::get('telegram_last_update_offset', null);
        $runOnce = (bool) $this->option('once');

        do {
            try {
                $updates = $telegram->getUpdates($offset, $runOnce ? 2 : 20);

                foreach ($updates as $upd) {
                    $updateId = $upd['update_id'] ?? null;
                    if ($updateId !== null) {
                        $offset = $updateId + 1;
                        Cache::put('telegram_last_update_offset', $offset, now()->addDays(7));
                    }

                    $from = $upd['message']['from']['first_name'] ?? 'Пользователь';
                    $text = $upd['message']['text'] ?? $upd['callback_query']['data'] ?? '[файл/действие]';
                    $this->line("[" . now()->format('H:i:s') . "] Сообщение от {$from}: {$text}");

                    $telegram->handleIncomingUpdate($upd);
                }
            } catch (\Throwable $e) {
                $this->warn('Ошибка при опросе Telegram: ' . $e->getMessage());
                sleep(2);
            }

            if (! $runOnce) {
                usleep(300000); // 0.3 сек пауза между запросами
            }
        } while (! $runOnce);

        return self::SUCCESS;
    }
}
