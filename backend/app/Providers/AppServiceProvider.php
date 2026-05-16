<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->configureRailwayDatabase();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Map Railway MySQL plugin variables (or DATABASE_URL) to Laravel DB config.
     */
    private function configureRailwayDatabase(): void
    {
        $host = env('MYSQLHOST') ?: env('MYSQL_HOST');
        if ($host) {
            $this->applyMysqlConfig(
                $host,
                (string) env('MYSQLPORT', env('MYSQL_PORT', '3306')),
                (string) env('MYSQLDATABASE', env('MYSQL_DATABASE', 'railway')),
                (string) env('MYSQLUSER', env('MYSQL_USER', 'root')),
                (string) env('MYSQLPASSWORD', env('MYSQL_PASSWORD', '')),
            );

            return;
        }

        $url = env('DATABASE_URL')
            ?: env('MYSQL_URL')
            ?: env('MYSQL_PUBLIC_URL')
            ?: env('MYSQL_PRIVATE_URL');

        if (! $url) {
            return;
        }

        $parsed = parse_url($url);
        if (! is_array($parsed) || empty($parsed['host'])) {
            return;
        }

        $this->applyMysqlConfig(
            $parsed['host'],
            (string) ($parsed['port'] ?? 3306),
            ltrim((string) ($parsed['path'] ?? '/railway'), '/') ?: 'railway',
            (string) ($parsed['user'] ?? 'root'),
            (string) ($parsed['pass'] ?? ''),
        );
    }

    private function applyMysqlConfig(
        string $host,
        string $port,
        string $database,
        string $username,
        string $password,
    ): void {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.host' => $host,
            'database.connections.mysql.port' => $port,
            'database.connections.mysql.database' => $database,
            'database.connections.mysql.username' => $username,
            'database.connections.mysql.password' => $password,
        ]);
    }
}
