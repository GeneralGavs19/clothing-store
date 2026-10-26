<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Command;

class SendTelegramOrdersReport extends Command
{
    protected $signature = 'telegram:orders-report';
    protected $description = 'Отправка отчета по заказам на пошив школьной формы и дедлайнам в Telegram';

    public function handle(TelegramService $telegram): int
    {
        $this->info('Формирование отчета по предзаказам формы...');

        $report = $telegram->getOrdersReportText();
        $menu = $telegram->getMainMenuKeyboard();

        $success = $telegram->sendMessage($report, null, 'HTML', $menu);

        if ($success) {
            $this->info('Отчет по заказам успешно отправлен в Telegram.');
            return self::SUCCESS;
        }

        $this->error('Не удалось отправить отчет по заказам.');
        return self::FAILURE;
    }
}
