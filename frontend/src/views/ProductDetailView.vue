<template>
  <div class="space-y-5">
    <div class="flex flex-wrap items-center gap-3">
      <RouterLink :to="{ name: 'products' }" class="btn-muted h-9 px-3 text-sm">← Товары</RouterLink>
      <button v-if="auth.canManageCatalog" type="button" class="btn-primary h-9 px-3 text-sm" @click="openEdit">
        Редактировать
      </button>
    </div>

    <SkeletonBlock v-if="loading" custom-class="h-64" />

    <template v-else-if="product">
      <section class="panel overflow-hidden">
        <div class="flex flex-col gap-5 p-5 sm:flex-row">
          <div class="mx-auto h-28 w-28 shrink-0 overflow-hidden rounded-xl border border-slate-200 bg-slate-100 dark:border-slate-700 dark:bg-slate-800 sm:mx-0">
            <img v-if="product.photo_url" :src="photoUrl(product.photo_url)" class="h-full w-full object-cover" alt="" />
            <div v-else class="flex h-full w-full items-center justify-center text-slate-400">—</div>
          </div>
          <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
              <h1 class="text-2xl font-semibold tracking-tight">{{ product.name }}</h1>
              <span class="badge" :class="statusClass(product.status)">{{ statusLabel(product.status) }}</span>
            </div>
            <p class="mt-1 text-sm text-slate-500">
              {{ product.category?.name || 'Без категории' }}
              <span v-if="product.color"> · {{ product.color }}</span>
            </p>
            <p v-if="product.description" class="mt-3 text-sm leading-relaxed text-slate-600 dark:text-slate-300">
              {{ product.description }}
            </p>
            <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
              <Stat label="Цена" :value="money(product.sale_price)" />
              <Stat label="В магазине" :value="String(product.display_quantity ?? 0)" />
              <Stat label="Всего единиц" :value="String(totalItems)" />
            </div>
          </div>
        </div>
      </section>

      <section class="panel overflow-hidden">
        <div class="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
          <h2 class="font-semibold">Размеры и остатки</h2>
        </div>
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-xs text-slate-500 dark:bg-slate-900">
              <tr>
                <th class="px-4 py-2.5 text-left font-medium">Размер</th>
                <th class="px-4 py-2.5 text-right font-medium">В магазине</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
              <tr v-for="variant in product.variants || []" :key="variant.id">
                <td class="px-4 py-3 font-medium">{{ variant.size }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ variant.stock_store ?? 0 }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="panel overflow-hidden">
        <div class="flex flex-col gap-3 border-b border-slate-200 p-4 dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 class="font-semibold">Единицы товара</h2>
            <p class="text-xs text-slate-500">Уникальный код каждой физической единицы</p>
          </div>
          <div class="flex flex-wrap gap-2">
            <input v-model="itemSearch" class="input h-9 max-w-xs" placeholder="Поиск по коду…" />
            <select v-model="itemFilter" class="select h-9 max-w-[10rem]">
              <option value="">Все</option>
              <option value="available">В наличии</option>
              <option value="sold">Продано</option>
            </select>
            <select v-model="sizeFilter" class="select h-9 max-w-[8rem]">
              <option value="">Все размеры</option>
              <option v-for="v in product.variants || []" :key="v.id" :value="v.id">{{ v.size }}</option>
            </select>
          </div>
        </div>
        <div class="max-h-[28rem] overflow-y-auto">
          <table class="min-w-full text-sm">
            <thead class="sticky top-0 bg-slate-50 text-xs text-slate-500 dark:bg-slate-900">
              <tr>
                <th class="px-4 py-2 text-left font-medium">Код</th>
                <th class="px-4 py-2 text-left font-medium">Размер</th>
                <th class="px-4 py-2 text-left font-medium">Место</th>
                <th class="px-4 py-2 text-left font-medium">Статус</th>
                <th class="px-4 py-2 text-left font-medium">Продажа</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
              <tr v-for="item in filteredItems" :key="item.id" class="hover:bg-slate-50/80 dark:hover:bg-slate-900/50">
                <td class="px-4 py-2 font-mono text-xs">{{ item.code }}</td>
                <td class="px-4 py-2">{{ itemVariantSize(item) }}</td>
                <td class="px-4 py-2 text-slate-500">{{ locationLabel(item.location) }}</td>
                <td class="px-4 py-2">
                  <span class="badge" :class="item.status === 'sold' ? 'bg-slate-100 text-slate-600 dark:bg-slate-800' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'">
                    {{ item.status === 'sold' ? 'Продано' : 'В наличии' }}
                  </span>
                </td>
                <td class="px-4 py-2 text-xs text-slate-500">{{ item.sold_at ? date(item.sold_at) : '—' }}</td>
              </tr>
            </tbody>
          </table>
          <p v-if="!filteredItems.length" class="p-6 text-center text-sm text-slate-500">Нет единиц по выбранным фильтрам</p>
        </div>
      </section>
    </template>

    <EmptyState v-else title="Товар не найден" text="Вернитесь к списку товаров." />
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { resolveApiOrigin } from '../config/api'
import { useAuthStore } from '../stores/auth'
import { useCatalogStore } from '../stores/catalog'
import EmptyState from '../components/ui/EmptyState.vue'
import SkeletonBlock from '../components/ui/SkeletonBlock.vue'

