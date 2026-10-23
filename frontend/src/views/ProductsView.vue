<template>
  <div class="space-y-5">
    <section class="panel p-4">
      <div class="flex flex-col gap-3">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <input v-model="filters.search" class="input" placeholder="Поиск по названию, цвету, размеру..." @input="debouncedFetch" />
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
            <RouterLink
              :to="{ name: 'product-detail', params: { id: product.id } }"
              class="h-14 w-14 shrink-0 overflow-hidden rounded-md border border-slate-200 bg-slate-100 dark:border-slate-700 dark:bg-slate-800"
            >
              <img v-if="product.photo_url" :src="photoUrl(product.photo_url)" class="h-full w-full object-cover" :alt="product.name" loading="lazy" />
              <div v-else class="flex h-full w-full items-center justify-center">
                <Package class="h-5 w-5 text-slate-400" />
              </div>
            </RouterLink>
            <RouterLink :to="{ name: 'product-detail', params: { id: product.id } }" class="min-w-0 flex-1 text-left">
              <div class="flex flex-wrap items-center gap-2">
                <span class="font-medium leading-snug">{{ product.name }}</span>
                <span class="badge shrink-0" :class="statusClass(product.status)">{{ statusLabel(product.status) }}</span>
              </div>
              <p class="mt-1 text-xs leading-relaxed text-slate-500">
                {{ variantSummaryLine(product) }}
              </p>
            </RouterLink>
          </div>
          <div class="product-row__aside">
            <div class="min-w-0">
              <div class="text-base font-semibold tabular-nums">{{ money(product.sale_price) }}</div>
              <div class="text-xs text-slate-500">в магазине {{ product.display_quantity }}</div>
            </div>
            <div class="flex shrink-0 items-center gap-1.5">
              <button
                v-if="auth.canManageCatalog && pickVariantForTransfer(product, 'stock_to_display')"
                type="button"
                class="btn-icon"
                title="В магазин (1 шт.)"
                @click="moveToStore(product)"
              >
                <ArrowRightLeft class="h-4 w-4" />
              </button>
              <button
                v-if="auth.canManageCatalog && pickVariantForTransfer(product, 'display_to_stock')"
                type="button"
                class="btn-icon"
                title="На склад (1 шт.)"
                @click="moveToStock(product)"
              >
                <ArrowRightLeft class="h-4 w-4" />
              </button>
              <RouterLink :to="{ name: 'product-detail', params: { id: product.id } }" class="btn-icon" title="Карточка">
                <Eye class="h-4 w-4" />
              </RouterLink>
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
          <span class="mb-1 block text-sm font-medium">Цвет</span>
          <input v-model="form.color" class="input" placeholder="Синий, чёрный…" />
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium">Цена</span>
          <input v-model.number="form.sale_price" class="input" type="number" min="0" step="0.01" required />
        </label>
        <label class="block sm:col-span-2">
          <span class="mb-1 block text-sm font-medium">Категория</span>
          <select v-model="form.category_id" class="select">
            <option value="">Без категории</option>
            <option v-for="category in catalog.categories" :key="category.id" :value="category.id">{{ category.name }}</option>
          </select>
        </label>

        <div ref="variantTableRef" class="block space-y-3 sm:col-span-2">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
              <span class="text-sm font-medium">Размеры и остатки</span>
              <p class="text-xs text-slate-500">Укажите размер и количество товара в магазине</p>
            </div>
            <div class="flex flex-wrap gap-2">
              <button type="button" class="btn-primary h-8 px-2.5 text-xs" @click="addVariantRow">+ Размер</button>
            </div>
          </div>
          <div class="space-y-2">
            <div
              v-for="(variant, index) in form.variants"
              :key="`variant-${index}-${variant.id ?? 'n'}`"
              class="flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50/50 p-3 dark:border-slate-800 dark:bg-slate-900/50"
            >
              <div class="flex-1">
                <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Размер</span>
                <input
                  v-model="variant.size"
                  class="input h-9 px-3 text-sm font-medium"
                  placeholder="S, M, L, 42..."
                  data-variant-focus="size"
                  required
                />
              </div>
              <div class="w-36">
                <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Количество (шт.)</span>
                <input
                  v-model.number="variant.stock_store"
                  class="input h-9 px-3 text-sm font-semibold tabular-nums"
                  type="number"
                  min="0"
                  step="1"
                  placeholder="0"
                  required
                />
              </div>
              <div class="pt-5">
                <button
                  type="button"
                  class="btn-muted h-9 w-9 px-0 text-slate-400 hover:text-rose-500"
                  :disabled="form.variants.length === 1"
                  @click="removeVariantRow(index)"
                  title="Удалить размер"
                >×</button>
              </div>
            </div>
          </div>
        </div>

        <label class="block sm:col-span-2">
          <span class="mb-1 block text-sm font-medium">Всего в магазине (авто)</span>
          <input :value="storeTotal" class="input bg-slate-50 dark:bg-slate-900" type="number" disabled />
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

    <ModalPanel :open="importOpen" title="Импорт товаров (CSV)" @close="importOpen = false">
      <form class="grid grid-cols-1 gap-4" @submit.prevent="importProducts">
        <p class="text-sm text-slate-500">
          Каждая строка — один товар с одним вариантом. Полный формат (11+ колонок):
          <b>name, sku, barcode, color, size, price, stock_warehouse, stock_store, low_stock_threshold, purchase_price, description</b>.
          Короткий формат с штрихкодом:
          <b>name, sku, barcode, size, price, stock_warehouse, stock_store, low_stock_threshold, description</b>.
          Разделитель — запятая или точка с запятой; первая строка может быть заголовком.
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
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
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
const variantTableRef = ref(null)
const filters = reactive({ search: '', category_id: '', status: '', sort: 'updated_at', direction: 'desc', page: 1, per_page: 12 })
const form = reactive(emptyForm())
const importForm = reactive({ category_id: '', file: null })
let debounce

