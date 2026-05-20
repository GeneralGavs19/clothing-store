<template>
  <div class="space-y-5">
    <section class="panel p-4">
      <div class="flex flex-col gap-3">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <input v-model="filters.search" class="input" placeholder="Поиск по названию, коду, штрихкоду или размеру" @input="debouncedFetch" />
          <select v-model="filters.category_id" class="select" @change="fetchProducts(1)">
            <option value="">Все категории</option>
            <option v-for="category in catalog.categories" :key="category.id" :value="category.id">{{ category.name }}</option>
          </select>
          <select v-model="filters.status" class="select" @change="fetchProducts(1)">
            <option value="">Все статусы</option>
            <option value="active">Активен</option>
            <option value="low_stock">Низкий остаток</option>
            <option value="out_of_stock">Нет в наличии</option>
          </select>
          <select v-model="filters.sort" class="select" @change="fetchProducts(1)">
            <option value="updated_at">По обновлению</option>
            <option value="name">По названию</option>
            <option value="sale_price">По цене</option>
            <option value="stock_quantity">По складу</option>
            <option value="display_quantity">По магазину</option>
          </select>
        </div>
        <button v-if="auth.canManageCatalog" class="btn-primary w-full sm:ml-auto sm:w-auto" @click="openCreate">
          <Plus class="h-4 w-4" />Товар
        </button>
        <button v-if="auth.canManageCatalog" class="btn-muted w-full sm:w-auto" @click="openImport">
          Импорт CSV
        </button>
      </div>
    </section>

    <section class="panel overflow-hidden">
      <div v-if="catalog.loadingProducts" class="grid gap-3 p-4 md:grid-cols-2 xl:grid-cols-3">
        <SkeletonBlock v-for="i in 6" :key="i" custom-class="h-44" />
      </div>
      <EmptyState v-else-if="!catalog.products.length" class="m-4" title="Товары не найдены" text="Измените фильтры или добавьте первый товар." />
      <div v-else class="divide-y divide-slate-200 dark:divide-slate-800">
        <article v-for="product in catalog.products" :key="product.id" class="product-row">
          <div class="flex min-w-0 flex-1 items-start gap-3">
            <button
              type="button"
              class="h-14 w-14 shrink-0 overflow-hidden rounded-md border border-slate-200 bg-slate-100 dark:border-slate-700 dark:bg-slate-800"
              @click="detail = product"
            >
              <img v-if="product.photo_url" :src="photoUrl(product.photo_url)" class="h-full w-full object-cover" :alt="product.name" loading="lazy" />
              <div v-else class="flex h-full w-full items-center justify-center">
                <Package class="h-5 w-5 text-slate-400" />
              </div>
            </button>
            <button type="button" class="min-w-0 flex-1 text-left" @click="detail = product">
              <div class="flex flex-wrap items-center gap-2">
                <span class="font-medium leading-snug">{{ product.name }}</span>
                <span class="badge shrink-0" :class="statusClass(product.status)">{{ statusLabel(product.status) }}</span>
              </div>
              <p class="mt-1 text-xs leading-relaxed text-slate-500">
                код {{ product.sku }}
                <span v-if="product.barcode"> · штрихкод {{ product.barcode }}</span>
                <span v-if="product.size"> · размер {{ product.size }}</span>
                · {{ product.category?.name || 'Без категории' }}
              </p>
            </button>
          </div>
          <div class="product-row__aside">
            <div class="min-w-0">
              <div class="text-base font-semibold tabular-nums">{{ money(product.sale_price) }}</div>
              <div class="text-xs text-slate-500">в магазине {{ product.display_quantity }} · склад {{ product.stock_quantity }}</div>
            </div>
            <div class="flex shrink-0 items-center gap-1.5">
              <button
                v-if="auth.canManageCatalog && product.stock_quantity > 0"
                type="button"
                class="btn-icon"
                title="В магазин (1 шт.)"
                @click="moveToStore(product)"
              >
                <ArrowRightLeft class="h-4 w-4" />
              </button>
              <button
                v-if="auth.canManageCatalog && product.display_quantity > 0"
                type="button"
                class="btn-icon"
                title="На склад (1 шт.)"
                @click="moveToStock(product)"
              >
                <ArrowRightLeft class="h-4 w-4" />
              </button>
              <button type="button" class="btn-icon" title="Карточка" @click="detail = product">
                <Eye class="h-4 w-4" />
              </button>
              <button v-if="auth.canManageCatalog" type="button" class="btn-icon" title="Редактировать" @click="openEdit(product)">
                <Pencil class="h-4 w-4" />
              </button>
              <button
                v-if="auth.canManageCatalog"
                type="button"
                class="btn-icon !border-rose-200 !text-rose-600"
                title="Удалить"
                @click="confirmDelete(product)"
              >
                <X class="h-4 w-4" />
              </button>
            </div>
          </div>
        </article>
      </div>
      <PaginationBar :meta="catalog.productMeta" @page="fetchProducts" />
    </section>

    <ModalPanel :open="editorOpen" :title="editing?.id ? 'Редактировать товар' : 'Новый товар'" @close="editorOpen = false">
      <form class="grid grid-cols-1 gap-4 sm:grid-cols-2" @submit.prevent="saveProduct">
        <label class="block sm:col-span-2">
          <span class="mb-1 block text-sm font-medium">Название</span>
          <input v-model="form.name" class="input" required />
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium">Код товара</span>
          <input v-model="form.sku" class="input" placeholder="Необязательно. Например: SAM-GAL-001" />
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium">Штрихкод</span>
          <input v-model="form.barcode" class="input" placeholder="Необязательно. Сгенерируется автоматически" />
        </label>
        <label class="block sm:col-span-2">
          <span class="mb-1 block text-sm font-medium">Категория</span>
          <select v-model="form.category_id" class="select">
            <option value="">Без категории</option>
            <option v-for="category in catalog.categories" :key="category.id" :value="category.id">{{ category.name }}</option>
          </select>
        </label>
        <label class="block sm:col-span-2">
          <span class="mb-1 block text-sm font-medium">Цена продажи</span>
          <input v-model.number="form.sale_price" class="input" type="number" min="0" step="0.01" required />
        </label>
        <div class="block sm:col-span-2">
          <div class="mb-1 flex items-center justify-between gap-2">
            <span class="text-sm font-medium">Размеры и количество</span>
            <button type="button" class="btn-muted h-8 px-3" @click="addVariantRow">+ Добавить размер</button>
          </div>
          <div class="space-y-2 rounded-md border border-slate-200 p-3 dark:border-slate-800">
            <div class="grid grid-cols-[1fr_1fr_1fr_auto] gap-2 text-xs text-slate-500">
              <span>Размер</span>
              <span>На складе</span>
              <span>В магазине</span>
              <span />
            </div>
            <div v-for="(variant, index) in form.variants" :key="`variant-${index}`" class="grid grid-cols-[1fr_1fr_1fr_auto] gap-2">
              <input v-model="variant.size" class="input" placeholder="Например: 98, 100, XL" />
              <input v-model.number="variant.stock_quantity" class="input" type="number" min="0" />
              <input v-model.number="variant.display_quantity" class="input" type="number" min="0" />
              <button type="button" class="btn-muted h-10 px-3" :disabled="form.variants.length === 1" @click="removeVariantRow(index)">
                ×
              </button>
            </div>
          </div>
        </div>
        <label class="block">
          <span class="mb-1 block text-sm font-medium">В магазине (авто)</span>
          <input :value="displayFromVariants" class="input bg-slate-50 dark:bg-slate-900" type="number" disabled />
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium">На складе (авто)</span>
          <input :value="stockFromVariants" class="input bg-slate-50 dark:bg-slate-900" type="number" disabled />
        </label>
        <label class="block sm:col-span-2">
          <span class="mb-1 block text-sm font-medium">Порог остатка</span>
          <p class="mb-1 text-xs text-slate-500">Если сумма склада и магазина ≤ порога — товар в «Низкий остаток»</p>
          <input v-model.number="form.low_stock_threshold" class="input" type="number" min="0" />
        </label>
        <label class="block sm:col-span-2">
          <span class="mb-1 block text-sm font-medium">Фото</span>
          <input class="input py-2" type="file" accept="image/*" @change="form.photo = $event.target.files[0]" />
        </label>
        <label class="block sm:col-span-2">
          <span class="mb-1 block text-sm font-medium">Описание</span>
          <textarea v-model="form.description" class="textarea" />
        </label>
        <div class="flex flex-col-reverse gap-2 sm:col-span-2 sm:flex-row sm:justify-end">
          <button type="button" class="btn-muted w-full sm:w-auto" @click="editorOpen = false">Отмена</button>
          <button class="btn-primary w-full sm:w-auto" :disabled="saving">
            <LoaderCircle v-if="saving" class="h-4 w-4 animate-spin" />Сохранить
          </button>
        </div>
      </form>
    </ModalPanel>

    <ModalPanel :open="!!detail" title="Карточка товара" @close="detail = null">
      <div v-if="detail" class="space-y-4">
        <div class="flex flex-col gap-4 sm:flex-row">
          <div class="mx-auto h-24 w-24 shrink-0 overflow-hidden rounded-lg bg-slate-100 sm:mx-0 dark:bg-slate-900">
            <img v-if="detail.photo_url" :src="photoUrl(detail.photo_url)" class="h-full w-full object-cover" alt="" />
            <Package v-else class="m-8 h-8 w-8 text-slate-400" />
          </div>
          <div class="min-w-0 text-center sm:text-left">
            <h2 class="text-xl font-semibold">{{ detail.name }}</h2>
            <p class="text-sm text-slate-500">Код: {{ detail.sku }}</p>
            <p class="text-sm text-slate-500">Штрихкод: {{ detail.barcode || '—' }}</p>
            <p v-if="detail.size" class="text-sm text-slate-500">Размеры: {{ detail.size }}</p>
            <p class="mt-2 text-sm">{{ detail.category?.name || 'Без категории' }}</p>
          </div>
        </div>
        <div v-if="detail.variants?.length" class="space-y-2">
          <h3 class="text-sm font-medium">Варианты</h3>
          <div class="overflow-hidden rounded-md border border-slate-200 dark:border-slate-800">
            <div class="grid grid-cols-2 bg-slate-50 px-3 py-2 text-xs text-slate-500 dark:bg-slate-900">
              <span>Размер</span>
              <span>Склад / Магазин</span>
            </div>
            <div v-for="(variant, index) in detail.variants" :key="`detail-variant-${index}`" class="grid grid-cols-2 px-3 py-2 text-sm">
              <span>{{ variant.size }}</span>
              <span>{{ variant.stock_quantity ?? variant.quantity ?? 0 }} / {{ variant.display_quantity ?? 0 }}</span>
            </div>
          </div>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <Info label="Цена" :value="money(detail.sale_price)" />
          <Info label="Склад" :value="detail.stock_quantity" />
          <Info label="В магазине" :value="detail.display_quantity" />
          <Info label="Общий остаток" :value="detail.total_quantity" />
          <Info label="Порог остатка" :value="detail.low_stock_threshold" />
          <Info label="Обновлён" :value="date(detail.updated_at)" />
        </div>
        <p v-if="detail.description" class="text-sm text-slate-600 dark:text-slate-300">{{ detail.description }}</p>
      </div>
    </ModalPanel>

    <ModalPanel :open="importOpen" title="Импорт товаров (CSV)" @close="importOpen = false">
      <form class="grid grid-cols-1 gap-4" @submit.prevent="importProducts">
        <p class="text-sm text-slate-500">
          Формат колонок: <b>name, sku, size, sale_price, stock_quantity, display_quantity, low_stock_threshold, description</b>.
          Можно также с barcode: <b>name, sku, barcode, size, sale_price, stock_quantity, display_quantity, low_stock_threshold, description</b>.
          Разделитель — запятая или точка с запятой. Первая строка может быть заголовком.
        </p>
        <label class="block">
          <span class="mb-1 block text-sm font-medium">Категория для всех товаров (необязательно)</span>
          <select v-model="importForm.category_id" class="select">
            <option value="">Без категории</option>
            <option v-for="category in catalog.categories" :key="category.id" :value="category.id">{{ category.name }}</option>
          </select>
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium">CSV файл</span>
          <input class="input py-2" type="file" accept=".csv,.txt" @change="importForm.file = $event.target.files[0]" required />
        </label>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" class="btn-muted w-full sm:w-auto" @click="importOpen = false">Отмена</button>
          <button class="btn-primary w-full sm:w-auto" :disabled="importing || !importForm.file">
            <LoaderCircle v-if="importing" class="h-4 w-4 animate-spin" />Импортировать
          </button>
        </div>
      </form>
    </ModalPanel>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ArrowRightLeft, Eye, LoaderCircle, Pencil, Plus, X } from 'lucide-vue-next'
