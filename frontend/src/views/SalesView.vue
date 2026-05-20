<template>
  <div class="space-y-5">
    <section class="grid grid-cols-1 gap-5 xl:grid-cols-[1fr_1.1fr]">
      <!-- Выбор товаров -->
      <div class="panel p-4">
        <div class="mb-4 flex items-center justify-between gap-3">
          <div>
            <h2 class="font-semibold">Создать продажу</h2>
            <p class="text-xs text-slate-500">Выберите товар и добавьте в корзину</p>
          </div>
          <button type="button" class="btn-muted h-9 px-3" title="Обновить" @click="refreshAll">
            <RefreshCw class="h-4 w-4" />
          </button>
        </div>
        <input v-model="productSearch" class="input" placeholder="Поиск по названию или коду" @input="debouncedProducts" />
        <div class="mt-4 max-h-[28rem] space-y-2 overflow-y-auto pr-1">
          <SkeletonBlock v-if="catalog.loadingProducts" custom-class="h-20" />
          <EmptyState
            v-else-if="!availableProducts.length"
            title="Нет товаров в наличии"
            text="Пополните склад или витрину, чтобы оформить продажу."
          />
          <button
            v-for="product in availableProducts"
            :key="product.id"
            type="button"
            class="flex w-full items-center gap-3 rounded-md border border-slate-200 p-3 text-left transition hover:border-emerald-300 dark:border-slate-800 dark:hover:border-emerald-700"
            @click="addToCart(product)"
          >
            <div class="h-10 w-10 shrink-0 overflow-hidden rounded-md bg-slate-100 dark:bg-slate-800">
              <img v-if="product.photo_url" :src="photoUrl(product.photo_url)" class="h-full w-full object-cover" alt="" loading="lazy" />
              <div v-else class="flex h-full w-full items-center justify-center text-xs text-slate-400">—</div>
            </div>
            <div class="min-w-0 flex-1">
              <div class="truncate text-sm font-medium">{{ product.name }}</div>
              <div class="text-xs text-slate-500">
                код {{ product.sku }}<span v-if="product.size"> · {{ product.size }}</span> · склад {{ product.stock_quantity }} · в магазине {{ product.display_quantity }}
              </div>
            </div>
            <div class="text-sm font-semibold">{{ money(product.sale_price) }}</div>
          </button>
        </div>
      </div>

      <!-- Корзина -->
      <div class="panel p-4">
        <h2 class="mb-1 font-semibold">Корзина</h2>
        <p class="mb-4 text-xs text-slate-500">Укажите откуда списать товар</p>

        <EmptyState v-if="!cart.length" title="Корзина пуста" text="Добавьте товары слева." :icon="ShoppingCart" />

        <form v-else class="space-y-4" @submit.prevent="createSale">
          <div class="space-y-2">
            <div
              v-for="item in cart"
              :key="item.product.id"
              class="rounded-md border border-slate-200 p-3 dark:border-slate-800"
            >
              <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                  <div class="text-sm font-medium">{{ item.product.name }}</div>
                  <div class="text-xs text-slate-500">{{ money(item.product.sale_price) }} за шт.</div>
                </div>
                <button type="button" class="btn-muted h-8 w-8 px-0" @click="removeFromCart(item.product.id)">
                  <X class="h-4 w-4" />
                </button>
              </div>
              <div class="mt-3 grid gap-2 sm:grid-cols-2">
                <label class="block">
                  <span class="mb-1 block text-xs text-slate-500">Количество</span>
                  <input
                    v-model.number="item.quantity"
                    class="input h-9"
                    type="number"
                    min="1"
                    :max="maxQty(item)"
                    @change="clampQty(item)"
                  />
                </label>
                <label class="block">
                  <span class="mb-1 block text-xs text-slate-500">Размер</span>
                  <select v-model="item.variant_size" class="select h-9" @change="onVariantChange(item)">
                    <option v-for="option in variantOptions(item.product, item.source)" :key="option.size" :value="option.size">
                      {{ option.size }} ({{ option.available }})
                    </option>
                  </select>
                </label>
                <label class="block">
                  <span class="mb-1 block text-xs text-slate-500">Списать с</span>
                  <select v-model="item.source" class="select h-9" @change="onSourceChange(item)">
                    <option value="display" :disabled="!item.product.display_quantity">В магазине ({{ item.product.display_quantity }})</option>
                    <option value="stock" :disabled="!item.product.stock_quantity">Склад ({{ item.product.stock_quantity }})</option>
                  </select>
                </label>
              </div>
            </div>
          </div>

          <label class="block">
            <span class="mb-1 block text-sm text-slate-500">Комментарий</span>
            <textarea v-model="cashierNote" class="textarea" rows="2" placeholder="Необязательно" />
          </label>

          <div class="flex flex-col gap-3 border-t border-slate-200 pt-4 dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <div class="text-sm text-slate-500">Сумма продажи</div>
              <div class="text-xl font-semibold">{{ money(cartTotal) }}</div>
              <div class="text-xs text-slate-500">{{ cartItemsCount }} шт. · {{ cart.length }} поз.</div>
            </div>
            <button type="submit" class="btn-primary w-full sm:w-auto" :disabled="saving">
              <LoaderCircle v-if="saving" class="h-4 w-4 animate-spin" />
              Создать продажу
            </button>
          </div>
        </form>
      </div>
    </section>

    <!-- Список проданных -->
    <section class="panel overflow-hidden">
      <div class="flex flex-col gap-3 border-b border-slate-200 p-4 dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 class="font-semibold">Проданное</h2>
          <p class="text-xs text-slate-500">Все оформленные продажи сохраняются в базе</p>
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
            <div class="font-medium text-slate-900 dark:text-white">{{ saleTitle(sale) }}</div>
            <p class="mt-1 text-sm text-slate-500">
              {{ sale.cashier?.name || 'Кассир' }} · {{ date(sale.sold_at || sale.approved_at) }}
            </p>
            <div class="mt-3 space-y-1">
              <div
                v-for="item in sale.items"
                :key="item.id"
                class="flex flex-wrap items-center gap-2 text-sm text-slate-600 dark:text-slate-300"
              >
                <span class="font-medium">{{ item.display_name || item.product_name || item.product?.name }}</span>
                <span class="text-slate-400">× {{ item.quantity }}</span>
                <span class="badge bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                  {{ sourceLabel(item.source_location) }}
                </span>
              </div>
            </div>
          </div>
          <div class="flex flex-col gap-2 sm:items-end sm:text-right">
            <div class="text-lg font-semibold">{{ money(sale.subtotal) }}</div>
            <button
              v-if="auth.isAdmin"
              type="button"
              class="btn-danger h-9 w-full px-3 sm:w-auto"
              @click="confirmDeleteSale(sale)"
            >
              Удалить
            </button>
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
let debounce
let timer

