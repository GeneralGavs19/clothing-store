<template>
  <div class="space-y-5">
    <!-- Шапка текущей рабочей смены и быстрые действия -->
    <div class="panel p-5 bg-gradient-to-r from-emerald-500/10 via-sky-500/5 to-transparent border-emerald-200/80 dark:border-emerald-900/50">
      <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <div class="flex items-center gap-2">
            <span class="inline-flex h-2.5 w-2.5 rounded-full bg-emerald-500 animate-pulse" />
            <span class="text-xs font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-300">
              Текущая рабочая смена
            </span>
          </div>
          <h1 class="text-xl sm:text-2xl font-black text-slate-900 dark:text-white mt-1">
            Задачи и показатели смены
          </h1>
          <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
            {{ todayFormatted }} · Кассир: <strong class="text-slate-700 dark:text-slate-200">{{ auth.user?.name || 'Сотрудник' }}</strong>
          </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <RouterLink
            to="/sales"
            class="btn-primary h-10 px-4 text-xs sm:text-sm font-bold flex items-center gap-2 shadow-sm"
          >
            <ShoppingCart class="h-4 w-4" />
            <span>Оформить продажу (Касса)</span>
          </RouterLink>

          <RouterLink
            to="/sales"
            class="rounded-lg border border-amber-300 bg-amber-50 px-3.5 py-2 text-xs sm:text-sm font-bold text-amber-900 transition hover:bg-amber-100 dark:border-amber-800 dark:bg-amber-950/60 dark:text-amber-200 flex items-center gap-1.5"
          >
            <Package class="h-4 w-4 text-amber-600 dark:text-amber-400" />
            <span>+ Заказ на пошив</span>
          </RouterLink>

          <button
            type="button"
            class="btn-muted h-10 w-10 px-0 shrink-0"
            title="Обновить данные смены"
            @click="refresh"
          >
            <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': dashboard.loading }" />
          </button>
        </div>
      </div>
    </div>

    <!-- 4 Главных KPI текущей смены (без складских остатков) -->
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
      <SkeletonBlock v-if="dashboard.loading" v-for="i in 4" :key="i" />
      <template v-else>
        <!-- 1. Выручка за сегодня -->
        <StatCard
          title="Выручка смены (сегодня)"
          :value="money(shiftData.today_revenue || summary.today_revenue || 0)"
          :hint="`${shiftData.today_items_sold || summary.today_items_sold || 0} шт. детской одежды продано`"
          to="/sales"
          tone="emerald"
        >
          <CircleDollarSign class="h-4 w-4" />
        </StatCard>

        <!-- 2. Чеков сегодня -->
        <StatCard
          title="Чеков оформлено"
          :value="shiftData.today_sales || summary.today_sales || 0"
          hint="Фактически завершённых продаж за сегодня"
          to="/sales"
          tone="sky"
        >
          <ReceiptText class="h-4 w-4" />
        </StatCard>

        <!-- 3. Средний чек -->
        <StatCard
          title="Средний чек"
          :value="money(shiftData.today_avg_check || summary.today_avg_check || 0)"
          hint="Средняя сумма одной покупки сегодня"
          to="/sales"
          tone="indigo"
        >
          <TrendingUp class="h-4 w-4" />
        </StatCard>

        <!-- 4. Заказы под заказ в работе -->
        <StatCard
          title="Заказы в работе"
          :value="shiftData.active_orders_count ?? 0"
          hint="Требуют обработки, пошива или выдачи"
          to="/sales"
          tone="amber"
        >
          <Package class="h-4 w-4" />
        </StatCard>
      </template>
    </div>

    <!-- Две операционные секции смены: Заказы под заказ и Последние продажи -->
    <div class="grid grid-cols-1 gap-5 xl:grid-cols-[1.2fr_1fr]">
      <!-- 1. Операционные задачи: Заказы на пошив / под заказ в работе -->
      <section class="panel p-4 flex flex-col">
        <div class="mb-3 flex items-center justify-between border-b border-slate-200 pb-3 dark:border-slate-800">
          <div>
            <div class="flex items-center gap-2">
              <Package class="h-4 w-4 text-amber-600 dark:text-amber-400" />
              <h2 class="font-bold text-base text-slate-900 dark:text-white">Заказы под заказ в работе</h2>
            </div>
            <p class="text-xs text-slate-500 mt-0.5">Предзаказы и школьная форма, требующие пошива и выдачи клиентам</p>
          </div>
          <RouterLink
            to="/sales"
            class="text-xs font-semibold text-amber-600 hover:text-amber-700 dark:text-amber-400"
          >
            Все заказы →
          </RouterLink>
        </div>

        <div v-if="!activeOrders.length" class="my-auto py-8">
          <EmptyState
            title="Нет активных заказов"
            text="Все заказы на пошив либо выданы покупателям, либо еще не оформлялись."
          />
        </div>

        <div v-else class="space-y-2.5 overflow-y-auto max-h-[22rem] pr-1">
          <div
            v-for="order in activeOrders"
            :key="order.id"
            class="rounded-xl border border-amber-200/80 bg-amber-50/40 p-3 transition hover:border-amber-400 dark:border-amber-900/60 dark:bg-amber-950/20"
          >
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                  <span class="font-bold text-slate-900 dark:text-white text-sm">
                    Чек №{{ order.id }}
                  </span>
                  <!-- Бейдж статуса выполнения заказа -->
                  <span
                    class="rounded-full px-2 py-0.5 text-[11px] font-bold border"
                    :class="getOrderStatusBadgeClass(order.fulfillment_status)"
                  >
                    {{ getOrderStatusLabel(order.fulfillment_status) }}
                  </span>

                  <!-- Срок готовности -->
                  <span
                    v-if="order.due_date"
                    class="rounded bg-white px-2 py-0.5 text-[11px] font-semibold text-amber-900 border border-amber-200 dark:border-amber-800 dark:bg-slate-900 dark:text-amber-300 inline-flex items-center gap-1"
                  >
                    <Calendar class="h-3 w-3 text-amber-600" />
                    Срок: {{ formatDate(order.due_date) }}
                  </span>
                </div>

                <!-- Состав заказа -->
                <div class="mt-1.5 text-xs text-slate-700 dark:text-slate-300">
                  <span
                    v-for="(item, idx) in order.items"
                    :key="idx"
                    class="inline-block mr-2"
                  >
                    <strong>{{ item.product_name }}</strong> (р. {{ item.variant_size }}) × {{ item.quantity }} шт.
                  </span>
                </div>

                <div v-if="order.cashier_note" class="mt-1 text-[11px] text-slate-500 italic">
                  Примечание: {{ order.cashier_note }}
                </div>
              </div>

              <div class="text-right shrink-0">
                <div class="text-sm font-black text-emerald-600 dark:text-emerald-400">
                  {{ money(order.subtotal) }}
                </div>
                <RouterLink
                  to="/sales"
                  class="mt-1 inline-block text-[11px] font-semibold text-slate-500 hover:text-slate-900 dark:hover:text-white"
                >
                  Открыть в кассе →
                </RouterLink>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- 2. Последние продажи текущей смены -->
      <section class="panel p-4 flex flex-col">
        <div class="mb-3 flex items-center justify-between border-b border-slate-200 pb-3 dark:border-slate-800">
          <div>
            <div class="flex items-center gap-2">
              <ReceiptText class="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
              <h2 class="font-bold text-base text-slate-900 dark:text-white">Последние продажи смены</h2>
            </div>
            <p class="text-xs text-slate-500 mt-0.5">Операции, оформленные за сегодня</p>
          </div>
          <RouterLink
            to="/sales"
            class="text-xs font-semibold text-emerald-600 hover:text-emerald-700 dark:text-emerald-400"
          >
            Все чеки →
          </RouterLink>
        </div>

        <div v-if="!recentSales.length" class="my-auto py-8">
          <EmptyState
            title="Продаж сегодня пока нет"
            text="Оформите первую продажу на кассе, чтобы зафиксировать операции смены."
          />
        </div>

        <div v-else class="space-y-2 overflow-y-auto max-h-[22rem] pr-1">
          <div
            v-for="sale in recentSales"
            :key="sale.id"
            class="flex items-center justify-between rounded-lg border border-slate-100 bg-slate-50/70 p-2.5 transition hover:border-slate-300 dark:border-slate-800 dark:bg-slate-900/60"
          >
            <div class="min-w-0 flex-1">
              <div class="flex items-center gap-2">
                <span class="font-bold text-slate-900 dark:text-white text-xs">Чек №{{ sale.id }}</span>
                <span class="text-[11px] text-slate-500">{{ date(sale.sold_at || sale.approved_at) }}</span>
                <span
                  class="rounded px-1.5 py-0.2 text-[10px] font-semibold"
                  :class="sale.is_on_order ? 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300' : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'"
                >
                  {{ sale.is_on_order ? 'На заказ' : 'В наличии' }}
                </span>
              </div>
              <div class="mt-0.5 truncate text-xs text-slate-600 dark:text-slate-400">
                <span v-for="(it, i) in sale.items" :key="i" class="mr-1.5">
                  {{ it.product_name }} ({{ it.variant_size }}) × {{ it.quantity }}
                </span>
              </div>
            </div>
            <div class="text-right shrink-0 ml-3">
              <div class="font-bold text-sm tabular-nums text-slate-900 dark:text-white">
                {{ money(sale.subtotal) }}
              </div>
            </div>
          </div>
        </div>
      </section>
    </div>

    <!-- График динамики продаж за 14 дней -->
    <section class="panel p-4">
      <div class="mb-4 flex items-center justify-between gap-3">
        <div>
          <h2 class="font-bold text-base leading-none">Динамика выручки по дням</h2>
          <p class="mt-1 text-xs text-slate-500">Последние 14 дней фактически завершённых продаж (₸)</p>
        </div>
      </div>
      <div v-if="!salesByDay.length" class="h-56">
        <EmptyState title="Данных пока нет" text="График заполнится автоматически после оформления продаж." />
      </div>
      <div v-else class="overflow-x-auto rounded-md bg-slate-50 p-3 dark:bg-slate-900">
        <SalesDayChart :days="salesByDay" />
      </div>
    </section>

    <!-- Нижний блок: Популярные товары и активность кассиров -->
    <div class="grid gap-5 xl:grid-cols-2">
      <section class="panel p-4">
        <h2 class="mb-1 font-bold text-base">Самые продаваемые модели</h2>
        <p class="mb-4 text-xs text-slate-500">Лидеры спроса за последние 14 дней</p>
        <EmptyState v-if="!topProducts.length" title="Нет данных о продажах" />
        <div v-else class="space-y-3">
          <div v-for="(item, index) in topProducts.slice(0, 5)" :key="item.id" class="flex items-center gap-3">
            <span class="w-5 shrink-0 text-xs font-bold text-slate-400">{{ index + 1 }}</span>
            <div class="h-10 w-10 shrink-0 overflow-hidden rounded-md bg-slate-100 dark:bg-slate-800">
              <img
                v-if="item.photo_url"
                :src="photoUrl(item.photo_url)"
                class="h-full w-full object-cover"
                :alt="item.name"
                loading="lazy"
              />
              <div v-else class="flex h-full w-full items-center justify-center text-slate-400">
                <Package class="h-4 w-4" />
              </div>
            </div>
            <div class="min-w-0 flex-1">
              <div class="truncate text-sm font-semibold">{{ item.name }}</div>
              <div class="text-xs text-slate-500">{{ item.quantity }} шт. продано · {{ money(item.revenue) }}</div>
            </div>
          </div>
        </div>
      </section>

      <section class="panel p-4">
        <h2 class="mb-1 font-bold text-base">Сотрудники и кассиры</h2>
        <p class="mb-4 text-xs text-slate-500">Оформленные продажи в системе</p>
        <EmptyState v-if="!cashiers.length" title="Кассиры не найдены" />
        <div v-else class="space-y-3">
          <div v-for="cashier in cashiers" :key="cashier.id" class="rounded-lg bg-slate-50 p-3 dark:bg-slate-900 flex items-center justify-between">
            <div>
              <div class="font-semibold text-sm">{{ cashier.name }}</div>
              <div class="text-[11px] text-slate-400">{{ cashier.email }}</div>
            </div>
            <span class="badge bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 font-bold">
              {{ cashier.approved_sales_count }} прод.
            </span>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted } from 'vue'
