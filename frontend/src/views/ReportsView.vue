<template>
  <div class="space-y-5">
    <!-- Верхняя панель управления: фильтры по дате, типу заказа и экспорт -->
    <section class="panel p-4">
      <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
          <h2 class="text-lg font-bold text-slate-900 dark:text-white">Отчет и статистика продаж</h2>
          <p class="text-xs text-slate-500">
            Наглядный расчет проданных по видам товаров и по каждому размеру (В наличии / На заказ)
          </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <!-- Фильтр по типу заказа: Все / В наличии / На заказ -->
          <div class="flex rounded-lg bg-slate-200/80 p-0.5 text-xs dark:bg-slate-800">
            <button
              type="button"
              class="rounded-md px-2.5 py-1.5 font-medium transition"
              :class="orderTypeFilter === '' ? 'bg-white shadow text-slate-900 dark:bg-slate-700 dark:text-white' : 'text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white'"
              @click="setOrderType('')"
            >
              Все заказы
            </button>
            <button
              type="button"
              class="rounded-md px-2.5 py-1.5 font-medium transition"
              :class="orderTypeFilter === 'in_stock' ? 'bg-white shadow text-emerald-700 dark:bg-slate-700 dark:text-emerald-300' : 'text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white'"
              @click="setOrderType('in_stock')"
            >
              ✓ В наличии
            </button>
            <button
              type="button"
              class="flex items-center gap-1 rounded-md px-2.5 py-1.5 font-medium transition"
              :class="orderTypeFilter === 'on_order' ? 'bg-amber-500 text-white shadow' : 'text-amber-700 hover:text-amber-900 dark:text-amber-400 dark:hover:text-white'"
              @click="setOrderType('on_order')"
            >
              <span>📦</span>
              <span>На заказ</span>
            </button>
          </div>

          <!-- Пресеты периодов -->
          <div class="flex rounded-lg bg-slate-100 p-0.5 text-xs dark:bg-slate-800">
            <button
              v-for="p in periodPresets"
              :key="p.key"
              type="button"
              class="rounded-md px-2.5 py-1.5 font-medium transition"
              :class="activePeriod === p.key ? 'bg-white shadow text-slate-900 dark:bg-slate-700 dark:text-white' : 'text-slate-500 hover:text-slate-900 dark:hover:text-white'"
              @click="setPeriod(p.key)"
            >
              {{ p.label }}
            </button>
          </div>

          <!-- Выбор дат (если период кастомный) -->
          <div v-if="activePeriod === 'custom'" class="flex items-center gap-1.5 text-xs">
            <input v-model="customDateFrom" type="date" class="input h-8 py-1 text-xs" @change="fetchAnalytics" />
            <span class="text-slate-400">—</span>
            <input v-model="customDateTo" type="date" class="input h-8 py-1 text-xs" @change="fetchAnalytics" />
          </div>

          <button type="button" class="btn-muted h-8 w-8 px-0" title="Обновить аналитику" @click="fetchAnalytics">
            <RefreshCw class="h-3.5 w-3.5" :class="{ 'animate-spin': loading }" />
          </button>

          <button
            type="button"
            class="btn-muted h-8 px-2.5 text-xs flex items-center gap-1"
            title="Скачать Excel отчет с названиями товаров и типами заказов"
            @click="downloadExcelReport"
          >
            <Download class="h-3.5 w-3.5" />
            Excel отчет
          </button>

          <button
            type="button"
            class="btn-muted h-8 px-2.5 text-xs flex items-center gap-1"
            title="Скачать Backup JSON"
            @click="download('/reports/backup', 'store-backup.json')"
          >
            <DatabaseBackup class="h-3.5 w-3.5" />
            Backup
          </button>
        </div>
      </div>
    </section>

    <!-- Сводные карточки KPI: понятный расчет вещей и выручки -->
    <section class="grid grid-cols-2 gap-3 sm:grid-cols-2 lg:grid-cols-5">
      <!-- 1. Продано вещей -->
      <div class="panel border-emerald-300 bg-emerald-50/60 p-4 dark:border-emerald-800 dark:bg-emerald-950/40">
        <div class="flex items-center justify-between">
          <span class="text-xs font-semibold text-emerald-900 dark:text-emerald-200">Продано вещей</span>
          <span class="rounded bg-emerald-200/80 px-1.5 py-0.5 text-[10px] font-bold text-emerald-900 dark:bg-emerald-900 dark:text-emerald-100">Всего</span>
        </div>
        <div class="mt-2 text-3xl font-black text-emerald-700 dark:text-emerald-300 tabular-nums">
          {{ summary.total_sold_items || 0 }} <span class="text-sm font-normal">шт.</span>
        </div>
        <div class="mt-2 flex flex-col gap-0.5 text-[11px] text-emerald-900/80 dark:text-emerald-200/80 border-t border-emerald-200 dark:border-emerald-900/60 pt-2">
          <div class="flex justify-between">
            <span>✓ Из наличия:</span>
            <strong class="font-bold">{{ summary.in_stock_units || 0 }} шт.</strong>
          </div>
          <div class="flex justify-between">
            <span>📦 На заказ:</span>
            <strong class="font-bold">{{ summary.on_order_units || 0 }} шт.</strong>
          </div>
        </div>
      </div>

      <!-- 2. Общая выручка -->
      <div class="panel border-sky-300 bg-sky-50/50 p-4 dark:border-sky-800 dark:bg-sky-950/40">
        <div class="flex items-center justify-between">
          <span class="text-xs font-semibold text-sky-900 dark:text-sky-200">Выручка за период</span>
          <span class="rounded bg-sky-200/80 px-1.5 py-0.5 text-[10px] font-bold text-sky-900 dark:bg-sky-900 dark:text-sky-100">Сумма</span>
        </div>
        <div class="mt-2 text-2xl font-black text-sky-900 dark:text-sky-200 tabular-nums">
          {{ money(summary.total_revenue) }}
        </div>
        <div class="mt-2 flex flex-col gap-0.5 text-[11px] text-sky-900/80 dark:text-sky-200/80 border-t border-sky-200 dark:border-sky-900/60 pt-2">
          <div class="flex justify-between">
            <span>Из наличия:</span>
            <strong>{{ money(summary.in_stock_revenue) }}</strong>
          </div>
          <div class="flex justify-between">
            <span>На заказ:</span>
            <strong>{{ money(summary.on_order_revenue) }}</strong>
          </div>
        </div>
      </div>

      <!-- 3. Текущий остаток -->
      <div class="panel p-4 border border-slate-200 dark:border-slate-800">
        <div class="flex items-center justify-between">
          <span class="text-xs font-semibold text-slate-600 dark:text-slate-400">Текущий остаток</span>
          <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-500 dark:bg-slate-800">Склад</span>
        </div>
        <div class="mt-2 text-3xl font-black text-slate-900 dark:text-white tabular-nums">
          {{ summary.total_stock_items || 0 }} <span class="text-sm font-normal text-slate-500">шт.</span>
        </div>
        <div class="mt-2 text-[11px] text-slate-500 border-t border-slate-100 dark:border-slate-800 pt-2">
          Физически доступно в магазине и на складе
        </div>
      </div>

      <!-- 4. Было учтено всего -->
      <div class="panel p-4 border border-slate-200 dark:border-slate-800">
        <div class="flex items-center justify-between">
          <span class="text-xs font-semibold text-slate-600 dark:text-slate-400">Было всего вещей</span>
          <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-500 dark:bg-slate-800">Формула</span>
        </div>
        <div class="mt-2 text-3xl font-black text-slate-900 dark:text-white tabular-nums">
          {{ summary.total_initial_items || 0 }} <span class="text-sm font-normal text-slate-500">шт.</span>
        </div>
        <div class="mt-2 text-[11px] text-slate-500 border-t border-slate-100 dark:border-slate-800 pt-2">
          Было = Продано ({{ summary.total_sold_items || 0 }}) + Остаток ({{ summary.total_stock_items || 0 }})
        </div>
      </div>

      <!-- 5. Количество чеков -->
      <div class="panel p-4 border border-slate-200 dark:border-slate-800">
        <div class="flex items-center justify-between">
          <span class="text-xs font-semibold text-slate-600 dark:text-slate-400">Количество чеков</span>
          <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-500 dark:bg-slate-800">Заказы</span>
        </div>
        <div class="mt-2 text-3xl font-black text-slate-900 dark:text-white tabular-nums">
          {{ summary.sales_count || 0 }} <span class="text-sm font-normal text-slate-500">чеков</span>
        </div>
        <div class="mt-2 text-[11px] text-slate-500 border-t border-slate-100 dark:border-slate-800 pt-2">
          Средний чек: <strong class="font-bold text-slate-800 dark:text-slate-200">{{ money(summary.average_check) }}</strong>
        </div>
      </div>
    </section>

    <!-- Основная секция аналитики: Переключение вкладок -->
    <section class="panel overflow-hidden">
      <!-- Табы -->
      <div class="flex flex-col gap-3 border-b border-slate-200 p-4 dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex flex-wrap gap-1.5">
          <button
            type="button"
            class="flex items-center gap-1.5 rounded-lg px-3.5 py-2 text-xs font-bold transition shadow-sm"
            :class="activeTab === 'types' ? 'bg-emerald-600 text-white shadow-emerald-600/30' : 'bg-slate-100 text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700'"
            @click="activeTab = 'types'"
          >
            <Layers class="h-4 w-4" />
            <span>По видам и размерам ({{ byType.length }})</span>
          </button>

          <button
            type="button"
            class="flex items-center gap-1.5 rounded-lg px-3.5 py-2 text-xs font-bold transition"
            :class="activeTab === 'products' ? 'bg-emerald-600 text-white shadow-emerald-600/30' : 'bg-slate-100 text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700'"
            @click="activeTab = 'products'"
          >
            <span>По товарам и моделям ({{ byProduct.length }})</span>
          </button>

          <button
            type="button"
            class="flex items-center gap-1.5 rounded-lg px-3.5 py-2 text-xs font-bold transition"
            :class="activeTab === 'categories' ? 'bg-emerald-600 text-white shadow-emerald-600/30' : 'bg-slate-100 text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700'"
            @click="activeTab = 'categories'"
          >
            <span>По категориям ({{ byCategory.length }})</span>
          </button>
        </div>

        <div class="w-full sm:w-72">
          <input
            v-model="tabSearch"
            class="input h-9 text-xs"
            placeholder="Поиск по виду, товару или размеру..."
          />
        </div>
      </div>

      <!-- ========================================================== -->
      <!-- ВКЛАДКА 1: ПО ВИДУ ТОВАРА И КАЖДОМУ РАЗМЕРУ (ОСНОВНОЙ ВИД) -->
      <!-- ========================================================== -->
      <div v-if="activeTab === 'types'">
        <div class="p-4 bg-slate-50/70 dark:bg-slate-900/50 text-xs text-slate-600 dark:text-slate-300 border-b border-slate-200 dark:border-slate-800 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
          <div>
            <span class="font-bold text-slate-800 dark:text-white">Расчет проданных вещей по видам товаров и по каждому размеру.</span>
            <span class="text-slate-500 ml-1">Здесь наглядно видно, сколько продано из наличия, сколько на заказ, сколько на складе и сколько было всего.</span>
          </div>
          <button
            type="button"
            class="text-xs font-semibold text-emerald-600 hover:underline dark:text-emerald-400 shrink-0"
            @click="toggleAllTypes"
          >
            {{ allTypesExpanded ? 'Свернуть все размеры' : 'Раскрыть все размеры' }}
          </button>
        </div>

        <div v-if="loading" class="p-6">
          <SkeletonBlock custom-class="h-60" />
        </div>

        <EmptyState
          v-else-if="!filteredTypes.length"
          class="m-6"
          title="Данные не найдены"
          text="За выбранный период продаж по видам не найдено. Попробуйте изменить период дат."
        />

        <div v-else class="divide-y divide-slate-200 dark:divide-slate-800">
          <div
            v-for="item in filteredTypes"
            :key="item.type"
            class="p-4 transition hover:bg-slate-50/50 dark:hover:bg-slate-900/30"
          >
            <!-- Главная строка вида товара -->
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                  <span class="text-base font-extrabold text-slate-900 dark:text-white">
                    {{ item.type }}
                  </span>
                  <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                    {{ (item.sizes || []).length }} разм.
                  </span>
                </div>

                <!-- Плашки показателей продаж по виду -->
                <div class="mt-2.5 flex flex-wrap items-center gap-2 text-xs">
                  <!-- Продано всего -->
                  <div class="rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-1.5 font-bold text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/70 dark:text-emerald-200">
                    Продано всего: <span class="text-sm font-black">{{ item.sold_quantity }}</span> шт.
                  </div>

                  <!-- Из наличия -->
                  <div class="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-slate-700 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300">
                    <span>✓ Из наличия: </span>
                    <strong class="text-emerald-700 dark:text-emerald-400">{{ item.in_stock_sold || 0 }} шт.</strong>
                    <span v-if="item.in_stock_revenue" class="text-slate-400 text-[11px] ml-1">({{ money(item.in_stock_revenue) }})</span>
                  </div>

                  <!-- На заказ -->
                  <div class="rounded-lg border border-amber-200 bg-amber-50/80 px-2.5 py-1.5 text-amber-900 dark:border-amber-800 dark:bg-amber-950/70 dark:text-amber-200">
                    <span>📦 На заказ: </span>
                    <strong class="font-bold">{{ item.on_order_sold || 0 }} шт.</strong>
                    <span v-if="item.on_order_revenue" class="text-amber-700/70 dark:text-amber-400 text-[11px] ml-1">({{ money(item.on_order_revenue) }})</span>
                  </div>

                  <!-- Текущий остаток -->
                  <div class="rounded-lg bg-slate-100 px-2.5 py-1.5 text-slate-600 dark:bg-slate-800 dark:text-slate-400">
                    Остаток: <strong class="text-slate-900 dark:text-white">{{ item.stock_quantity }} шт.</strong>
                  </div>

                  <!-- Было учтено -->
                  <div class="rounded-lg bg-slate-100 px-2.5 py-1.5 text-slate-600 dark:bg-slate-800 dark:text-slate-400">
                    Было: <strong class="text-slate-900 dark:text-white">{{ item.initial_quantity }} шт.</strong>
                  </div>
                </div>
              </div>

              <!-- Выручка и кнопка раскрытия размеров -->
              <div class="flex items-center justify-between sm:justify-end gap-3 pt-2 lg:pt-0">
                <div class="text-right">
                  <div class="text-lg font-black text-slate-900 dark:text-white tabular-nums">
                    {{ money(item.revenue) }}
                  </div>
                  <div class="text-[11px] text-slate-400">
                    {{ item.revenue_percent }}% от всей выручки
                  </div>
                </div>

                <button
                  type="button"
                  class="flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 shadow-sm transition hover:border-emerald-500 hover:text-emerald-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:border-emerald-500"
                  @click="toggleTypeSizes(item.type)"
                >
                  <span>Размеры ({{ (item.sizes || []).length }})</span>
                  <ChevronDown
                    class="h-3.5 w-3.5 transition-transform duration-200"
                    :class="{ 'rotate-180': isTypeExpanded(item.type) }"
                  />
                </button>
              </div>
            </div>

            <!-- ВЛОЖЕННАЯ ТАБЛИЦА: РАСЧЕТ ПО КАЖДОМУ РАЗМЕРУ -->
            <div
              v-if="isTypeExpanded(item.type)"
              class="mt-3.5 rounded-xl border border-slate-200/90 bg-slate-50/70 p-3.5 dark:border-slate-800 dark:bg-slate-950/60"
            >
              <div class="mb-2 text-xs font-bold text-slate-800 dark:text-slate-200 flex items-center justify-between">
                <span>Детализация по каждому размеру для «{{ item.type }}»:</span>
                <span class="text-[11px] text-slate-500 font-normal">Было = Продано + Остаток</span>
              </div>

              <div v-if="!(item.sizes || []).length" class="text-xs text-slate-400 py-3 text-center">
                У этого вида товара нет данных по размерам.
              </div>

              <div v-else class="overflow-x-auto rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <table class="w-full text-left text-xs">
                  <thead class="border-b border-slate-200 bg-slate-50 text-[10px] font-semibold uppercase text-slate-500 dark:border-slate-800 dark:bg-slate-950">
                    <tr>
                      <th class="px-3.5 py-2.5">Размер</th>
                      <th class="px-3.5 py-2.5 text-right font-black text-slate-900 dark:text-white">Продано всего</th>
                      <th class="px-3.5 py-2.5 text-right text-emerald-700 dark:text-emerald-300">✓ Из наличия</th>
                      <th class="px-3.5 py-2.5 text-right text-amber-700 dark:text-amber-300">📦 На заказ</th>
                      <th class="px-3.5 py-2.5 text-right">Текущий остаток</th>
                      <th class="px-3.5 py-2.5 text-right">Было учтено</th>
                      <th class="px-3.5 py-2.5 text-right">Выручка</th>
                      <th class="px-3.5 py-2.5 text-right">Ср. цена шт.</th>
                      <th class="px-3.5 py-2.5 text-right">Доля в продажах</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <tr
                      v-for="sz in item.sizes"
                      :key="sz.size"
                      class="hover:bg-slate-50/80 dark:hover:bg-slate-800/40 transition"
                    >
                      <!-- Размер -->
                      <td class="px-3.5 py-2.5 font-bold text-slate-900 dark:text-white">
                        <span class="inline-block rounded-md border border-emerald-300 bg-emerald-50 px-2.5 py-1 text-xs font-black text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/80 dark:text-emerald-200">
                          {{ sz.size }}
                        </span>
                      </td>

                      <!-- Продано всего -->
                      <td class="px-3.5 py-2.5 text-right font-black text-slate-900 dark:text-white tabular-nums text-sm">
                        {{ sz.sold_quantity }} шт.
                      </td>

                      <!-- Из наличия -->
                      <td class="px-3.5 py-2.5 text-right tabular-nums">
                        <span class="rounded bg-emerald-50 border border-emerald-200 px-2 py-0.5 text-emerald-800 dark:bg-emerald-950/50 dark:border-emerald-900 dark:text-emerald-300 font-semibold">
                          {{ sz.in_stock_sold || 0 }} шт.
                        </span>
                        <div v-if="sz.in_stock_revenue" class="text-[10px] text-slate-400 mt-0.5">
                          {{ money(sz.in_stock_revenue) }}
                        </div>
                      </td>

                      <!-- На заказ -->
                      <td class="px-3.5 py-2.5 text-right tabular-nums">
                        <span class="rounded bg-amber-50 border border-amber-200 px-2 py-0.5 text-amber-900 dark:bg-amber-950/50 dark:border-amber-900 dark:text-amber-300 font-semibold">
                          {{ sz.on_order_sold || 0 }} шт.
                        </span>
                        <div v-if="sz.on_order_revenue" class="text-[10px] text-slate-400 mt-0.5">
                          {{ money(sz.on_order_revenue) }}
                        </div>
                      </td>

                      <!-- Текущий остаток -->
                      <td class="px-3.5 py-2.5 text-right tabular-nums font-medium text-slate-700 dark:text-slate-300">
                        {{ sz.stock_quantity }} шт.
                      </td>

                      <!-- Было учтено -->
                      <td class="px-3.5 py-2.5 text-right tabular-nums font-bold text-slate-900 dark:text-slate-100">
                        {{ sz.initial_quantity }} шт.
                      </td>

                      <!-- Выручка -->
                      <td class="px-3.5 py-2.5 text-right font-black text-slate-900 dark:text-white tabular-nums">
                        {{ money(sz.revenue) }}
                      </td>

                      <!-- Ср. цена -->
                      <td class="px-3.5 py-2.5 text-right tabular-nums text-slate-500">
                        {{ money(sz.average_price) }}
                      </td>

                      <!-- Доля в виде -->
                      <td class="px-3.5 py-2.5 text-right tabular-nums text-slate-600 dark:text-slate-400">
                        {{ percent(sz.sold_quantity, item.sold_quantity || 1) }}
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ========================================================== -->
      <!-- ВКЛАДКА 2: ПО НАИМЕНОВАНИЮ ТОВАРОВ И МОДЕЛЕЙ С РАЗМЕРАМИ -->
      <!-- ========================================================== -->
      <div v-else-if="activeTab === 'products'">
        <div class="p-4 bg-slate-50/70 dark:bg-slate-900/50 text-xs text-slate-600 dark:text-slate-300 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
          <span>Детализация по конкретным моделям товаров. Нажмите на размер, чтобы увидеть детали.</span>
          <button
            type="button"
            class="text-xs text-emerald-600 hover:underline dark:text-emerald-400 font-medium shrink-0 ml-2"
            @click="toggleAllProductSizes"
          >
            {{ allExpanded ? 'Свернуть все размеры' : 'Раскрыть все размеры' }}
          </button>
        </div>

        <div v-if="loading" class="p-6">
          <SkeletonBlock custom-class="h-60" />
        </div>
        <EmptyState v-else-if="!filteredProducts.length" class="m-6" title="Товары не найдены" text="Измените фильтр поиска или период." />
        <div v-else class="divide-y divide-slate-200 dark:divide-slate-800">
          <div
            v-for="product in filteredProducts"
            :key="product.product_id || product.name"
            class="p-4 transition hover:bg-slate-50/50 dark:hover:bg-slate-900/30"
          >
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                  <span class="text-sm font-bold text-slate-900 dark:text-white">{{ product.name }}</span>
                  <span v-if="product.type" class="rounded bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
                    {{ product.type }}
                  </span>
                  <span v-if="product.category_name" class="rounded bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                    {{ product.category_name }}
                  </span>
                  <span v-if="product.color" class="text-xs text-slate-500">
                    Цвет: {{ product.color }}
                  </span>
                </div>

                <div class="mt-2.5 flex flex-wrap items-center gap-2 text-xs">
                  <span class="rounded-lg bg-emerald-50 border border-emerald-200 px-2.5 py-1 font-bold text-emerald-900 dark:bg-emerald-950/60 dark:border-emerald-800 dark:text-emerald-200">
                    Продано: {{ product.sold_quantity }} шт.
                  </span>
                  <span class="rounded-lg bg-white border border-slate-200 px-2 py-1 text-slate-700 dark:bg-slate-900 dark:border-slate-800 dark:text-slate-300">
                    ✓ В наличии: <strong class="text-emerald-700 dark:text-emerald-400">{{ product.in_stock_sold || 0 }} шт.</strong>
                  </span>
                  <span class="rounded-lg bg-amber-50 border border-amber-200 px-2 py-1 text-amber-900 dark:bg-amber-950/60 dark:border-amber-800 dark:text-amber-200">
                    📦 На заказ: <strong>{{ product.on_order_sold || 0 }} шт.</strong>
                  </span>
                  <span class="rounded bg-slate-100 px-2 py-1 text-slate-600 dark:bg-slate-800 dark:text-slate-400">
                    Остаток: {{ product.stock_quantity }} шт.
                  </span>
                  <span class="rounded bg-slate-100 px-2 py-1 text-slate-600 dark:bg-slate-800 dark:text-slate-400">
                    Было: {{ product.initial_quantity }} шт.
                  </span>
                </div>
              </div>

              <div class="flex items-center justify-between sm:justify-end gap-3 pt-2 lg:pt-0">
                <div class="text-right">
                  <div class="text-base font-bold text-slate-900 dark:text-white tabular-nums">
                    {{ money(product.revenue) }}
                  </div>
                </div>

                <button
                  type="button"
                  class="flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:border-emerald-400 hover:text-emerald-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                  @click="toggleProductSizes(product)"
                >
                  <span>Размеры ({{ (product.sizes || []).length }})</span>
                  <ChevronDown
                    class="h-3.5 w-3.5 transition-transform"
                    :class="{ 'rotate-180': isProductExpanded(product) }"
                  />
                </button>
              </div>
            </div>

            <!-- Вложенная таблица: Размеры этого товара -->
            <div
              v-if="isProductExpanded(product)"
              class="mt-3 border-t border-slate-200 bg-slate-50/70 p-3.5 dark:border-slate-800 dark:bg-slate-950/60 rounded-xl"
            >
              <div v-if="!(product.sizes || []).length" class="text-xs text-slate-400 py-2">
                Нет данных по размерам.
              </div>
              <div v-else class="overflow-x-auto rounded-md border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
                <table class="w-full text-left text-xs">
                  <thead class="border-b border-slate-200 bg-slate-50 text-[10px] font-semibold uppercase text-slate-500 dark:border-slate-800 dark:bg-slate-900">
                    <tr>
                      <th class="px-3.5 py-2">Размер</th>
                      <th class="px-3.5 py-2 text-right">Продано</th>
                      <th class="px-3.5 py-2 text-right text-emerald-600 dark:text-emerald-400">✓ В наличии</th>
                      <th class="px-3.5 py-2 text-right text-amber-600 dark:text-amber-400">📦 На заказ</th>
                      <th class="px-3.5 py-2 text-right">Остаток</th>
                      <th class="px-3.5 py-2 text-right">Было</th>
                      <th class="px-3.5 py-2 text-right">Цена продажи</th>
                      <th class="px-3.5 py-2 text-right">Выручка</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100 dark:divide-slate-900">
                    <tr
                      v-for="sz in product.sizes"
                      :key="sz.variant_id || sz.size"
                      class="hover:bg-slate-50/60 dark:hover:bg-slate-900/50"
                    >
                      <td class="px-3.5 py-2 font-bold text-slate-900 dark:text-white">
                        <span class="inline-block rounded border border-slate-200 bg-slate-50 px-2 py-0.5 text-xs dark:border-slate-700 dark:bg-slate-800">
                          {{ sz.size }}
                        </span>
                      </td>
                      <td class="px-3.5 py-2 text-right font-bold text-slate-900 dark:text-white tabular-nums">
                        {{ sz.sold_quantity }} шт.
                      </td>
                      <td class="px-3.5 py-2 text-right tabular-nums text-emerald-700 dark:text-emerald-400">
                        {{ sz.in_stock_sold || 0 }} шт.
                      </td>
                      <td class="px-3.5 py-2 text-right tabular-nums text-amber-700 dark:text-amber-400">
                        {{ sz.on_order_sold || 0 }} шт.
                      </td>
                      <td class="px-3.5 py-2 text-right tabular-nums text-slate-600 dark:text-slate-400">
                        {{ sz.stock_quantity }} шт.
                      </td>
                      <td class="px-3.5 py-2 text-right tabular-nums font-semibold text-slate-700 dark:text-slate-300">
                        {{ sz.initial_quantity }} шт.
                      </td>
                      <td class="px-3.5 py-2 text-right tabular-nums text-slate-600 dark:text-slate-400">
                        {{ money(sz.sale_price) }}
                      </td>
                      <td class="px-3.5 py-2 text-right font-bold text-slate-900 dark:text-white tabular-nums">
                        {{ money(sz.revenue) }}
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ========================================================== -->
      <!-- ВКЛАДКА 3: ПО КАТЕГОРИЯМ КАТАЛОГА                          -->
      <!-- ========================================================== -->
      <div v-else-if="activeTab === 'categories'">
        <div class="p-4 bg-slate-50/70 dark:bg-slate-900/50 text-xs text-slate-600 dark:text-slate-300 border-b border-slate-200 dark:border-slate-800">
          Сводка продаж и остатков по категориям каталога.
        </div>

        <div v-if="loading" class="p-6">
          <SkeletonBlock custom-class="h-44" />
        </div>
        <EmptyState v-else-if="!filteredCategories.length" class="m-6" title="Нет данных по категориям" text="За выбранный период продаж по категориям не найдено." />
        <div v-else class="overflow-x-auto">
          <table class="w-full text-left text-xs">
            <thead class="border-b border-slate-200 bg-slate-50 text-[11px] font-semibold uppercase text-slate-500 dark:border-slate-800 dark:bg-slate-900/80">
              <tr>
                <th class="px-4 py-3">Категория</th>
                <th class="px-4 py-3 text-right">Было всего</th>
                <th class="px-4 py-3 text-right font-bold text-slate-900 dark:text-white">Продано всего</th>
                <th class="px-4 py-3 text-right text-emerald-600 dark:text-emerald-400">✓ В наличии</th>
                <th class="px-4 py-3 text-right text-amber-600 dark:text-amber-400">📦 На заказ</th>
                <th class="px-4 py-3 text-right">Остаток</th>
                <th class="px-4 py-3 text-right">Выручка</th>
                <th class="px-4 py-3 text-right">Доля в продажах</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
              <tr
                v-for="item in filteredCategories"
                :key="item.category_id || item.category_name"
                class="transition hover:bg-slate-50/80 dark:hover:bg-slate-900/40"
              >
                <td class="px-4 py-3 font-bold text-slate-900 dark:text-white">
                  {{ item.category_name }}
                </td>
                <td class="px-4 py-3 text-right font-bold text-slate-900 dark:text-slate-100 tabular-nums">
                  {{ item.initial_quantity }} шт.
                </td>
                <td class="px-4 py-3 text-right font-bold text-slate-900 dark:text-white tabular-nums">
                  {{ item.sold_quantity }} шт.
                </td>
                <td class="px-4 py-3 text-right tabular-nums text-emerald-700 dark:text-emerald-400">
                  {{ item.in_stock_sold || 0 }} шт.
                </td>
                <td class="px-4 py-3 text-right tabular-nums text-amber-700 dark:text-amber-400">
                  {{ item.on_order_sold || 0 }} шт.
                </td>
                <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-400">
                  {{ item.stock_quantity }} шт.
                </td>
                <td class="px-4 py-3 text-right font-bold text-slate-900 dark:text-white tabular-nums">
                  {{ money(item.revenue) }}
                </td>
                <td class="px-4 py-3 text-right font-medium tabular-nums text-slate-700 dark:text-slate-300">
                  {{ item.revenue_percent }}%
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { ChevronDown, DatabaseBackup, Download, Layers, RefreshCw } from 'lucide-vue-next'
import api, { apiError } from '../api/client'
import { useToastStore } from '../stores/toasts'
import EmptyState from '../components/ui/EmptyState.vue'
import SkeletonBlock from '../components/ui/SkeletonBlock.vue'

