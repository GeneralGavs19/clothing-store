<?php

namespace App\Console;

use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Console\Scheduling\Schedule;
use App\Console\Commands\AggregateDailyStats;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        AggregateDailyStats::class,
    ];

    protected function schedule(Schedule $schedule): void
    {
        // Run daily aggregation shortly after midnight
        $schedule->command('stats:aggregate-daily')->dailyAt('00:05');

        // Ежевечерний отчет по смене кассы в Telegram (в 20:00)
        $schedule->command('telegram:daily-report')->dailyAt('20:00');

        // Ночной бэкап базы данных SQLite в Telegram (в 23:30)
        $schedule->command('telegram:backup-db')->dailyAt('23:30');
    }

    protected function commands(): void
    {
        // load commands from routes/console.php
        $this->load(__DIR__.'/Commands');
        require base_path('routes/console.php');
    }
}