const apiBase = computed(() => resolveApiOrigin())

function blankVariant() {
  return { id: null, size: '', stock_store: 0 }
}

function emptyForm() {
  return {
    name: '',
    color: '',
    sale_price: '',
    variants: [blankVariant()],
    category_id: '',
    description: '',
    photo: null,
  }
}

function normalizeVariantsFromProduct(product, rawVariants) {
  const list = Array.isArray(rawVariants) ? rawVariants : []
  if (list.length) {
    return list.map((v) => ({
      id: v.id ?? null,
      size: String(v.size || '').trim(),
      stock_store: Number(v.stock_store ?? v.display_quantity ?? 0),
    }))
  }
  const fbSize = String(product?.size || '').trim()
  if (fbSize) {
    return [{ ...blankVariant(), size: fbSize, stock_store: Number(product?.display_quantity ?? 0) }]
  }
  return [blankVariant()]
}

function resetForm(product = null) {
  const base = emptyForm()
  Object.assign(form, base)
  if (product) {
    form.name = product.name ?? ''
    form.color = product.color ?? ''
    form.sale_price = product.sale_price != null ? product.sale_price : ''
    form.category_id = product.category_id ?? ''
    form.description = product.description ?? ''
  }
  form.variants = normalizeVariantsFromProduct(product, product?.variants)
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

function prepareVariantsForSave(rows) {
  return (rows || [])
    .map((v) => ({
      id: v.id,
      size: String(v.size || '').trim(),
      stock_store: Math.max(0, Number(v.stock_store) || 0),
    }))
    .filter((v) => v.size)
}

async function saveProduct() {
  saving.value = true
  try {
    const variants = prepareVariantsForSave(form.variants)
    if (!variants.length) throw new Error('Добавьте хотя бы один размер.')
    const payload = {
      name: form.name,
      color: form.color,
      sale_price: form.sale_price,
      category_id: form.category_id,
      description: form.description,
      variants,
      photo: form.photo,
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

function pickVariantForTransfer(product, direction) {
  const variants = Array.isArray(product?.variants) ? product.variants : []
  if (direction === 'stock_to_display') {
    return variants.find((v) => Number(v.stock_warehouse ?? v.stock_quantity ?? 0) > 0) ?? null
  }
  return variants.find((v) => Number(v.stock_store ?? v.display_quantity ?? 0) > 0) ?? null
}

async function moveToStore(product) {
  const v = pickVariantForTransfer(product, 'stock_to_display')
  if (!v?.id) {
    toast.push('Нет остатка на складе ни у одного варианта', 'error')
    return
  }
  try {
    await catalog.transferStock({
      product_id: product.id,
      product_variant_id: v.id,
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
  const v = pickVariantForTransfer(product, 'display_to_stock')
  if (!v?.id) {
    toast.push('Нет остатка в магазине ни у одного варианта', 'error')
    return
  }
  try {
    await catalog.transferStock({
      product_id: product.id,
      product_variant_id: v.id,
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

const storeTotal = computed(() =>
  (form.variants || []).reduce((s, v) => s + (Number(v.stock_store) || 0), 0),
)

function variantSummaryLine(product) {
  const parts = []
  if (product.color) parts.push(product.color)
  const vars = product.variants || []
  const sizes = vars.map((v) => v.size).filter(Boolean)
  if (sizes.length) parts.push(`размеры: ${sizes.slice(0, 6).join(', ')}${sizes.length > 6 ? '…' : ''}`)
  else if (product.size) parts.push(`размеры: ${product.size}`)
  const total = Number(product.total_quantity ?? 0)
  if (total) parts.push(`${total} ед.`)
  parts.push(product.category?.name || 'Без категории')
  return parts.join(' · ')
}

async function addVariantRow() {
  form.variants.push(blankVariant())
  await nextTick()
  const root = variantTableRef.value
  if (!root) return
  const inputs = root.querySelectorAll('input[data-variant-focus="size"]')
  const last = inputs[inputs.length - 1]
  last?.focus?.()
}

function removeVariantRow(index) {
  form.variants.splice(index, 1)
  if (!form.variants.length) form.variants.push(blankVariant())
}

function quickAddSizesPrompt() {
  const size = window.prompt('Новый размер (S, M, L…)', '')
  if (!size?.trim()) return
  form.variants.push({ ...blankVariant(), size: size.trim(), stock_store: 0 })
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

async function openEditFromRoute() {
  const editId = route.query.edit
  if (!editId) return
  try {
    const p = await catalog.fetchProduct(editId)
    if (p) openEdit(p)
  } catch {
    //
  }
}

onMounted(async () => {
  await catalog.fetchCategories()
  applyRouteFilters()
  await fetchProducts()
  await openEditFromRoute()
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