const apiBase = computed(() => resolveApiOrigin())

const availableProducts = computed(() =>
  catalog.products.filter((p) => Number(p.display_quantity) > 0 || Number(p.stock_quantity) > 0),
)

const cartTotal = computed(() =>
  cart.value.reduce((sum, item) => sum + Number(item.product.sale_price) * Number(item.quantity || 0), 0),
)

const cartItemsCount = computed(() => cart.value.reduce((sum, item) => sum + Number(item.quantity || 0), 0))

function photoUrl(path) {
  if (!path) return ''
  return path.startsWith('http') ? path : `${apiBase.value}${path}`
}

function maxQty(item) {
  if (item.variant_size) return variantAvailable(item.product, item.variant_size, item.source)
  return item.source === 'stock' ? Number(item.product.stock_quantity) : Number(item.product.display_quantity)
}

function clampQty(item) {
  const max = maxQty(item)
  if (max <= 0) {
    item.source = item.product.display_quantity > 0 ? 'display' : 'stock'
  }
  item.quantity = Math.min(Math.max(1, Number(item.quantity) || 1), Math.max(1, maxQty(item)))
}

function addToCart(product) {
  const existing = cart.value.find((item) => item.product.id === product.id)
  if (existing) {
    existing.quantity += 1
    clampQty(existing)
    return
  }
  const source = product.display_quantity > 0 ? 'display' : 'stock'
  const options = variantOptions(product, source)
  cart.value.push({ product, quantity: 1, source, variant_size: options[0]?.size || null })
}

function removeFromCart(productId) {
  cart.value = cart.value.filter((item) => item.product.id !== productId)
}

async function createSale() {
  if (!cart.value.length) return
  saving.value = true
  try {
    await sales.create({
      items: cart.value.map((item) => ({
        product_id: item.product.id,
        quantity: item.quantity,
        source: item.source,
        variant_size: item.variant_size || undefined,
      })),
      cashier_note: cashierNote.value,
    })
    toast.push('Продажа создана и сохранена')
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
  await catalog.fetchProducts({ search: productSearch.value || undefined, per_page: 20, status: 'active' })
}

function debouncedProducts() {
  window.clearTimeout(debounce)
  debounce = window.setTimeout(fetchProducts, 300)
}

async function fetchHistory(page = 1) {
  await sales.fetch({ page, status: historyStatus.value || undefined })
}

async function refreshAll() {
  try {
    await Promise.all([fetchProducts(), fetchHistory(sales.meta.current_page || 1)])
  } catch (error) {
    toast.push(apiError(error), 'error')
  }
}

function saleTitle(sale) {
  return sale.display_title || sale.number || `Продажа №${sale.id}`
}

function sourceLabel(source) {
  return source === 'stock' ? 'со склада' : 'из магазина'
}

function money(value) {
  return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'KZT', maximumFractionDigits: 0 }).format(
    Number(value || 0),
  )
}

function date(value) {
  return value ? new Date(value).toLocaleString('ru-RU') : '—'
}

async function confirmDeleteSale(sale) {
  const restoreNote =
    sale.status === 'approved'
      ? '\n\nОстатки вернутся на склад или витрину. Запись исчезнет из списка продаж.'
      : ''
  if (!confirm(`Удалить ${saleTitle(sale)}?${restoreNote}`)) return
  try {
    const { data } = await sales.deleteSale(sale.id)
    toast.push(data?.message || 'Продажа удалена')
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

function variantOptions(product, source) {
  const variants = Array.isArray(product?.variants) ? product.variants : []
  if (!variants.length) {
    const available = source === 'stock' ? Number(product?.stock_quantity || 0) : Number(product?.display_quantity || 0)
    return [{ size: product?.size || 'Без размера', available }]
  }
  return variants
    .map((variant) => ({
      size: String(variant?.size || 'Без размера'),
      available: source === 'stock' ? Number(variant?.stock_quantity ?? variant?.quantity ?? 0) : Number(variant?.display_quantity || 0),
    }))
    .filter((variant) => variant.available > 0)
}

function variantAvailable(product, size, source) {
  const variants = Array.isArray(product?.variants) ? product.variants : []
  const variant = variants.find((row) => String(row?.size || '') === String(size || ''))
  if (!variant) return 0
  return source === 'stock' ? Number(variant?.stock_quantity ?? variant?.quantity ?? 0) : Number(variant?.display_quantity || 0)
}

function onVariantChange(item) {
  clampQty(item)
}

function onSourceChange(item) {
  const options = variantOptions(item.product, item.source)
  if (!options.find((option) => option.size === item.variant_size)) {
    item.variant_size = options[0]?.size || null
  }
  clampQty(item)
}
</script>
