<template>
  <div class="space-y-5">
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
      <SkeletonBlock v-if="dashboard.loading" v-for="i in 4" :key="i" />
      <template v-else>
        <StatCard title="Прибыль" :value="money(summary.total_profit)" tone="emerald">
          <CircleDollarSign class="h-5 w-5" />
        </StatCard>
        <StatCard title="Продажи" :value="summary.approved_sales || 0" tone="sky">
          <ReceiptText class="h-5 w-5" />
        </StatCard>
        <StatCard title="Сегодня" :value="summary.today_sales || 0" tone="amber">
          <Clock3 class="h-5 w-5" />
        </StatCard>
        <StatCard title="Низкий остаток" :value="summary.low_stock || 0" tone="rose">
          <TriangleAlert class="h-5 w-5" />
        </StatCard>
      </template>
    </div>

    <div class="grid gap-5 xl:grid-cols-[1.35fr_0.65fr]">
      <section class="panel p-4">
        <div class="mb-4 flex items-center justify-between gap-3">
          <h2 class="font-semibold">Продажи по дням (14 дней)</h2>
          <button class="btn-muted h-9 px-3" @click="refresh"><RefreshCw class="h-4 w-4" />Обновить</button>
        </div>
        <div v-if="!salesByDay.length" class="h-64">
          <EmptyState title="Продаж пока нет" text="После оформления продаж график заполнится автоматически." />
        </div>
        <div v-else class="flex h-64 items-end gap-2 overflow-hidden rounded-md bg-slate-50 p-4 dark:bg-slate-900">
          <div v-for="day in salesByDay" :key="day.day" class="flex min-w-0 flex-1 flex-col items-center gap-2">
            <div class="flex w-full flex-1 items-end">
              <div class="w-full rounded-t-md bg-emerald-500" :style="{ height: barHeight(day.revenue) }" />
            </div>
            <span class="text-center text-[11px] leading-tight text-slate-500 dark:text-slate-400">
              <span class="block">{{ day.label }}</span>
              <span class="block text-[10px]">{{ day.sales }} прод. · {{ day.items_sold }} шт.</span>
            </span>
          </div>
        </div>
      </section>

      <section class="panel p-4">
        <h2 class="mb-4 font-semibold">Склад</h2>
        <div class="space-y-3">
          <div class="flex items-center justify-between rounded-md bg-slate-50 p-3 dark:bg-slate-900">
            <span class="text-sm text-slate-500 dark:text-slate-400">На складе</span>
            <span class="font-semibold">{{ summary.stock_units || 0 }}</span>
          </div>
          <div class="flex items-center justify-between rounded-md bg-slate-50 p-3 dark:bg-slate-900">
            <span class="text-sm text-slate-500 dark:text-slate-400">На витрине</span>
            <span class="font-semibold">{{ summary.display_units || 0 }}</span>
          </div>
          <div class="flex items-center justify-between rounded-md bg-slate-50 p-3 dark:bg-slate-900">
            <span class="text-sm text-slate-500 dark:text-slate-400">Товаров</span>
            <span class="font-semibold">{{ summary.products || 0 }}</span>
          </div>
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
              <img v-if="item.photo_url" :src="photoUrl(item.photo_url)" class="h-full w-full object-cover" :alt="item.name" loading="lazy" />
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
        <h2 class="mb-4 font-semibold">Активность кассиров</h2>
        <EmptyState v-if="!cashiers.length" title="Кассиры не найдены" />
        <div v-else class="space-y-3">
          <div v-for="cashier in cashiers" :key="cashier.id" class="rounded-md bg-slate-50 p-3 dark:bg-slate-900">
            <div class="flex items-center justify-between gap-3">
              <span class="truncate text-sm font-medium">{{ cashier.name }}</span>
              <span class="badge bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">{{ cashier.approved_sales_count }}</span>
            </div>
            <div class="mt-1 text-xs text-slate-500">Ожидают: {{ cashier.pending_sales_count }}</div>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed, h, onMounted, onUnmounted } from 'vue'
import { apiError } from '../api/client'
import { resolveApiOrigin } from '../config/api'
import { useDashboardStore } from '../stores/dashboard'
import { useToastStore } from '../stores/toasts'
import EmptyState from '../components/ui/EmptyState.vue'
import SkeletonBlock from '../components/ui/SkeletonBlock.vue'
import StatCard from '../components/StatCard.vue'
import { icons } from '../plugins/icons'

// Функция для создания Vue компонента из SVG строки
function createIcon(svgString) {
  return {
    setup(_, { attrs }) {
      return () => h('span', {
        class: attrs.class,
        innerHTML: svgString
      })
    }
  }
}

// Создаем иконки
const CircleDollarSign = createIcon(icons.CircleDollarSign)
const ReceiptText = createIcon(icons.ReceiptText)
const Clock3 = createIcon(icons.Clock3)
const TriangleAlert = createIcon(icons.TriangleAlert)
const RefreshCw = createIcon(icons.RefreshCw)

const dashboard = useDashboardStore()
const toast = useToastStore()
let timer

const apiBase = computed(() => resolveApiOrigin())

const summary = computed(() => dashboard.data?.summary || {})
const salesByDay = computed(() => dashboard.data?.sales_by_day || [])
const topProducts = computed(() => dashboard.data?.top_products || [])
const categoryRevenue = computed(() => dashboard.data?.category_revenue || [])
const cashiers = computed(() => dashboard.data?.cashier_activity || [])
const maxRevenue = computed(() => Math.max(...salesByDay.value.map((item) => Number(item.revenue)), 1))
const maxCategoryRevenue = computed(() => Math.max(...categoryRevenue.value.map((item) => Number(item.revenue)), 1))

function photoUrl(path) {
  if (!path) return ''
  return path.startsWith('http') ? path : `${apiBase.value}${path}`
}

function money(value) {
  return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'KZT', maximumFractionDigits: 0 }).format(Number(value || 0))
}

function barHeight(value) {
  return `${Math.max(4, (Number(value || 0) / maxRevenue.value) * 100)}%`
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