import {
  Calendar,
  CircleDollarSign,
  Package,
  ReceiptText,
  RefreshCw,
  ShoppingCart,
  TrendingUp,
} from 'lucide-vue-next'
import { RouterLink } from 'vue-router'
import { apiError } from '../api/client'
import { resolveApiOrigin } from '../config/api'
import { useAuthStore } from '../stores/auth'
import { useDashboardStore } from '../stores/dashboard'
import { useToastStore } from '../stores/toasts'
import SalesDayChart from '../components/SalesDayChart.vue'
import EmptyState from '../components/ui/EmptyState.vue'
import SkeletonBlock from '../components/ui/SkeletonBlock.vue'
import StatCard from '../components/StatCard.vue'
import { date, formatDate, money } from '../utils/formatters'

const auth = useAuthStore()
const dashboard = useDashboardStore()
const toast = useToastStore()
let timer

const apiBase = computed(() => resolveApiOrigin())

const summary = computed(() => dashboard.data?.summary || {})
const shiftData = computed(() => dashboard.data?.shift || {})
const salesByDay = computed(() => dashboard.data?.sales_by_day || [])
const topProducts = computed(() => dashboard.data?.top_products || [])
const cashiers = computed(() => dashboard.data?.cashier_activity || [])
const activeOrders = computed(() => shiftData.value?.active_orders || [])
const recentSales = computed(() => shiftData.value?.recent_sales || [])

