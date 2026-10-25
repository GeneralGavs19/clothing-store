<template>
  <div class="space-y-6">
    <!-- ======================================================== -->
    <!-- ЗОНА 1: ЗАГОЛОВОК И ДЕЙСТВИЯ                             -->
    <!-- ======================================================== -->
    <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <div class="flex items-center gap-2">
          <h1 class="text-2xl font-black tracking-tight text-slate-900 dark:text-white">
            Отчёты
          </h1>
          <span class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-800 dark:bg-emerald-950/70 dark:text-emerald-300">
            Аналитика продаж (в тенге ₸)
          </span>
        </div>
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
          Фактические продажи детской одежды и школьной формы: динамика, виды товаров и спрос по размерам
        </p>
      </div>

      <!-- Кнопки действий -->
      <div class="flex flex-wrap items-center gap-2">
        <!-- Главное действие: Экспорт в Excel -->
        <button
          type="button"
          class="btn-primary flex items-center gap-2 px-3.5 py-2 text-xs font-bold shadow-sm"
          :disabled="exportingExcel"
          title="Скачать полный отчет по продажам в формате Excel (с автофильтрами и суммами в тенге ₸)"
          @click="downloadExcelReport"
        >
          <FileSpreadsheet class="h-4 w-4" :class="{ 'animate-pulse': exportingExcel }" />
          <span>{{ exportingExcel ? 'Формирование...' : 'Экспорт в Excel' }}</span>
        </button>

        <!-- Кнопка обновления -->
        <button
          type="button"
          class="btn-muted flex h-9 w-9 items-center justify-center p-0"
          :disabled="loading"
          title="Обновить аналитику"
          @click="fetchAnalytics"
        >
          <RefreshCw class="h-4 w-4 text-slate-600 dark:text-slate-300" :class="{ 'animate-spin': loading }" />
        </button>

        <!-- Меню дополнительных действий (Dropdown) -->
        <div class="relative">
          <button
            type="button"
            class="btn-muted flex h-9 items-center gap-1.5 px-2.5 text-xs font-medium"
            title="Дополнительные действия"
            @click="showMoreActions = !showMoreActions"
          >
            <MoreVertical class="h-4 w-4 text-slate-600 dark:text-slate-300" />
            <span class="hidden sm:inline">Действия</span>
          </button>

          <div
            v-if="showMoreActions"
            class="absolute right-0 top-full z-30 mt-1.5 w-56 rounded-xl border border-slate-200 bg-white py-1 shadow-lg dark:border-slate-800 dark:bg-slate-900"
            @click="showMoreActions = false"
          >
            <button
              type="button"
              class="flex w-full items-center gap-2.5 px-3 py-2 text-left text-xs font-medium text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-800"
              @click="download('/reports/backup', 'store-backup.json')"
            >
              <DatabaseBackup class="h-4 w-4 text-slate-400" />
              <div>
                <div>Резервная копия (JSON)</div>
                <div class="text-[10px] text-slate-400">Экспорт базы данных магазина</div>
              </div>
            </button>
            <button
              type="button"
              class="flex w-full items-center gap-2.5 px-3 py-2 text-left text-xs font-medium text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-800"
              @click="triggerRebuildStats"
            >
              <Sparkles class="h-4 w-4 text-amber-500" />
              <div>
                <div>Пересчитать статистику</div>
                <div class="text-[10px] text-slate-400">Обновить агрегированные данные</div>
              </div>
            </button>
          </div>
        </div>
      </div>
    </header>

    <!-- ======================================================== -->
    <!-- ЗОНА 2: ПЕРЕРАБОТАННАЯ ПАНЕЛЬ ФИЛЬТРОВ (КАК В EXCEL)      -->
    <!-- ======================================================== -->
    <section class="panel p-4 space-y-3.5">
      <!-- 1-я строка: Период, Наличие, Категория, Размер и Поиск -->
      <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div class="flex flex-wrap items-center gap-2">
          <!-- Пресеты периодов -->
          <div class="flex rounded-lg bg-slate-100 p-0.5 text-xs font-medium dark:bg-slate-800">
            <button
              v-for="p in periodPresets"
              :key="p.key"
              type="button"
              class="rounded-md px-2.5 py-1.5 transition"
              :class="activePeriod === p.key ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' : 'text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white'"
              @click="setPeriod(p.key)"
            >
              {{ p.label }}
            </button>
          </div>

          <!-- Фильтр по типу операции: Все / В наличии / Под заказ -->
          <div class="flex rounded-lg bg-slate-100 p-0.5 text-xs font-medium dark:bg-slate-800">
            <button
              type="button"
              class="rounded-md px-2.5 py-1.5 transition"
              :class="orderTypeFilter === '' ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' : 'text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white'"
              @click="setOrderType('')"
            >
              Все
            </button>
            <button
              type="button"
              class="rounded-md px-2.5 py-1.5 transition"
              :class="orderTypeFilter === 'in_stock' ? 'bg-white text-emerald-700 shadow-sm dark:bg-slate-700 dark:text-emerald-300' : 'text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white'"
              @click="setOrderType('in_stock')"
            >
              ✓ В наличии
            </button>
            <button
              type="button"
              class="rounded-md px-2.5 py-1.5 transition"
              :class="orderTypeFilter === 'on_order' ? 'bg-amber-500 text-white shadow-sm' : 'text-amber-700 hover:text-amber-900 dark:text-amber-400 dark:hover:text-white'"
              @click="setOrderType('on_order')"
            >
              📦 Под заказ
            </button>
          </div>

          <!-- Фильтр по категории -->
          <div class="relative">
            <select
              v-model="categoryFilter"
              class="input h-8 py-1 pl-2.5 pr-8 text-xs font-medium"
              @change="fetchAnalytics"
            >
              <option :value="null">Все категории</option>
              <option v-for="cat in categories" :key="cat.id" :value="cat.id">
                {{ cat.name }}
              </option>
            </select>
          </div>

          <!-- Фильтр по размеру (как выпадающий фильтр в столбце Excel) -->
          <div class="relative">
            <select
              v-model="selectedSize"
              class="input h-8 py-1 pl-2.5 pr-8 text-xs font-medium"
              :class="{ 'border-indigo-500 font-bold text-indigo-700 dark:text-indigo-300': selectedSize }"
              @change="fetchAnalytics"
            >
              <option value="">Все размеры</option>
              <option v-for="sz in availableSizes" :key="sz" :value="sz">
                Размер: {{ sz }}
              </option>
            </select>
          </div>
        </div>

        <!-- Правая часть: Строка поиска -->
        <div class="relative w-full sm:w-72">
          <Search class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" />
          <input
            v-model="searchQuery"
            type="text"
            class="input h-8 pl-8 pr-7 text-xs"
            placeholder="Поиск по модели, цвету, размеру..."
            @input="onSearchInput"
          />
          <button
            v-if="searchQuery"
            type="button"
            class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-white"
            @click="clearSearch"
          >
            <X class="h-3.5 w-3.5" />
          </button>
        </div>
      </div>

      <!-- 2-я строка: Быстрые фильтры по видам одежды (Чипы / Теги, как в розничных таблицах) -->
      <div class="flex items-center gap-1.5 overflow-x-auto pb-1 text-xs no-scrollbar">
        <span class="text-slate-400 font-semibold shrink-0 text-[11px] uppercase tracking-wider mr-1">
          Вид товара:
        </span>
        <button
          type="button"
          class="shrink-0 rounded-full px-3 py-1 font-semibold transition"
          :class="!selectedType ? 'bg-emerald-600 text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700'"
          @click="setType('')"
        >
          Все виды
        </button>
        <button
          v-for="t in availableTypes"
          :key="t"
          type="button"
          class="shrink-0 rounded-full px-3 py-1 font-semibold transition"
          :class="selectedType === t ? 'bg-emerald-600 text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700'"
          @click="setType(t)"
        >
          {{ t }}
        </button>
      </div>

      <!-- Поля выбора произвольного периода (если выбран 'custom') -->
      <div
        v-if="activePeriod === 'custom'"
        class="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-2.5 text-xs dark:border-slate-800"
      >
        <span class="text-slate-500 font-medium">Произвольный период:</span>
        <div class="flex items-center gap-1.5">
          <input
            v-model="customDateFrom"
            type="date"
            class="input h-8 py-1 text-xs"
            @change="fetchAnalytics"
          />
          <span class="text-slate-400">—</span>
          <input
            v-model="customDateTo"
            type="date"
            class="input h-8 py-1 text-xs"
            @change="fetchAnalytics"
          />
        </div>
      </div>

      <!-- Информационная плашка активных фильтров (Active Filters Tags с кнопкой снятия ✕) -->
      <div
        v-if="isFiltered"
        class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-2.5 text-xs dark:border-slate-800"
      >
        <div class="flex flex-wrap items-center gap-1.5">
          <span class="text-slate-400 text-[11px]">Активные фильтры:</span>
          <!-- Период -->
          <span class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-300">
            📅 {{ activePeriodLabel }}
          </span>
          <!-- Вид -->
          <span
            v-if="selectedType"
            class="inline-flex items-center gap-1 rounded-md bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300"
          >
            👕 Вид: {{ selectedType }}
            <button type="button" class="hover:text-emerald-950 ml-0.5" title="Снять фильтр вида" @click="setType('')">✕</button>
          </span>
          <!-- Размер -->
          <span
            v-if="selectedSize"
            class="inline-flex items-center gap-1 rounded-md bg-indigo-100 px-2 py-0.5 text-xs font-semibold text-indigo-800 dark:bg-indigo-950 dark:text-indigo-300"
          >
            📏 Размер: {{ selectedSize }}
            <button type="button" class="hover:text-indigo-950 ml-0.5" title="Снять фильтр размера" @click="setSize('')">✕</button>
          </span>
          <!-- Тип заказа -->
          <span
            v-if="orderTypeFilter"
            class="inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-semibold"
            :class="orderTypeFilter === 'in_stock' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300'"
          >
            {{ orderTypeFilter === 'in_stock' ? '✓ В наличии' : '📦 Под заказ' }}
            <button type="button" class="ml-0.5" title="Снять фильтр типа заказа" @click="setOrderType('')">✕</button>
          </span>
          <!-- Категория -->
          <span
            v-if="categoryFilter"
            class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-300"
          >
            📁 {{ selectedCategoryName }}
            <button type="button" class="hover:text-slate-900 ml-0.5" title="Снять фильтр категории" @click="setCategory(null)">✕</button>
          </span>
          <!-- Текст поиска -->
          <span
            v-if="searchQuery"
            class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-300"
          >
            🔍 «{{ searchQuery }}»
            <button type="button" class="hover:text-slate-900 ml-0.5" title="Очистить поиск" @click="clearSearch">✕</button>
          </span>
        </div>

        <button
          type="button"
          class="text-xs font-semibold text-rose-600 hover:underline dark:text-rose-400"
          @click="resetFilters"
        >
          Сбросить все фильтры
        </button>
      </div>
    </section>

    <!-- ======================================================== -->
    <!-- ЗОНА 3: 4 КОМПАКТНЫЕ КАРТОЧКИ ПОКАЗАТЕЛЕЙ И ДИНАМИКА     -->
    <!-- ======================================================== -->
    <section class="grid grid-cols-1 gap-3.5 sm:grid-cols-2 lg:grid-cols-4">
      <!-- КАРТОЧКА 1: ПРОДАНО ТОВАРОВ -->
      <div class="panel border-l-4 border-l-emerald-500 p-4">
        <div class="flex items-center justify-between">
          <span class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
            Продано товаров
          </span>
          <ShoppingBag class="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
        </div>

        <div class="mt-2.5 flex items-baseline gap-2">
          <span class="text-3xl font-black text-slate-900 dark:text-white tabular-nums">
            {{ formatNumber(summary.total_sold_items) }}
          </span>
          <span class="text-xs font-medium text-slate-500">шт.</span>

          <!-- Динамика к прошлому периоду -->
          <span
            v-if="summary.comparison?.units_diff_percent !== null && summary.comparison?.units_diff_percent !== undefined"
            class="ml-auto inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold"
            :class="summary.comparison.units_diff >= 0 ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300'"
            :title="`К прошлому периоду: ${summary.comparison.units_diff >= 0 ? '+' : ''}${summary.comparison.units_diff} шт.`"
          >
            {{ summary.comparison.units_diff >= 0 ? '+' : '' }}{{ summary.comparison.units_diff_percent }}%
          </span>
        </div>

        <!-- Разделение: Из наличия / Под заказ -->
        <div class="mt-3 flex flex-col gap-1 border-t border-slate-100 pt-2.5 text-[11px] text-slate-600 dark:border-slate-800 dark:text-slate-400">
          <div class="flex items-center justify-between">
            <span class="text-emerald-700 dark:text-emerald-400 font-medium">✓ Из наличия:</span>
            <strong class="font-bold text-slate-900 dark:text-white tabular-nums">{{ formatNumber(summary.in_stock_units) }} шт.</strong>
          </div>
          <div class="flex items-center justify-between">
            <span class="text-amber-700 dark:text-amber-400 font-medium">📦 Под заказ:</span>
            <strong class="font-bold text-slate-900 dark:text-white tabular-nums">{{ formatNumber(summary.on_order_units) }} шт.</strong>
          </div>
        </div>
      </div>

      <!-- КАРТОЧКА 2: ВЫРУЧКА (СТРОГО В ТЕНГЕ ₸) -->
      <div class="panel border-l-4 border-l-sky-500 p-4">
        <div class="flex items-center justify-between">
          <span class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
            Выручка
          </span>
          <span class="rounded bg-sky-100 px-1.5 py-0.5 text-[10px] font-bold text-sky-800 dark:bg-sky-950 dark:text-sky-300">
            В тенге ₸
          </span>
        </div>

        <div class="mt-2.5 flex items-baseline gap-2">
          <span class="text-2xl font-black text-slate-900 dark:text-white tabular-nums">
            {{ formatMoney(summary.total_revenue) }}
          </span>

          <!-- Динамика к прошлому периоду -->
          <span
            v-if="summary.comparison?.revenue_diff_percent !== null && summary.comparison?.revenue_diff_percent !== undefined"
            class="ml-auto inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold"
            :class="summary.comparison.revenue_diff >= 0 ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300'"
            :title="`К прошлому периоду: ${summary.comparison.revenue_diff >= 0 ? '+' : ''}${formatMoney(summary.comparison.revenue_diff)}`"
          >
            {{ summary.comparison.revenue_diff >= 0 ? '+' : '' }}{{ summary.comparison.revenue_diff_percent }}%
          </span>
        </div>

        <!-- Разделение по типу операций -->
        <div class="mt-3 flex flex-col gap-1 border-t border-slate-100 pt-2.5 text-[11px] text-slate-600 dark:border-slate-800 dark:text-slate-400">
          <div class="flex items-center justify-between">
            <span>Из наличия:</span>
            <strong class="font-bold text-slate-900 dark:text-white tabular-nums">{{ formatMoney(summary.in_stock_revenue) }}</strong>
          </div>
          <div class="flex items-center justify-between">
            <span>Под заказ:</span>
            <strong class="font-bold text-slate-900 dark:text-white tabular-nums">{{ formatMoney(summary.on_order_revenue) }}</strong>
          </div>
        </div>
      </div>

      <!-- КАРТОЧКА 3: ЧЕКИ -->
      <div class="panel border-l-4 border-l-indigo-500 p-4">
        <div class="flex items-center justify-between">
          <span class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
            Чеки (продажи)
          </span>
          <Receipt class="h-4 w-4 text-indigo-600 dark:text-indigo-400" />
        </div>

        <div class="mt-2.5 flex items-baseline gap-2">
          <span class="text-3xl font-black text-slate-900 dark:text-white tabular-nums">
            {{ formatNumber(summary.sales_count) }}
          </span>
          <span class="text-xs font-medium text-slate-500">чеков</span>
        </div>

        <div class="mt-3 flex flex-col gap-1 border-t border-slate-100 pt-2.5 text-[11px] text-slate-600 dark:border-slate-800 dark:text-slate-400">
          <div class="flex items-center justify-between">
            <span>Статус чеков:</span>
            <span class="font-bold text-emerald-600 dark:text-emerald-400">Завершённые</span>
          </div>
          <div class="flex items-center justify-between">
            <span>В среднем позиций:</span>
            <strong class="font-bold text-slate-900 dark:text-white tabular-nums">
              {{ summary.sales_count > 0 ? (summary.total_sold_items / summary.sales_count).toFixed(1) : 0 }} шт./чек
            </strong>
          </div>
        </div>
      </div>

      <!-- КАРТОЧКА 4: СРЕДНИЙ ЧЕК -->
      <div class="panel border-l-4 border-l-purple-500 p-4">
        <div class="flex items-center justify-between">
          <span class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
            Средний чек
          </span>
          <BarChart3 class="h-4 w-4 text-purple-600 dark:text-purple-400" />
        </div>

        <div class="mt-2.5 flex items-baseline gap-2">
          <span class="text-2xl font-black text-slate-900 dark:text-white tabular-nums">
            {{ formatMoney(summary.average_check) }}
          </span>
        </div>

        <div class="mt-3 flex flex-col gap-1 border-t border-slate-100 pt-2.5 text-[11px] text-slate-600 dark:border-slate-800 dark:text-slate-400">
          <div class="flex items-center justify-between">
            <span>Расчет:</span>
            <span>Выручка ÷ Чеки</span>
          </div>
          <div class="flex items-center justify-between">
            <span>Период сравнения:</span>
            <span class="text-slate-500">{{ summary.comparison?.period_label || 'Весь период' }}</span>
          </div>
        </div>
      </div>
    </section>

    <!-- ======================================================== -->
    <!-- ВИЗУАЛЬНЫЙ БЛОК: ДИНАМИКА ПРОДАЖ И ЛИДЕРЫ СПРОСА         -->
    <!-- ======================================================== -->
    <section class="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <!-- ГРАФИК ДИНАМИКИ ПРОДАЖ ПО ДНЯМ (2 КОЛОНКИ) -->
      <div class="panel p-4 lg:col-span-2">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3 dark:border-slate-800">
          <div>
            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-700 dark:text-slate-200">
              Динамика продаж по дням
            </h3>
            <p class="text-[11px] text-slate-400">
              Выручка (₸) и количество проданных товаров в выбранном периоде
            </p>
          </div>
          <div class="flex items-center gap-3 text-xs">
            <span class="flex items-center gap-1.5 text-slate-500">
              <span class="h-2.5 w-2.5 rounded-sm bg-emerald-500"></span>
              В наличии
            </span>
            <span class="flex items-center gap-1.5 text-slate-500">
              <span class="h-2.5 w-2.5 rounded-sm bg-amber-500"></span>
              Под заказ
            </span>
          </div>
        </div>

        <!-- Столбчатый график -->
        <div v-if="!salesDynamics.length" class="py-12 text-center text-xs text-slate-400">
          За выбранный период нет данных о динамике продаж
        </div>

        <div v-else class="mt-4">
          <div class="flex h-44 items-end gap-1 overflow-x-auto pb-1 pt-6">
            <div
              v-for="d in salesDynamics"
              :key="d.date"
              class="group relative flex flex-1 min-w-[28px] flex-col items-center justify-end h-full"
            >
              <!-- Тултип при наведении -->
              <div class="pointer-events-none absolute -top-12 z-20 hidden -translate-x-1/2 whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-[10px] text-white shadow-lg group-hover:block dark:bg-slate-800">
                <div class="font-bold">{{ d.day_label }}</div>
                <div>{{ formatMoney(d.revenue) }} · {{ d.units }} шт.</div>
              </div>

              <!-- Составной столбец (В наличии + Под заказ) -->
              <div
                class="w-full max-w-[24px] rounded-t transition-all flex flex-col justify-end"
                :style="{ height: getBarHeight(d.revenue) }"
              >
                <!-- Доля под заказ -->
                <div
                  v-if="d.on_order_units > 0"
                  class="w-full bg-amber-500 rounded-t"
                  :style="{ height: `${(d.on_order_units / (d.units || 1)) * 100}%` }"
                  title="Под заказ"
                ></div>
                <!-- Доля из наличия -->
                <div
                  v-if="d.in_stock_units > 0"
                  class="w-full bg-emerald-500"
                  :class="{ 'rounded-t': d.on_order_units === 0 }"
                  :style="{ height: `${(d.in_stock_units / (d.units || 1)) * 100}%` }"
                  title="Из наличия"
                ></div>
              </div>

              <!-- Подпись даты снизу -->
              <span class="mt-1.5 truncate text-[10px] text-slate-400">
                {{ d.day_label }}
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- ЛИДЕРЫ СПРОСА: ТОП ТОВАРОВ И ТОП РАЗМЕРОВ (1 КОЛОНКА) -->
      <div class="panel p-4 flex flex-col justify-between">
        <!-- Блок 1: Популярные размеры -->
        <div>
          <div class="flex items-center justify-between">
            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-700 dark:text-slate-200">
              Востребованные размеры
            </h3>
            <span class="text-[10px] text-slate-400">Клик для фильтра</span>
          </div>
          <p class="text-[11px] text-slate-400 mb-2.5">
            Размеры с наибольшим объемом продаж
          </p>

          <div v-if="!topSizes.length" class="text-xs text-slate-400 py-3 text-center">
            Нет данных по размерам
          </div>

          <div v-else class="flex flex-wrap gap-1.5">
            <button
              v-for="sz in topSizes"
              :key="sz.size"
              type="button"
              class="flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs transition hover:border-emerald-500"
              :class="selectedSize === sz.size ? 'border-emerald-500 bg-emerald-50 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200' : 'border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300'"
              @click="setSize(selectedSize === sz.size ? '' : sz.size)"
            >
              <span class="font-black">{{ sz.size }}</span>
              <span class="rounded bg-emerald-100 px-1.5 py-0.2 text-[10px] font-bold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                {{ sz.sold_quantity }} шт.
              </span>
            </button>
          </div>
        </div>

        <!-- Блок 2: Топ продаваемых позиций -->
        <div class="mt-4 border-t border-slate-100 pt-3 dark:border-slate-800">
          <h3 class="text-xs font-bold uppercase tracking-wider text-slate-700 dark:text-slate-200">
            Топ товаров
          </h3>
          <p class="text-[11px] text-slate-400 mb-2.5">
            Лидеры по числу проданных единиц
          </p>

          <div v-if="!topProducts.length" class="text-xs text-slate-400 py-3 text-center">
            Нет данных по товарам
          </div>

          <div v-else class="space-y-2">
            <div
              v-for="p in topProducts"
              :key="p.product_id || p.name"
              class="flex items-center justify-between text-xs"
            >
              <div class="min-w-0 pr-2">
                <div class="truncate font-semibold text-slate-800 dark:text-slate-200">{{ p.name }}</div>
                <div class="text-[10px] text-slate-400">{{ p.type }}</div>
              </div>
              <div class="text-right shrink-0">
                <div class="font-bold text-slate-900 dark:text-white tabular-nums">{{ p.sold_quantity }} шт.</div>
                <div class="text-[10px] text-slate-400">{{ formatMoney(p.revenue) }}</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ======================================================== -->
    <!-- ЗОНА 4: ПОДРОБНАЯ АНАЛИТИКА ПО ТОВАРАМ И РАЗМЕРАМ        -->
    <!-- ======================================================== -->
    <section class="panel overflow-hidden">
      <!-- Панель переключения вкладок -->
      <div class="flex flex-col gap-3 border-b border-slate-200 p-4 dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex flex-wrap gap-1.5">
          <!-- Вкладка 1: По видам и размерам (Основная) -->
          <button
            type="button"
            class="flex items-center gap-1.5 rounded-lg px-3.5 py-2 text-xs font-bold transition shadow-sm"
            :class="activeTab === 'types' ? 'bg-emerald-600 text-white shadow-emerald-600/30' : 'bg-slate-100 text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700'"
            @click="activeTab = 'types'"
          >
            <Layers class="h-4 w-4" />
            <span>По видам и размерам ({{ filteredTypes.length }})</span>
          </button>

          <!-- Вкладка 2: По товарам и моделям -->
          <button
            type="button"
            class="flex items-center gap-1.5 rounded-lg px-3.5 py-2 text-xs font-bold transition"
            :class="activeTab === 'products' ? 'bg-emerald-600 text-white shadow-emerald-600/30' : 'bg-slate-100 text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700'"
            @click="activeTab = 'products'"
          >
            <span>По товарам и моделям ({{ filteredProducts.length }})</span>
          </button>

          <!-- Вкладка 3: По категориям -->
          <button
            type="button"
            class="flex items-center gap-1.5 rounded-lg px-3.5 py-2 text-xs font-bold transition"
            :class="activeTab === 'categories' ? 'bg-emerald-600 text-white shadow-emerald-600/30' : 'bg-slate-100 text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700'"
            @click="activeTab = 'categories'"
          >
            <span>По категориям ({{ filteredCategories.length }})</span>
          </button>

          <!-- Вкладка 4: По сменам и сотрудникам -->
          <button
            type="button"
            class="flex items-center gap-1.5 rounded-lg px-3.5 py-2 text-xs font-bold transition"
            :class="activeTab === 'cashiers' ? 'bg-emerald-600 text-white shadow-emerald-600/30' : 'bg-slate-100 text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700'"
            @click="activeTab = 'cashiers'"
          >
            <UserCheck class="h-4 w-4" />
            <span>По сменам и сотрудникам ({{ filteredCashiers.length }})</span>
          </button>
        </div>

        <!-- Управление отображением: сортировка и раскрытие -->
        <div class="flex flex-wrap items-center gap-3 text-xs">
          <!-- Выпадающий список сортировки (как в Excel) -->
          <div class="flex items-center gap-1.5 text-slate-500">
            <span>Сортировка:</span>
            <select
              v-model="sortField"
              class="input h-7 py-0.5 pl-2 pr-6 text-xs font-medium"
            >
              <option value="sold_quantity">По числу продаж</option>
              <option value="revenue">По выручке</option>
              <option value="name">По алфавиту / названию</option>
            </select>
            <button
              type="button"
              class="btn-muted h-7 px-2 text-xs"
              :title="sortDirection === 'desc' ? 'По убыванию (кликните для возрастания)' : 'По возрастанию'"
              @click="sortDirection = sortDirection === 'desc' ? 'asc' : 'desc'"
            >
              {{ sortDirection === 'desc' ? '▼ Убыв.' : '▲ Возр.' }}
            </button>
          </div>

          <!-- Кнопка «Свернуть / Развернуть все» -->
          <div v-if="activeTab === 'types' && filteredTypes.length">
            <button
              type="button"
              class="text-xs font-semibold text-emerald-600 hover:underline dark:text-emerald-400"
              @click="toggleAllTypes"
            >
              {{ allTypesExpanded ? 'Свернуть все размеры' : 'Раскрыть все размеры' }}
            </button>
          </div>
          <div v-else-if="activeTab === 'products' && filteredProducts.length">
            <button
              type="button"
              class="text-xs font-semibold text-emerald-600 hover:underline dark:text-emerald-400"
              @click="toggleAllProductSizes"
            >
              {{ allProductsExpanded ? 'Свернуть все размеры' : 'Раскрыть все размеры' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Содержимое при загрузке -->
      <div v-if="loading" class="p-6">
        <SkeletonBlock custom-class="h-60" />
      </div>

      <!-- ======================================================== -->
      <!-- ВКЛАДКА 1: ПО ВИДАМ И РАЗМЕРАМ (ОСНОВНОЙ ВИД)            -->
      <!-- ======================================================== -->
      <div v-else-if="activeTab === 'types'">
        <EmptyState
          v-if="!filteredTypes.length"
          class="m-6"
          title="Данные не найдены"
          text="По выбранным фильтрам и параметрам поиска товаров не найдено. Попробуйте снять фильтр вида или размера."
        />

        <div v-else class="divide-y divide-slate-200 dark:divide-slate-800">
          <div
            v-for="item in sortedTypes"
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
                  <div class="rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-1 font-bold text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/70 dark:text-emerald-200">
                    Продано: <span class="text-sm font-black">{{ item.sold_quantity }}</span> шт.
                  </div>

                  <!-- Из наличия -->
                  <div class="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-slate-700 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300">
                    <span>✓ Из наличия: </span>
                    <strong class="text-emerald-700 dark:text-emerald-400 font-bold">{{ item.in_stock_sold || 0 }} шт.</strong>
                    <span v-if="item.in_stock_revenue" class="text-slate-400 text-[11px] ml-1">({{ formatMoney(item.in_stock_revenue) }})</span>
                  </div>

                  <!-- Под заказ -->
                  <div class="rounded-lg border border-amber-200 bg-amber-50/80 px-2.5 py-1 text-amber-900 dark:border-amber-800 dark:bg-amber-950/70 dark:text-amber-200">
                    <span>📦 Под заказ: </span>
                    <strong class="font-bold">{{ item.on_order_sold || 0 }} шт.</strong>
                    <span v-if="item.on_order_revenue" class="text-amber-700/70 dark:text-amber-400 text-[11px] ml-1">({{ formatMoney(item.on_order_revenue) }})</span>
                  </div>
                </div>
              </div>

              <!-- Выручка и кнопка раскрытия размеров -->
              <div class="flex items-center justify-between sm:justify-end gap-3 pt-2 lg:pt-0">
                <div class="text-right">
                  <div class="text-lg font-black text-slate-900 dark:text-white tabular-nums">
                    {{ formatMoney(item.revenue) }}
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

            <!-- ВЛОЖЕННАЯ ТАБЛИЦА: ДЕТАЛИЗАЦИЯ ПО КАЖДОМУ РАЗМЕРУ -->
            <div
              v-if="isTypeExpanded(item.type)"
              class="mt-3.5 rounded-xl border border-slate-200/90 bg-slate-50/70 p-3.5 dark:border-slate-800 dark:bg-slate-950/60"
            >
              <div class="mb-2 text-xs font-bold text-slate-800 dark:text-slate-200 flex items-center justify-between">
                <span>Детализация проданных размеров для «{{ item.type }}»:</span>
                <span class="text-[11px] text-slate-500 font-normal">Сортировка по порядку размеров</span>
              </div>

              <div v-if="!(item.sizes || []).length" class="text-xs text-slate-400 py-3 text-center">
                У этого вида товара нет данных по проданным размерам.
              </div>

              <div v-else class="overflow-x-auto rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <table class="w-full text-left text-xs">
                  <thead class="border-b border-slate-200 bg-slate-50 text-[10px] font-semibold uppercase text-slate-500 dark:border-slate-800 dark:bg-slate-950">
                    <tr>
                      <th class="px-3.5 py-2.5">Размер</th>
                      <th class="px-3.5 py-2.5 text-right font-black text-slate-900 dark:text-white">Продано всего</th>
                      <th class="px-3.5 py-2.5 text-right text-emerald-700 dark:text-emerald-300">✓ Из наличия</th>
                      <th class="px-3.5 py-2.5 text-right text-amber-700 dark:text-amber-300">📦 Под заказ</th>
                      <th class="px-3.5 py-2.5 text-right">Выручка (₸)</th>
                      <th class="px-3.5 py-2.5 text-right">Ср. цена шт.</th>
                      <th class="px-3.5 py-2.5 text-right">Доля в виде</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <tr
                      v-for="sz in item.sizes"
                      :key="sz.size"
                      class="hover:bg-slate-50/80 dark:hover:bg-slate-800/40 transition"
                      :class="{ 'bg-indigo-50/50 dark:bg-indigo-950/30': selectedSize && sz.size === selectedSize }"
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
                          {{ formatMoney(sz.in_stock_revenue) }}
                        </div>
                      </td>

                      <!-- Под заказ -->
                      <td class="px-3.5 py-2.5 text-right tabular-nums">
                        <span class="rounded bg-amber-50 border border-amber-200 px-2 py-0.5 text-amber-900 dark:bg-amber-950/50 dark:border-amber-900 dark:text-amber-300 font-semibold">
                          {{ sz.on_order_sold || 0 }} шт.
                        </span>
                        <div v-if="sz.on_order_revenue" class="text-[10px] text-slate-400 mt-0.5">
                          {{ formatMoney(sz.on_order_revenue) }}
                        </div>
                      </td>

                      <!-- Выручка -->
                      <td class="px-3.5 py-2.5 text-right font-black text-slate-900 dark:text-white tabular-nums">
                        {{ formatMoney(sz.revenue) }}
                      </td>

                      <!-- Ср. цена -->
                      <td class="px-3.5 py-2.5 text-right tabular-nums text-slate-500">
                        {{ formatMoney(sz.average_price) }}
                      </td>

                      <!-- Доля в виде -->
                      <td class="px-3.5 py-2.5 text-right tabular-nums text-slate-600 dark:text-slate-400 font-medium">
                        {{ sz.share_in_type }}%
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ======================================================== -->
      <!-- ВКЛАДКА 2: ПО НАИМЕНОВАНИЯМ ТОВАРОВ И МОДЕЛЕЙ            -->
      <!-- ======================================================== -->
      <div v-else-if="activeTab === 'products'">
        <EmptyState
          v-if="!filteredProducts.length"
          class="m-6"
          title="Товары не найдены"
          text="За выбранный период товаров с продажами не найдено. Попробуйте изменить параметры фильтров."
        />

        <div v-else class="divide-y divide-slate-200 dark:divide-slate-800">
          <div
            v-for="product in sortedProducts"
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

                <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                  <span class="rounded-lg bg-emerald-50 border border-emerald-200 px-2.5 py-1 font-bold text-emerald-900 dark:bg-emerald-950/60 dark:border-emerald-800 dark:text-emerald-200">
                    Продано: {{ product.sold_quantity }} шт.
                  </span>
                  <span class="rounded-lg bg-white border border-slate-200 px-2 py-1 text-slate-700 dark:bg-slate-900 dark:border-slate-800 dark:text-slate-300">
                    ✓ В наличии: <strong class="text-emerald-700 dark:text-emerald-400">{{ product.in_stock_sold || 0 }} шт.</strong>
                  </span>
                  <span class="rounded-lg bg-amber-50 border border-amber-200 px-2 py-1 text-amber-900 dark:bg-amber-950/60 dark:border-amber-800 dark:text-amber-200">
                    📦 Под заказ: <strong>{{ product.on_order_sold || 0 }} шт.</strong>
                  </span>
                </div>
              </div>

              <div class="flex items-center justify-between sm:justify-end gap-3 pt-2 lg:pt-0">
                <div class="text-right">
                  <div class="text-base font-bold text-slate-900 dark:text-white tabular-nums">
                    {{ formatMoney(product.revenue) }}
                  </div>
                  <div class="text-[11px] text-slate-400">
                    {{ product.revenue_percent }}% от всей выручки
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
              <div v-if="!(product.sizes || []).length" class="text-xs text-slate-400 py-2 text-center">
                Нет данных по размерам.
              </div>
              <div v-else class="overflow-x-auto rounded-md border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
                <table class="w-full text-left text-xs">
                  <thead class="border-b border-slate-200 bg-slate-50 text-[10px] font-semibold uppercase text-slate-500 dark:border-slate-800 dark:bg-slate-900">
                    <tr>
                      <th class="px-3.5 py-2">Размер</th>
                      <th class="px-3.5 py-2 text-right">Продано</th>
                      <th class="px-3.5 py-2 text-right text-emerald-600 dark:text-emerald-400">✓ В наличии</th>
                      <th class="px-3.5 py-2 text-right text-amber-600 dark:text-amber-400">📦 Под заказ</th>
                      <th class="px-3.5 py-2 text-right">Ср. цена</th>
                      <th class="px-3.5 py-2 text-right">Выручка (₸)</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100 dark:divide-slate-900">
                    <tr
                      v-for="sz in product.sizes"
                      :key="sz.size"
                      class="hover:bg-slate-50/60 dark:hover:bg-slate-900/50"
                      :class="{ 'bg-indigo-50/50 dark:bg-indigo-950/30': selectedSize && sz.size === selectedSize }"
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
                        {{ formatMoney(sz.average_price) }}
                      </td>
                      <td class="px-3.5 py-2 text-right font-bold text-slate-900 dark:text-white tabular-nums">
                        {{ formatMoney(sz.revenue) }}
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ======================================================== -->
      <!-- ВКЛАДКА 3: ПО КАТЕГОРИЯМ КАТАЛОГА                        -->
      <!-- ======================================================== -->
      <div v-else-if="activeTab === 'categories'">
        <EmptyState
          v-if="!filteredCategories.length"
          class="m-6"
          title="Нет данных по категориям"
          text="За выбранный период продаж по категориям не найдено."
        />

        <div v-else class="overflow-x-auto">
          <table class="w-full text-left text-xs">
            <thead class="border-b border-slate-200 bg-slate-50 text-[11px] font-semibold uppercase text-slate-500 dark:border-slate-800 dark:bg-slate-900/80">
              <tr>
                <th class="px-4 py-3">Категория</th>
                <th class="px-4 py-3 text-right font-bold text-slate-900 dark:text-white">Продано всего</th>
                <th class="px-4 py-3 text-right text-emerald-600 dark:text-emerald-400">✓ В наличии</th>
                <th class="px-4 py-3 text-right text-amber-600 dark:text-amber-400">📦 Под заказ</th>
                <th class="px-4 py-3 text-right">Выручка (₸)</th>
                <th class="px-4 py-3 text-right">Доля в продажах</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
              <tr
                v-for="item in sortedCategories"
                :key="item.category_id || item.category_name"
                class="transition hover:bg-slate-50/80 dark:hover:bg-slate-900/40"
              >
                <td class="px-4 py-3 font-bold text-slate-900 dark:text-white">
                  {{ item.category_name }}
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
                <td class="px-4 py-3 text-right font-bold text-slate-900 dark:text-white tabular-nums">
                  {{ formatMoney(item.revenue) }}
                </td>
                <td class="px-4 py-3 text-right font-medium tabular-nums text-slate-700 dark:text-slate-300">
                  {{ item.revenue_percent }}%
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ======================================================== -->
      <!-- ВКЛАДКА 4: ПО СМЕНАМ И СОТРУДНИКАМ                       -->
      <!-- ======================================================== -->
      <div v-else-if="activeTab === 'cashiers'">
        <EmptyState
          v-if="!filteredCashiers.length"
          class="m-6"
          title="Смены и сотрудники не найдены"
          text="За выбранный период данных по продажам сотрудников не найдено."
        />

        <div v-else class="overflow-x-auto">
          <table class="w-full text-left text-xs">
            <thead class="border-b border-slate-200 bg-slate-50 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:border-slate-800 dark:bg-slate-900/60 dark:text-slate-400">
              <tr>
                <th class="px-4 py-3">Сотрудник / Кассир</th>
                <th class="px-4 py-3 text-right">Чеков</th>
                <th class="px-4 py-3 text-right">Продано шт.</th>
                <th class="px-4 py-3 text-right">✓ Из наличия</th>
                <th class="px-4 py-3 text-right">📦 Под заказ</th>
                <th class="px-4 py-3 text-right">Выручка (₸)</th>
                <th class="px-4 py-3 text-right">Средний чек</th>
                <th class="px-4 py-3 text-right">Доля в выручке</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800/60">
              <tr
                v-for="cashier in sortedCashiers"
                :key="cashier.cashier_id || cashier.cashier_name"
                class="hover:bg-slate-50/50 dark:hover:bg-slate-900/30 transition"
              >
                <td class="px-4 py-3 font-bold text-slate-900 dark:text-white">
                  <div class="flex items-center gap-2">
                    <div class="flex h-7 w-7 items-center justify-center rounded-full bg-emerald-100 text-xs font-black text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                      {{ cashier.cashier_name ? cashier.cashier_name.charAt(0).toUpperCase() : 'К' }}
                    </div>
                    <span>{{ cashier.cashier_name }}</span>
                  </div>
                </td>
                <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-800 dark:text-slate-200">
                  {{ cashier.sales_count }}
                </td>
                <td class="px-4 py-3 text-right font-black tabular-nums text-slate-900 dark:text-white">
                  {{ cashier.sold_quantity }} шт.
                </td>
                <td class="px-4 py-3 text-right tabular-nums text-emerald-700 dark:text-emerald-400 font-semibold">
                  {{ cashier.in_stock_sold }} шт.
                </td>
                <td class="px-4 py-3 text-right tabular-nums text-amber-700 dark:text-amber-400 font-semibold">
                  {{ cashier.on_order_sold }} шт.
                </td>
                <td class="px-4 py-3 text-right font-black tabular-nums text-slate-900 dark:text-white">
                  {{ formatMoney(cashier.revenue) }}
                </td>
                <td class="px-4 py-3 text-right tabular-nums text-slate-500 font-medium">
                  {{ formatMoney(cashier.average_check) }}
                </td>
                <td class="px-4 py-3 text-right font-bold tabular-nums text-emerald-700 dark:text-emerald-400">
                  {{ cashier.revenue_percent }}%
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
import {
  BarChart3,
  ChevronDown,
  DatabaseBackup,
  FileSpreadsheet,
  Layers,
  MoreVertical,
  Receipt,
  RefreshCw,
  Search,
  ShoppingBag,
  Sparkles,
  UserCheck,
  X,
} from 'lucide-vue-next'
import api, { apiError } from '../api/client'
import { useToastStore } from '../stores/toasts'
import EmptyState from '../components/ui/EmptyState.vue'
import SkeletonBlock from '../components/ui/SkeletonBlock.vue'
import { formatMoney, formatNumber, money, percent } from '../utils/formatters'

const toast = useToastStore()

const loading = ref(false)
const exportingExcel = ref(false)
const showMoreActions = ref(false)

const activeTab = ref('types') // 'types' | 'products' | 'categories' | 'cashiers'
const activePeriod = ref('month') // 'all' | 'today' | '7days' | 'month' | 'custom'
const orderTypeFilter = ref('') // '' (все) | 'in_stock' | 'on_order'
const categoryFilter = ref(null) // ID категории или null
const selectedType = ref('') // Вид товара ('Поло', 'Брюки'...)
const selectedSize = ref('') // Размер ('116', '122', '140'...)
const searchQuery = ref('')
const customDateFrom = ref('')
const customDateTo = ref('')

// Сортировка таблицы (как в Excel)
const sortField = ref('sold_quantity') // 'sold_quantity' | 'revenue' | 'name'
const sortDirection = ref('desc') // 'desc' | 'asc'

const categories = ref([])

const expandedTypeNames = ref(new Set())
const allTypesExpanded = ref(false)

const expandedProductKeys = ref(new Set())
const allProductsExpanded = ref(false)

const analyticsData = ref({
  summary: {},
  sales_dynamics: [],
  top_products: [],
  top_sizes: [],
  available_types: [],
  available_sizes: [],
  by_type: [],
  by_category: [],
  by_product: [],
  by_cashier: [],
})

const periodPresets = [
  { key: 'today', label: 'Сегодня' },
  { key: '7days', label: '7 дней' },
  { key: 'month', label: 'Этот месяц' },
  { key: 'all', label: 'Все время' },
  { key: 'custom', label: 'Свой период' },
]

const summary = computed(() => analyticsData.value?.summary || {})
const salesDynamics = computed(() => analyticsData.value?.sales_dynamics || [])
const topProducts = computed(() => analyticsData.value?.top_products || [])
const topSizes = computed(() => analyticsData.value?.top_sizes || [])
const byType = computed(() => analyticsData.value?.by_type || [])
const byCategory = computed(() => analyticsData.value?.by_category || [])
const byProduct = computed(() => analyticsData.value?.by_product || [])
const byCashier = computed(() => analyticsData.value?.by_cashier || [])

const availableTypes = computed(() => {
  const serverTypes = analyticsData.value?.available_types || []
  if (serverTypes.length) return serverTypes
  const defaults = ['Поло', 'Брюки', 'Юбка', 'Рубашка', 'Жилет', 'Пиджак', 'Сарафан', 'Блузка', 'Шорты', 'Платье', 'Галстук']
  const fromData = byType.value.map((t) => t.type)
  return Array.from(new Set([...defaults, ...fromData])).filter(Boolean)
})

const availableSizes = computed(() => {
  return analyticsData.value?.available_sizes || []
})

const isFiltered = computed(() => {
  return (
    activePeriod.value !== 'month' ||
    orderTypeFilter.value !== '' ||
    categoryFilter.value !== null ||
    selectedType.value !== '' ||
    selectedSize.value !== '' ||
    searchQuery.value.trim() !== ''
  )
})

const activePeriodLabel = computed(() => {
  const p = periodPresets.find((item) => item.key === activePeriod.value)
  if (activePeriod.value === 'custom') {
    return `${customDateFrom.value || '...'} — ${customDateTo.value || '...'}`
  }
  return p ? p.label : 'Текущий период'
})

const selectedCategoryName = computed(() => {
  if (!categoryFilter.value) return ''
  const cat = categories.value.find((c) => c.id === categoryFilter.value)
  return cat ? cat.name : 'Категория'
})

// Фильтрация на стороне клиента
const filteredTypes = computed(() => {
  let list = byType.value

  if (selectedType.value) {
    list = list.filter((item) => (item.type || '').toLowerCase() === selectedType.value.toLowerCase())
  }

  if (selectedSize.value) {
    list = list.filter((item) =>
      (item.sizes || []).some((sz) => (sz.size || '').toLowerCase() === selectedSize.value.toLowerCase())
    )
  }

  const q = searchQuery.value.trim().toLowerCase()
  if (!q) return list

  return list.filter((item) => {
    const matchType = (item.type || '').toLowerCase().includes(q)
    const matchSize = (item.sizes || []).some((sz) => (sz.size || '').toLowerCase().includes(q))
    return matchType || matchSize
  })
})

const sortedTypes = computed(() => {
  const list = [...filteredTypes.value]
  const field = sortField.value
  const dir = sortDirection.value === 'asc' ? 1 : -1

  return list.sort((a, b) => {
    if (field === 'sold_quantity') {
      return (Number(a.sold_quantity || 0) - Number(b.sold_quantity || 0)) * dir
    }
    if (field === 'revenue') {
      return (Number(a.revenue || 0) - Number(b.revenue || 0)) * dir
    }
    if (field === 'name') {
      return (a.type || '').localeCompare(b.type || '') * dir
    }
    return 0
  })
})

const filteredProducts = computed(() => {
  let list = byProduct.value

  if (selectedType.value) {
    list = list.filter((prod) =>
      (prod.type || '').toLowerCase().includes(selectedType.value.toLowerCase()) ||
      (prod.name || '').toLowerCase().includes(selectedType.value.toLowerCase())
    )
  }

  if (selectedSize.value) {
    list = list.filter((prod) =>
      (prod.sizes || []).some((sz) => (sz.size || '').toLowerCase() === selectedSize.value.toLowerCase())
    )
  }

  const q = searchQuery.value.trim().toLowerCase()
  if (!q) return list

  return list.filter((prod) =>
    (prod.name || '').toLowerCase().includes(q) ||
    (prod.type || '').toLowerCase().includes(q) ||
    (prod.category_name || '').toLowerCase().includes(q) ||
    (prod.color || '').toLowerCase().includes(q) ||
    (prod.sizes || []).some((sz) => (sz.size || '').toLowerCase().includes(q)),
  )
})

const sortedProducts = computed(() => {
  const list = [...filteredProducts.value]
  const field = sortField.value
  const dir = sortDirection.value === 'asc' ? 1 : -1

  return list.sort((a, b) => {
    if (field === 'sold_quantity') {
      return (Number(a.sold_quantity || 0) - Number(b.sold_quantity || 0)) * dir
    }
    if (field === 'revenue') {
      return (Number(a.revenue || 0) - Number(b.revenue || 0)) * dir
    }
    if (field === 'name') {
      return (a.name || '').localeCompare(b.name || '') * dir
    }
    return 0
  })
})

const filteredCategories = computed(() => {
  const q = searchQuery.value.trim().toLowerCase()
  if (!q) return byCategory.value
  return byCategory.value.filter((item) => (item.category_name || '').toLowerCase().includes(q))
})

const sortedCategories = computed(() => {
  const list = [...filteredCategories.value]
  const field = sortField.value
  const dir = sortDirection.value === 'asc' ? 1 : -1

  return list.sort((a, b) => {
    if (field === 'sold_quantity') {
      return (Number(a.sold_quantity || 0) - Number(b.sold_quantity || 0)) * dir
    }
    if (field === 'revenue') {
      return (Number(a.revenue || 0) - Number(b.revenue || 0)) * dir
    }
    if (field === 'name') {
      return (a.category_name || '').localeCompare(b.category_name || '') * dir
    }
    return 0
  })
})

const filteredCashiers = computed(() => {
  const q = searchQuery.value.trim().toLowerCase()
  if (!q) return byCashier.value
  return byCashier.value.filter((item) => (item.cashier_name || '').toLowerCase().includes(q))
})

const sortedCashiers = computed(() => {
  const list = [...filteredCashiers.value]
  const field = sortField.value
  const dir = sortDirection.value === 'asc' ? 1 : -1

  return list.sort((a, b) => {
    if (field === 'sold_quantity') {
      return (Number(a.sold_quantity || 0) - Number(b.sold_quantity || 0)) * dir
    }
    if (field === 'revenue') {
      return (Number(a.revenue || 0) - Number(b.revenue || 0)) * dir
    }
    if (field === 'name') {
      return (a.cashier_name || '').localeCompare(b.cashier_name || '') * dir
    }
    return 0
  })
})

function getBarHeight(revenue) {
  const max = Math.max(...salesDynamics.value.map((d) => d.revenue || 0), 1)
  const ratio = (revenue || 0) / max
  return `${Math.max(6, Math.round(ratio * 100))}%`
}

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

function setType(type) {
  selectedType.value = type
  fetchAnalytics()
}

function setSize(size) {
  selectedSize.value = size
  fetchAnalytics()
}

function setCategory(id) {
  categoryFilter.value = id
  fetchAnalytics()
}

let searchDebounceTimer = null
function onSearchInput() {
  clearTimeout(searchDebounceTimer)
  searchDebounceTimer = setTimeout(() => {
    fetchAnalytics()
  }, 350)
}

function clearSearch() {
  searchQuery.value = ''
  fetchAnalytics()
}

function resetFilters() {
  activePeriod.value = 'month'
  orderTypeFilter.value = ''
  categoryFilter.value = null
  selectedType.value = ''
  selectedSize.value = ''
  searchQuery.value = ''
  const today = new Date()
  const firstDay = new Date(today.getFullYear(), today.getMonth(), 1)
  customDateFrom.value = firstDay.toISOString().split('T')[0]
  customDateTo.value = today.toISOString().split('T')[0]
  fetchAnalytics()
}

async function fetchCategories() {
  try {
    const { data } = await api.get('/categories')
    categories.value = Array.isArray(data) ? data : data.data || []
  } catch (err) {
    // Тихо игнорируем ошибку загрузки категорий
  }
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
    if (categoryFilter.value) {
      params.category_id = categoryFilter.value
    }
    if (selectedType.value) {
      params.type = selectedType.value
    }
    if (selectedSize.value) {
      params.size = selectedSize.value
    }
    if (searchQuery.value.trim()) {
      params.search = searchQuery.value.trim()
    }

    const { data } = await api.get('/reports/analytics', { params })
    analyticsData.value = data

    // По умолчанию списки свернуты (согласно требованиям)
    allTypesExpanded.value = false
    expandedTypeNames.value = new Set()
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
  return expandedProductKeys.value.has(getProductKey(product))
}

function toggleProductSizes(product) {
  const key = getProductKey(product)
  const set = new Set(expandedProductKeys.value)
  if (set.has(key)) {
    set.delete(key)
  } else {
    set.add(key)
  }
  expandedProductKeys.value = set
}

function toggleAllProductSizes() {
  if (allProductsExpanded.value) {
    expandedProductKeys.value = new Set()
    allProductsExpanded.value = false
  } else {
    const set = new Set()
    byProduct.value.forEach((p) => set.add(getProductKey(p)))
    expandedProductKeys.value = set
    allProductsExpanded.value = true
  }
}

async function downloadExcelReport() {
  exportingExcel.value = true
  try {
    const params = new URLSearchParams()
    if (activePeriod.value !== 'all') {
      if (customDateFrom.value) params.set('date_from', customDateFrom.value)
      if (customDateTo.value) params.set('date_to', customDateTo.value)
    }
    if (orderTypeFilter.value === 'on_order') params.set('is_on_order', '1')
    if (orderTypeFilter.value === 'in_stock') params.set('is_on_order', '0')
    if (categoryFilter.value) params.set('category_id', String(categoryFilter.value))
    if (selectedType.value) params.set('type', selectedType.value)
    if (selectedSize.value) params.set('size', selectedSize.value)

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
  } finally {
    exportingExcel.value = false
  }
}

async function triggerRebuildStats() {
  try {
    const { data } = await api.post('/reports/rebuild-statistics')
    toast.push(data.message || 'Статистика успешно пересчитана')
    fetchAnalytics()
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
  // Инициализация периода «Этот месяц»
  const today = new Date()
  const firstDay = new Date(today.getFullYear(), today.getMonth(), 1)
  customDateFrom.value = firstDay.toISOString().split('T')[0]
  customDateTo.value = today.toISOString().split('T')[0]

  fetchCategories()
  fetchAnalytics()
})
</script>
