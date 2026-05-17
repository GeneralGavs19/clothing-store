<template>
  <div class="space-y-5">
    <section class="grid gap-5 xl:grid-cols-[0.9fr_1.1fr]">
      <div class="panel p-4">
        <div class="mb-4 flex items-center justify-between gap-3">
          <h2 class="font-semibold">Новая продажа</h2>
          <button class="btn-muted h-9 px-3" @click="refreshAll"><RefreshCw class="h-4 w-4" /></button>
        </div>
        <input v-model="productSearch" class="input" placeholder="Найти товар для продажи" @input="debouncedProducts" />
        <div class="mt-4 max-h-[31rem] space-y-2 overflow-y-auto pr-1">
          <SkeletonBlock v-if="catalog.loadingProducts" custom-class="h-20" />
          <EmptyState v-else-if="!sellableProducts.length" title="Товары не найдены" text="Для продажи нужен товар с остатком на витрине." />
          <button v-for="product in sellableProducts" :key="product.id" class="flex w-full items-center justify-between gap-3 rounded-md border border-slate-200 p-3 text-left transition hover:border-emerald-300 dark:border-slate-800 dark:hover:border-emerald-700" @click.stop="addToCart(product)">
            <div class="min-w-0">
              <div class="truncate text-sm font-medium">{{ product.name }}</div>
              <div class="text-xs text-slate-500">{{ product.sku }} · витрина {{ product.display_quantity }}</div>
            </div>
            <div class="text-sm font-semibold">{{ money(product.sale_price) }}</div>
          </button>
        </div>
      </div>

      <div class="panel p-4">
        <h2 class="mb-4 font-semibold">Заявка</h2>
        <EmptyState v-if="!cart.length" title="Корзина пустая" text="Выберите товары из списка слева." :icon="ShoppingCart" />
        <form v-else class="space-y-4" @submit.prevent="createSale">
          <div class="space-y-2">
            <div v-for="item in cart" :key="item.product.id" class="grid grid-cols-[1fr_6rem_2.5rem] items-center gap-2 rounded-md bg-slate-50 p-3 dark:bg-slate-900">
              <div class="min-w-0">
                <div class="truncate text-sm font-medium">{{ item.product.name }}</div>
                <div class="text-xs text-slate-500">{{ money(item.product.sale_price) }} · доступно {{ item.product.display_quantity }}</div>
              </div>
              <input v-model.number="item.quantity" class="input h-9" type="number" min="1" :max="item.product.display_quantity" />
              <button class="btn-muted h-9 w-9 px-0" type="button" @click="removeFromCart(item.product.id)"><X class="h-4 w-4" /></button>
            </div>
          </div>
          <textarea v-model="cashierNote" class="textarea" placeholder="Комментарий" />
          <div class="flex items-center justify-between gap-3 border-t border-slate-200 pt-4 dark:border-slate-800">
            <div>
              <div class="text-sm text-slate-500">Итого</div>
              <div class="text-xl font-semibold">{{ money(cartTotal) }}</div>
            </div>
            <button class="btn-primary" :disabled="saving">
              <LoaderCircle v-if="saving" class="h-4 w-4 animate-spin" />Оформить продажу
            </button>
          </div>
        </form>
      </div>
    </section>

    <section class="panel overflow-hidden">
      <div class="flex flex-col gap-3 border-b border-slate-200 p-4 dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between">
        <h2 class="font-semibold">История продаж</h2>
        <select v-model="historyStatus" class="select sm:max-w-48" @change="fetchHistory(1)">
          <option value="">Все статусы</option>
          <option value="approved">Оформлены</option>
          <option value="rejected">Отклонены</option>
        </select>
      </div>
      <EmptyState v-if="!sales.loading && !sales.sales.length" class="m-4" title="История пустая" />
      <div v-else class="divide-y divide-slate-200 dark:divide-slate-800">
        <div v-for="sale in sales.sales" :key="sale.id" class="card grid gap-3 p-4 lg:grid-cols-[1fr_auto] lg:items-center">
          <div>
            <div class="flex flex-wrap items-center gap-2">
              <h3 class="font-medium">{{ sale.number }}</h3>
              <span class="badge" :class="saleStatusClass(sale.status)">{{ sale.status }}</span>
            </div>
            <p class="mt-1 text-sm text-slate-500">{{ sale.cashier?.name }} · {{ date(sale.created_at) }}</p>
            <div class="mt-2 flex flex-wrap gap-2 text-xs text-slate-500">
              <span v-for="item in sale.items" :key="item.id">{{ item.product?.name }} × {{ item.quantity }}</span>
            </div>
          </div>
          <div class="text-right">
            <div class="font-semibold">{{ money(sale.subtotal) }}</div>
            <div class="text-xs text-slate-500">прибыль {{ money(sale.profit) }}</div>
            <div class="mt-2">
              <button v-if="auth.isAdmin" class="btn-danger h-8 px-3" @click="confirmDeleteSale(sale)">Удалить</button>
            </div>
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
const historyStatus = ref('')
const cart = ref([])
const cashierNote = ref('')
const saving = ref(false)
let debounce
let timer

const sellableProducts = computed(() => catalog.products.filter((product) => product.display_quantity > 0 && product.status !== 'archived'))
const cartTotal = computed(() => cart.value.reduce((sum, item) => sum + Number(item.product.sale_price) * Number(item.quantity || 0), 0))

function addToCart(product) {
  const existing = cart.value.find((item) => item.product.id === product.id)
  if (existing) {
    existing.quantity = Math.min(existing.quantity + 1, product.display_quantity)
    return
  }
  cart.value.push({ product, quantity: 1 })
}

function removeFromCart(productId) {
  cart.value = cart.value.filter((item) => item.product.id !== productId)
}

async function createSale() {
  if (!cart.value.length) return
  saving.value = true
  try {
    await sales.create({
      items: cart.value.map((item) => ({ product_id: item.product.id, quantity: item.quantity })),
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
  await catalog.fetchProducts({ search: productSearch.value || undefined, per_page: 12 })
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
    await Promise.all([fetchProducts(), fetchHistory(sales.meta.current_page)])
  } catch (error) {
    toast.push(apiError(error), 'error')
  }
}

function money(value) {
  return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'KZT', maximumFractionDigits: 0 }).format(Number(value || 0))
}

function date(value) {
  return value ? new Date(value).toLocaleString('ru-RU') : '—'
}

function saleStatusClass(status) {
  return {
    pending: 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
    approved: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
    rejected: 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300',
  }[status]
}

onMounted(() => {
  refreshAll()
  timer = window.setInterval(refreshAll, 10000)
})
async function confirmDeleteSale(sale) {
  if (!confirm(`Удалить продажу ${sale.number}?`)) return
  try {
    await sales.deleteSale(sale.id)
    toast.push('Продажа удалена')
    await refreshAll()
  } catch (error) {
    toast.push(apiError(error), 'error')
  }
}

onUnmounted(() => window.clearInterval(timer))
</script>
