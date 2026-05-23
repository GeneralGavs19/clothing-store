<template>
  <div class="space-y-5">
    <section class="grid grid-cols-1 gap-5 xl:grid-cols-[1fr_1.1fr]">
      <div class="panel p-4">
        <div class="mb-4 flex items-center justify-between gap-3">
          <div>
            <h2 class="font-semibold">Создать продажу</h2>
            <p class="text-xs text-slate-500">{{ stepHint }}</p>
          </div>
          <div class="flex gap-1">
            <button v-if="saleStep !== 'products'" type="button" class="btn-muted h-9 px-3 text-xs" @click="goBackStep">Назад</button>
            <button type="button" class="btn-muted h-9 px-3" title="Обновить" @click="refreshAll">
              <RefreshCw class="h-4 w-4" />
            </button>
          </div>
        </div>

        <input
          v-model="productSearch"
          class="input"
          placeholder="Сканируйте код единицы или ищите модель"
          @input="debouncedProducts"
          @keyup.enter="onSearchEnter"
        />

        <!-- Шаг 1: модель -->
        <div v-if="saleStep === 'products'" class="mt-4 max-h-[28rem] space-y-2 overflow-y-auto pr-1">
          <SkeletonBlock v-if="catalog.loadingProducts" custom-class="h-20" />
          <EmptyState v-else-if="!availableProducts.length" title="Нет товаров в наличии" text="Пополните остатки в разделе «Товары»." />
          <button
            v-for="product in availableProducts"
            :key="product.id"
            type="button"
            class="flex w-full items-center gap-3 rounded-lg border border-slate-200 p-3 text-left transition hover:border-emerald-300 dark:border-slate-800 dark:hover:border-emerald-700"
            @click="selectProduct(product)"
          >
            <div class="h-10 w-10 shrink-0 overflow-hidden rounded-md bg-slate-100 dark:bg-slate-800">
              <img v-if="product.photo_url" :src="photoUrl(product.photo_url)" class="h-full w-full object-cover" alt="" loading="lazy" />
            </div>
            <div class="min-w-0 flex-1">
              <div class="truncate text-sm font-medium">{{ product.name }}</div>
              <div class="text-xs text-slate-500">{{ productLineMeta(product) }}</div>
            </div>
            <div class="text-sm font-semibold">{{ money(product.sale_price) }}</div>
          </button>
        </div>

        <!-- Шаг 2: размер -->
        <div v-else-if="saleStep === 'variant'" class="mt-4 space-y-2">
          <p class="text-sm font-medium">{{ selectedProduct?.name }}</p>
          <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
            <button
              v-for="v in variantsWithStock"
              :key="v.id"
              type="button"
              class="rounded-lg border border-slate-200 px-4 py-3 text-left transition hover:border-emerald-400 dark:border-slate-800"
              @click="selectVariant(v)"
            >
              <div class="text-lg font-semibold">{{ v.size }}</div>
              <div class="text-xs text-slate-500">{{ variantStockLabel(v) }} шт.</div>
            </button>
          </div>
          <p v-if="!variantsWithStock.length" class="text-sm text-slate-500">Нет размеров в наличии</p>
        </div>

        <!-- Шаг 3: код единицы -->
        <div v-else class="mt-4 space-y-2">
          <p class="text-sm text-slate-500">{{ selectedProduct?.name }} · размер {{ selectedVariant?.size }}</p>
          <div class="max-h-[24rem] space-y-1 overflow-y-auto">
            <button
              v-for="item in availableItemsForVariant"
              :key="item.id"
              type="button"
              class="flex w-full items-center justify-between rounded-md border border-slate-200 px-3 py-2 font-mono text-sm transition hover:border-emerald-400 dark:border-slate-800"
              @click="addItemToCart(item)"
            >
              <span>{{ item.code }}</span>
              <span class="text-xs text-slate-500">{{ locationLabel(item.location) }}</span>
            </button>
          </div>
          <p v-if="!availableItemsForVariant.length" class="text-sm text-slate-500">Нет доступных единиц</p>
        </div>
      </div>

      <div class="panel p-4">
        <h2 class="mb-1 font-semibold">Корзина</h2>
        <p class="mb-4 text-xs text-slate-500">Каждая позиция — одна единица с уникальным кодом</p>

        <EmptyState v-if="!cart.length" title="Корзина пуста" text="Выберите товар и код единицы слева." :icon="ShoppingCart" />

        <form v-else class="space-y-4" @submit.prevent="createSale">
          <div class="space-y-2">
            <div
              v-for="item in cart"
              :key="item.product_item_id"
              class="rounded-md border border-slate-200 p-3 dark:border-slate-800"
            >
              <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                  <div class="text-sm font-medium">{{ item.product.name }}</div>
                  <div class="text-xs text-slate-500">
                    {{ item.variant.size }} · <span class="font-mono">{{ item.code }}</span>
                  </div>
                  <div class="mt-1 text-sm font-semibold">{{ money(item.price) }}</div>
                </div>
                <button type="button" class="btn-muted h-8 w-8 px-0" @click="removeFromCart(item.product_item_id)">
                  <X class="h-4 w-4" />
                </button>
              </div>
            </div>
          </div>

          <label class="block">
            <span class="mb-1 block text-sm text-slate-500">Комментарий</span>
            <textarea v-model="cashierNote" class="textarea" rows="2" placeholder="Необязательно" />
          </label>

          <div class="flex flex-col gap-3 border-t border-slate-200 pt-4 dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <div class="text-sm text-slate-500">Сумма</div>
              <div class="text-xl font-semibold">{{ money(cartTotal) }}</div>
              <div class="text-xs text-slate-500">{{ cart.length }} ед.</div>
            </div>
            <button type="submit" class="btn-primary w-full sm:w-auto" :disabled="saving">
              <LoaderCircle v-if="saving" class="h-4 w-4 animate-spin" />
              Оформить
            </button>
          </div>
        </form>
      </div>
    </section>

    <section class="panel overflow-hidden">
      <div class="flex flex-col gap-3 border-b border-slate-200 p-4 dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 class="font-semibold">Проданное</h2>
          <p class="text-xs text-slate-500">История оформленных продаж</p>
        </div>
        <select v-model="historyStatus" class="select sm:max-w-48" @change="fetchHistory(1)">
          <option value="approved">Оформленные</option>
          <option value="">Все статусы</option>
          <option value="rejected">Отменённые</option>
        </select>
      </div>

      <EmptyState v-if="!sales.loading && !sales.sales.length" class="m-4" title="Продаж пока нет" text="Создайте первую продажу выше." />
      <div v-else class="divide-y divide-slate-200 dark:divide-slate-800">
        <div v-for="sale in sales.sales" :key="sale.id" class="flex flex-col gap-3 p-4 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <div class="font-medium">{{ saleTitle(sale) }}</div>
            <p class="mt-1 text-sm text-slate-500">{{ sale.cashier?.name || 'Кассир' }} · {{ date(sale.sold_at || sale.approved_at) }}</p>
            <div class="mt-3 space-y-1">
              <div v-for="item in sale.items" :key="item.id" class="text-sm text-slate-600 dark:text-slate-300">
                {{ item.display_name || item.product_name }} · × {{ item.quantity }}
              </div>
            </div>
          </div>
          <div class="flex flex-col gap-2 sm:items-end">
            <div class="text-lg font-semibold">{{ money(sale.subtotal) }}</div>
            <button v-if="auth.isAdmin" type="button" class="btn-danger h-9 px-3" @click="confirmDeleteSale(sale)">Удалить</button>
          </div>
        </div>
      </div>
      <PaginationBar :meta="sales.meta" @page="fetchHistory" />
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { LoaderCircle, RefreshCw, ShoppingCart, X } from 'lucide-vue-next'
import { apiError } from '../api/client'
import { resolveApiOrigin } from '../config/api'
import { useAuthStore } from '../stores/auth'
import { useCatalogStore } from '../stores/catalog'
import { useSalesStore } from '../stores/sales'
import { useToastStore } from '../stores/toasts'
import EmptyState from '../components/ui/EmptyState.vue'
import PaginationBar from '../components/ui/PaginationBar.vue'
import SkeletonBlock from '../components/ui/SkeletonBlock.vue'

