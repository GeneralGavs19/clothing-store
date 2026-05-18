<template>
  <div class="space-y-5">
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
      <SkeletonBlock v-if="dashboard.loading" v-for="i in 4" :key="i" />
      <template v-else>
        <StatCard
          title="Прибыль"
          :value="money(summary.total_profit)"
          hint="Суммарная прибыль по всем продажам"
          to="/reports"
          tone="emerald"
        >
          <CircleDollarSign class="h-4 w-4" />
        </StatCard>
        <StatCard
          title="Продажи"
          :value="summary.approved_sales || 0"
          hint="Всего оформленных продаж"
          to="/sales"
          tone="sky"
        >
          <ReceiptText class="h-4 w-4" />
        </StatCard>
        <StatCard
          title="Сегодня"
          :value="summary.today_sales || 0"
          :hint="todayHint"
          to="/sales"
          tone="amber"
        >
          <Clock3 class="h-4 w-4" />
        </StatCard>
        <StatCard
          title="Низкий остаток"
          :value="summary.low_stock || 0"
          hint="Товары ниже порога — нужно пополнить"
          :to="{ path: '/products', query: { status: 'low_stock' } }"
          tone="rose"
        >
          <TriangleAlert class="h-4 w-4" />
        </StatCard>
      </template>
    </div>

    <section
      v-if="!dashboard.loading && lowStockProducts.length"
      class="panel border-amber-200 bg-amber-50/50 p-4 dark:border-amber-900 dark:bg-amber-950/20"
    >
      <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <div>
          <h2 class="font-semibold text-amber-900 dark:text-amber-100">Требуют внимания</h2>
          <p class="text-xs text-amber-800/80 dark:text-amber-200/80">Остаток на складе и витрине ниже порога</p>
        </div>
        <RouterLink
          class="text-sm font-medium text-amber-800 underline-offset-2 hover:underline dark:text-amber-200"
          :to="{ path: '/products', query: { status: 'low_stock' } }"
        >
          Все товары →
        </RouterLink>
      </div>
      <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
        <RouterLink
          v-for="product in lowStockProducts"
          :key="product.id"
          :to="{ path: '/products', query: { search: product.sku } }"
          class="flex items-center justify-between rounded-md border border-amber-200/80 bg-white px-3 py-2 text-sm transition hover:border-amber-400 dark:border-amber-900 dark:bg-slate-950 dark:hover:border-amber-700"
        >
          <span class="truncate font-medium">{{ product.name }}</span>
          <span class="shrink-0 text-xs text-amber-800 dark:text-amber-200">
            {{ product.display_quantity + product.stock_quantity }} шт.
          </span>
        </RouterLink>
      </div>
    </section>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-[1.35fr_0.65fr]">
      <section class="panel p-4">
        <div class="mb-4 flex items-center justify-between gap-3">
          <div>
            <h2 class="font-semibold leading-none">Продажи по дням</h2>
            <p class="mt-1 text-xs text-slate-500">Последние 14 дней · зелёный столбец — объём продаж за день</p>
          </div>
          <button type="button" class="btn-muted h-9 shrink-0 px-3" title="Обновить данные" @click="refresh">
            <RefreshCw class="h-4 w-4" />
            Обновить
          </button>
        </div>
        <div v-if="!salesByDay.length" class="h-64">
          <EmptyState title="Продаж пока нет" text="После оформления продаж график заполнится автоматически." />
        </div>
        <div v-else class="overflow-x-auto rounded-md bg-slate-50 p-3 dark:bg-slate-900">
          <SalesDayChart :days="salesByDay" />
        </div>
      </section>

      <section class="panel p-4">
        <h2 class="mb-1 font-semibold">Склад</h2>
        <p class="mb-4 text-xs text-slate-500">Текущие остатки по всем товарам</p>
        <div class="space-y-3">
          <RouterLink
            to="/products"
            class="flex items-center justify-between rounded-md bg-slate-50 p-3 transition hover:bg-slate-100 dark:bg-slate-900 dark:hover:bg-slate-800"
          >
            <span class="text-sm text-slate-500 dark:text-slate-400">На складе</span>
            <span class="font-semibold">{{ summary.stock_units || 0 }} шт.</span>
          </RouterLink>
          <RouterLink
            to="/products"
            class="flex items-center justify-between rounded-md bg-slate-50 p-3 transition hover:bg-slate-100 dark:bg-slate-900 dark:hover:bg-slate-800"
          >
            <span class="text-sm text-slate-500 dark:text-slate-400">На витрине</span>
            <span class="font-semibold">{{ summary.display_units || 0 }} шт.</span>
          </RouterLink>
          <RouterLink
            to="/products"
            class="flex items-center justify-between rounded-md bg-slate-50 p-3 transition hover:bg-slate-100 dark:bg-slate-900 dark:hover:bg-slate-800"
          >
            <span class="text-sm text-slate-500 dark:text-slate-400">Всего товаров</span>
            <span class="font-semibold">{{ summary.products || 0 }}</span>
          </RouterLink>
        </div>
      </section>
    </div>

    <div class="grid gap-5 xl:grid-cols-3">
      <section class="panel p-4">
        <h2 class="mb-1 font-semibold">Самые продаваемые</h2>
        <p class="mb-4 text-xs text-slate-500">За последние 14 дней</p>
        <EmptyState v-if="!topProducts.length" title="Нет данных" />
        <div v-else class="space-y-3">
          <div v-for="(item, index) in topProducts" :key="item.id" class="flex items-center gap-3">
            <span class="w-5 shrink-0 text-xs font-semibold text-slate-400">{{ index + 1 }}</span>
            <div class="h-10 w-10 shrink-0 overflow-hidden rounded-md bg-slate-100 dark:bg-slate-800">
              <img
                v-if="item.photo_url"
                :src="photoUrl(item.photo_url)"
                class="h-full w-full object-cover"
                :alt="item.name"
                loading="lazy"
              />
            </div>
            <div class="min-w-0 flex-1">
              <div class="truncate text-sm font-medium">{{ item.name }}</div>
              <div class="text-xs text-slate-500">{{ item.quantity }} шт. · {{ money(item.revenue) }}</div>
            </div>
          </div>
        </div>
      </section>

      <section class="panel p-4">
        <h2 class="mb-1 font-semibold">Доход по категориям</h2>
        <p class="mb-4 text-xs text-slate-500">За последние 14 дней</p>
        <EmptyState v-if="!categoryRevenue.length" title="Нет данных" />
        <div v-else class="space-y-3">
          <div v-for="item in categoryRevenue" :key="item.name">
            <div class="mb-1 flex justify-between text-sm">
              <span class="truncate">{{ item.name }}</span>
              <span class="font-medium">{{ money(item.revenue) }}</span>
            </div>
            <div class="h-2 rounded-full bg-slate-100 dark:bg-slate-800">
              <div class="h-2 rounded-full bg-sky-500" :style="{ width: percent(item.revenue, maxCategoryRevenue) }" />
            </div>
          </div>
        </div>
      </section>

      <section class="panel p-4">
        <h2 class="mb-1 font-semibold">Кассиры</h2>
        <p class="mb-4 text-xs text-slate-500">Оформленные и ожидающие продажи</p>
        <EmptyState v-if="!cashiers.length" title="Кассиры не найдены" />
        <div v-else class="space-y-3">
          <div v-for="cashier in cashiers" :key="cashier.id" class="rounded-md bg-slate-50 p-3 dark:bg-slate-900">
            <div class="flex items-center justify-between gap-3">
              <span class="truncate text-sm font-medium">{{ cashier.name }}</span>
              <span class="badge bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                {{ cashier.approved_sales_count }} прод.
              </span>
            </div>
            <div v-if="cashier.pending_sales_count > 0" class="mt-1 text-xs text-amber-600 dark:text-amber-400">
              Ожидают подтверждения: {{ cashier.pending_sales_count }}
            </div>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted } from 'vue'