const toast = useToastStore()

const loading = ref(false)
const activeTab = ref('types') // 'types' | 'categories' | 'products'
const tabSearch = ref('')
const activePeriod = ref('all') // 'all' | 'today' | '7days' | 'month' | 'custom'
const orderTypeFilter = ref('') // '' (all) | 'in_stock' | 'on_order'
const customDateFrom = ref('')
const customDateTo = ref('')

const expandedTypeNames = ref(new Set())
const allTypesExpanded = ref(true)

const expandedProductIds = ref(new Set())
const allExpanded = ref(false)

const analyticsData = ref({
  summary: {},
  by_type: [],
  by_category: [],
  by_product: [],
})

const periodPresets = [
  { key: 'all', label: 'Все время' },
  { key: 'today', label: 'Сегодня' },
  { key: '7days', label: '7 дней' },
  { key: 'month', label: 'Этот месяц' },
  { key: 'custom', label: 'Свой период' },
]

const summary = computed(() => analyticsData.value?.summary || {})
const byType = computed(() => analyticsData.value?.by_type || [])
const byCategory = computed(() => analyticsData.value?.by_category || [])
const byProduct = computed(() => analyticsData.value?.by_product || [])

const filteredTypes = computed(() => {
  const q = tabSearch.value.trim().toLowerCase()
  if (!q) return byType.value
  return byType.value.filter((item) => {
    const matchType = (item.type || '').toLowerCase().includes(q)
    const matchSize = (item.sizes || []).some((sz) => (sz.size || '').toLowerCase().includes(q))
    return matchType || matchSize
  })
})

