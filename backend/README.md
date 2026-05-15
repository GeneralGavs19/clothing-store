# Backend

Laravel REST API для Durability Store.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
php artisan serve
```

API публикуется под `/api`. Полный список маршрутов:

```bash
php artisan route:list --path=api
```
