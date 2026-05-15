<template>
  <div class="flex flex-col gap-3 border-t border-slate-200 px-4 py-3 text-sm dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between">
    <span class="text-slate-500 dark:text-slate-400">Всего: {{ meta.total || 0 }}</span>
    <div class="flex items-center gap-2">
      <button class="btn-muted h-9 px-3" :disabled="meta.current_page <= 1" @click="$emit('page', meta.current_page - 1)">Назад</button>
      <span class="min-w-20 text-center text-slate-600 dark:text-slate-300">{{ meta.current_page }} / {{ meta.last_page }}</span>
      <button class="btn-muted h-9 px-3" :disabled="meta.current_page >= meta.last_page" @click="$emit('page', meta.current_page + 1)">Вперед</button>
    </div>
  </div>
</template>

<script setup>
defineEmits(['page'])
defineProps({ meta: { type: Object, required: true } })
</script>