const filteredCategories = computed(() => {
  const q = tabSearch.value.trim().toLowerCase()
  if (!q) return byCategory.value
  return byCategory.value.filter((item) => (item.category_name || '').toLowerCase().includes(q))
})

const filteredProducts = computed(() => {
  const q = tabSearch.value.trim().toLowerCase()
  if (!q) return byProduct.value
  return byProduct.value.filter((prod) =>
    (prod.name || '').toLowerCase().includes(q) ||
    (prod.type || '').toLowerCase().includes(q) ||
    (prod.category_name || '').toLowerCase().includes(q) ||
    (prod.color || '').toLowerCase().includes(q) ||
    (prod.sizes || []).some((sz) => (sz.size || '').toLowerCase().includes(q)),
  )
})

function setPeriod(period) {
  activePeriod.value = period
  const today = new Date()

  if (period === 'today') {
    const d = today.toISOString().split('T')[0]
    customDateFrom.value = d
    customDateTo.value = d
  } else if (period === '7days') {
    const past = new Date(today)
    past.setDate(past.getDate() - 6)
    customDateFrom.value = past.toISOString().split('T')[0]
    customDateTo.value = today.toISOString().split('T')[0]
  } else if (period === 'month') {
    const firstDay = new Date(today.getFullYear(), today.getMonth(), 1)
    customDateFrom.value = firstDay.toISOString().split('T')[0]
    customDateTo.value = today.toISOString().split('T')[0]
  } else if (period === 'all') {
    customDateFrom.value = ''
    customDateTo.value = ''
  }

  fetchAnalytics()
}

