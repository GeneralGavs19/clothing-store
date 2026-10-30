<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class AnalyticsCacheService
{
    private const CACHE_VERSION_KEY = 'analytics_cache_version';
    private const DEFAULT_TTL_SECONDS = 300; // 5 минут

    /**
     * Получить текущую версию кэша аналитики
     */
    public static function getVersion(): int
    {
        return (int) Cache::get(self::CACHE_VERSION_KEY, 1);
    }

    /**
     * Сбросить весь кэш аналитики (вызывается при создании, изменении или отмене чека)
     */
    public static function invalidate(): void
    {
        Cache::increment(self::CACHE_VERSION_KEY);
    }

    /**
     * Построить уникальный ключ кэша на основе параметров запроса и текущей версии
     */
    public static function makeKey(array $params): string
    {
        $version = self::getVersion();
        ksort($params);
        $hash = md5(json_encode($params));
        return "analytics_v{$version}_{$hash}";
    }

    /**
     * Получить или вычислить кэшированные данные аналитики
     */
    public static function remember(array $params, callable $callback, ?int $ttl = null)
    {
        $key = self::makeKey($params);
        return Cache::remember($key, $ttl ?? self::DEFAULT_TTL_SECONDS, $callback);
    }
}
