<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Command;

class SendTelegramBackup extends Command
{
    protected $signature = 'telegram:backup-db';
    protected $description = 'Отправка резервной копии базы данных SQLite в Telegram';

    public function handle(TelegramService $telegram): int
    {
        $this->info('Создание и отправка резервной копии SQLite в Telegram...');

        $success = $telegram->sendDatabaseBackup();

        if ($success) {
            $this->info('Резервная копия базы данных успешно отправлена в Telegram.');
            return self::SUCCESS;
        }

        $this->error('Не удалось отправить резервную копию базы данных.');
        return self::FAILURE;
    }
}
