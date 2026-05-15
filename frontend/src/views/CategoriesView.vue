<template>
  <div class="grid gap-5 lg:grid-cols-[0.75fr_1.25fr]">
    <section class="panel p-4">
      <h2 class="mb-4 font-semibold">{{ editing?.id ? 'Редактировать категорию' : 'Новая категория' }}</h2>
      <form class="space-y-4" @submit.prevent="save">
        <label class="block">
          <span class="mb-1 block text-sm font-medium">Название</span>
          <input v-model="form.name" class="input" required />
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium">Описание</span>
          <textarea v-model="form.description" class="textarea" />
        </label>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-emerald-600" />
          Активна
        </label>
        <div class="flex gap-2">
          <button class="btn-primary" :disabled="saving">
            <LoaderCircle v-if="saving" class="h-4 w-4 animate-spin" />Сохранить
          </button>
          <button v-if="editing" type="button" class="btn-muted" @click="reset">Отмена</button>
        </div>
      </form>
    </section>

    <section class="panel overflow-hidden">
      <div class="border-b border-slate-200 p-4 dark:border-slate-800">
        <input v-model="search" class="input" placeholder="Поиск категории" @input="debouncedFetch" />
      </div>
      <div v-if="catalog.loadingCategories" class="space-y-3 p-4">
        <SkeletonBlock v-for="i in 5" :key="i" custom-class="h-16" />
      </div>
      <EmptyState v-else-if="!catalog.categories.length" class="m-4" title="Категорий нет" />
      <div v-else class="divide-y divide-slate-200 dark:divide-slate-800">
        <div v-for="category in catalog.categories" :key="category.id" class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <div class="flex items-center gap-2">
              <h3 class="font-medium">{{ category.name }}</h3>
              <span class="badge" :class="category.is_active ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'">
                {{ category.is_active ? 'Активна' : 'Скрыта' }}
              </span>
            </div>
            <p class="mt-1 text-sm text-slate-500">{{ category.description || '—' }}</p>
            <p class="mt-1 text-xs text-slate-400">Товаров: {{ category.products_count }}</p>
          </div>
          <div class="flex gap-2">
            <button class="btn-muted h-9 px-3" @click="edit(category)"><Pencil class="h-4 w-4" /></button>
            <button class="btn-danger h-9 px-3" @click="remove(category)"><Trash2 class="h-4 w-4" /></button>
          </div>
        </div>
      </div>
      <PaginationBar :meta="catalog.categoryMeta" @page="fetchCategories" />
    </section>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { LoaderCircle, Pencil, Trash2 } from 'lucide-vue-next'
import { apiError } from '../api/client'
import { useCatalogStore } from '../stores/catalog'
import { useToastStore } from '../stores/toasts'
import EmptyState from '../components/ui/EmptyState.vue'
import PaginationBar from '../components/ui/PaginationBar.vue'
import SkeletonBlock from '../components/ui/SkeletonBlock.vue'

const catalog = useCatalogStore()
const toast = useToastStore()
const saving = ref(false)
const editing = ref(null)
const search = ref('')
const form = reactive({ name: '', description: '', is_active: true })
let debounce

function reset() {
  editing.value = null
  Object.assign(form, { name: '', description: '', is_active: true })
}

function edit(category) {
  editing.value = category
  Object.assign(form, { name: category.name, description: category.description || '', is_active: category.is_active })
}

async function save() {
  saving.value = true
  try {
    await catalog.saveCategory(form, editing.value?.id)
    toast.push('Категория сохранена')
    reset()
    await fetchCategories()
  } catch (error) {
    toast.push(apiError(error), 'error')
  } finally {
    saving.value = false
  }
}

async function remove(category) {
  if (!confirm(`Удалить категорию "${category.name}"?`)) return
  try {
    await catalog.deleteCategory(category.id)
    toast.push('Категория удалена')
    await fetchCategories()
  } catch (error) {
    toast.push(apiError(error), 'error')
  }
}

async function fetchCategories(page = 1) {
  await catalog.fetchCategories({ page, per_page: 12, search: search.value || undefined })
}

function debouncedFetch() {
  window.clearTimeout(debounce)
  debounce = window.setTimeout(() => fetchCategories(1), 300)
}

onMounted(fetchCategories)
</script>