import { createIcon, icons } from '../plugins/icons'
import { apiError } from '../api/client'
import { resolveApiOrigin } from '../config/api'
import { useAuthStore } from '../stores/auth'
import { useCatalogStore } from '../stores/catalog'
import { useToastStore } from '../stores/toasts'
import EmptyState from '../components/ui/EmptyState.vue'
import ModalPanel from '../components/ui/ModalPanel.vue'
import PaginationBar from '../components/ui/PaginationBar.vue'
import SkeletonBlock from '../components/ui/SkeletonBlock.vue'

const Package = createIcon(icons.Package)

const route = useRoute()
const auth = useAuthStore()
const catalog = useCatalogStore()
const toast = useToastStore()
const editorOpen = ref(false)
const importOpen = ref(false)
const saving = ref(false)
const importing = ref(false)
const editing = ref(null)
const detail = ref(null)
const filters = reactive({ search: '', category_id: '', status: '', sort: 'updated_at', direction: 'desc', page: 1, per_page: 12 })
const form = reactive(emptyForm())
const importForm = reactive({ category_id: '', file: null })
let debounce

const apiBase = computed(() => resolveApiOrigin())

function emptyForm() {
  return {
    name: '',
    sku: '',
    barcode: '',
    size: '',
    variants: [{ size: '', stock_quantity: 0, display_quantity: 0 }],
    category_id: '',
    description: '',
    sale_price: 0,
    stock_quantity: 0,
    display_quantity: 0,
    low_stock_threshold: 0,
    photo: null,
  }
}

