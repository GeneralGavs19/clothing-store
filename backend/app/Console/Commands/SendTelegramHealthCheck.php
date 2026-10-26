<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Command;

class SendTelegramHealthCheck extends Command
{
    protected $signature = 'telegram:health-check';
    protected $description = 'Отправка отчета о работоспособности и диагностике сайта в Telegram';

    public function handle(TelegramService $telegram): int
    {
        $this->info('Формирование отчета о работоспособности сайта...');

        $report = $telegram->getHealthReport();
        $menu = $telegram->getMainMenuKeyboard();

        $success = $telegram->sendMessage($report, null, 'HTML', $menu);

        if ($success) {
            $this->info('Отчет о работоспособности успешно отправлен в Telegram.');
            return self::SUCCESS;
        }

        $this->error('Не удалось отправить отчет о работоспособности.');
        return self::FAILURE;
    }
}
