<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Статистика за день
Schedule::command('stats:aggregate-daily')->dailyAt('00:05');

// Утренний контроль заказов под пошив формы и дедлайнов (в 09:00)
Schedule::command('telegram:orders-report')->dailyAt('09:00');

// Диагностика работоспособности сайта и базы данных (в 12:00 и 18:00)
Schedule::command('telegram:health-check')->twiceDaily(12, 18);

// Ежевечерний отчет по смене кассы в Telegram (в 20:00)
Schedule::command('telegram:daily-report')->dailyAt('20:00');

// Ночной бэкап базы данных SQLite в Telegram (в 23:30)
Schedule::command('telegram:backup-db')->dailyAt('23:30');

// Еженедельная автоматическая проверка целостности базы данных SQLite
Schedule::call(function () {
    if (config('database.default') === 'sqlite') {
        $status = DB::select("PRAGMA quick_check")[0]->quick_check ?? 'error';
        if ($status !== 'ok') {
            Log::critical("SQLite integrity check failed: {$status}");
        }
    }
})->weekly()->name('sqlite-integrity-check');