function resetForm(product = null) {
  Object.assign(form, emptyForm(), product || {})
  form.variants = normalizeVariants(product?.variants, product?.size, product?.stock_quantity)
  form.stock_quantity = stockFromVariantList(form.variants)
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

function openImport() {
  importForm.category_id = ''
  importForm.file = null
  importOpen.value = true
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
    const variants = normalizeVariants(form.variants)
    if (!variants.length) throw new Error('Добавьте хотя бы один размер.')
    const payload = {
      ...form,
      variants,
      stock_quantity: stockFromVariantList(variants),
      display_quantity: displayFromVariantList(variants),
    }
    await catalog.saveProduct(payload, editing.value?.id)
    toast.push('Товар сохранен')
    editorOpen.value = false
    await fetchProducts(filters.page)
  } catch (error) {
    toast.push(apiError(error), 'error')
  } finally {
    saving.value = false
  }
}

async function moveToStore(product) {
  try {
    await catalog.transferStock({
      product_id: product.id,
      quantity: 1,
      direction: 'stock_to_display',
      note: 'Перенос в магазин',
    })
    toast.push('Перенесено в магазин')
    await fetchProducts(filters.page)
  } catch (error) {
    toast.push(apiError(error), 'error')
  }
}

async function moveToStock(product) {
  try {
    await catalog.transferStock({
      product_id: product.id,
      quantity: 1,
      direction: 'display_to_stock',
      note: 'Возврат на склад',
    })
    toast.push('Перенесено на склад')
    await fetchProducts(filters.page)
  } catch (error) {
    toast.push(apiError(error), 'error')
  }
}

