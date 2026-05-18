<template>
  <div class="sales-chart-root">
    <div class="flex gap-1 overflow-x-auto pb-1" :style="{ minWidth: `${days.length * columnWidth}px` }">
      <div
        v-for="day in days"
        :key="day.day"
        class="flex shrink-0 flex-col items-center"
        :style="{ width: `${columnWidth}px` }"
        :title="tooltip(day)"
      >
        <div
          class="flex w-full items-end justify-center"
          :style="{ height: `${chartHeight}px` }"
        >
          <div :style="barStyle(day)" />
        </div>
        <div class="mt-2 w-full space-y-0.5 text-center leading-tight">
          <div class="text-[11px] font-medium text-slate-600 dark:text-slate-300">{{ day.label }}</div>
          <div class="text-[10px] text-slate-500">{{ day.sales }} прод.</div>
          <div class="text-[10px] text-slate-400">{{ day.items_sold }} шт.</div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  days: {
    type: Array,
    default: () => [],
  },
  chartHeight: {
    type: Number,
    default: 160,
  },
  columnWidth: {
    type: Number,
    default: 44,
  },
})

const maxValue = computed(() => {
  if (!props.days.length) return 1
  return Math.max(1, ...props.days.map((day) => barValue(day)))
})

function barValue(day) {
  const sales = Number(day?.sales) || 0
  const items = Number(day?.items_sold) || 0
  const revenue = Number(day?.revenue) || 0
  const chartValue = Number(day?.chart_value) || 0
  return Math.max(sales, items, revenue, chartValue)
}

function barStyle(day) {
  const value = barValue(day)
  const width = '12px'
  const radius = '4px 4px 0 0'

  if (value <= 0) {
    return {
      width,
      height: '2px',
      backgroundColor: '#cbd5e1',
      borderRadius: radius,
    }
  }

  const height = Math.max(10, Math.round((value / maxValue.value) * props.chartHeight))

  return {
    width,
    height: `${height}px`,
    backgroundColor: '#10b981',
    borderRadius: radius,
    boxShadow: '0 1px 2px rgba(16, 185, 129, 0.35)',
  }
}

function tooltip(day) {
  const sales = Number(day?.sales) || 0
  const items = Number(day?.items_sold) || 0
  const revenue = Number(day?.revenue) || 0
  if (sales <= 0) return `${day?.label}: нет продаж`
  return `${day?.label}: ${sales} прод., ${items} шт., ${revenue.toLocaleString('ru-RU')} ₸`
}
</script>

<style scoped>
.sales-chart-root {
  width: 100%;
}
</style>
