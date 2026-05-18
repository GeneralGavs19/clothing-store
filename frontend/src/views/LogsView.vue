<template>
  <div class="space-y-5">
    <section class="panel overflow-hidden">
      <div class="flex flex-col gap-3 border-b border-slate-200 p-4 dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 class="font-semibold">Журнал действий</h2>
          <p class="text-xs text-slate-500">Все события системы, включая удаление товаров</p>
        </div>
        <div class="flex flex-wrap gap-2">
          <select v-model="filterMode" class="select sm:max-w-56" @change="applyFilter">
            <option value="all">Все события</option>
            <option value="deleted_products">Удалённые товары</option>
          </select>
          <input
            v-if="filterMode === 'all'"
            v-model="action"
            class="input sm:max-w-sm"
            placeholder="Фильтр по действию"
            @input="debouncedFetch"
          />
          <button class="btn-muted" @click="fetchLogs(logs.meta.current_page)">
            <RefreshCw class="h-4 w-4" />Обновить
          </button>
        </div>
      </div>

      <div v-if="logs.loading" class="space-y-3 p-4">
        <SkeletonBlock v-for="i in 6" :key="i" custom-class="h-16" />
      </div>
      <EmptyState
        v-else-if="!logs.logs.length"
        class="m-4"
        :title="filterMode === 'deleted_products' ? 'Удалений товаров пока нет' : 'Журнал пустой'"
      />
      <div v-else class="divide-y divide-slate-200 dark:divide-slate-800">
        <div
          v-for="entry in logs.logs"
          :key="entry.id"
          class="grid gap-3 p-4 md:grid-cols-[1fr_auto] md:items-start"
        >
          <div>
            <div class="flex flex-wrap items-center gap-2">
              <span class="font-medium">{{ actionLabel(entry.action) }}</span>
              <span
                v-if="entry.action === 'products.deleted'"
                class="badge bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300"
              >
                удаление
              </span>
            </div>

            <div
              v-if="entry.action === 'products.deleted' && entry.meta"
              class="mt-3 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm dark:border-slate-800 dark:bg-slate-900"
            >
              <div class="font-medium text-slate-900 dark:text-white">{{ entry.meta.name }}</div>
              <p class="mt-1 text-slate-500">код {{ entry.meta.sku }} · {{ entry.meta.category || 'Без категории' }}</p>
              <p class="mt-2 text-slate-600 dark:text-slate-300">
                Цена продажи {{ formatMoney(entry.meta.sale_price) }} · склад {{ entry.meta.stock_quantity }} · витрина
                {{ entry.meta.display_quantity }}
              </p>
              <p v-if="entry.meta.had_sales" class="mt-1 text-xs text-slate-500">Были оформленные продажи — история сохранена</p>
            </div>

            <p v-else-if="entry.meta && Object.keys(entry.meta).length" class="mt-2 text-sm text-slate-500">
              {{ formatMeta(entry.meta) }}
            </p>

            <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
              <span class="rounded-md bg-indigo-50 px-2 py-1 font-medium text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">
                {{ entry.user?.name || entry.meta?.deleted_by?.name || 'Система' }}
              </span>
              <span v-if="entry.user?.role || entry.meta?.deleted_by?.role" class="text-slate-500">
                {{ roleLabel(entry.user?.role || entry.meta?.deleted_by?.role) }}
              </span>
              <span class="text-slate-400">· {{ entry.ip_address || '—' }}</span>
            </div>
          </div>
          <div class="text-sm text-slate-500 md:text-right">{{ date(entry.created_at) }}</div>
        </div>
      </div>
      <PaginationBar :meta="logs.meta" @page="fetchLogs" />
    </section>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { RefreshCw } from 'lucide-vue-next'
import { apiError } from '../api/client'
import { useLogsStore } from '../stores/logs'
import { useToastStore } from '../stores/toasts'
import { actionLabel, formatMoney } from '../utils/logLabels'
import { roleLabel as roleLabelText } from '../utils/permissions'
import EmptyState from '../components/ui/EmptyState.vue'
import PaginationBar from '../components/ui/PaginationBar.vue'
import SkeletonBlock from '../components/ui/SkeletonBlock.vue'

const logs = useLogsStore()
const toast = useToastStore()
const action = ref('')
const filterMode = ref('all')
let debounce

function queryParams(page = 1) {
  if (filterMode.value === 'deleted_products') {
    return { page, deleted_products: 1 }
  }

  return { page, action: action.value || undefined }
}

async function fetchLogs(page = 1) {
  try {
    await logs.fetch(queryParams(page))
  } catch (error) {
    toast.push(apiError(error), 'error')
  }
}

function applyFilter() {
  action.value = ''
  fetchLogs(1)
}

function debouncedFetch() {
  window.clearTimeout(debounce)
  debounce = window.setTimeout(() => fetchLogs(1), 300)
}

function date(value) {
  return value ? new Date(value).toLocaleString('ru-RU') : '—'
}

function roleLabel(role) {
  return roleLabelText(role)
}

function formatMeta(meta) {
  if (meta.name && meta.sku) {
    return `${meta.name} (${meta.sku})`
  }

  return Object.entries(meta)
    .filter(([, value]) => value !== null && value !== undefined && typeof value !== 'object')
    .map(([key, value]) => `${key}: ${value}`)
    .join(' · ')
}

onMounted(fetchLogs)
</script>
