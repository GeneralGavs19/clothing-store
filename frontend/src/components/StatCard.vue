<template>
  <article class="panel p-4">
    <div class="flex items-center justify-between gap-3">
      <div>
        <p class="text-sm text-slate-500 dark:text-slate-400">{{ title }}</p>
        <p class="mt-2 text-2xl font-semibold">{{ value }}</p>
      </div>
      <div class="flex h-11 w-11 items-center justify-center rounded-lg" :class="toneClass">
        <slot></slot>
      </div>
    </div>
  </article>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  title: {
    type: String,
    required: true
  },
  value: {
    type: [String, Number],
    required: true
  },
  tone: {
    type: String,
    default: 'emerald'
  }
})

const toneClass = computed(() => ({
  emerald: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
  sky: 'bg-sky-50 text-sky-700 dark:bg-sky-950 dark:text-sky-300',
  amber: 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
  rose: 'bg-rose-50 text-rose-700 dark:bg-rose-950 dark:text-rose-300',
}[props.tone]))
</script>