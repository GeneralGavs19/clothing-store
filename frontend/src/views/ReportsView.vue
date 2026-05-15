<template>
  <div class="space-y-5">
    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
      <article class="panel p-4">
        <p class="text-sm text-slate-500">Выручка</p>
        <p class="mt-2 text-2xl font-semibold">{{ money(summary.total_revenue) }}</p>
      </article>
      <article class="panel p-4">
        <p class="text-sm text-slate-500">Прибыль</p>
        <p class="mt-2 text-2xl font-semibold">{{ money(summary.total_profit) }}</p>
      </article>
      <article class="panel p-4">
        <p class="text-sm text-slate-500">Неделя</p>
        <p class="mt-2 text-2xl font-semibold">{{ money(summary.week_revenue) }}</p>
      </article>
      <article class="panel p-4">
        <p class="text-sm text-slate-500">Месяц</p>
        <p class="mt-2 text-2xl font-semibold">{{ money(summary.month_revenue) }}</p>
      </article>
    </section>

    <section class="panel p-4">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 class="font-semibold">Экспорт и backup</h2>
          <p class="mt-1 text-sm text-slate-500">Файлы формируются из текущей базы данных.</p>
        </div>
        <div class="flex flex-wrap gap-2">
          <button class="btn-muted" @click="download('/reports/sales.csv', 'sales-report.csv')">
            <Download class="h-4 w-4" />CSV продаж
          </button>
          <button class="btn-muted" @click="download('/reports/backup', 'store-backup.json')">
            <DatabaseBackup class="h-4 w-4" />Backup JSON
          </button>
          <button class="btn-primary" @click="rebuild">
            <RefreshCw class="h-4 w-4" />Пересчитать
          </button>
        </div>
      </div>
    </section>

    <section class="grid gap-5 xl:grid-cols-2">
      <div class="panel p-4">
        <h2 class="mb-4 font-semibold">Доход по категориям</h2>
        <EmptyState v-if="!categories.length" title="Нет данных" />
        <div v-else class="space-y-4">
          <div v-for="item in categories" :key="item.name">
            <div class="mb-1 flex justify-between text-sm">
              <span>{{ item.name }}</span>
              <span class="font-medium">{{ money(item.revenue) }}</span>
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
          <div v-for="product in lowStock" :key="product.id" class="flex items-center justify-between gap-3 rounded-md bg-slate-50 p-3 dark:bg-slate-900">
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
