<template>
  <div class="space-y-5">
    <!-- Верхняя панель: Переключатели «Основной завоз» vs «Новый завоз» и действия -->
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <!-- Табы двух завозов -->
      <div class="inline-flex rounded-xl bg-slate-100 p-1 dark:bg-slate-800">
        <button
          type="button"
          class="flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition"
          :class="activeBatchType === 'standard'
            ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-950 dark:text-white'
            : 'text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white'"
          @click="setBatchType('standard')"
        >
          <Package class="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
          <span>Основной завоз</span>
          <span class="rounded-full bg-slate-200/90 px-2 py-0.5 text-xs font-bold text-slate-700 dark:bg-slate-700 dark:text-slate-200">
            {{ catalog.batchCounters.standard }}
          </span>
        </button>

        <button
          type="button"
          class="flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition"
          :class="activeBatchType === 'new_batch'
            ? 'bg-white text-amber-900 shadow-sm dark:bg-slate-950 dark:text-amber-300'
            : 'text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white'"
          @click="setBatchType('new_batch')"
        >
          <Sparkles class="h-4 w-4 text-amber-500" />
          <span>Новый завоз</span>
          <span
            class="rounded-full px-2 py-0.5 text-xs font-bold transition"
            :class="catalog.batchCounters.new_batch > 0
              ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/80 dark:text-amber-300'
              : 'bg-slate-200/90 text-slate-700 dark:bg-slate-700 dark:text-slate-200'"
          >
            {{ catalog.batchCounters.new_batch }}
          </span>
        </button>
      </div>

      <!-- Кнопки действий -->
      <div class="flex flex-wrap items-center gap-2">
        <button
          v-if="activeBatchType === 'new_batch' && selectedProductIds.length > 0 && auth.canManageCatalog"
          type="button"
          class="btn-primary !bg-amber-600 hover:!bg-amber-700 text-white shadow-sm flex items-center gap-1.5"
          @click="openBulkTransferConfirm"
        >
          <ArrowRightLeft class="h-4 w-4" />
          <span>Перенести выбранные ({{ selectedProductIds.length }})</span>
        </button>

        <button v-if="auth.canManageCatalog" class="btn-primary" @click="openCreate">
          <Plus class="h-4 w-4" />
          <span>{{ activeBatchType === 'new_batch' ? 'Товар в новый завоз' : 'Товар' }}</span>
        </button>

        <button v-if="auth.canManageCatalog && activeBatchType === 'standard'" class="btn-muted" @click="openImport">
          Импорт CSV
        </button>
      </div>
    </div>

    <!-- Информационный баннер для Нового завоза -->
    <div
      v-if="activeBatchType === 'new_batch'"
      class="rounded-xl border border-amber-200 bg-amber-50/70 p-4 text-xs text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-200 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 shadow-xs"
    >
      <div class="flex items-start gap-2.5">
        <Sparkles class="h-5 w-5 shrink-0 text-amber-500 mt-0.5" />
        <div>
          <span class="font-semibold text-sm block">Временный реестр поступивших товаров («Новый завоз»)</span>
          <p class="text-amber-800 dark:text-amber-300 mt-0.5">
            Товары, поступившие недавно. Вы можете проверить и отредактировать параметры, перенести их в основной ассортимент как новые товары либо объединить с существующими моделями без создания дубликатов.
          </p>
        </div>
      </div>
      <div v-if="selectedProductIds.length > 0" class="shrink-0 flex items-center gap-2">
        <span class="font-semibold text-xs text-amber-900 dark:text-amber-100">Выбрано: {{ selectedProductIds.length }}</span>
        <button type="button" class="underline text-amber-700 hover:text-amber-900 dark:text-amber-300 font-medium" @click="selectedProductIds = []">
          Снять выбор
        </button>
      </div>
    </div>

    <!-- Панель фильтров и поиска -->
    <section class="panel p-4">
      <div class="flex flex-col gap-3">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
          <input
            v-model="filters.search"
            class="input"
            :placeholder="activeBatchType === 'new_batch' ? 'Поиск по названию, примечанию, цвету...' : 'Поиск по названию, цвету, размеру...'"
            @input="debouncedFetch"
          />

          <select v-model="filters.category_id" class="select" @change="fetchProducts(1)">
            <option value="">Все категории</option>
            <option v-for="category in catalog.categories" :key="category.id" :value="category.id">{{ category.name }}</option>
          </select>

          <select v-model="filters.type" class="select" @change="fetchProducts(1)">
            <option value="">Все виды</option>
            <option v-for="type in popularTypes" :key="type" :value="type">{{ type }}</option>
          </select>

          <select v-if="activeBatchType === 'standard'" v-model="filters.status" class="select" @change="fetchProducts(1)">
            <option value="">Все статусы</option>
            <option value="active">Активен</option>
            <option value="low_stock">Низкий остаток</option>
            <option value="out_of_stock">Нет в наличии</option>
          </select>

          <select v-model="filters.sort" class="select" @change="fetchProducts(1)">
            <option value="updated_at">По обновлению</option>
            <option value="name">По названию</option>
            <option value="sale_price">По цене</option>
            <option v-if="activeBatchType === 'new_batch'" value="new_batch_sold">По продажам в партии</option>
            <option v-if="activeBatchType === 'new_batch'" value="batch_period">По дате поступления</option>
            <option v-if="activeBatchType === 'standard'" value="total_sold">По продажам (всего)</option>
            <option v-if="activeBatchType === 'standard'" value="display_quantity">По магазину</option>
          </select>
        </div>
      </div>
    </section>

    <!-- Основной блок списка товаров -->
    <section class="panel overflow-hidden">
      <div v-if="catalog.loadingProducts" class="grid gap-3 p-4 md:grid-cols-2 xl:grid-cols-3">
        <SkeletonBlock v-for="i in 6" :key="i" custom-class="h-44" />
      </div>

      <EmptyState
        v-else-if="!catalog.products.length"
        class="m-4"
        :title="activeBatchType === 'new_batch' ? 'В новом завозе нет товаров' : 'Товары не найдены'"
        :text="activeBatchType === 'new_batch'
          ? 'Все поступившие товары уже проверены и перенесены в основной ассортимент магазина.'
          : 'Измените фильтры или добавьте первый товар.'"
      />

      <!-- РЕЖИМ 1: ТАБЛИЦА НОВОГО ЗАВОЗА -->
      <div v-else-if="activeBatchType === 'new_batch'">
        <!-- Десктопная / планшетная таблица -->
        <div class="hidden md:block overflow-x-auto">
          <table class="w-full text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50/75 text-xs text-slate-500 dark:border-slate-800 dark:bg-slate-900">
              <tr>
                <th v-if="auth.canManageCatalog" class="w-10 px-3 py-3 text-center">
                  <input
                    type="checkbox"
                    class="rounded border-slate-300 text-amber-600 focus:ring-amber-500"
                    :checked="isAllSelected"
                    @change="toggleSelectAll"
                  />
                </th>
                <th class="px-3 py-3 font-semibold text-slate-700 dark:text-slate-300">Товар</th>
                <th class="px-3 py-3 font-semibold text-slate-700 dark:text-slate-300">Вид</th>
                <th class="px-3 py-3 font-semibold text-slate-700 dark:text-slate-300">Размеры в сетке</th>
                <th class="px-3 py-3 font-semibold text-slate-700 dark:text-slate-300 text-center">Продано</th>
                <th class="px-3 py-3 font-semibold text-slate-700 dark:text-slate-300">Примечание</th>
                <th class="px-3 py-3 font-semibold text-slate-700 dark:text-slate-300">Поступление</th>
                <th class="px-3 py-3 font-semibold text-slate-700 dark:text-slate-300 text-right">Цена</th>
                <th class="px-3 py-3 font-semibold text-slate-700 dark:text-slate-300 text-right">Действия</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
              <tr
                v-for="product in catalog.products"
                :key="product.id"
                class="hover:bg-slate-50/60 dark:hover:bg-slate-900/60 transition"
                :class="selectedProductIds.includes(product.id) ? 'bg-amber-50/40 dark:bg-amber-950/20' : ''"
              >
                <td v-if="auth.canManageCatalog" class="px-3 py-3 text-center">
                  <input
                    type="checkbox"
                    class="rounded border-slate-300 text-amber-600 focus:ring-amber-500"
                    :checked="selectedProductIds.includes(product.id)"
                    @change="toggleSelectProduct(product.id)"
                  />
                </td>
                <td class="px-3 py-3">
                  <div class="flex items-center gap-2.5">
                    <RouterLink
                      :to="{ name: 'product-detail', params: { id: product.id } }"
                      class="h-10 w-10 shrink-0 overflow-hidden rounded-md border border-slate-200 bg-slate-100 dark:border-slate-800 dark:bg-slate-800 flex items-center justify-center hover:opacity-80 transition"
                    >
                      <img v-if="product.photo_url" :src="photoUrl(product.photo_url)" class="h-full w-full object-cover" />
                      <Package v-else class="h-4 w-4 text-slate-400" />
                    </RouterLink>
                    <div class="min-w-0">
                      <RouterLink
                        :to="{ name: 'product-detail', params: { id: product.id } }"
                        class="font-semibold block truncate leading-tight hover:text-emerald-600 transition"
                      >
                        {{ product.name }}
                      </RouterLink>
                      <span class="text-xs text-slate-500">{{ product.color || product.category?.name || 'Без категории' }}</span>
                    </div>
                  </div>
                </td>
                <td class="px-3 py-3">
                  <span class="rounded bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
                    {{ product.type || '—' }}
                  </span>
                </td>
                <td class="px-3 py-3">
                  <div class="flex flex-wrap gap-1 max-w-[220px]">
                    <template v-if="product.variants?.length">
                      <span
                        v-for="v in product.variants"
                        :key="v.id"
                        class="rounded border border-slate-200 bg-white px-1.5 py-0.5 text-[11px] font-semibold text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                      >
                        {{ v.size || 'Единый' }}
                      </span>
                    </template>
                    <span v-else class="text-xs text-slate-400">—</span>
                  </div>
                </td>
                <td class="px-3 py-3 text-center">
                  <span class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-bold text-amber-800 dark:bg-amber-950/80 dark:text-amber-300">
                    {{ product.new_batch_sold ?? product.total_sold ?? 0 }} шт.
                  </span>
                </td>
                <td class="px-3 py-3">
                  <span class="text-xs text-slate-600 dark:text-slate-300 flex items-center gap-1 font-medium">
                    <Tag class="h-3 w-3 text-slate-400" />
                    {{ product.batch_note || 'Новый завоз' }}
                  </span>
                </td>
                <td class="px-3 py-3">
                  <span class="text-xs text-slate-600 dark:text-slate-400 flex items-center gap-1">
                    <Calendar class="h-3 w-3 text-slate-400" />
                    {{ product.batch_period || '—' }}
                  </span>
                </td>
                <td class="px-3 py-3 text-right font-semibold tabular-nums">
                  {{ money(product.sale_price) }}
                </td>
                <td class="px-3 py-3 text-right">
                  <div class="flex items-center justify-end gap-1.5">
                    <button
                      v-if="auth.canManageCatalog"
                      type="button"
                      class="btn-primary h-8 px-2.5 text-xs !bg-emerald-600 hover:!bg-emerald-700 text-white font-semibold flex items-center gap-1 shadow-sm"
                      title="Перенести в основной завоз или объединить"
                      @click="openTransfer(product)"
                    >
                      <ArrowRightLeft class="h-3.5 w-3.5" />
                      <span>Перенести</span>
                    </button>
                    <button
                      v-if="auth.canManageCatalog"
                      type="button"
                      class="btn-icon h-8 w-8"
                      title="Редактировать товар"
                      @click="openEdit(product)"
                    >
                      <Pencil class="h-3.5 w-3.5" />
                    </button>
                    <button
                      v-if="auth.canManageCatalog"
                      type="button"
                      class="btn-icon h-8 w-8 !border-slate-300 !text-slate-600 hover:!text-slate-900"
                      title="Исключить из нового завоза (вернуть в обычный)"
                      @click="toggleBatchType(product)"
                    >
                      <ArrowUpRight class="h-3.5 w-3.5" />
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Мобильный адаптивный список Нового завоза (< md) -->
        <div class="md:hidden divide-y divide-slate-200 dark:divide-slate-800">
          <article
            v-for="product in catalog.products"
            :key="product.id"
            class="p-4 space-y-3"
            :class="selectedProductIds.includes(product.id) ? 'bg-amber-50/40 dark:bg-amber-950/20' : ''"
          >
            <div class="flex items-start gap-3">
              <input
                v-if="auth.canManageCatalog"
                type="checkbox"
                class="mt-1 rounded border-slate-300 text-amber-600 focus:ring-amber-500"
                :checked="selectedProductIds.includes(product.id)"
                @change="toggleSelectProduct(product.id)"
              />
              <div class="h-12 w-12 shrink-0 overflow-hidden rounded-md border border-slate-200 bg-slate-100 dark:border-slate-800 dark:bg-slate-800 flex items-center justify-center">
                <img v-if="product.photo_url" :src="photoUrl(product.photo_url)" class="h-full w-full object-cover" />
                <Package v-else class="h-5 w-5 text-slate-400" />
              </div>
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-1.5">
                  <span class="font-semibold text-base">{{ product.name }}</span>
                  <span v-if="product.type" class="rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
                    {{ product.type }}
                  </span>
                </div>
                <p class="text-xs text-slate-500 mt-0.5">{{ product.color || product.category?.name || 'Без категории' }}</p>
                <div class="mt-1 font-semibold text-sm">{{ money(product.sale_price) }}</div>
              </div>
            </div>

            <div class="flex flex-wrap items-center gap-2 text-xs text-slate-600 dark:text-slate-400">
              <span class="rounded-full bg-amber-100 px-2 py-0.5 font-bold text-amber-800 dark:bg-amber-950/80 dark:text-amber-300">
                Продано: {{ product.new_batch_sold ?? product.total_sold ?? 0 }} шт.
              </span>
              <span v-if="product.batch_period" class="flex items-center gap-1">
                <Calendar class="h-3 w-3 text-slate-400" /> {{ product.batch_period }}
              </span>
              <span v-if="product.batch_note" class="flex items-center gap-1">
                <Tag class="h-3 w-3 text-slate-400" /> {{ product.batch_note }}
              </span>
            </div>

            <div v-if="product.variants?.length" class="flex flex-wrap gap-1">
              <span
                v-for="v in product.variants"
                :key="v.id"
                class="rounded border border-slate-200 bg-white px-1.5 py-0.5 text-[10px] font-semibold text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
              >
                {{ v.size || 'Единый' }}
              </span>
            </div>

            <div class="flex items-center gap-2 pt-1">
              <button
                v-if="auth.canManageCatalog"
                type="button"
                class="btn-primary flex-1 h-9 text-xs !bg-emerald-600 hover:!bg-emerald-700 text-white font-semibold flex items-center justify-center gap-1.5"
                @click="openTransfer(product)"
              >
                <ArrowRightLeft class="h-3.5 w-3.5" />
                <span>Перенести в основной</span>
              </button>
              <button
                v-if="auth.canManageCatalog"
                type="button"
                class="btn-icon h-9 w-9"
                title="Редактировать"
                @click="openEdit(product)"
              >
                <Pencil class="h-4 w-4" />
              </button>
              <button
                v-if="auth.canManageCatalog"
                type="button"
                class="btn-icon h-9 w-9"
                title="Исключить в обычный"
                @click="toggleBatchType(product)"
              >
                <ArrowUpRight class="h-4 w-4" />
              </button>
            </div>
          </article>
        </div>
      </div>

      <!-- РЕЖИМ 2: СТАНДАРТНЫЙ СПИСОК ОСНОВНОГО ЗАВОЗА -->
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
                <span v-if="product.type" class="rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
                  {{ product.type }}
                </span>
                <span class="badge shrink-0" :class="statusClass(product.status)">{{ statusLabel(product.status) }}</span>
                <span
                  v-if="product.transferred_at"
                  class="rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700 dark:bg-amber-950/60 dark:text-amber-300"
                  title="Товар был перенесён из нового завоза"
                >
                  ✓ Перенесён из нового завоза
                </span>
              </div>
              <p class="mt-1 text-xs leading-relaxed text-slate-500">
                {{ variantSummaryLine(product) }}
              </p>
              <div class="mt-1.5 flex flex-wrap items-center gap-2 text-[11px] text-slate-500">
                <span class="rounded bg-emerald-50 px-2 py-0.5 font-semibold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
                  Продано: {{ product.total_sold || 0 }} шт.
                </span>
                <span class="rounded bg-slate-100 px-2 py-0.5 text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                  Размеров в сетке: {{ product.variants_count || product.variants?.length || 0 }}
                </span>
              </div>
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
                class="btn-icon !border-amber-200 !text-amber-600 hover:!bg-amber-50"
                title="Пометить как новый завоз"
                @click="toggleBatchType(product)"
              >
                <Sparkles class="h-4 w-4" />
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

    <!-- МОДАЛЬНОЕ ОКНО ПЕРЕНОСА ИЛИ ОБЪЕДИНЕНИЯ ТОВАРА -->
    <ModalPanel
      :open="transferModalOpen"
      :title="`Перенос в основной завоз: «${transferringProduct?.name || ''}»`"
      @close="transferModalOpen = false"
    >
      <form class="space-y-4" @submit.prevent="executeTransfer">
        <!-- Выбор режима: Как новый товар или Объединение -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <label
            class="flex items-start gap-3 rounded-xl border p-3 cursor-pointer transition"
            :class="transferMode === 'as_new'
              ? 'border-emerald-500 bg-emerald-50/50 dark:border-emerald-600 dark:bg-emerald-950/30'
              : 'border-slate-200 dark:border-slate-800'"
          >
            <input
              v-model="transferMode"
              type="radio"
              value="as_new"
              class="mt-1 text-emerald-600 focus:ring-emerald-500"
            />
            <div>
              <span class="font-semibold text-sm block">Добавить как новый товар</span>
              <p class="text-xs text-slate-500 mt-0.5">Товар появится в основном ассортименте как самостоятельная модель.</p>
            </div>
          </label>

          <label
            class="flex items-start gap-3 rounded-xl border p-3 cursor-pointer transition"
            :class="transferMode === 'merge'
              ? 'border-emerald-500 bg-emerald-50/50 dark:border-emerald-600 dark:bg-emerald-950/30'
              : 'border-slate-200 dark:border-slate-800'"
          >
            <input
              v-model="transferMode"
              type="radio"
              value="merge"
              class="mt-1 text-emerald-600 focus:ring-emerald-500"
            />
            <div>
              <span class="font-semibold text-sm block">Объединить с существующим</span>
              <p class="text-xs text-slate-500 mt-0.5">Размеры и продажи объединятся с выбранной моделью без дубликатов.</p>
            </div>
          </label>
        </div>

        <!-- Если выбран режим «Добавить как новый»: проверка и редактирование полей -->
        <div v-if="transferMode === 'as_new'" class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-1">
          <label class="block sm:col-span-2">
            <span class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Название в основном каталоге</span>
            <input v-model="transferForm.name" class="input" required />
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Вид товара</span>
            <input v-model="transferForm.type" class="input" list="transfer-types" />
            <datalist id="transfer-types">
              <option v-for="t in popularTypes" :key="t" :value="t" />
            </datalist>
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Цвет</span>
            <input v-model="transferForm.color" class="input" />
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Цена продажи (₸)</span>
            <input v-model.number="transferForm.sale_price" class="input" type="number" min="0" step="0.01" required />
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Категория</span>
            <select v-model="transferForm.category_id" class="select">
              <option value="">Без категории</option>
              <option v-for="c in catalog.categories" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
          </label>
        </div>

        <!-- Если выбран режим «Объединение»: список кандидатов для объединения -->
        <div v-else class="space-y-3 pt-1">
          <div class="rounded-lg bg-slate-50 p-3 text-xs text-slate-600 dark:bg-slate-900/60 dark:text-slate-300">
            ℹ️ Все продажи товара «{{ transferringProduct?.name }}» ({{ transferringProduct?.new_batch_sold ?? transferringProduct?.total_sold ?? 0 }} шт.) и все недостающие размеры будут безопасно перенесены в выбранную модель основного каталога. Временная карточка будет отправлена в архив, исключая дублирование.
          </div>

          <label class="block">
            <span class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">Выберите существующую модель в основном ассортименте:</span>
            <select v-model="targetProductId" class="select font-medium" required>
              <option :value="null" disabled>— Выберите товар для объединения —</option>
              <option v-for="cand in mergeCandidates" :key="cand.id" :value="cand.id">
                {{ cand.name }} · {{ cand.type }} · {{ money(cand.sale_price) }} (ID: {{ cand.id }}, продано: {{ cand.total_sold || 0 }} шт.)
              </option>
            </select>
          </label>

          <!-- Быстрые карточки кандидатов с совпадением по виду -->
          <div v-if="suggestedCandidates.length > 0" class="space-y-1.5">
            <span class="text-xs font-semibold text-slate-500">Рекомендуемые совпадения (по виду «{{ transferringProduct?.type }}»):</span>
            <div class="grid grid-cols-1 gap-2 max-h-48 overflow-y-auto">
              <button
                v-for="cand in suggestedCandidates"
                :key="`cand-${cand.id}`"
                type="button"
                class="flex items-center justify-between rounded-lg border p-2.5 text-left text-xs transition"
                :class="targetProductId === cand.id
                  ? 'border-emerald-500 bg-emerald-50 dark:border-emerald-600 dark:bg-emerald-950/40 text-emerald-900 dark:text-emerald-100'
                  : 'border-slate-200 hover:border-slate-300 dark:border-slate-800'"
                @click="targetProductId = cand.id"
              >
                <div>
                  <span class="font-semibold block">{{ cand.name }}</span>
                  <span class="text-slate-500">{{ cand.type }} · {{ cand.color || 'Цвет не указан' }} · Размеры: {{ cand.variants?.map(v => v.size).filter(Boolean).join(', ') || '—' }}</span>
                </div>
                <div class="text-right">
                  <span class="font-semibold block tabular-nums">{{ money(cand.sale_price) }}</span>
                  <span class="text-[10px] text-emerald-600 font-semibold" v-if="targetProductId === cand.id">✓ Выбрано</span>
                </div>
              </button>
            </div>
          </div>
        </div>

        <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2 pt-2 border-t border-slate-200 dark:border-slate-800">
          <button type="button" class="btn-muted w-full sm:w-auto" @click="transferModalOpen = false">Отмена</button>
          <button
            type="submit"
            class="btn-primary w-full sm:w-auto !bg-emerald-600 hover:!bg-emerald-700 text-white font-semibold flex items-center justify-center gap-1.5"
            :disabled="transferSubmitting || (transferMode === 'merge' && !targetProductId)"
          >
            <LoaderCircle v-if="transferSubmitting" class="h-4 w-4 animate-spin" />
            <span>{{ transferMode === 'merge' ? 'Объединить и перенести' : 'Перенести в основной завоз' }}</span>
          </button>
        </div>
      </form>
    </ModalPanel>

    <!-- МОДАЛЬНОЕ ОКНО МАССОВОГО ПЕРЕНОСА -->
    <ModalPanel
      :open="bulkTransferModalOpen"
      title="Массовый перенос товаров в основной завоз"
      @close="bulkTransferModalOpen = false"
    >
      <div class="space-y-4">
        <p class="text-sm text-slate-600 dark:text-slate-300">
          Вы уверены, что хотите перенести следующие товары (<b>{{ selectedProductIds.length }} шт.</b>) из нового завоза в основной ассортимент магазина?
        </p>

        <div class="max-h-60 overflow-y-auto divide-y divide-slate-200 rounded-lg border border-slate-200 bg-slate-50/50 p-2 text-xs dark:divide-slate-800 dark:border-slate-800 dark:bg-slate-900/50">
          <div
            v-for="p in selectedProductsList"
            :key="p.id"
            class="flex items-center justify-between py-2 px-1"
          >
            <div>
              <span class="font-semibold">{{ p.name }}</span>
              <span class="text-slate-500 ml-2">({{ p.type || 'Без вида' }}, {{ money(p.sale_price) }})</span>
            </div>
            <span class="rounded bg-amber-100 px-2 py-0.5 font-bold text-amber-800 dark:bg-amber-950 dark:text-amber-300">
              Продано: {{ p.new_batch_sold ?? p.total_sold ?? 0 }} шт.
            </span>
          </div>
        </div>

        <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2 pt-2">
          <button type="button" class="btn-muted w-full sm:w-auto" @click="bulkTransferModalOpen = false">Отмена</button>
          <button
            type="button"
            class="btn-primary w-full sm:w-auto !bg-amber-600 hover:!bg-amber-700 text-white font-semibold flex items-center justify-center gap-1.5"
            :disabled="bulkSubmitting"
            @click="executeBulkTransfer"
          >
            <LoaderCircle v-if="bulkSubmitting" class="h-4 w-4 animate-spin" />
            <span>Подтвердить перенос ({{ selectedProductIds.length }})</span>
          </button>
        </div>
      </div>
    </ModalPanel>

    <!-- МОДАЛЬНОЕ ОКНО СОЗДАНИЯ / РЕДАКТИРОВАНИЯ ТОВАРА -->
    <ModalPanel :open="editorOpen" :title="editing?.id ? 'Редактировать товар' : (activeBatchType === 'new_batch' ? 'Новый товар в «Новый завоз»' : 'Новый товар')" @close="editorOpen = false">
      <form class="grid grid-cols-1 gap-4 sm:grid-cols-2" @submit.prevent="saveProduct">
        <label class="block sm:col-span-2">
          <span class="mb-1 block text-sm font-medium">Название</span>
          <input v-model="form.name" class="input" required />
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium">Вид товара</span>
          <input v-model="form.type" class="input" list="products-view-types" placeholder="Футболка, Худи, Джинсы…" />
          <datalist id="products-view-types">
            <option v-for="t in popularTypes" :key="t" :value="t" />
          </datalist>
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium">Цвет</span>
          <input v-model="form.color" class="input" placeholder="Синий, чёрный…" />
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium">Цена</span>
          <input v-model.number="form.sale_price" class="input" type="number" min="0" step="0.01" required />
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium">Категория</span>
          <select v-model="form.category_id" class="select">
            <option value="">Без категории</option>
            <option v-for="category in catalog.categories" :key="category.id" :value="category.id">{{ category.name }}</option>
          </select>
        </label>

        <!-- Выбор типа завоза -->
        <label class="block sm:col-span-2">
          <span class="mb-1 block text-sm font-medium">Тип завоза</span>
          <select v-model="form.batch_type" class="select font-medium">
            <option value="standard">Основной завоз (Основной ассортимент)</option>
            <option value="new_batch">Новый завоз (Временный реестр поступивших)</option>
          </select>
        </label>

        <label v-if="form.batch_type === 'new_batch'" class="block">
          <span class="mb-1 block text-sm font-medium">Примечание к партии</span>
          <input v-model="form.batch_note" class="input" placeholder="Новый завоз, Новый завоз мерч..." />
        </label>

        <label v-if="form.batch_type === 'new_batch'" class="block">
          <span class="mb-1 block text-sm font-medium">Период / Дата поступления</span>
          <input v-model="form.batch_period" class="input" placeholder="Например: 16.09.2026 или Октябрь 2026" />
        </label>

        <div v-if="!isHat(form.type, form.name)" ref="variantTableRef" class="block space-y-3 sm:col-span-2">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
              <span class="text-sm font-semibold">Размеры модели</span>
              <p class="text-xs text-slate-500">Добавьте нужные размеры вручную или кликом по готовой размерной сетке</p>
            </div>
            <div class="flex flex-wrap gap-2">
              <button type="button" class="btn-primary h-8 px-2.5 text-xs font-semibold" @click="addVariantRow">
                + Добавить размер
              </button>
            </div>
          </div>

          <!-- Быстрый выбор размерных сеток и массовое добавление -->
          <div class="rounded-lg border border-slate-200 bg-slate-50/80 p-3 text-xs dark:border-slate-800 dark:bg-slate-900/60 space-y-2.5">
            <div class="flex flex-wrap items-center gap-2">
              <span class="font-semibold text-slate-600 dark:text-slate-400">Быстрые наборы:</span>
              <button
                type="button"
                class="rounded-md border border-emerald-300 bg-white px-2 py-1 font-semibold text-emerald-700 hover:bg-emerald-50 dark:border-emerald-800 dark:bg-slate-800 dark:text-emerald-300"
                @click="addPresetSizes(schoolSizes)"
              >
                + Школьная форма (110–194)
              </button>
              <button
                type="button"
                class="rounded-md border border-slate-300 bg-white px-2 py-1 font-medium text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                @click="addPresetSizes(letterSizes)"
              >
                + Буквы (XXS–XXL)
              </button>
              <button
                type="button"
                class="rounded-md border border-slate-300 bg-white px-2 py-1 font-medium text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                @click="addPresetSizes(toddlerSizes)"
              >
                + Малыши (86–110)
              </button>
            </div>

            <!-- Чипы популярных размеров -->
            <div class="flex flex-wrap items-center gap-1.5 pt-1">
              <span class="text-[11px] text-slate-500 mr-1">Быстрый клик:</span>
              <button
                v-for="sz in [...schoolSizes, ...letterSizes.slice(1, 5)]"
                :key="sz"
                type="button"
                class="rounded border border-slate-200 bg-white px-2 py-0.5 text-xs font-semibold hover:border-emerald-500 hover:text-emerald-600 dark:border-slate-700 dark:bg-slate-900"
                :class="isSizeAlreadyAdded(sz) ? 'opacity-40 line-through' : 'text-slate-700 dark:text-slate-300'"
                :title="isSizeAlreadyAdded(sz) ? 'Размер уже добавлен' : `Добавить размер ${sz}`"
                @click="addSingleSize(sz)"
              >
                {{ sz }}
              </button>
            </div>

            <!-- Вставка списка через запятую -->
            <div class="flex items-center gap-2 pt-1">
              <input
                v-model="bulkSizesInput"
                class="input h-8 text-xs flex-1"
                placeholder="Вставить списком через запятую (например: 122, 128, 134, 140)..."
                @keyup.enter.prevent="applyBulkSizes"
              />
              <button
                type="button"
                class="btn-muted h-8 px-3 text-xs font-semibold shrink-0"
                @click="applyBulkSizes"
              >
                Добавить список
              </button>
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
              <div class="flex items-end pb-0.5">
                <button
                  type="button"
                  class="btn-icon text-slate-400 hover:text-rose-600"
                  title="Удалить размер"
                  @click="removeVariantRow(index)"
                >
                  <X class="h-4 w-4" />
                </button>
              </div>
            </div>
          </div>
        </div>

        <div v-else class="block space-y-2 sm:col-span-2 rounded-xl border border-indigo-200 bg-indigo-50/60 p-4 dark:border-indigo-900/60 dark:bg-indigo-950/30">
          <div class="flex items-center justify-between">
            <span class="text-sm font-semibold text-indigo-900 dark:text-indigo-200">Товар «Шапки» (без размера)</span>
            <span class="rounded bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-800 dark:bg-indigo-900/50 dark:text-indigo-300">Размер не требуется</span>
          </div>
          <p class="text-xs text-slate-500 dark:text-slate-400">Для шапок размер не запрашивается и не отображается в списках.</p>
          <label class="block pt-1">
            <span class="mb-1 block text-xs font-medium text-slate-700 dark:text-slate-300">Количество в магазине (шт.)</span>
            <input
              v-model.number="form.variants[0].stock_store"
              class="input h-9 px-3 text-sm font-semibold tabular-nums w-48"
              type="number"
              min="0"
              step="1"
              placeholder="0"
            />
          </label>
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

    <!-- МОДАЛЬНОЕ ОКНО ИМПОРТА CSV -->
    <ModalPanel :open="importOpen" title="Импорт товаров (CSV)" @close="importOpen = false">
      <form class="grid grid-cols-1 gap-4" @submit.prevent="importProducts">
        <p class="text-sm text-slate-500">
          Каждая строка — один товар с одним вариантом. Полный формат:
          <b>name, sku, barcode, color, size, price, stock_warehouse, stock_store, low_stock_threshold, purchase_price, description</b>.
        </p>
        <label class="block">
          <span class="mb-1 block text-sm font-medium">Категория по умолчанию</span>
          <select v-model="importForm.category_id" class="select">
            <option value="">Авто / Без категории</option>
            <option v-for="category in catalog.categories" :key="category.id" :value="category.id">{{ category.name }}</option>
          </select>
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium">Файл CSV</span>
          <input class="input py-2" type="file" accept=".csv,text/csv,text/plain" required @change="importForm.file = $event.target.files[0]" />
        </label>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" class="btn-muted w-full sm:w-auto" @click="importOpen = false">Отмена</button>
          <button class="btn-primary w-full sm:w-auto" :disabled="importing">
            <LoaderCircle v-if="importing" class="h-4 w-4 animate-spin" />Импортировать
          </button>
        </div>
      </form>
    </ModalPanel>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import {
  ArrowRightLeft,
  ArrowUpRight,
  Calendar,
  Check,
  Eye,
  LoaderCircle,
  Pencil,
  Plus,
  Sparkles,
  Tag,
  X,
} from 'lucide-vue-next'
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
import { money } from '../utils/formatters'

