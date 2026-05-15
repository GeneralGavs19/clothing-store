<template>
  <div class="fixed right-4 top-4 z-50 flex w-[calc(100vw-2rem)] max-w-sm flex-col gap-2">
    <button
      v-for="toast in toasts.items"
      :key="toast.id"
      class="rounded-lg border px-4 py-3 text-left text-sm shadow-soft transition"
      :class="toastClass(toast.type)"
      @click="toasts.remove(toast.id)"
    >
      {{ toast.message }}
    </button>
  </div>
</template>

<script setup>
import { useToastStore } from '../../stores/toasts'

const toasts = useToastStore()

function toastClass(type) {
  if (type === 'error') return 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-900 dark:bg-rose-950 dark:text-rose-100'
  if (type === 'warning') return 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100'
  return 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100'
}
</script>
