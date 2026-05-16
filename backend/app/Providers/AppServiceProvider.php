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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRailwayDatabase();
    }

    /**
     * Map Railway / PlanetScale MySQL plugin variables to Laravel DB config.
     */
    private function configureRailwayDatabase(): void
    {
        $host = env('MYSQLHOST') ?: env('MYSQL_HOST');
        if (! $host) {
            return;
        }

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.host' => $host,
            'database.connections.mysql.port' => env('MYSQLPORT', env('MYSQL_PORT', '3306')),
            'database.connections.mysql.database' => env('MYSQLDATABASE', env('MYSQL_DATABASE', 'railway')),
            'database.connections.mysql.username' => env('MYSQLUSER', env('MYSQL_USER', 'root')),
            'database.connections.mysql.password' => env('MYSQLPASSWORD', env('MYSQL_PASSWORD', '')),
        ]);
    }
}