const Package = createIcon(icons.Package)

const route = useRoute()
const auth = useAuthStore()
const catalog = useCatalogStore()
const toast = useToastStore()

// Активный тип завоза: 'standard' (Основной завоз) или 'new_batch' (Новый завоз)
const activeBatchType = ref('standard')
const selectedProductIds = ref([])

const editorOpen = ref(false)
const importOpen = ref(false)
const saving = ref(false)
const importing = ref(false)
const editing = ref(null)
const variantTableRef = ref(null)

// Перенос товара
const transferModalOpen = ref(false)
const transferringProduct = ref(null)
const transferMode = ref('as_new') // 'as_new' | 'merge'
const targetProductId = ref(null)
const mergeCandidates = ref([])
const transferSubmitting = ref(false)
const transferForm = reactive({
  name: '',
  type: '',
  color: '',
  sale_price: 0,
  category_id: '',
})

// Массовый перенос
const bulkTransferModalOpen = ref(false)
const bulkSubmitting = ref(false)

const popularTypes = [
  'Шапка',
  'Футболка',
  'Худи',
  'Свитшот',
  'Бомбер',
  'Брюки',
  'Джинсы',
  'Штаны',
  'Шорты',
  'Куртка',
  'Рубашка',
  'Поло',
  'Платье',
  'Юбка',
  'Костюм',
  'Обувь',
  'Кроссовки',
  'Кепка',
  'Аксессуары',
]