function setOrderType(type) {
  orderTypeFilter.value = type
  fetchAnalytics()
}

async function fetchAnalytics() {
  loading.value = true
  try {
    const params = {}
    if (activePeriod.value !== 'all') {
      if (customDateFrom.value) params.date_from = customDateFrom.value
      if (customDateTo.value) params.date_to = customDateTo.value
    }
    if (orderTypeFilter.value === 'on_order') {
      params.is_on_order = true
    } else if (orderTypeFilter.value === 'in_stock') {
      params.is_on_order = false
    }

    const { data } = await api.get('/reports/analytics', { params })
    analyticsData.value = data

    // Expand all types by default
    const set = new Set()
    ;(data.by_type || []).forEach((t) => set.add(t.type))
    expandedTypeNames.value = set
    allTypesExpanded.value = true
  } catch (error) {
    toast.push(apiError(error), 'error')
  } finally {
    loading.value = false
  }
}

function isTypeExpanded(type) {
  return expandedTypeNames.value.has(type)
}

function toggleTypeSizes(type) {
  const set = new Set(expandedTypeNames.value)
  if (set.has(type)) {
    set.delete(type)
  } else {
    set.add(type)
  }
  expandedTypeNames.value = set
}

function toggleAllTypes() {
  if (allTypesExpanded.value) {
    expandedTypeNames.value = new Set()
    allTypesExpanded.value = false
  } else {
    const set = new Set()
    byType.value.forEach((t) => set.add(t.type))
    expandedTypeNames.value = set
    allTypesExpanded.value = true
  }
}