const auth = useAuthStore()
const catalog = useCatalogStore()
const sales = useSalesStore()
const toast = useToastStore()

const productSearch = ref('')
const historyStatus = ref('approved')
const cart = ref([])
const cashierNote = ref('')
const saving = ref(false)
const saleStep = ref('products')
const selectedProduct = ref(null)
const selectedVariant = ref(null)
const productDetail = ref(null)
let debounce
let timer

const apiBase = computed(() => resolveApiOrigin())

const stepHint = computed(() => {
  if (saleStep.value === 'products') return 'Шаг 1: выберите модель товара'
  if (saleStep.value === 'variant') return 'Шаг 2: выберите размер'
  return 'Шаг 3: выберите код единицы'
})

const availableProducts = computed(() =>
  catalog.products.filter((p) => Number(p.display_quantity) > 0 || Number(p.stock_quantity) > 0),
)

const variantsWithStock = computed(() => {
  const variants = productDetail.value?.variants || selectedProduct.value?.variants || []
  return variants.filter((v) => variantStock(v) > 0)
})

const availableItemsForVariant = computed(() => {
  if (!selectedVariant.value) return []
  const items = selectedVariant.value.items || []
  return items.filter((i) => i.status === 'available')
})

const cartTotal = computed(() => cart.value.reduce((sum, row) => sum + Number(row.price || 0), 0))

