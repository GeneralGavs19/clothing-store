<template>
  <div class="space-y-5">
    <!-- Верхняя панель управления: фильтры по дате и экспорт -->
    <section class="panel p-4">
      <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
          <h2 class="text-lg font-bold text-slate-900 dark:text-white">Статистика и аналитика продаж</h2>
          <p class="text-xs text-slate-500">
            Подсчет вещей (Было = Продано + Остаток), продажи по видам, категориям, названиям и размерам
          </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
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
            class="btn-muted h-8 px-2.5 text-xs"
            title="Скачать Excel отчет"
            @click="download('/reports/sales.xlsx', 'sales-report.xlsx')"
          >
            <Download class="h-3.5 w-3.5" />
            Excel
          </button>

          <button
            type="button"
            class="btn-muted h-8 px-2.5 text-xs"
            title="Скачать Backup JSON"
            @click="download('/reports/backup', 'store-backup.json')"
          >
            <DatabaseBackup class="h-3.5 w-3.5" />
            Backup
          </button>
        </div>
      </div>
    </section>

    <!-- Сводные карточки: Общий подсчет вещей и Общая статистика продаж -->
    <section class="grid grid-cols-2 gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
      <div class="panel border-emerald-200 bg-emerald-50/50 p-3.5 dark:border-emerald-900/60 dark:bg-emerald-950/20">
        <div class="text-[11px] font-medium text-emerald-800 dark:text-emerald-300">Было всего вещей</div>
        <div class="mt-1 text-2xl font-black text-emerald-700 dark:text-emerald-300 tabular-nums">
          {{ summary.total_initial_items || 0 }} <span class="text-xs font-normal">шт.</span>
        </div>
        <div class="mt-0.5 text-[10px] text-emerald-600 dark:text-emerald-400">Продано + Остаток</div>
      </div>

      <div class="panel p-3.5">
        <div class="text-[11px] font-medium text-slate-500">Продано вещей</div>
        <div class="mt-1 text-2xl font-black text-slate-900 dark:text-white tabular-nums">
          {{ summary.total_sold_items || 0 }} <span class="text-xs font-normal text-slate-500">шт.</span>
        </div>
        <div class="mt-0.5 text-[10px] text-slate-500">За выбранный период</div>
      </div>

      <div class="panel p-3.5">
        <div class="text-[11px] font-medium text-slate-500">Текущий остаток</div>
        <div class="mt-1 text-2xl font-black text-slate-900 dark:text-white tabular-nums">
          {{ summary.total_stock_items || 0 }} <span class="text-xs font-normal text-slate-500">шт.</span>
        </div>
        <div class="mt-0.5 text-[10px] text-slate-500">В магазине и на складе</div>
      </div>

      <div class="panel border-sky-200 bg-sky-50/40 p-3.5 dark:border-sky-900/50 dark:bg-sky-950/20">
        <div class="text-[11px] font-medium text-sky-800 dark:text-sky-300">Общая выручка</div>
        <div class="mt-1 text-xl font-bold text-sky-950 dark:text-sky-100 tabular-nums sm:text-2xl">
          {{ money(summary.total_revenue) }}
        </div>
        <div class="mt-0.5 text-[10px] text-sky-600 dark:text-sky-400">Сумма всех продаж</div>
      </div>

      <div class="panel border-teal-200 bg-teal-50/40 p-3.5 dark:border-teal-900/50 dark:bg-teal-950/20">
        <div class="text-[11px] font-medium text-teal-800 dark:text-teal-300">Общая прибыль</div>
        <div class="mt-1 text-xl font-bold text-teal-950 dark:text-teal-100 tabular-nums sm:text-2xl">
          {{ money(summary.total_profit) }}
        </div>
        <div class="mt-0.5 text-[10px] text-teal-600 dark:text-teal-400">После себестоимости</div>
      </div>

      <div class="panel p-3.5">
        <div class="text-[11px] font-medium text-slate-500">Количество чеков</div>
        <div class="mt-1 text-2xl font-bold text-slate-900 dark:text-white tabular-nums">
          {{ summary.sales_count || 0 }}
        </div>
        <div class="mt-0.5 text-[10px] text-slate-500">Всего оформлено</div>
      </div>

      <div class="panel p-3.5">
        <div class="text-[11px] font-medium text-slate-500">Средний чек</div>
        <div class="mt-1 text-lg font-bold text-slate-900 dark:text-white tabular-nums sm:text-xl">
          {{ money(summary.average_check) }}
        </div>
        <div class="mt-0.5 text-[10px] text-slate-500">Выручка / Чеки</div>
      </div>
    </section>

    <!-- Основная секция аналитики: Переключение вкладок -->
    <section class="panel overflow-hidden">
      <!-- Табы -->
      <div class="flex flex-col gap-3 border-b border-slate-200 p-4 dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex flex-wrap gap-1">
          <button
            type="button"
            class="flex items-center gap-1.5 rounded-lg px-3.5 py-2 text-xs font-semibold transition"
            :class="activeTab === 'types' ? 'bg-emerald-500 text-white shadow' : 'bg-slate-100 text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700'"
            @click="activeTab = 'types'"
          >
            <span>По видам ({{ byType.length }})</span>
          </button>

          <button
            type="button"
            class="flex items-center gap-1.5 rounded-lg px-3.5 py-2 text-xs font-semibold transition"
            :class="activeTab === 'categories' ? 'bg-emerald-500 text-white shadow' : 'bg-slate-100 text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700'"
            @click="activeTab = 'categories'"
          >
            <span>По категориям ({{ byCategory.length }})</span>
          </button>

          <button
            type="button"
            class="flex items-center gap-1.5 rounded-lg px-3.5 py-2 text-xs font-semibold transition"
            :class="activeTab === 'products' ? 'bg-emerald-500 text-white shadow' : 'bg-slate-100 text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700'"
            @click="activeTab = 'products'"
          >
            <span>По названию и размерам ({{ byProduct.length }})</span>
          </button>
        </div>

        <div class="w-full sm:w-64">
          <input
            v-model="tabSearch"
            class="input h-9 text-xs"
            placeholder="Поиск в таблице..."
          />
        </div>
      </div>

      <!-- ВКЛАДКА 1: ПО ВИДАМ -->
      <div v-if="activeTab === 'types'">
        <div class="p-4 bg-slate-50/50 dark:bg-slate-900/30 text-xs text-slate-500 border-b border-slate-200 dark:border-slate-800">
          Подсчет всех вещей сгруппирован по виду (например: Футболки, Худи, Джинсы). Формула: <span class="font-semibold text-slate-700 dark:text-slate-300">Было = Продано + Остаток</span>.
        </div>

        <div v-if="loading" class="p-6">
          <SkeletonBlock custom-class="h-44" />
        </div>
        <EmptyState v-else-if="!filteredTypes.length" class="m-6" title="Нет данных по видам" text="За выбранный период продаж по видам не найдено." />
        <div v-else class="overflow-x-auto">
          <table class="w-full text-left text-xs">
            <thead class="border-b border-slate-200 bg-slate-50 text-[11px] font-semibold uppercase text-slate-500 dark:border-slate-800 dark:bg-slate-900/80">
              <tr>
                <th class="px-4 py-3">Вид товара</th>
                <th class="px-4 py-3 text-right">Было всего</th>
                <th class="px-4 py-3 text-right text-emerald-600 dark:text-emerald-400">Продано</th>
                <th class="px-4 py-3 text-right">Остаток</th>
                <th class="px-4 py-3 text-right">Выручка</th>
                <th class="px-4 py-3 text-right">Прибыль</th>
                <th class="px-4 py-3 text-right">Доля в продажах</th>
                <th class="px-4 py-3">Прогресс продаж</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
              <tr
                v-for="item in filteredTypes"
                :key="item.type"
                class="transition hover:bg-slate-50/80 dark:hover:bg-slate-900/40"
              >
                <td class="px-4 py-3 font-semibold text-slate-900 dark:text-white">
                  {{ item.type }}
                </td>
                <td class="px-4 py-3 text-right font-bold text-slate-900 dark:text-slate-100 tabular-nums">
                  {{ item.initial_quantity }} шт.
                </td>
                <td class="px-4 py-3 text-right font-bold text-emerald-600 dark:text-emerald-400 tabular-nums">
                  {{ item.sold_quantity }} шт.
                </td>
                <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-400">
                  {{ item.stock_quantity }} шт.
                </td>
                <td class="px-4 py-3 text-right font-semibold text-slate-900 dark:text-white tabular-nums">
                  {{ money(item.revenue) }}
                </td>
                <td class="px-4 py-3 text-right font-semibold text-teal-600 dark:text-teal-400 tabular-nums">
                  {{ money(item.profit) }}
                </td>
                <td class="px-4 py-3 text-right font-medium tabular-nums text-slate-700 dark:text-slate-300">
                  {{ item.revenue_percent }}%
                </td>
                <td class="px-4 py-3 w-40">
                  <div class="h-2 w-full rounded-full bg-slate-200 dark:bg-slate-800 overflow-hidden">
                    <div
                      class="h-full rounded-full bg-emerald-500 transition-all duration-300"
                      :style="{ width: percent(item.sold_quantity, item.initial_quantity || 1) }"
                    />
                  </div>
                  <div class="mt-0.5 text-[10px] text-slate-400 text-right">
                    {{ percent(item.sold_quantity, item.initial_quantity || 1) }}
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ВКЛАДКА 2: ПО КАТЕГОРИЯМ -->
      <div v-else-if="activeTab === 'categories'">
        <div class="p-4 bg-slate-50/50 dark:bg-slate-900/30 text-xs text-slate-500 border-b border-slate-200 dark:border-slate-800">
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
                <th class="px-4 py-3 text-right text-emerald-600 dark:text-emerald-400">Продано</th>
                <th class="px-4 py-3 text-right">Остаток</th>
                <th class="px-4 py-3 text-right">Выручка</th>
                <th class="px-4 py-3 text-right">Прибыль</th>
                <th class="px-4 py-3 text-right">Доля в продажах</th>
                <th class="px-4 py-3">Прогресс продаж</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
              <tr
                v-for="item in filteredCategories"
                :key="item.category_id || item.category_name"
                class="transition hover:bg-slate-50/80 dark:hover:bg-slate-900/40"
              >
                <td class="px-4 py-3 font-semibold text-slate-900 dark:text-white">
                  {{ item.category_name }}
                </td>
                <td class="px-4 py-3 text-right font-bold text-slate-900 dark:text-slate-100 tabular-nums">
                  {{ item.initial_quantity }} шт.
                </td>
                <td class="px-4 py-3 text-right font-bold text-emerald-600 dark:text-emerald-400 tabular-nums">
                  {{ item.sold_quantity }} шт.
                </td>
                <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-400">
                  {{ item.stock_quantity }} шт.
                </td>
                <td class="px-4 py-3 text-right font-semibold text-slate-900 dark:text-white tabular-nums">
                  {{ money(item.revenue) }}
                </td>
                <td class="px-4 py-3 text-right font-semibold text-teal-600 dark:text-teal-400 tabular-nums">
                  {{ money(item.profit) }}
                </td>
                <td class="px-4 py-3 text-right font-medium tabular-nums text-slate-700 dark:text-slate-300">
                  {{ item.revenue_percent }}%
                </td>
                <td class="px-4 py-3 w-40">
                  <div class="h-2 w-full rounded-full bg-slate-200 dark:bg-slate-800 overflow-hidden">
                    <div
                      class="h-full rounded-full bg-emerald-500 transition-all duration-300"
                      :style="{ width: percent(item.sold_quantity, item.initial_quantity || 1) }"
                    />
                  </div>
                  <div class="mt-0.5 text-[10px] text-slate-400 text-right">
                    {{ percent(item.sold_quantity, item.initial_quantity || 1) }}
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ВКЛАДКА 3: ПО НАЗВАНИЮ, А НАЗВАНИЕ ПО РАЗМЕРУ -->
      <div v-else-if="activeTab === 'products'">
        <div class="p-4 bg-slate-50/50 dark:bg-slate-900/30 text-xs text-slate-500 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
          <span>
            Детализация по каждому товару (по названию). Нажмите <span class="font-semibold text-slate-700 dark:text-slate-300">«Размеры ▼»</span>, чтобы раскрыть статистику по каждому отдельному размеру.
          </span>
          <button
            type="button"
            class="text-xs text-emerald-600 hover:underline dark:text-emerald-400 font-medium shrink-0 ml-2"
            @click="toggleAllSizes"
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
            class="transition hover:bg-slate-50/50 dark:hover:bg-slate-900/30"
          >
            <!-- Главная строка товара -->
            <div class="flex flex-col gap-3 p-4 lg:flex-row lg:items-center lg:justify-between">
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

                <div class="mt-2 flex flex-wrap items-center gap-4 text-xs text-slate-600 dark:text-slate-300">
                  <span class="rounded bg-slate-100 px-2 py-1 font-semibold dark:bg-slate-800">
                    Было учтено: <strong class="text-emerald-600 dark:text-emerald-400">{{ product.initial_quantity }}</strong> шт.
                  </span>
                  <span>Продано: <strong class="text-slate-900 dark:text-white">{{ product.sold_quantity }}</strong> шт.</span>
                  <span>Остаток: <strong class="text-slate-900 dark:text-white">{{ product.stock_quantity }}</strong> шт.</span>
                  <span class="text-slate-400">·</span>
                  <span>Выручка: <strong class="text-emerald-600 dark:text-emerald-400">{{ money(product.revenue) }}</strong></span>
                  <span v-if="product.profit">Прибыль: <strong class="text-teal-600 dark:text-teal-400">{{ money(product.profit) }}</strong></span>
                </div>
              </div>

              <div class="flex items-center gap-2">
                <button
                  type="button"
                  class="flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:border-emerald-400 hover:text-emerald-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:border-emerald-500"
                  @click="toggleProductSizes(product)"
                >
                  <Layers class="h-3.5 w-3.5 text-emerald-500" />
                  Размеры ({{ (product.sizes || []).length }})
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
              class="border-t border-slate-200 bg-slate-50/70 p-4 dark:border-slate-800 dark:bg-slate-900/60"
            >
              <div class="mb-2 text-xs font-semibold text-slate-700 dark:text-slate-300 flex items-center gap-1.5">
                <span>Детализация товара «{{ product.name }}» по размерам:</span>
              </div>

              <div v-if="!(product.sizes || []).length" class="text-xs text-slate-400 py-2">
                Нет данных по размерам.
              </div>

              <div v-else class="overflow-x-auto rounded-md border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
                <table class="w-full text-left text-xs">
                  <thead class="border-b border-slate-200 bg-slate-50 text-[10px] font-semibold uppercase text-slate-500 dark:border-slate-800 dark:bg-slate-900">
                    <tr>
                      <th class="px-3.5 py-2">Размер</th>
                      <th class="px-3.5 py-2 text-right">Было учтено</th>
                      <th class="px-3.5 py-2 text-right text-emerald-600 dark:text-emerald-400">Продано</th>
                      <th class="px-3.5 py-2 text-right">В магазине</th>
                      <th class="px-3.5 py-2 text-right">На складе</th>
                      <th class="px-3.5 py-2 text-right">Всего остаток</th>
                      <th class="px-3.5 py-2 text-right">Цена продажи</th>
                      <th class="px-3.5 py-2 text-right">Выручка</th>
                      <th class="px-3.5 py-2 text-right">Прибыль</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100 dark:divide-slate-900">
                    <tr
                      v-for="sz in product.sizes"
                      :key="sz.variant_id || sz.size"
                      class="hover:bg-slate-50/60 dark:hover:bg-slate-900/50"
                    >
                      <td class="px-3.5 py-2.5 font-bold text-slate-900 dark:text-white">
                        <span class="inline-block rounded border border-slate-200 bg-slate-50 px-2 py-0.5 text-xs dark:border-slate-700 dark:bg-slate-800">
                          {{ sz.size }}
                        </span>
                        <span v-if="sz.sku" class="ml-1.5 text-[10px] font-normal text-slate-400">{{ sz.sku }}</span>
                      </td>
                      <td class="px-3.5 py-2.5 text-right font-bold text-slate-900 dark:text-slate-100 tabular-nums">
                        {{ sz.initial_quantity }} шт.
                      </td>
                      <td class="px-3.5 py-2.5 text-right font-bold text-emerald-600 dark:text-emerald-400 tabular-nums">
                        {{ sz.sold_quantity }} шт.
                      </td>
                      <td class="px-3.5 py-2.5 text-right tabular-nums text-slate-600 dark:text-slate-400">
                        {{ sz.stock_store ?? 0 }} шт.
                      </td>
                      <td class="px-3.5 py-2.5 text-right tabular-nums text-slate-600 dark:text-slate-400">
                        {{ sz.stock_warehouse ?? 0 }} шт.
                      </td>
                      <td class="px-3.5 py-2.5 text-right font-semibold tabular-nums text-slate-800 dark:text-slate-200">
                        {{ sz.stock_quantity }} шт.
                      </td>
                      <td class="px-3.5 py-2.5 text-right tabular-nums text-slate-600 dark:text-slate-400">
                        {{ money(sz.sale_price) }}
                      </td>
                      <td class="px-3.5 py-2.5 text-right font-semibold text-slate-900 dark:text-white tabular-nums">
                        {{ money(sz.revenue) }}
                      </td>
                      <td class="px-3.5 py-2.5 text-right font-semibold text-teal-600 dark:text-teal-400 tabular-nums">
                        {{ money(sz.profit) }}
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
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
const customDateFrom = ref('')
const customDateTo = ref('')

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
  return byType.value.filter((item) => (item.type || '').toLowerCase().includes(q))
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

async function fetchAnalytics() {
  loading.value = true
  try {
    const params = {}
    if (activePeriod.value !== 'all') {
      if (customDateFrom.value) params.date_from = customDateFrom.value
      if (customDateTo.value) params.date_to = customDateTo.value
    }
    const { data } = await api.get('/reports/analytics', { params })
    analyticsData.value = data
  } catch (error) {
    toast.push(apiError(error), 'error')
  } finally {
    loading.value = false
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

function toggleAllSizes() {
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
