<template>
  <div class="animate-pulse rounded-lg bg-slate-200/80 dark:bg-slate-800" :class="customClass" />
</template>

<script setup>
defineProps({
  customClass: { type: String, default: 'h-24' },
})
</script>