const filters = reactive({
  search: '',
  category_id: '',
  type: '',
  status: '',
  sort: 'updated_at',
  direction: 'desc',
  page: 1,
  per_page: 12,
})

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
    type: '',
    color: '',
    sale_price: '',
    variants: [blankVariant()],
    category_id: '',
    description: '',
    photo: null,
    batch_type: 'standard',
    batch_note: '',
    batch_period: '',
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
    form.type = product.type ?? ''
    form.color = product.color ?? ''
    form.sale_price = product.sale_price != null ? product.sale_price : ''
    form.category_id = product.category_id ?? ''
    form.description = product.description ?? ''
    form.batch_type = product.batch_type ?? 'standard'
    form.batch_note = product.batch_note ?? ''
    form.batch_period = product.batch_period ?? ''
  } else {
    form.batch_type = activeBatchType.value
    if (activeBatchType.value === 'new_batch') {
      form.batch_note = 'Новый завоз'
      form.batch_period = new Date().toLocaleDateString('ru-RU')
    }
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

// Переключение табов
function setBatchType(type) {
  if (activeBatchType.value === type) return
  activeBatchType.value = type
  selectedProductIds.value = []
  filters.page = 1
  fetchProducts(1)
}

// Чекбоксы массового выбора
const isAllSelected = computed(() =>
  catalog.products.length > 0 && selectedProductIds.value.length === catalog.products.length,
)

function toggleSelectAll() {
  if (isAllSelected.value) {
    selectedProductIds.value = []
  } else {
    selectedProductIds.value = catalog.products.map((p) => p.id)
  }
}

function toggleSelectProduct(id) {
  const idx = selectedProductIds.value.indexOf(id)
  if (idx >= 0) {
    selectedProductIds.value.splice(idx, 1)
  } else {
    selectedProductIds.value.push(id)
  }
}

const selectedProductsList = computed(() =>
  catalog.products.filter((p) => selectedProductIds.value.includes(p.id)),
)

// Открытие модалки переноса для одного товара
async function openTransfer(product) {
  transferringProduct.value = product
  transferMode.value = 'as_new'
  targetProductId.value = null
  transferForm.name = product.name || ''
  transferForm.type = product.type || ''
  transferForm.color = product.color || ''
  transferForm.sale_price = product.sale_price || 0
  transferForm.category_id = product.category_id || ''

  try {
    mergeCandidates.value = await catalog.fetchMergeCandidates(product.id)
    if (suggestedCandidates.value.length > 0) {
      targetProductId.value = suggestedCandidates.value[0].id
    }
  } catch {
    mergeCandidates.value = []
  }

  transferModalOpen.value = true
}

// Рекомендуемые кандидаты (совпадение по виду или схожему названию)
const suggestedCandidates = computed(() => {
  if (!transferringProduct.value || !mergeCandidates.value.length) return []
  const targetType = (transferringProduct.value.type || '').trim().toLowerCase()
  return mergeCandidates.value.filter((c) => {
    const cType = (c.type || '').trim().toLowerCase()
    return targetType && cType === targetType
  })
})

// Подтверждение одиночного переноса
async function executeTransfer() {
  if (!transferringProduct.value) return
  transferSubmitting.value = true
  try {
    const payload = {
      mode: transferMode.value,
    }
    if (transferMode.value === 'as_new') {
      payload.name = transferForm.name
      payload.type = transferForm.type
      payload.color = transferForm.color
      payload.sale_price = transferForm.sale_price
      payload.category_id = transferForm.category_id || null
    } else {
      payload.target_product_id = targetProductId.value
    }

    const { data } = await catalog.transferToStandard(transferringProduct.value.id, payload)
    toast.push(data?.message || 'Товар успешно перенесён')
    transferModalOpen.value = false
    await fetchProducts(filters.page)
  } catch (error) {
    toast.push(apiError(error), 'error')
  } finally {
    transferSubmitting.value = false
  }
}

// Открытие модалки массового переноса
function openBulkTransferConfirm() {
  if (!selectedProductIds.value.length) return
  bulkTransferModalOpen.value = true
}

// Подтверждение массового переноса
async function executeBulkTransfer() {
  bulkSubmitting.value = true
  try {
    const { data } = await catalog.bulkTransfer(selectedProductIds.value)
    toast.push(data?.message || 'Товары успешно перенесены')
    selectedProductIds.value = []
    bulkTransferModalOpen.value = false
    await fetchProducts(1)
  } catch (error) {
    toast.push(apiError(error), 'error')
  } finally {
    bulkSubmitting.value = false
  }
}

// Быстрое переключение статуса (исключение из нового завоза или перенос в новый)
async function toggleBatchType(product) {
  const isNew = product.batch_type === 'new_batch'
  const actionText = isNew
    ? `Исключить товар «${product.name}» из нового завоза и перенести в основной?`
    : `Поместить товар «${product.name}» в список «Новый завоз»?`

  if (!confirm(actionText)) return

  try {
    const { data } = await catalog.toggleBatchType(product.id)
    toast.push(data?.message || 'Статус товара обновлён')
    await fetchProducts(filters.page)
  } catch (error) {
    toast.push(apiError(error), 'error')
  }
}

function params(page = filters.page) {
  return {
    ...filters,
    page,
    batch_type: activeBatchType.value,
    category_id: filters.category_id || undefined,
    type: filters.type || undefined,
    status: filters.status || undefined,
    search: filters.search || undefined,
  }
}

async function fetchProducts(page = 1) {
  filters.page = page
  await catalog.fetchProducts(params(page))
}

function debouncedFetch() {
  window.clearTimeout(debounce)
  debounce = window.setTimeout(() => fetchProducts(1), 300)
}

function isHat(type, name) {
  const t = String(type || '').toLowerCase()
  const n = String(name || '').toLowerCase()
  return t === 'шапки' || t === 'шапка' || n.includes('шапк')
}

function prepareVariantsForSave(rows) {
  if (isHat(form.type, form.name)) {
    const qty = Math.max(0, Number(rows?.[0]?.stock_store) || 0)
    return [{ id: rows?.[0]?.id ?? null, size: '', stock_store: qty }]
  }
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
      type: form.type || null,
      color: form.color,
      sale_price: form.sale_price,
      category_id: form.category_id,
      description: form.description,
      batch_type: form.batch_type,
      batch_note: form.batch_note || null,
      batch_period: form.batch_period || null,
      variants,
      photo: form.photo,
    }
    await catalog.saveProduct(payload, editing.value?.id)
    toast.push('Товар сохранен')
    editorOpen.value = false
    await catalog.fetchBatchCounters()
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
    await catalog.fetchBatchCounters()
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
  if (!isHat(product.type, product.name)) {
    const vars = product.variants || []
    const sizes = vars.map((v) => v.size).filter(Boolean)
    if (sizes.length) parts.push(`размеры: ${sizes.slice(0, 6).join(', ')}${sizes.length > 6 ? '…' : ''}`)
    else if (product.size) parts.push(`размеры: ${product.size}`)
  }
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

const schoolSizes = ['110', '116', '122', '128', '134', '140', '146', '152', '158', '164', '170', '176', '182', '188', '194']
const letterSizes = ['XXS', 'XS', 'S', 'M', 'L', 'XL', 'XXL']
const toddlerSizes = ['86', '92', '98', '104', '110']
const bulkSizesInput = ref('')

function isSizeAlreadyAdded(sz) {
  const clean = String(sz || '').trim().toLowerCase()
  return (form.variants || []).some((v) => (v.size || '').trim().toLowerCase() === clean)
}

function addSingleSize(sz) {
  const clean = String(sz || '').trim()
  if (!clean) return
  if (isSizeAlreadyAdded(clean)) return
  if (form.variants.length === 1 && !form.variants[0].size && !form.variants[0].id) {
    form.variants[0].size = clean
  } else {
    form.variants.push({ ...blankVariant(), size: clean })
  }
}

function addPresetSizes(sizesArray) {
  for (const s of sizesArray) {
    addSingleSize(s)
  }
}

function applyBulkSizes() {
  if (!bulkSizesInput.value.trim()) return
  const parts = bulkSizesInput.value.split(/[,;\s]+/).map((s) => s.trim()).filter(Boolean)
  addPresetSizes(parts)
  bulkSizesInput.value = ''
}

function photoUrl(path) {
  if (!path) return ''
  return path.startsWith('http') ? path : `${apiBase.value}${path}`
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
  if (route.query.batch_type) activeBatchType.value = String(route.query.batch_type)
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
  await Promise.all([catalog.fetchCategories(), catalog.fetchBatchCounters()])
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
    await catalog.fetchBatchCounters()
    await fetchProducts(filters.page)
  } catch (error) {
    toast.push(apiError(error), 'error')
  }
}
</script>
