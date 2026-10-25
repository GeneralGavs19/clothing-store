<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Command;

class SendTelegramDailyReport extends Command
{
    protected $signature = 'telegram:daily-report {--date= : Дата в формате YYYY-MM-DD}';
    protected $description = 'Отправка ежедневного отчета по продажам смены в Telegram';

    public function handle(TelegramService $telegram): int
    {
        $date = $this->option('date');
        $this->info('Формирование отчета за ' . ($date ?: 'сегодня') . '...');

        $success = $telegram->sendDailyReport($date);

        if ($success) {
            $this->info('Отчет успешно отправлен в Telegram.');
            return self::SUCCESS;
        }

        $this->error('Не удалось отправить отчет в Telegram.');
        return self::FAILURE;
    }
}