import { RefreshCw } from 'lucide-vue-next'
import { RouterLink } from 'vue-router'
import { apiError } from '../api/client'
import { resolveApiOrigin } from '../config/api'
import { createIcon, icons } from '../plugins/icons'
import { useDashboardStore } from '../stores/dashboard'
import { useToastStore } from '../stores/toasts'
import SalesDayChart from '../components/SalesDayChart.vue'
import EmptyState from '../components/ui/EmptyState.vue'
import SkeletonBlock from '../components/ui/SkeletonBlock.vue'
import StatCard from '../components/StatCard.vue'

const CircleDollarSign = createIcon(icons.CircleDollarSign)
const ReceiptText = createIcon(icons.ReceiptText)
const Clock3 = createIcon(icons.Clock3)
const TriangleAlert = createIcon(icons.TriangleAlert)

const dashboard = useDashboardStore()
const toast = useToastStore()
let timer

const apiBase = computed(() => resolveApiOrigin())

const summary = computed(() => dashboard.data?.summary || {})
const salesByDay = computed(() => dashboard.data?.sales_by_day || [])
const topProducts = computed(() => dashboard.data?.top_products || [])
const categoryRevenue = computed(() => dashboard.data?.category_revenue || [])
const cashiers = computed(() => dashboard.data?.cashier_activity || [])
const lowStockProducts = computed(() => dashboard.data?.low_stock_products || [])
const maxCategoryRevenue = computed(() => Math.max(...categoryRevenue.value.map((item) => Number(item.revenue)), 1))

const todayHint = computed(() => {
  const rev = money(summary.value.today_revenue)
  const items = summary.value.today_items_sold || 0
  return `Выручка ${rev} · ${items} шт.`
})

function photoUrl(path) {
  if (!path) return ''
  return path.startsWith('http') ? path : `${apiBase.value}${path}`
}

function money(value) {
  return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'KZT', maximumFractionDigits: 0 }).format(
    Number(value || 0),
  )
}

function percent(value, max) {
  return `${Math.max(3, (Number(value || 0) / max) * 100)}%`
}

async function refresh() {
  try {
    await dashboard.fetch()
  } catch (error) {
    toast.push(apiError(error), 'error')
  }
}

onMounted(() => {
  refresh()
  timer = window.setInterval(refresh, 10000)
})

onUnmounted(() => window.clearInterval(timer))
</script>