function getProductKey(product) {
  return String(product.product_id || product.name)
}

function isProductExpanded(product) {
  return expandedProductIds.value.has(getProductKey(product))
}

function toggleProductSizes(product) {
  const key = getProductKey(product)
  const set = new Set(expandedProductIds.value)
  if (set.has(key)) {
    set.delete(key)
  } else {
    set.add(key)
  }
  expandedProductIds.value = set
}

function toggleAllProductSizes() {
  if (allExpanded.value) {
    expandedProductIds.value = new Set()
    allExpanded.value = false
  } else {
    const set = new Set()
    byProduct.value.forEach((p) => set.add(getProductKey(p)))
    expandedProductIds.value = set
    allExpanded.value = true
  }
}

function money(value) {
  return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'KZT', maximumFractionDigits: 0 }).format(Number(value || 0))
}

function percent(value, total) {
  if (!total) return '0%'
  return `${Math.min(100, Math.round((Number(value || 0) / Number(total)) * 100))}%`
}

async function downloadExcelReport() {
  try {
    const params = new URLSearchParams()
    if (activePeriod.value !== 'all') {
      if (customDateFrom.value) params.set('date_from', customDateFrom.value)
      if (customDateTo.value) params.set('date_to', customDateTo.value)
    }
    if (orderTypeFilter.value === 'on_order') params.set('is_on_order', '1')
    if (orderTypeFilter.value === 'in_stock') params.set('is_on_order', '0')

    const url = `/reports/sales.xlsx?${params.toString()}`
    const response = await api.get(url, { responseType: 'blob' })
    const blobUrl = URL.createObjectURL(response.data)
    const link = document.createElement('a')
    link.href = blobUrl
    link.download = `sales-report-${new Date().toISOString().slice(0, 10)}.xlsx`
    link.click()
    URL.revokeObjectURL(blobUrl)
    toast.push('Excel отчет успешно скачан!')
  } catch (error) {
    toast.push(apiError(error), 'error')
  }
}

async function download(endpoint, fallbackName) {
  try {
    const response = await api.get(endpoint, { responseType: 'blob' })
    const url = URL.createObjectURL(response.data)
    const link = document.createElement('a')
    link.href = url
    link.download = fallbackName
    link.click()
    URL.revokeObjectURL(url)
    toast.push('Файл успешно скачан')
  } catch (error) {
    toast.push(apiError(error), 'error')
  }
}

onMounted(() => {
  fetchAnalytics()
})
</script>
