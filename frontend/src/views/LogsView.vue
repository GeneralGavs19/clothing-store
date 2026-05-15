<template>
  <section class="panel overflow-hidden">
    <div class="flex flex-col gap-3 border-b border-slate-200 p-4 dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between">
      <input v-model="action" class="input sm:max-w-sm" placeholder="Фильтр по действию" @input="debouncedFetch" />
      <button class="btn-muted" @click="fetchLogs(logs.meta.current_page)">
        <RefreshCw class="h-4 w-4" />Обновить
      </button>
    </div>
    <div v-if="logs.loading" class="space-y-3 p-4">
      <SkeletonBlock v-for="i in 6" :key="i" custom-class="h-16" />
    </div>
    <EmptyState v-else-if="!logs.logs.length" class="m-4" title="Журнал пустой" />
    <div v-else class="divide-y divide-slate-200 dark:divide-slate-800">
      <div v-for="entry in logs.logs" :key="entry.id" class="grid gap-2 p-4 md:grid-cols-[1fr_auto] md:items-center">
        <div>
          <div class="flex flex-wrap items-center gap-2">
            <span class="font-medium">{{ entry.action }}</span>
            <span class="badge bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ entry.user?.role || 'system' }}</span>
          </div>
          <p class="mt-1 text-sm text-slate-500">{{ entry.user?.name || 'System' }} · {{ entry.ip_address || '—' }}</p>
        </div>
        <div class="text-sm text-slate-500">{{ date(entry.created_at) }}</div>
      </div>
    </div>
    <PaginationBar :meta="logs.meta" @page="fetchLogs" />
  </section>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { RefreshCw } from 'lucide-vue-next'
import { apiError } from '../api/client'
import { useLogsStore } from '../stores/logs'
import { useToastStore } from '../stores/toasts'
import EmptyState from '../components/ui/EmptyState.vue'
import PaginationBar from '../components/ui/PaginationBar.vue'
import SkeletonBlock from '../components/ui/SkeletonBlock.vue'

const logs = useLogsStore()
const toast = useToastStore()
const action = ref('')
let debounce

async function fetchLogs(page = 1) {
  try {
    await logs.fetch({ page, action: action.value || undefined })
  } catch (error) {
    toast.push(apiError(error), 'error')
  }
}

function debouncedFetch() {
  window.clearTimeout(debounce)
  debounce = window.setTimeout(() => fetchLogs(1), 300)
}

function date(value) {
  return value ? new Date(value).toLocaleString('ru-RU') : '—'
}

onMounted(fetchLogs)
</script>
