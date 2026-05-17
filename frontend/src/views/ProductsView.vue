<template>
  <div class="space-y-5">
    <section class="panel p-4">
      <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <input v-model="filters.search" class="input" placeholder="Поиск" @input="debouncedFetch" />
          <select v-model="filters.category_id" class="select" @change="fetchProducts(1)">
            <option value="">Все категории</option>
            <option v-for="category in catalog.categories" :key="category.id" :value="category.id">{{ category.name }}</option>
          </select>
          <select v-model="filters.status" class="select" @change="fetchProducts(1)">
            <option value="">Все статусы</option>
            <option value="active">Активен</option>
            <option value="low_stock">Низкий остаток</option>
            <option value="out_of_stock">Нет в наличии</option>
            <option value="archived">Архив</option>
          </select>
          <select v-model="filters.sort" class="select" @change="fetchProducts(1)">
            <option value="updated_at">По обновлению</option>
            <option value="name">По названию</option>
            <option value="sale_price">По цене</option>
            <option value="stock_quantity">По складу</option>
            <option value="display_quantity">По витрине</option>
          </select>
        </div>
        <button v-if="auth.isAdmin" class="btn-primary" @click="openCreate">
          <Plus class="h-4 w-4" />Товар
        </button>
      </div>
    </section>

    <section class="panel overflow-hidden">
      <div v-if="catalog.loadingProducts" class="grid gap-3 p-4 md:grid-cols-2 xl:grid-cols-3">
        <SkeletonBlock v-for="i in 6" :key="i" custom-class="h-44" />
      </div>
      <EmptyState v-else-if="!catalog.products.length" class="m-4" title="Товары не найдены" text="Измените фильтры или добавьте первый товар." />
      <div v-else class="p-2">
        <div class="divide-y divide-slate-200 dark:divide-slate-800">
          <div v-for="product in catalog.products" :key="product.id" class="flex items-center justify-between p-3 hover:bg-slate-50 dark:hover:bg-slate-900">
            <div class="flex min-w-0 flex-1 items-center gap-3">
              <button type="button" class="h-12 w-12 shrink-0 overflow-hidden rounded-md border border-slate-200 bg-slate-100 dark:border-slate-700 dark:bg-slate-800" @click="detail = product">
                <img v-if="product.photo_url" :src="photoUrl(product.photo_url)" class="h-full w-full object-cover" :alt="product.name" loading="lazy" />
                <div v-else class="flex h-full w-full items-center justify-center">
                  <Package class="h-5 w-5 text-slate-400" />
                </div>
              </button>
              <button type="button" class="min-w-0 text-left" @click="detail = product">
                <div class="truncate font-medium">{{ product.name }}</div>
                <div class="text-xs text-slate-500">{{ product.sku }} · {{ product.category?.name || 'Без категории' }}</div>
              </button>
            </div>
            <div class="flex items-center gap-3">
              <div class="text-sm font-semibold">{{ money(product.sale_price) }}</div>
              <div class="text-sm text-slate-500">{{ product.display_quantity }} / {{ product.stock_quantity }}</div>
              <div class="flex items-center gap-2">
                <button v-if="auth.isAdmin && product.stock_quantity > 0" class="btn-muted h-8 px-3" @click="moveToDisplay(product)">
                  <ArrowRightLeft class="h-4 w-4" />
                </button>
                <button class="btn-muted h-8 px-3" @click="detail = product">
                  <Eye class="h-4 w-4" />
                </button>
                <button v-if="auth.isAdmin" class="btn-muted h-8 px-3" @click="openEdit(product)">
                  <Pencil class="h-4 w-4" />
                </button>
                <button v-if="auth.isAdmin" class="btn-danger h-8 px-3" @click="confirmDelete(product)">
                  <X class="h-4 w-4" />
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
      <PaginationBar :meta="catalog.productMeta" @page="fetchProducts" />
    </section>

    <ModalPanel :open="editorOpen" :title="editing?.id ? 'Редактировать товар' : 'Новый товар'" @close="editorOpen = false">
      <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="saveProduct">
        <label class="block sm:col-span-2">
          <span class="mb-1 block text-sm font-medium">Название</span>
          <input v-model="form.name" class="input" required />
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium">SKU</span>
          <input v-model="form.sku" class="input" required />
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium">Категория</span>
          <select v-model="form.category_id" class="select">
            <option value="">Без категории</option>
            <option v-for="category in catalog.categories" :key="category.id" :value="category.id">{{ category.name }}</option>
          </select>
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium">Цена закупки</span>
          <input v-model.number="form.purchase_price" class="input" type="number" min="0" step="0.01" required />
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium">Цена продажи</span>
          <input v-model.number="form.sale_price" class="input" type="number" min="0" step="0.01" required />
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium">На складе</span>
          <input v-model.number="form.stock_quantity" class="input" type="number" min="0" required />
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium">На витрине</span>
          <input v-model.number="form.display_quantity" class="input" type="number" min="0" required />
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium">Порог остатка</span>
          <input v-model.number="form.low_stock_threshold" class="input" type="number" min="0" />
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium">Фото</span>
          <input class="input py-2" type="file" accept="image/*" @change="form.photo = $event.target.files[0]" />
        </label>
        <label class="block sm:col-span-2">
          <span class="mb-1 block text-sm font-medium">Описание</span>
          <textarea v-model="form.description" class="textarea" />
        </label>
        <div class="flex justify-end gap-2 sm:col-span-2">
          <button type="button" class="btn-muted" @click="editorOpen = false">Отмена</button>
          <button class="btn-primary" :disabled="saving">
            <LoaderCircle v-if="saving" class="h-4 w-4 animate-spin" />Сохранить
          </button>
        </div>
      </form>
    </ModalPanel>

    <ModalPanel :open="!!detail" title="Карточка товара" @close="detail = null">
      <div v-if="detail" class="space-y-4">
        <div class="flex gap-4">
          <div class="h-24 w-24 overflow-hidden rounded-lg bg-slate-100 dark:bg-slate-900">
            <img v-if="detail.photo_url" :src="photoUrl(detail.photo_url)" class="h-full w-full object-cover" alt="" />
            <Package v-else class="m-8 h-8 w-8 text-slate-400" />
          </div>
          <div>
            <h2 class="text-xl font-semibold">{{ detail.name }}</h2>
            <p class="text-sm text-slate-500">{{ detail.sku }}</p>
            <p class="mt-2 text-sm">{{ detail.category?.name || 'Без категории' }}</p>
          </div>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
          <Info label="Закупка" :value="money(detail.purchase_price)" />
          <Info label="Продажа" :value="money(detail.sale_price)" />
          <Info label="Склад" :value="detail.stock_quantity" />
          <Info label="Витрина" :value="detail.display_quantity" />
          <Info label="Общий остаток" :value="detail.total_quantity" />
          <Info label="Обновлен" :value="date(detail.updated_at)" />
        </div>
        <p v-if="detail.description" class="text-sm text-slate-600 dark:text-slate-300">{{ detail.description }}</p>
      </div>
    </ModalPanel>
  </div>