async function importProducts() {
  if (!importForm.file) return
  importing.value = true
  try {
    const { data } = await catalog.importProducts(importForm.file, importForm.category_id)
    toast.push(`Импортировано товаров: ${data?.count ?? 0}`)
    importOpen.value = false
    await fetchProducts(1)
  } catch (error) {
    toast.push(apiError(error), 'error')
  } finally {
    importing.value = false
  }
}

const stockFromVariants = computed(() => stockFromVariantList(form.variants))
const displayFromVariants = computed(() => displayFromVariantList(form.variants))

function normalizeVariants(rawVariants, fallbackSize = '', fallbackQty = 0) {
  if (Array.isArray(rawVariants)) {
    const prepared = rawVariants
      .map((variant) => ({
        size: String(variant?.size || '').trim(),
        stock_quantity: Math.max(0, Number(variant?.stock_quantity ?? variant?.quantity ?? 0)),
        display_quantity: Math.max(0, Number(variant?.display_quantity || 0)),
      }))
      .filter((variant) => variant.size)
    if (prepared.length) return prepared
  }
  if (String(fallbackSize || '').trim()) {
    return [{ size: String(fallbackSize).trim(), stock_quantity: Math.max(0, Number(fallbackQty || 0)), display_quantity: 0 }]
  }
  return [{ size: '', stock_quantity: 0, display_quantity: 0 }]
}

function stockFromVariantList(list) {
  return (list || []).reduce((sum, variant) => sum + Math.max(0, Number(variant?.stock_quantity ?? variant?.quantity ?? 0)), 0)
}

function displayFromVariantList(list) {
  return (list || []).reduce((sum, variant) => sum + Math.max(0, Number(variant?.display_quantity || 0)), 0)
}

function addVariantRow() {
  form.variants.push({ size: '', stock_quantity: 0, display_quantity: 0 })
}

function removeVariantRow(index) {
  form.variants.splice(index, 1)
  if (!form.variants.length) addVariantRow()
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
  return {
    active: 'В наличии',
    low_stock: 'Мало',
    out_of_stock: 'Нет в наличии',
  }[status] || status
}

function applyRouteFilters() {
  if (route.query.status) filters.status = String(route.query.status)
  if (route.query.search) filters.search = String(route.query.search)
}

watch(
  () => route.query,
  () => {
    applyRouteFilters()
    fetchProducts(1)
  },
)

function statusClass(status) {
  return {
    active: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
    low_stock: 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
    out_of_stock: 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300',
  }[status]
}

onMounted(async () => {
  await catalog.fetchCategories()
  applyRouteFilters()
  fetchProducts()
})

async function confirmDelete(product) {
  const warning = product.total_quantity > 0 ? '\n\nОстатки на складе и в магазине будут списаны.' : ''
  if (!confirm(`Удалить товар "${product.name}"?${warning}`)) return
  try {
    const { data } = await catalog.deleteProduct(product.id)
    toast.push(data?.message || 'Товар удалён')
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
