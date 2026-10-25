# AGENTS.md — Справочник для AI-агентов и разработчиков

Данный файл содержит ключевую информацию об архитектуре, бизнес-правилах, структуре проекта и командах сборки магазина детской одежды и школьной формы.

---

## 1. Стек технологий и окружение

- **Frontend**: Vue 3 (Composition API `<script setup>`), Tailwind CSS v3, Vite v5, Pinia, Vue Router, Lucide Icons.
- **Backend**: Laravel 11, PHP 8.2 (OSPanel путь: `C:\OSPanel\modules\PHP-8.2\PHP\php.exe`), SQLite (`backend/database/database.sqlite`), PhpSpreadsheet.
- **ОС**: Windows 11.

---

## 2. Ключевые бизнес-правила и ограничения

1. **Отсутствие складского учета на текущем этапе**:
   - Магазин в данный момент **НЕ** ведёт полноценный складской учет остатков.
   - Мы достоверно знаем, что и в каком объеме **продано**, но **не знаем** реальных остатков на складе и начальных партий.
   - **Запрещено**: придумывать или выводить вымышленные складские остатки в главных KPI, сводных строках и формулах (`Было = Продано + Остаток`).
   - Отчетность опирается исключительно на фактически завершенные продажи (`status = 'approved'`).

2. **Разделение типов операций**:
   - Все продажи и позиции делятся на два типа:
     - `В наличии` (`is_on_order = false`): товар выдан со склада/магазина.
     - `📦 Под заказ` (`is_on_order = true`): предварительный заказ / пошив школьной формы.
   - Во всех отчетах, сводках и Excel-экспорте сохраняется строгое разделение по этим двум типам.

3. **Учет размеров детской одежды**:
   - Источником правды по проданным размерам являются записи `sale_items` (`variant_size`) в связке с каталогом.
   - Размеры сортируются в естественном порядке (числовые размеры формы: 110, 116, 122... или буквенные: XXS, XS, S, M, L...).
   - Нельзя терять размеры или показывать 0 размеров для товаров с продажами.

4. **Редактирование продаж**:
   - Реализован эндпоинт `PUT /api/sales/{sale}` (`SalesService::updateSale`), позволяющий корректировать состав, цены, скидки, тип заказа и статус чека.

---

## 3. Команды для сборки и проверки

```powershell
# Сборка фронтенда
npm run build

# Проверка синтаксиса PHP-файлов
& 'C:\OSPanel\modules\PHP-8.2\PHP\php.exe' -l backend/app/Http/Controllers/Api/ReportController.php

# Запуск artisan команд
& 'C:\OSPanel\modules\PHP-8.2\PHP\php.exe' backend/artisan route:list
```

---

## 4. Карта структуры проекта

```
durabiliry/
├── backend/
│   ├── app/
│   │   ├── Http/Controllers/Api/
│   │   │   ├── ReportController.php   # Аналитика, Excel экспорт (3 листа), Backup
│   │   │   ├── SaleController.php     # Управление продажами (CRUD, approval)
│   │   │   ├── ProductController.php  # Каталог товаров
│   │   │   └── ...
│   │   ├── Models/
│   │   │   ├── Sale.php               # Чек/продажа (is_on_order, subtotal, status)
│   │   │   ├── SaleItem.php           # Позиция чека (variant_size, is_on_order, line_total)
│   │   │   ├── Product.php            # Товар (type, name, category_id)
│   │   │   └── ProductVariant.php     # Вариант товара (size, stock_store, stock_warehouse)
│   │   └── Services/
│   │       ├── SalesService.php       # Бизнес-логика оформления и обновления продаж
│   │       └── ...
│   ├── database/
│   │   └── database.sqlite            # Основная база данных
│   └── routes/
│       └── api.php                    # Маршруты REST API
├── frontend/
│   ├── src/
│   │   ├── views/
│   │   │   ├── ReportsView.vue        # Редизайн аналитической панели «Отчёты»
│   │   │   ├── SalesView.vue          # Касса, оформление и редактирование продаж
│   │   │   ├── ProductsView.vue       # Управление каталогом товаров
│   │   │   └── DashboardView.vue      # Сводный дашборд
│   │   ├── utils/
│   │   │   └── formatters.js          # Единые утилиты форматирования: money, date, percent, formatDiff
│   │   ├── stores/                    # Pinia хранилища (sales, catalog, auth, toasts)
│   │   └── components/                # UI компоненты
│   └── package.json
└── AGENTS.md                          # Этот файл документации
```

---

## 5. Основные эндпоинты раздела «Отчёты»

- `GET /api/reports/analytics`
  - Параметры: `date_from`, `date_to`, `order_type` (`in_stock` / `on_order`), `category_id`, `search`.
  - Возвращает:
    - `summary`: 4 основных показателя (продано товаров, выручка, чеки, средний чек) + динамика сравнения с прошлым аналогичным периодом (`comparison`).
    - `sales_dynamics`: динамика по дням (выручка, кол-во штук, наличие / под заказ) для графиков.
    - `top_products`: топ-5 продаваемых товаров.
    - `top_sizes`: топ-5 востребованных размеров детской одежды.
    - `by_type`: основная группировка по видам одежды с детализацией по каждому размеру.
    - `by_product`: группировка по конкретным моделям товаров.
    - `by_category`: группировка по категориям каталога.
- `GET /api/reports/sales.xlsx`
  - Скачивание фирменного Excel-отчета с 3 детальными листами без вымышленных остатков.
- `GET /api/reports/backup`
  - Скачивание JSON-резервной копии данных.
- `POST /api/reports/rebuild-statistics`
  - Пересчет агрегированных метрик.