</template>

<script setup>
import { computed, h, onMounted, reactive, ref } from 'vue'
import { icons } from '../plugins/icons'
import { apiError } from '../api/client'
import { resolveApiOrigin } from '../config/api'
import { useAuthStore } from '../stores/auth'
import { useCatalogStore } from '../stores/catalog'
import { useToastStore } from '../stores/toasts'
import EmptyState from '../components/ui/EmptyState.vue'
import ModalPanel from '../components/ui/ModalPanel.vue'
import PaginationBar from '../components/ui/PaginationBar.vue'
import SkeletonBlock from '../components/ui/SkeletonBlock.vue'

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

// Создаем иконки
const Package = createIcon(icons.Package)
const Plus = createIcon(icons.Plus)
const Eye = createIcon(icons.Search)
const Pencil = createIcon(icons.Edit)
const LoaderCircle = createIcon(icons.RefreshCw)
const ArrowRightLeft = createIcon(icons.RefreshCw)
const X = createIcon(icons.X)

const auth = useAuthStore()
const catalog = useCatalogStore()
const toast = useToastStore()
const editorOpen = ref(false)
const saving = ref(false)
const editing = ref(null)
const detail = ref(null)
const filters = reactive({ search: '', category_id: '', status: '', sort: 'updated_at', direction: 'desc', page: 1, per_page: 12 })
const form = reactive(emptyForm())
let debounce

const apiBase = computed(() => resolveApiOrigin())

function emptyForm() {
  return { name: '', sku: '', category_id: '', description: '', purchase_price: 0, sale_price: 0, stock_quantity: 0, display_quantity: 0, low_stock_threshold: 5, photo: null }
}

function resetForm(product = null) {
  Object.assign(form, emptyForm(), product || {})
  form.photo = null
}

function openCreate() {
  editing.value = null
  resetForm()
  editorOpen.value = true
}

function openEdit(product) {
  editing.value = product
  resetForm(product)
  editorOpen.value = true
}

function params(page = filters.page) {
  return { ...filters, page, category_id: filters.category_id || undefined, status: filters.status || undefined, search: filters.search || undefined }
}

async function fetchProducts(page = 1) {
  filters.page = page
  await catalog.fetchProducts(params(page))
}

function debouncedFetch() {
  window.clearTimeout(debounce)
  debounce = window.setTimeout(() => fetchProducts(1), 300)
}

async function saveProduct() {
  saving.value = true
  try {
    await catalog.saveProduct(form, editing.value?.id)
    toast.push('Товар сохранен')
    editorOpen.value = false
    await fetchProducts(filters.page)
  } catch (error) {
    toast.push(apiError(error), 'error')
  } finally {
    saving.value = false
  }
}

async function moveToDisplay(product) {
  try {
    await catalog.transferStock({ product_id: product.id, quantity: 1, direction: 'stock_to_display', note: 'Пополнение витрины' })
    toast.push('Витрина пополнена')
    await fetchProducts(filters.page)
  } catch (error) {
    toast.push(apiError(error), 'error')
  }
}

function photoUrl(path) {
  if (!path) return ''
  return path.startsWith('http') ? path : `${apiBase.value}${path}`
}

function money(value) {
  return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'KZT', maximumFractionDigits: 0 }).format(Number(value || 0))
}

function date(value) {
  return value ? new Date(value).toLocaleString('ru-RU') : '—'
}

function statusLabel(status) {
  return { active: 'Активен', low_stock: 'Мало', out_of_stock: 'Нет', archived: 'Архив' }[status] || status
}

function statusClass(status) {
  return {
    active: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
    low_stock: 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
    out_of_stock: 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300',
    archived: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
  }[status]
}

onMounted(async () => {
  await catalog.fetchCategories()
  fetchProducts()
})

async function confirmDelete(product) {
  if (!confirm(`Удалить товар "${product.name}"?`)) return
  try {
    await catalog.deleteProduct(product.id)
    toast.push('Товар удалён')
    await fetchProducts(filters.page)
  } catch (error) {
    toast.push(apiError(error), 'error')
  }
}
</script>

<script>
export default {
  components: {
    Info: {
      props: ['label', 'value'],
      template: '<div class="rounded-md bg-slate-50 p-3 dark:bg-slate-900"><div class="text-xs text-slate-500">{{ label }}</div><div class="mt-1 font-semibold">{{ value }}</div></div>',
    },
  },
}
</script>
