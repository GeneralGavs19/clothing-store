<template>
  <component
    :is="to ? RouterLink : 'article'"
    :to="to || undefined"
    class="panel block p-4 transition"
    :class="
      to
        ? 'cursor-pointer hover:border-emerald-300 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-400 dark:hover:border-emerald-700'
        : ''
    "
  >
    <div class="flex items-start justify-between gap-3">
      <div class="min-w-0 flex-1">
        <p class="text-sm text-slate-500 dark:text-slate-400">{{ title }}</p>
        <p class="mt-1 text-xl font-semibold leading-tight sm:text-2xl">{{ value }}</p>
        <p v-if="hint" class="mt-1.5 text-xs leading-snug text-slate-500 dark:text-slate-400">{{ hint }}</p>
        <p v-if="to" class="mt-2 text-[11px] font-medium text-emerald-600 dark:text-emerald-400">Подробнее →</p>
      </div>
      <div class="stat-icon" :class="toneClass">
        <slot />
      </div>
    </div>
  </component>
</template>

<script setup>
import { computed } from 'vue'
import { RouterLink } from 'vue-router'

const props = defineProps({
  title: { type: String, required: true },
  value: { type: [String, Number], required: true },
  hint: { type: String, default: '' },
  to: { type: [String, Object], default: '' },
  tone: { type: String, default: 'emerald' },
})

const toneClass = computed(
  () =>
    ({
      emerald: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
      sky: 'bg-sky-50 text-sky-700 dark:bg-sky-950 dark:text-sky-300',
      amber: 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
      rose: 'bg-rose-50 text-rose-700 dark:bg-rose-950 dark:text-rose-300',
    })[props.tone],
)
</script>
