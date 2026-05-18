<template>
  <div class="space-y-5">
    <section class="panel border-sky-200 bg-sky-50/60 p-4 dark:border-sky-900 dark:bg-sky-950/30">
      <h2 class="font-semibold text-sky-950 dark:text-sky-100">Выручка и прибыль</h2>
      <div class="mt-3 grid gap-3 text-sm text-sky-900/90 dark:text-sky-100/90 sm:grid-cols-2">
        <div class="rounded-md bg-white/80 p-3 dark:bg-slate-950/60">
          <p class="font-medium">Выручка</p>
          <p class="mt-1 text-slate-600 dark:text-slate-300">Сколько денег получили от продаж — сумма всех чеков.</p>
        </div>
        <div class="rounded-md bg-white/80 p-3 dark:bg-slate-950/60">
          <p class="font-medium">Прибыль</p>
          <p class="mt-1 text-slate-600 dark:text-slate-300">Сколько осталось после себестоимости. Сейчас закупка не учитывается, поэтому прибыль совпадает с выручкой.</p>
        </div>
      </div>
    </section>

    <section class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
      <article class="panel p-4">
        <p class="text-sm text-slate-500">Выручка</p>
        <p class="mt-2 text-xl font-semibold sm:text-2xl">{{ money(summary.total_revenue) }}</p>
        <p class="mt-1 text-xs text-slate-500">Все продажи</p>
      </article>
      <article class="panel p-4">
        <p class="text-sm text-slate-500">Прибыль</p>
        <p class="mt-2 text-xl font-semibold sm:text-2xl">{{ money(summary.total_profit) }}</p>
        <p class="mt-1 text-xs text-slate-500">После себестоимости</p>
      </article>
      <article class="panel p-4">
        <p class="text-sm text-slate-500">Неделя</p>
        <p class="mt-2 text-xl font-semibold sm:text-2xl">{{ money(summary.week_revenue) }}</p>
        <p class="mt-1 text-xs text-slate-500">Выручка за 7 дней</p>
      </article>
      <article class="panel p-4">
        <p class="text-sm text-slate-500">Месяц</p>
        <p class="mt-2 text-xl font-semibold sm:text-2xl">{{ money(summary.month_revenue) }}</p>
        <p class="mt-1 text-xs text-slate-500">Выручка за месяц</p>
      </article>
    </section>

    <section class="panel p-4">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 class="font-semibold">Экспорт и backup</h2>
          <p class="mt-1 text-sm text-slate-500">Файлы формируются из текущей базы данных.</p>
        </div>
        <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
          <button class="btn-muted w-full sm:w-auto" @click="download('/reports/sales.xlsx', 'sales-report.xlsx')">
            <Download class="h-4 w-4" />Excel продаж
          </button>
          <button class="btn-muted w-full sm:w-auto" @click="download('/reports/backup', 'store-backup.json')">
            <DatabaseBackup class="h-4 w-4" />Backup JSON
          </button>
          <button class="btn-primary w-full sm:w-auto" @click="rebuild">
            <RefreshCw class="h-4 w-4" />Пересчитать
          </button>
        </div>
      </div>
    </section>

    <section class="grid grid-cols-1 gap-5 xl:grid-cols-2">
      <div class="panel p-4">
        <h2 class="mb-4 font-semibold">Доход по категориям</h2>
        <EmptyState v-if="!categories.length" title="Нет данных" />
        <div v-else class="space-y-4">
          <div v-for="item in categories" :key="item.name">
            <div class="mb-1 flex justify-between gap-3 text-sm">
              <span class="min-w-0 truncate">{{ item.name }}</span>
              <span class="shrink-0 font-medium">{{ money(item.revenue) }}</span>
            </div>
            <div class="h-2 rounded-full bg-slate-100 dark:bg-slate-800">
              <div class="h-2 rounded-full bg-emerald-500" :style="{ width: percent(item.revenue, maxCategory) }" />
            </div>
          </div>
        </div>
      </div>

      <div class="panel p-4">
        <h2 class="mb-4 font-semibold">Низкие остатки</h2>
        <EmptyState v-if="!lowStock.length" title="Остатки в порядке" />
        <div v-else class="space-y-3">
          <div
            v-for="product in lowStock"
            :key="product.id"
            class="flex items-center justify-between gap-3 rounded-md bg-slate-50 p-3 dark:bg-slate-900"
          >
            <div class="min-w-0">
              <div class="truncate text-sm font-medium">{{ product.name }}</div>
              <div class="text-xs text-slate-500">{{ product.category?.name || 'Без категории' }}</div>
            </div>
            <div class="text-sm font-semibold">{{ product.total_quantity }}</div>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted } from 'vue'
import { DatabaseBackup, Download, RefreshCw } from 'lucide-vue-next'
import api, { apiError } from '../api/client'
import { useDashboardStore } from '../stores/dashboard'
import { useToastStore } from '../stores/toasts'
import EmptyState from '../components/ui/EmptyState.vue'

const dashboard = useDashboardStore()
const toast = useToastStore()

const summary = computed(() => dashboard.data?.summary || {})
const categories = computed(() => dashboard.data?.category_revenue || [])
const lowStock = computed(() => dashboard.data?.low_stock_products || [])
const maxCategory = computed(() => Math.max(...categories.value.map((item) => Number(item.revenue)), 1))

function money(value) {
  return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'KZT', maximumFractionDigits: 0 }).format(Number(value || 0))
}

function percent(value, max) {
  return `${Math.max(3, (Number(value || 0) / max) * 100)}%`
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
  } catch (error) {
    toast.push(apiError(error), 'error')
  }
}

async function rebuild() {
  try {
    await api.post('/reports/rebuild-statistics')
    toast.push('Статистика пересчитана')
    await dashboard.fetch()
  } catch (error) {
    toast.push(apiError(error), 'error')
  }
}

onMounted(() => dashboard.fetch().catch((error) => toast.push(apiError(error), 'error')))
</script>
