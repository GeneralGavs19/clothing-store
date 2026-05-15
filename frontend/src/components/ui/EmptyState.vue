<template>
  <div class="flex min-h-40 flex-col items-center justify-center rounded-lg border border-dashed border-slate-300 p-8 text-center dark:border-slate-700">
    <component :is="icon" class="mb-3 h-8 w-8 text-slate-400" />
    <h3 class="text-sm font-semibold text-slate-900 dark:text-white">{{ title }}</h3>
    <p v-if="text" class="mt-1 max-w-sm text-sm text-slate-500 dark:text-slate-400">{{ text }}</p>
  </div>
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

const PackageOpen = createIcon(icons.Package)

const props = defineProps({
  title: { type: String, required: true },
  text: { type: String, default: '' },
  icon: { type: [Object, Function], default: null },
})

import { computed } from 'vue'
const icon = computed(() => props.icon || PackageOpen)
</script>