const props = defineProps({ id: { type: [String, Number], required: true } })

const auth = useAuthStore()
const catalog = useCatalogStore()
const router = useRouter()
const product = ref(null)
const loading = ref(true)
const itemSearch = ref('')
const itemFilter = ref('')
const sizeFilter = ref('')

const apiBase = computed(() => resolveApiOrigin())

const variantById = computed(() => {
  const map = new Map()
  for (const v of product.value?.variants || []) map.set(Number(v.id), v)
  return map
})

const allItems = computed(() => {
  const rows = []
  for (const variant of product.value?.variants || []) {
    for (const item of variant.items || []) {
      rows.push({ ...item, _variantId: variant.id, _size: variant.size })
    }
  }
  return rows.sort((a, b) => String(a.code).localeCompare(String(b.code)))
})

const filteredItems = computed(() => {
  let list = allItems.value
  if (itemFilter.value) list = list.filter((i) => i.status === itemFilter.value)
  if (sizeFilter.value) list = list.filter((i) => Number(i._variantId) === Number(sizeFilter.value))
  const q = itemSearch.value.trim().toLowerCase()
  if (q) list = list.filter((i) => String(i.code).toLowerCase().includes(q))
  return list
})

const totalItems = computed(() => allItems.value.length)

async function load() {
  loading.value = true
  try {
    product.value = await catalog.fetchProduct(props.id)
  } catch {
    product.value = null
  } finally {
    loading.value = false
  }
}

function openEdit() {
  router.push({ name: 'products', query: { edit: product.value?.id } })
}

function variantStock(v) {
  return Number(v.stock_store ?? 0) + Number(v.stock_warehouse ?? 0)
}

function itemVariantSize(item) {
  return item._size || variantById.value.get(Number(item.product_variant_id))?.size || '—'
}

function locationLabel(loc) {
  return loc === 'stock' ? 'Склад' : 'Магазин'
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
  return { active: 'В наличии', low_stock: 'Мало', out_of_stock: 'Нет в наличии' }[status] || status
}

function statusClass(status) {
  return {
    active: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
    low_stock: 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
    out_of_stock: 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300',
  }[status]
}

watch(() => props.id, load)
onMounted(load)
</script>

<script>
export default {
  components: {
    Stat: {
      props: ['label', 'value'],
      template:
        '<div class="rounded-lg bg-slate-50 px-3 py-2 dark:bg-slate-900"><div class="text-xs text-slate-500">{{ label }}</div><div class="mt-0.5 font-semibold tabular-nums">{{ value }}</div></div>',
    },
  },
}
</script>