const todayFormatted = computed(() => {
  const d = new Date()
  return d.toLocaleDateString('ru-RU', {
    weekday: 'long',
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  })
})

function getOrderStatusLabel(status) {
  switch (status) {
    case 'in_progress':
      return 'В пошиве'
    case 'ready':
      return 'Готов к выдаче'
    case 'delivered':
      return 'Выдан'
    case 'cancelled':
      return 'Отменен'
    default:
      return 'Новый заказ'
  }
}

function getOrderStatusBadgeClass(status) {
  switch (status) {
    case 'in_progress':
      return 'border-sky-300 bg-sky-50 text-sky-900 dark:border-sky-800 dark:bg-sky-950 dark:text-sky-300'
    case 'ready':
      return 'border-emerald-300 bg-emerald-50 text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
    case 'delivered':
      return 'border-slate-300 bg-slate-100 text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300'
    case 'cancelled':
      return 'border-rose-300 bg-rose-50 text-rose-900 dark:border-rose-800 dark:bg-rose-950 dark:text-rose-300'
    default:
      return 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-300'
  }
}

function photoUrl(path) {
  if (!path) return ''
  return path.startsWith('http') ? path : `${apiBase.value}${path}`
}

async function refresh() {
  try {
    await dashboard.fetch()
  } catch (error) {
    toast.push(apiError(error), 'error')
  }
}

onMounted(() => {
  refresh()
  timer = window.setInterval(refresh, 15000)
})

onUnmounted(() => window.clearInterval(timer))
</script>
