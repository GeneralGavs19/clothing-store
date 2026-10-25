<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Command;

class TestTelegramConnection extends Command
{
    protected $signature = 'telegram:test';
    protected $description = 'Проверка подключения и отправка тестового сообщения в Telegram';

    public function handle(TelegramService $telegram): int
    {
        $this->info('Проверка подключения к Telegram боту...');

        if (! $telegram->isConfigured()) {
            $this->error('Telegram бот не настроен! Проверьте TELEGRAM_BOT_TOKEN и TELEGRAM_ADMIN_CHAT_ID в .env.');
            return self::FAILURE;
        }

        $message = "🤖 <b>Durability Store Bot</b> подключен успешно!\n\n"
            . "• Уведомления об отмене чеков: <b>ВКЛЮЧЕНЫ</b>\n"
            . "• Уведомления о заказах под пошив: <b>ВКЛЮЧЕНЫ</b>\n"
            . "• Вечерние сводки кассы (20:00): <b>ВКЛЮЧЕНЫ</b>\n"
            . "• Ночной бэкап базы данных (23:30): <b>ВКЛЮЧЕНЫ</b>\n\n"
            . "Магазин детской одежды и школьной формы под надежным контролем! 👔👗";

        $success = $telegram->sendMessage($message);

        if ($success) {
            $this->info('Тестовое сообщение успешно доставлено!');
            return self::SUCCESS;
        }

        $this->error('Ошибка при отправке сообщения в Telegram.');
        return self::FAILURE;
    }
}
