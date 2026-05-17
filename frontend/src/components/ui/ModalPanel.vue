<template>
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-40 flex items-end bg-slate-950/40 p-3 backdrop-blur-sm sm:items-center sm:justify-center" @click.self="$emit('close')">
      <div class="max-h-[92vh] w-full overflow-y-auto rounded-lg bg-white p-5 shadow-soft dark:bg-slate-950 sm:max-w-2xl">
        <div class="mb-4 flex items-start justify-between gap-3">
          <div>
            <h2 class="text-lg font-semibold text-slate-950 dark:text-white">{{ title }}</h2>
            <p v-if="subtitle" class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ subtitle }}</p>
          </div>
          <button class="btn-icon" type="button" title="Закрыть" @click="$emit('close')">
            <X class="h-4 w-4" />
          </button>
        </div>
        <slot />
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { createIcon, icons } from '../../plugins/icons'

const X = createIcon(icons.X)

defineEmits(['close'])
defineProps({
  open: Boolean,
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
})
</script>
