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
     * Map Railway MySQL variables to Laravel (uses getenv — works with config cache).
     */
    private function configureRailwayDatabase(): void
    {
        $host = getenv('MYSQLHOST') ?: getenv('MYSQL_HOST') ?: false;
        if ($host) {
            $this->applyMysqlConfig(
                $host,
                getenv('MYSQLPORT') ?: getenv('MYSQL_PORT') ?: '3306',
                getenv('MYSQLDATABASE') ?: getenv('MYSQL_DATABASE') ?: 'railway',
                getenv('MYSQLUSER') ?: getenv('MYSQL_USER') ?: 'root',
                getenv('MYSQLPASSWORD') ?: getenv('MYSQL_PASSWORD') ?: '',
            );

            return;
        }

        $url = getenv('DATABASE_URL')
            ?: getenv('MYSQL_URL')
            ?: getenv('MYSQL_PUBLIC_URL')
            ?: getenv('MYSQL_PRIVATE_URL');

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
