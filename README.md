# Durability Store

Полноценная web-система учета товаров, склада, витрины, продаж и подтверждений для небольшого магазина.

## Стек

- Backend: Laravel 12 REST API, MySQL, собственный JWT auth, RBAC middleware.
- Frontend: Vue 3, Vite, Tailwind CSS, Pinia, Axios.
- Real-time: polling каждые 10 секунд на dashboard и продажах. Все данные считаются из базы.

## Что реализовано

- Роли `admin` и `cashier`.
- JWT login, protected routes, role-based access control.
- CRUD товаров с фото, SKU, категорией, закупочной/продажной ценой, складом и витриной.
- CRUD категорий для администратора.
- Продажа создается как `pending` и не влияет на статистику.
- Подтверждение продажи администратором в транзакции: уменьшается только витрина, создаются stock movements, прибыль попадает в аналитику.
- Защита от отрицательных остатков и двойного резервирования pending-продаж.
- Dashboard: прибыль, выручка, продажи, pending, низкие остатки, топ товаров, доход по категориям, активность кассиров.
- Пользователи, журнал действий, история операций.
- Экспорт продаж CSV, backup JSON, пересчет таблицы `statistics`.
- Темная тема, поиск, фильтры, сортировка, pagination, loading/skeleton/empty/error states, toast-сообщения.

## Запуск backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
```

Создайте MySQL базу:

```sql
CREATE DATABASE durability_store CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Проверьте `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=durability_store
DB_USERNAME=root
DB_PASSWORD=
JWT_SECRET=change_this_to_a_long_random_string
FRONTEND_URL=http://localhost:5173
```

Миграции и первый администратор:

```bash
php artisan migrate --seed
php artisan storage:link
php artisan serve
```

Первый вход после seed:

- Email: `admin@store.local`
- Password: `ChangeMe123!`

Смените пароль администратора сразу после первого входа.

## Запуск frontend

```bash
cd frontend
npm install
cp .env.example .env
npm run dev
```

Frontend будет доступен на `http://localhost:5173`, API ожидается на `http://localhost:8000/api`.

## API

Основные маршруты:

- `POST /api/auth/login`
- `GET /api/dashboard`
- `GET /api/products`
- `POST /api/products` admin
- `POST /api/sales`
- `GET /api/sales-pending` admin
- `POST /api/sales/{sale}/approve` admin
- `POST /api/sales/{sale}/reject` admin
- `GET /api/reports/sales.csv` admin
- `GET /api/reports/backup` admin

Полный список:

```bash
cd backend
php artisan route:list --path=api
```

## База данных

Созданы таблицы:

- `users`
- `categories`
- `products`
- `sales`
- `sale_items`
- `pending_sales`
- `logs`
- `statistics`
- `stock_movements`

Таблица `statistics` используется для пересчитываемых снимков, но dashboard считает текущие показатели напрямую из подтвержденных продаж.

## Проверки

В текущей сборке выполнено:

```bash
cd frontend && npm run build
cd backend && php artisan route:list --path=api
cd backend && php -l app/... routes/... database/...
cd backend && DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan migrate:fresh --seed --force
```
=======
# clothing-store
>>>>>>> e11bd316bcf717abadad904b40960d0acb5e1509
