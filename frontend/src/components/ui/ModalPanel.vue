<template>
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-40 flex items-end bg-slate-950/40 p-3 backdrop-blur-sm sm:items-center sm:justify-center" @click.self="$emit('close')">
      <div class="max-h-[92vh] w-full overflow-y-auto rounded-lg bg-white p-5 shadow-soft dark:bg-slate-950 sm:max-w-2xl">
        <div class="mb-4 flex items-start justify-between gap-3">
          <div>
            <h2 class="text-lg font-semibold text-slate-950 dark:text-white">{{ title }}</h2>
            <p v-if="subtitle" class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ subtitle }}</p>
          </div>
          <button class="btn-muted h-9 w-9 px-0" type="button" @click="$emit('close')">
            <X class="h-4 w-4" />
          </button>
        </div>
        <slot />
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { h } from 'vue'
import { icons } from '../../plugins/icons'

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

const X = createIcon(icons.X)

defineEmits(['close'])
defineProps({
  open: Boolean,
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
})
</script>