function variantStock(v) {
  return Number(v.stock_store ?? 0) + Number(v.stock_warehouse ?? 0)
}

function variantStockLabel(v) {
  const store = Number(v.stock_store ?? 0)
  const wh = Number(v.stock_warehouse ?? 0)
  if (store && wh) return `${store} маг. + ${wh} скл.`
  if (store) return `${store}`
  return `${wh}`
}

function locationLabel(loc) {
  return loc === 'stock' ? 'Склад' : 'Магазин'
}

function photoUrl(path) {
  if (!path) return ''
  return path.startsWith('http') ? path : `${apiBase.value}${path}`
}

function productLineMeta(product) {
  const parts = []
  if (product.color) parts.push(product.color)
  const vars = product.variants || []
  if (vars.length) parts.push(vars.map((v) => v.size).filter(Boolean).slice(0, 4).join(', '))
  parts.push(`${product.display_quantity ?? 0} в магазине`)
  return parts.join(' · ')
}

async function selectProduct(product) {
  selectedProduct.value = product
  saleStep.value = 'variant'
  try {
    productDetail.value = await catalog.fetchProduct(product.id)
    selectedProduct.value = productDetail.value
  } catch (error) {
    toast.push(apiError(error), 'error')
  }
}

function selectVariant(variant) {
  const full = (productDetail.value?.variants || []).find((v) => Number(v.id) === Number(variant.id)) || variant
  selectedVariant.value = full
  saleStep.value = 'items'
}

function goBackStep() {
  if (saleStep.value === 'items') {
    saleStep.value = 'variant'
    selectedVariant.value = null
  } else if (saleStep.value === 'variant') {
    saleStep.value = 'products'
    selectedProduct.value = null
    productDetail.value = null
  }
}

function addItemToCart(item) {
  if (cart.value.some((c) => c.product_item_id === item.id)) {
    toast.push('Эта единица уже в корзине', 'error')
    return
  }
  const product = productDetail.value || selectedProduct.value
  const variant = selectedVariant.value
  cart.value.push({
    product_item_id: item.id,
    code: item.code,
    product,
    variant,
    price: Number(variant?.sale_price ?? product?.sale_price ?? 0),
  })
  toast.push(`Добавлено: ${item.code}`)
  saleStep.value = 'products'
  selectedProduct.value = null
  selectedVariant.value = null
  productDetail.value = null
}

function removeFromCart(itemId) {
  cart.value = cart.value.filter((c) => c.product_item_id !== itemId)
}

async function createSale() {
  if (!cart.value.length) return
  saving.value = true
  try {
    await sales.create({
      items: cart.value.map((row) => ({ product_item_id: row.product_item_id })),
      cashier_note: cashierNote.value,
    })
    toast.push('Продажа оформлена')
    cart.value = []
    cashierNote.value = ''
    await refreshAll()
  } catch (error) {
    toast.push(apiError(error), 'error')
  } finally {
    saving.value = false
  }
}

async function fetchProducts() {
  await catalog.fetchProducts({ search: productSearch.value || undefined, per_page: 30, status: 'active' })
}

function debouncedProducts() {
  window.clearTimeout(debounce)
  debounce = window.setTimeout(fetchProducts, 300)
}

async function onSearchEnter() {
  const code = String(productSearch.value || '').trim()
  if (!code) return
  try {
    const data = await catalog.lookupItemByCode(code)
    const item = data.item
    const product = data.product
    const variant = data.variant
    if (!item || item.status !== 'available') {
      toast.push('Единица недоступна', 'error')
      return
    }
    cart.value.push({
      product_item_id: item.id,
      code: item.code,
      product,
      variant,
      price: Number(variant?.sale_price ?? product?.sale_price ?? 0),
    })
    toast.push(`Добавлено: ${item.code}`)
    productSearch.value = ''
  } catch {
    await fetchProducts()
  }
}

async function fetchHistory(page = 1) {
  await sales.fetch({ page, status: historyStatus.value || undefined })
}

async function refreshAll() {
  await Promise.all([fetchProducts(), fetchHistory(sales.meta.current_page || 1)])
}

function saleTitle(sale) {
  return sale.display_title || sale.number || `Продажа №${sale.id}`
}

function money(value) {
  return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'KZT', maximumFractionDigits: 0 }).format(Number(value || 0))
}

function date(value) {
  return value ? new Date(value).toLocaleString('ru-RU') : '—'
}

async function confirmDeleteSale(sale) {
  if (!confirm(`Удалить ${saleTitle(sale)}?`)) return
  try {
    const { data } = await sales.deleteSale(sale.id)
    toast.push(data?.message || 'Удалено')
    await refreshAll()
  } catch (error) {
    toast.push(apiError(error), 'error')
  }
}

onMounted(() => {
  refreshAll()
  timer = window.setInterval(refreshAll, 15000)
})

onUnmounted(() => window.clearInterval(timer))
</script>
