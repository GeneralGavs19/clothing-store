<template>
  <div class="space-y-5">
    <section class="grid grid-cols-1 gap-5 xl:grid-cols-[1.1fr_1fr]">
      <div class="panel p-4">
        <!-- Режимы: Выбор из каталога или Быстро создать товар и продать -->
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 class="font-semibold text-base sm:text-lg">Оформление продажи</h2>
            <p class="text-xs text-slate-500">
              {{ activeMode === 'quick' ? 'Создать новый товар и продать за один шаг' : stepHint }}
            </p>
          </div>
          <div class="flex items-center gap-1.5">
            <div class="flex rounded-lg bg-slate-100 p-0.5 dark:bg-slate-800">
              <button
                type="button"
                class="rounded-md px-3 py-1.5 text-xs font-medium transition"
                :class="activeMode === 'catalog' ? 'bg-white shadow text-slate-900 dark:bg-slate-700 dark:text-white' : 'text-slate-500 hover:text-slate-900 dark:hover:text-white'"
                @click="switchMode('catalog')"
              >
                Из каталога
              </button>
              <button
                type="button"
                class="flex items-center gap-1 rounded-md px-3 py-1.5 text-xs font-medium transition"
                :class="activeMode === 'quick' ? 'bg-emerald-600 text-white shadow' : 'text-emerald-600 hover:text-emerald-700 dark:text-emerald-400'"
                @click="switchMode('quick')"
              >
                <Sparkles class="h-3.5 w-3.5" />
                + Создать и продать
              </button>
            </div>
            <button
              v-if="activeMode === 'catalog' && saleStep !== 'products'"
              type="button"
              class="btn-muted h-8 px-2.5 text-xs"
              @click="goBackStep"
            >
              Назад
            </button>
            <button type="button" class="btn-muted h-8 w-8 px-0" title="Обновить" @click="refreshAll">
              <RefreshCw class="h-3.5 w-3.5" />
            </button>
          </div>
        </div>

        <!-- РЕЖИМ 1: БЫСТРОЕ СОЗДАНИЕ ТОВАРА И СРАЗУ ПРОДАЖА -->
        <div v-if="activeMode === 'quick'" class="space-y-4">
          <div class="rounded-lg border border-emerald-200 bg-emerald-50/70 p-3.5 text-xs text-emerald-950 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-100">
            <div class="flex items-center gap-1.5 font-semibold text-emerald-900 dark:text-emerald-200">
              <Sparkles class="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
              Быстрое добавление и продажа
            </div>
            <p class="mt-1 leading-relaxed text-emerald-800 dark:text-emerald-300">
              Склад заранее знать не нужно! Укажите, сколько продали прямо сейчас. Система сразу рассчитает, сколько вещей было учтено: <span class="font-semibold">Было = Продано + Остаток</span>.
            </p>
          </div>

          <form class="space-y-3.5" @submit.prevent="submitQuickSale">
            <!-- Выбор: В наличии или На заказ -->
            <div>
              <label class="block text-xs font-medium text-slate-700 dark:text-slate-300">
                Тип продажи / заказа <span class="text-rose-500">*</span>
              </label>
              <div class="mt-1.5 grid grid-cols-2 gap-2 text-xs">
                <button
                  type="button"
                  class="flex items-center justify-center gap-2 rounded-lg border p-2.5 font-medium transition"
                  :class="!quickForm.is_on_order
                    ? 'border-emerald-500 bg-emerald-50 text-emerald-900 shadow-sm dark:border-emerald-500 dark:bg-emerald-950/60 dark:text-emerald-200'
                    : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400'"
                  @click="quickForm.is_on_order = false"
                >
                  <Check class="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                  <span class="font-semibold">В наличии</span>
                  <span class="text-[10px] text-slate-400">(из магазина)</span>
                </button>

                <button
                  type="button"
                  class="flex items-center justify-center gap-2 rounded-lg border p-2.5 font-medium transition"
                  :class="quickForm.is_on_order
                    ? 'border-amber-500 bg-amber-50 text-amber-900 shadow-sm dark:border-amber-500 dark:bg-amber-950/60 dark:text-amber-200'
                    : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400'"
                  @click="quickForm.is_on_order = true"
                >
                  <Package class="h-4 w-4 text-amber-600 dark:text-amber-400" />
                  <span class="font-semibold">📦 На заказ</span>
                  <span class="text-[10px] text-slate-400">(предзаказ)</span>
                </button>
              </div>
            </div>

            <!-- Название товара -->
            <div>
              <label class="block text-xs font-medium text-slate-700 dark:text-slate-300">
                Название товара <span class="text-rose-500">*</span>
              </label>
              <input
                ref="quickNameRef"
                v-model="quickForm.name"
                class="input mt-1"
                placeholder="Например: Поло к/р, Брюки.спорт..."
                required
                autofocus
              />
              <!-- Быстрые кнопки названий: Брюки.спорт, Поло к/р, Поло д/р, Бомбер, Худи -->
              <div class="mt-1.5 flex flex-wrap gap-1 items-center">
                <span class="text-[11px] text-slate-400 mr-0.5">Вставить:</span>
                <button
                  v-for="name in commonNames"
                  :key="name"
                  type="button"
                  class="rounded border px-2 py-0.5 text-[11px] font-semibold transition"
                  :class="quickForm.name === name
                    ? 'border-emerald-500 bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
                    : 'border-slate-200 bg-white text-slate-700 hover:border-emerald-400 hover:text-emerald-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300'"
                  @click="setQuickName(name)"
                >
                  {{ name }}
                </button>
              </div>
            </div>

            <!-- Вид товара и Категория -->
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div>
                <label class="block text-xs font-medium text-slate-700 dark:text-slate-300">
                  Вид товара
                </label>
                <input
                  v-model="quickForm.type"
                  class="input mt-1"
                  list="quick-product-types"
                  placeholder="Футболка, Худи, Джинсы..."
                />
                <datalist id="quick-product-types">
                  <option v-for="type in popularTypes" :key="type" :value="type" />
                </datalist>
                <!-- Быстрые чипы видов -->
                <div class="mt-1.5 flex flex-wrap gap-1">
                  <button
                    v-for="t in popularTypes"
                    :key="t"
                    type="button"
                    class="rounded border px-1.5 py-0.5 text-[11px] font-medium transition"
                    :class="quickForm.type === t
                      ? 'border-emerald-500 bg-emerald-50 text-emerald-800 font-bold dark:bg-emerald-950 dark:text-emerald-300'
                      : 'border-slate-200 bg-white text-slate-600 hover:border-emerald-400 hover:text-emerald-800 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300'"
                    @click="quickForm.type = t"
                  >
                    {{ t }}
                  </button>
                </div>
              </div>

              <div>
                <label class="block text-xs font-medium text-slate-700 dark:text-slate-300">
                  Категория
                </label>
                <select v-if="!quickCustomCategory" v-model="quickForm.category_id" class="select mt-1">
                  <option value="">Без категории</option>
                  <option v-for="cat in catalog.categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                </select>
                <input
                  v-else
                  v-model="quickForm.category_name"
                  class="input mt-1"
                  placeholder="Новая категория..."
                />
                <button
                  type="button"
                  class="mt-1 text-[11px] text-emerald-600 hover:underline dark:text-emerald-400"
                  @click="toggleCustomCategory"
                >
                  {{ quickCustomCategory ? '← Выбрать из существующих' : '+ Новая категория' }}
                </button>
              </div>
            </div>

            <!-- Размер и Цвет -->
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div>
                <label class="block text-xs font-medium text-slate-700 dark:text-slate-300">
                  Размер <span class="text-rose-500">*</span>
                </label>
                <input
                  v-model="quickForm.size"
                  class="input mt-1"
                  placeholder="128, 170, M, L..."
                  required
                />
                <!-- Быстрые чипы размеров -->
                <div class="mt-1.5 flex flex-wrap gap-1">
                  <button
                    v-for="sz in commonSizes"
                    :key="sz"
                    type="button"
                    class="rounded border px-2 py-0.5 text-[11px] font-semibold transition"
                    :class="quickForm.size === sz
                      ? 'border-emerald-500 bg-emerald-50 text-emerald-800 font-bold dark:bg-emerald-950 dark:text-emerald-300'
                      : 'border-slate-200 bg-white text-slate-700 hover:border-emerald-400 hover:text-emerald-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300'"
                    @click="quickForm.size = sz"
                  >
                    {{ sz }}
                  </button>
                </div>
              </div>

              <div>
                <label class="block text-xs font-medium text-slate-700 dark:text-slate-300">
                  Цвет (необязательно)
                </label>
                <input
                  v-model="quickForm.color"
                  class="input mt-1"
                  placeholder="Голубой, Синий, Черный..."
                />
                <!-- Быстрые кнопки цветов (Голубой, Синий...) -->
                <div class="mt-1.5 flex flex-wrap gap-1 items-center">
                  <span class="text-[11px] text-slate-400 mr-0.5">Цвет:</span>
                  <button
                    v-for="c in commonColors"
                    :key="c"
                    type="button"
                    class="rounded border px-2 py-0.5 text-[11px] font-semibold transition"
                    :class="quickForm.color === c
                      ? 'border-emerald-500 bg-emerald-50 text-emerald-800 font-bold dark:bg-emerald-950 dark:text-emerald-300'
                      : 'border-slate-200 bg-white text-slate-700 hover:border-emerald-400 hover:text-emerald-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300'"
                    @click="quickForm.color = c"
                  >
                    {{ c }}
                  </button>
                  <button
                    v-if="quickForm.color"
                    type="button"
                    class="text-[11px] text-rose-500 hover:underline ml-1"
                    title="Сбросить цвет"
                    @click="quickForm.color = ''"
                  >
                    ✕
                  </button>
                </div>
              </div>
            </div>

            <!-- Цена продажи и Количество к продаже -->
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div>
                <label class="block text-xs font-medium text-slate-700 dark:text-slate-300">
                  Цена продажи (₸) <span class="text-rose-500">*</span>
                </label>
                <input
                  v-model.number="quickForm.sale_price"
                  type="number"
                  min="0"
                  step="100"
                  class="input mt-1 font-semibold"
                  placeholder="0"
                  required
                />
                <!-- Быстрые кнопки скидок для быстрой продажи -->
                <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                  <span class="text-[11px] text-slate-400">Скидка:</span>
                  <button
                    type="button"
                    class="rounded border px-2 py-0.5 text-[11px] font-semibold transition"
                    :class="quickDiscountType === '10'
                      ? 'border-blue-500 bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-300 font-bold'
                      : 'border-slate-200 bg-white text-slate-700 hover:border-blue-400 hover:text-blue-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300'"
                    title="Скидка 10%"
                    @click="applyQuickDiscount('10')"
                  >
                    -10%
                  </button>
                  <button
                    type="button"
                    class="rounded border px-2 py-0.5 text-[11px] font-semibold transition"
                    :class="quickDiscountType === '15'
                      ? 'border-indigo-500 bg-indigo-50 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300 font-bold'
                      : 'border-slate-200 bg-white text-slate-700 hover:border-indigo-400 hover:text-indigo-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300'"
                    title="Скидка 15%"
                    @click="applyQuickDiscount('15')"
                  >
                    -15%
                  </button>
                  <button
                    type="button"
                    class="rounded border px-2.5 py-0.5 text-[11px] font-bold transition flex items-center gap-1"
                    :class="quickDiscountType === 'free' || Number(quickForm.sale_price) === 0
                      ? 'border-emerald-500 bg-emerald-600 text-white shadow-sm'
                      : 'border-emerald-300 bg-emerald-50 text-emerald-800 hover:bg-emerald-100 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'"
                    title="Бесплатно для учителей (0 ₸)"
                    @click="applyQuickDiscount('free')"
                  >
                    🎁 Бесплатно (0 ₸)
                  </button>
                  <button
                    v-if="quickDiscountType"
                    type="button"
                    class="text-[11px] text-rose-500 hover:underline ml-1"
                    title="Сбросить скидку"
                    @click="applyQuickDiscount(null)"
                  >
                    ✕ Сброс
                  </button>
                </div>
              </div>

              <div>
                <label class="block text-xs font-medium text-slate-700 dark:text-slate-300">
                  Сколько продали сейчас (шт.) <span class="text-rose-500">*</span>
                </label>
                <div class="mt-1 flex items-center gap-1.5">
                  <button
                    type="button"
                    class="btn-muted h-10 w-10 px-0 text-base font-bold"
                    @click="quickForm.quantity = Math.max(1, (quickForm.quantity || 1) - 1)"
                  >
                    -
                  </button>
                  <input
                    v-model.number="quickForm.quantity"
                    type="number"
                    min="1"
                    class="input text-center font-bold tabular-nums"
                    required
                  />
                  <button
                    type="button"
                    class="btn-muted h-10 w-10 px-0 text-base font-bold"
                    @click="quickForm.quantity = (quickForm.quantity || 1) + 1"
                  >
                    +
                  </button>
                </div>
              </div>
            </div>

            <!-- Дата оформления продажи и срок готовности заказа (фиксируется с прошлой продажи) -->
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div>
                <div class="flex items-center justify-between">
                  <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300">
                    Дата оформления чека <span class="text-rose-500">*</span>
                  </label>
                  <button
                    v-if="quickForm.sold_at !== getTodayIsoDate()"
                    type="button"
                    class="text-[11px] font-semibold text-emerald-600 hover:underline dark:text-emerald-400 flex items-center gap-1"
                    title="Сбросить на сегодняшнюю дату"
                    @click="quickForm.sold_at = getTodayIsoDate()"
                  >
                    <Calendar class="h-3 w-3" />
                    <span>Сегодня ({{ formatDate(getTodayIsoDate()) }})</span>
                  </button>
                </div>
                <input
                  v-model="quickForm.sold_at"
                  type="date"
                  class="input mt-1 font-semibold text-xs"
                  required
                />
                <p class="mt-1 text-[11px] text-slate-500 flex items-center gap-1">
                  <span class="inline-block h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                  Фиксированная дата (сохраняется с прошлой добавленной продажи).
                </p>
              </div>

              <div v-if="quickForm.is_on_order">
                <label class="block text-xs font-semibold text-amber-800 dark:text-amber-300">
                  Срок готовности заказа (план)
                </label>
                <input
                  v-model="quickForm.due_date"
                  type="date"
                  class="input mt-1 text-xs border-amber-300 dark:border-amber-700"
                />
                <p class="mt-1 text-[11px] text-amber-600/80 dark:text-amber-400/80">
                  Ориентировочная дата выдачи покупателю
                </p>
              </div>
            </div>

            <!-- Сводный блок расчета чека -->
            <div class="rounded-xl border border-slate-200 bg-slate-50/80 p-3.5 dark:border-slate-800 dark:bg-slate-900/60 space-y-2">
              <div class="flex items-center justify-between text-xs">
                <span class="text-slate-500">Тип операции:</span>
                <span
                  class="rounded-full px-2 py-0.5 text-xs font-bold"
                  :class="quickForm.is_on_order ? 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300' : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'"
                >
                  {{ quickForm.is_on_order ? '📦 На заказ' : 'В наличии' }}
                </span>
              </div>
              <div class="flex items-center justify-between text-xs">
                <span class="text-slate-500">Количество позиций:</span>
                <span class="font-bold text-slate-800 dark:text-slate-200">{{ quickForm.quantity || 1 }} шт.</span>
              </div>
              <div class="flex items-center justify-between text-xs">
                <span class="text-slate-500">Цена за единицу:</span>
                <span class="font-semibold text-slate-700 dark:text-slate-300">{{ money(quickForm.sale_price || 0) }}</span>
              </div>
              <div class="pt-2 border-t border-slate-200 dark:border-slate-800 flex items-baseline justify-between">
                <span class="text-sm font-semibold text-slate-700 dark:text-slate-300">Итого к оплате:</span>
                <span class="text-xl font-black text-emerald-600 dark:text-emerald-400 tabular-nums">
                  {{ money((Number(quickForm.sale_price) || 0) * (Number(quickForm.quantity) || 1)) }}
                </span>
              </div>
            </div>

            <!-- Комментарий -->
            <div>
              <label class="block text-xs font-medium text-slate-700 dark:text-slate-300">Комментарий к чеку</label>
              <textarea
                v-model="quickForm.cashier_note"
                class="textarea mt-1"
                rows="2"
                placeholder="Необязательно (например: Kaspi QR, контакт клиента...)"
              />
            </div>

            <button
              type="submit"
              class="btn-primary w-full py-3 text-sm font-semibold"
              :disabled="quickSaving || !quickForm.name || !quickForm.sale_price"
            >
              <LoaderCircle v-if="quickSaving" class="h-4 w-4 animate-spin" />
              <Check v-else class="h-4 w-4" />
              Создать товар и оформить продажу
            </button>
          </form>
        </div>

        <!-- РЕЖИМ 2: ВЫБОР ИЗ СУЩЕСТВУЮЩЕГО КАТАЛОГА -->
        <div v-else>
          <div class="relative">
            <input
              ref="searchInputRef"
              v-model="productSearch"
              class="input pr-10"
              placeholder="Поиск товара по названию, виду, цвету... (быстрый фокус: Ctrl+K или /)"
              @input="debouncedProducts"
              @keyup.enter="debouncedProducts"
            />
            <button
              v-if="productSearch"
              type="button"
              class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
              @click="productSearch = ''; fetchProducts()"
            >
              <X class="h-4 w-4" />
            </button>
          </div>

          <!-- Шаг 1: список моделей -->
          <div v-if="saleStep === 'products'" class="mt-4 max-h-[30rem] space-y-2 overflow-y-auto pr-1">
            <SkeletonBlock v-if="catalog.loadingProducts" custom-class="h-20" />
            <div v-else-if="!availableProducts.length" class="my-6 text-center">
              <p class="text-sm text-slate-500">
                {{ productSearch ? `Товар «${productSearch}» не найден.` : 'В каталоге пока нет товаров.' }}
              </p>
              <button
                type="button"
                class="btn-primary mt-3 text-xs"
                @click="openQuickWithSearch"
              >
                <Sparkles class="h-3.5 w-3.5" />
                {{ productSearch ? `Создать «${productSearch}» и продать` : 'Создать первый товар и продать' }}
              </button>
            </div>

            <button
              v-for="product in availableProducts"
              :key="product.id"
              type="button"
              class="flex w-full items-center gap-3 rounded-lg border border-slate-200 p-3 text-left transition hover:border-emerald-400 hover:bg-slate-50/50 dark:border-slate-800 dark:hover:border-emerald-700 dark:hover:bg-slate-900/50"
              @click="selectProduct(product)"
            >
              <div class="h-11 w-11 shrink-0 overflow-hidden rounded-md bg-slate-100 dark:bg-slate-800">
                <img v-if="product.photo_url" :src="photoUrl(product.photo_url)" class="h-full w-full object-cover" alt="" loading="lazy" />
                <div v-else class="flex h-full w-full items-center justify-center text-slate-400">
                  <Package class="h-5 w-5" />
                </div>
              </div>
              <div class="min-w-0 flex-1">
                <div class="truncate text-sm font-medium">{{ product.name }}</div>
                <div class="mt-0.5 text-xs text-slate-500">{{ productLineMeta(product) }}</div>
                <div class="mt-1 flex flex-wrap items-center gap-1.5 text-[11px] text-slate-500">
                  <span class="rounded bg-emerald-50 px-1.5 py-0.5 font-medium text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
                    Всего продано: {{ product.total_sold || 0 }} шт.
                  </span>
                  <span
                    v-for="v in (product.variants || []).slice(0, 5)"
                    :key="v.id"
                    class="rounded border border-slate-200 bg-white px-1.5 py-0.2 text-[10px] font-semibold text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300"
                  >
                    {{ v.size }}
                  </span>
                  <span v-if="(product.variants || []).length > 5" class="text-[10px] text-slate-400">
                    +{{ (product.variants || []).length - 5 }}
                  </span>
                </div>
              </div>
              <div class="text-right">
                <div class="text-sm font-semibold">{{ money(product.sale_price) }}</div>
                <span class="text-[11px] text-emerald-600 dark:text-emerald-400 font-medium">Выбрать размер →</span>
              </div>
            </button>
          </div>

          <!-- Шаг 2: выбор размера -->
          <div v-else-if="saleStep === 'variant'" class="mt-4 space-y-3">
            <div class="flex items-center justify-between border-b border-slate-200 pb-2 dark:border-slate-800">
              <div>
                <p class="text-sm font-semibold">{{ selectedProduct?.name }}</p>
                <p class="text-xs text-slate-500">Нажмите на размер, чтобы добавить его в чек</p>
              </div>
              <button
                type="button"
                class="btn-muted h-8 px-2.5 text-xs"
                @click="goBackStep"
              >
                ← К моделям
              </button>
            </div>

            <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-3">
              <button
                v-for="v in allVariantsOfSelected"
                :key="v.id"
                type="button"
                class="group rounded-lg border border-slate-200 p-3 text-left transition hover:border-emerald-500 hover:bg-emerald-50/30 dark:border-slate-800 dark:hover:border-emerald-700 dark:hover:bg-emerald-950/20"
                @click="selectVariant(v)"
              >
                <div class="flex items-baseline justify-between">
                  <span class="text-lg font-bold text-slate-900 group-hover:text-emerald-600 dark:text-white dark:group-hover:text-emerald-400">
                    {{ v.size }}
                  </span>
                  <span class="text-xs font-semibold tabular-nums text-slate-700 dark:text-slate-300">
                    {{ money(v.sale_price || selectedProduct?.sale_price) }}
                  </span>
                </div>
                <div class="mt-2 flex items-center justify-between text-xs text-emerald-600 dark:text-emerald-400 font-medium">
                  <span>+ В чек</span>
                  <span class="text-slate-400 text-[10px]">клик для выбора</span>
                </div>
              </button>
            </div>

            <p v-if="!allVariantsOfSelected.length" class="py-4 text-center text-sm text-slate-500">
              У этого товара нет размеров.
            </p>
          </div>
        </div>
      </div>

      <!-- Правая панель: Корзина -->
      <div class="panel p-4">
        <div class="mb-3 flex items-center justify-between">
          <div>
            <h2 class="font-semibold text-base sm:text-lg">Чек продажи</h2>
            <p class="text-xs text-slate-500">Позиции в текущем чеке</p>
          </div>
          <button
            v-if="cart.length"
            type="button"
            class="text-xs text-rose-500 hover:underline"
            @click="cart = []"
          >
            Очистить
          </button>
        </div>

        <EmptyState
          v-if="!cart.length"
          title="Чек пуст"
          text="Выберите модель и размер слева, или нажмите «Создать и продать»."
          :icon="ShoppingCart"
        />

        <form v-else class="space-y-4" @submit.prevent="createSale">
          <!-- Переключатель: В наличии / На заказ для чека -->
          <div>
            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
              Тип заказа
            </label>
            <div class="grid grid-cols-2 gap-2 text-xs">
              <button
                type="button"
                class="flex items-center justify-center gap-1.5 rounded-lg border p-2 font-medium transition"
                :class="!cartIsOnOrder
                  ? 'border-emerald-500 bg-emerald-50 text-emerald-900 font-bold shadow-sm dark:border-emerald-500 dark:bg-emerald-950/60 dark:text-emerald-200'
                  : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400'"
                @click="cartIsOnOrder = false"
              >
                <Check class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" />
                В наличии
              </button>
              <button
                type="button"
                class="flex items-center justify-center gap-1.5 rounded-lg border p-2 font-medium transition"
                :class="cartIsOnOrder
                  ? 'border-amber-500 bg-amber-50 text-amber-900 font-bold shadow-sm dark:border-amber-500 dark:bg-amber-950/60 dark:text-amber-200'
                  : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400'"
                @click="cartIsOnOrder = true"
              >
                <Package class="h-3.5 w-3.5 text-amber-600 dark:text-amber-400" />
                📦 На заказ
              </button>
            </div>
          </div>

          <!-- Дата оформления чека и срок готовности (фиксируется с прошлой продажи) -->
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
              <div class="flex items-center justify-between mb-1">
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300">
                  Дата оформления чека <span class="text-rose-500">*</span>
                </label>
                <button
                  v-if="saleDate !== getTodayIsoDate()"
                  type="button"
                  class="text-[11px] font-semibold text-emerald-600 hover:underline dark:text-emerald-400 flex items-center gap-1"
                  title="Сбросить на сегодняшнюю дату"
                  @click="saleDate = getTodayIsoDate()"
                >
                  <Calendar class="h-3 w-3" />
                  <span>Сегодня ({{ formatDate(getTodayIsoDate()) }})</span>
                </button>
              </div>
              <input
                v-model="saleDate"
                type="date"
                class="input text-xs font-semibold"
                required
              />
              <p class="mt-1 text-[10px] text-slate-400">
                🔒 Фиксированная дата с прошлой продажи. Не сбрасывается автоматически.
              </p>
            </div>

            <div v-if="cartIsOnOrder">
              <label class="block text-xs font-semibold text-amber-800 dark:text-amber-300 mb-1">
                Срок готовности заказа (план)
              </label>
              <input
                v-model="saleDueDate"
                type="date"
                class="input text-xs border-amber-300 dark:border-amber-700"
              />
              <p class="mt-1 text-[10px] text-amber-600/80 dark:text-amber-400/80">Ориентировочная дата выдачи</p>
            </div>
          </div>

          <!-- Панель быстрых скидок на чек (необязательно, для ускорения работы) -->
          <div class="rounded-xl border border-slate-200 bg-slate-50/80 p-2.5 text-xs dark:border-slate-800 dark:bg-slate-900/50">
            <div class="flex items-center justify-between mb-1.5">
              <span class="text-[11px] font-semibold text-slate-600 dark:text-slate-400">
                Быстрые скидки для чека:
              </span>
              <button
                v-if="hasAnyDiscount"
                type="button"
                class="text-[11px] font-semibold text-rose-500 hover:underline flex items-center gap-0.5"
                @click="applyAllDiscount(null)"
              >
                ✕ Сбросить скидки
              </button>
            </div>
            <div class="grid grid-cols-3 gap-1.5">
              <button
                type="button"
                class="rounded-lg border border-blue-200 bg-white py-1 px-1 text-center text-[11px] font-bold text-blue-700 shadow-sm transition hover:bg-blue-50 dark:border-blue-900 dark:bg-slate-800 dark:text-blue-300 dark:hover:bg-blue-950"
                title="Сделать скидку 10% на все товары в чеке"
                @click="applyAllDiscount('10')"
              >
                -10% всем
              </button>
              <button
                type="button"
                class="rounded-lg border border-indigo-200 bg-white py-1 px-1 text-center text-[11px] font-bold text-indigo-700 shadow-sm transition hover:bg-indigo-50 dark:border-indigo-900 dark:bg-slate-800 dark:text-indigo-300 dark:hover:bg-indigo-950"
                title="Сделать скидку 15% на все товары в чеке"
                @click="applyAllDiscount('15')"
              >
                -15% всем
              </button>
              <button
                type="button"
                class="rounded-lg border border-emerald-300 bg-emerald-50 py-1 px-1 text-center text-[11px] font-bold text-emerald-800 shadow-sm transition hover:bg-emerald-100 dark:border-emerald-800 dark:bg-emerald-950/70 dark:text-emerald-300 dark:hover:bg-emerald-900/60 flex items-center justify-center gap-1"
                title="Сделать все товары бесплатными (например, для учителей)"
                @click="applyAllDiscount('free')"
              >
                <span>🎁 Учителям 0₸</span>
              </button>
            </div>
            <p class="mt-1.5 text-[10px] text-slate-400">
              💡 Нажимать кнопки не обязательно — если скидок нет, просто оформляйте чек как обычно.
            </p>
          </div>

          <div class="max-h-[26rem] space-y-2 overflow-y-auto pr-1">
            <div
              v-for="item in cart"
              :key="item.product_variant_id"
              class="rounded-lg border border-slate-200 p-3 transition hover:border-slate-300 dark:border-slate-800 dark:hover:border-slate-700"
              :class="item.discount_type === 'free' || item.price === 0 ? 'bg-emerald-50/40 border-emerald-300 dark:border-emerald-900 dark:bg-emerald-950/20' : ''"
            >
              <div class="flex items-start justify-between gap-2">
                <div class="min-w-0 flex-1">
                  <div class="truncate text-sm font-medium">{{ item.product.name }}</div>
                  <div class="mt-0.5 flex items-center gap-2 text-xs text-slate-500">
                    <span>Размер: <strong class="text-slate-800 dark:text-slate-200">{{ item.variant.size }}</strong></span>
                    <span v-if="item.product.type" class="rounded bg-slate-100 px-1.5 py-0.2 text-[10px] dark:bg-slate-800">{{ item.product.type }}</span>
                  </div>

                  <!-- Отображение стоимости позиции -->
                  <div class="mt-1 flex flex-wrap items-baseline gap-1.5">
                    <!-- Бесплатно для учителей -->
                    <template v-if="item.discount_type === 'free' || Number(item.price) === 0">
                      <span class="text-sm font-black text-emerald-600 dark:text-emerald-400 tabular-nums">0 ₸</span>
                      <span class="text-xs text-slate-400 line-through tabular-nums">{{ money((item.original_price || item.price) * item.quantity) }}</span>
                      <span class="rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-bold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                        🎁 Бесплатно (учителям)
                      </span>
                    </template>
                    <!-- Со скидкой (10%, 15% или ручной) -->
                    <template v-else-if="item.discount_type || (item.original_price && Number(item.price) < Number(item.original_price))">
                      <span class="text-sm font-bold text-emerald-600 dark:text-emerald-400 tabular-nums">
                        {{ money(item.price * item.quantity) }}
                      </span>
                      <span class="text-xs text-slate-400 line-through tabular-nums">{{ money(item.original_price * item.quantity) }}</span>
                      <span
                        class="rounded px-1.5 py-0.5 text-[10px] font-bold"
                        :class="item.discount_type === '10'
                          ? 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300'
                          : item.discount_type === '15'
                          ? 'bg-indigo-100 text-indigo-800 dark:bg-indigo-950 dark:text-indigo-300'
                          : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300'"
                      >
                        {{ item.discount_type === '10' ? '-10%' : item.discount_type === '15' ? '-15%' : 'Скидка' }}
                      </span>
                      <span v-if="item.quantity > 1" class="text-[11px] text-slate-400">
                        ({{ money(item.price) }}/шт.)
                      </span>
                    </template>
                    <!-- Стандартная цена без скидки -->
                    <template v-else>
                      <div class="text-sm font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">
                        {{ money(item.price * item.quantity) }}
                        <span v-if="item.quantity > 1" class="text-xs font-normal text-slate-500">
                          ({{ money(item.price) }} / шт.)
                        </span>
                      </div>
                    </template>
                  </div>
                </div>

                <div class="flex items-center gap-1.5">
                  <button
                    type="button"
                    class="btn-muted h-7 w-7 px-0 text-sm font-bold"
                    @click="decreaseQty(item)"
                  >
                    -
                  </button>
                  <span class="w-7 text-center text-sm font-bold tabular-nums">{{ item.quantity }}</span>
                  <button
                    type="button"
                    class="btn-muted h-7 w-7 px-0 text-sm font-bold"
                    @click="increaseQty(item)"
                  >
                    +
                  </button>
                  <button
                    type="button"
                    class="btn-muted ml-1 h-7 w-7 px-0 text-rose-500 hover:text-rose-600"
                    title="Удалить"
                    @click="removeFromCart(item.product_variant_id)"
                  >
                    <X class="h-4 w-4" />
                  </button>
                </div>
              </div>

              <!-- Кнопки выбора скидки на конкретную позицию -->
              <div class="mt-2.5 flex flex-wrap items-center gap-1.5 border-t border-slate-100 pt-2 dark:border-slate-800/80">
                <span class="text-[11px] font-medium text-slate-400 mr-0.5">Скидка:</span>

                <!-- 0% (Без скидки) -->
                <button
                  type="button"
                  class="rounded-md px-2 py-0.5 text-[11px] font-semibold transition"
                  :class="!item.discount_type && Number(item.price) === Number(item.original_price ?? item.price)
                    ? 'bg-slate-200 text-slate-800 font-bold dark:bg-slate-700 dark:text-slate-200'
                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-400 dark:hover:bg-slate-700'"
                  title="Обычная цена товара (без скидки)"
                  @click="applyItemDiscount(item, null)"
                >
                  0%
                </button>

                <!-- -10% -->
                <button
                  type="button"
                  class="rounded-md px-2 py-0.5 text-[11px] font-semibold transition"
                  :class="item.discount_type === '10'
                    ? 'bg-blue-600 text-white font-bold shadow-sm dark:bg-blue-500'
                    : 'bg-blue-50 text-blue-700 hover:bg-blue-100 dark:bg-blue-950/60 dark:text-blue-300 dark:hover:bg-blue-900/60'"
                  title="Скидка 10% на эту позицию"
                  @click="applyItemDiscount(item, '10')"
                >
                  -10%
                </button>

                <!-- -15% -->
                <button
                  type="button"
                  class="rounded-md px-2 py-0.5 text-[11px] font-semibold transition"
                  :class="item.discount_type === '15'
                    ? 'bg-indigo-600 text-white font-bold shadow-sm dark:bg-indigo-500'
                    : 'bg-indigo-50 text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-950/60 dark:text-indigo-300 dark:hover:bg-indigo-900/60'"
                  title="Скидка 15% на эту позицию"
                  @click="applyItemDiscount(item, '15')"
                >
                  -15%
                </button>

                <!-- 🎁 Бесплатно (для учителей) -->
                <button
                  type="button"
                  class="rounded-md px-2.5 py-0.5 text-[11px] font-bold transition flex items-center gap-1"
                  :class="item.discount_type === 'free' || Number(item.price) === 0
                    ? 'bg-emerald-600 text-white shadow-sm dark:bg-emerald-500'
                    : 'border border-emerald-300 bg-emerald-50 text-emerald-800 hover:bg-emerald-100 dark:border-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300 dark:hover:bg-emerald-900/60'"
                  title="Бесплатно (например, для учителей) — 0 ₸"
                  @click="applyItemDiscount(item, 'free')"
                >
                  <span>🎁 Бесплатно</span>
                  <span class="text-[9px] opacity-80">(0 ₸)</span>
                </button>

                <!-- Кнопка ручной правки цены при необходимости -->
                <button
                  v-if="!item.customPriceActive"
                  type="button"
                  class="text-[10px] text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 underline ml-auto"
                  title="Ввести произвольную цену"
                  @click="item.customPriceActive = true"
                >
                  Своя цена
                </button>
                <div v-else class="flex items-center gap-1 ml-auto">
                  <input
                    v-model.number="item.price"
                    type="number"
                    min="0"
                    step="50"
                    class="input h-6 w-20 text-[11px] px-1 font-semibold text-right"
                    placeholder="Цена"
                    @change="item.discount_type = 'custom'"
                  />
                  <button
                    type="button"
                    class="text-[11px] text-slate-400 hover:text-slate-600"
                    title="Закрыть"
                    @click="item.customPriceActive = false"
                  >
                    ✕
                  </button>
                </div>
              </div>
            </div>
          </div>

          <label class="block">
            <span class="mb-1 block text-xs font-medium text-slate-500">Комментарий к продаже</span>
            <textarea
              v-model="cashierNote"
              class="textarea"
              rows="2"
              placeholder="Необязательно (например: Для учителей, номер чека, данные покупателя...)"
            />
          </label>

          <div class="rounded-lg bg-slate-50 p-3.5 dark:bg-slate-900/60">
            <div class="flex items-center justify-between text-xs text-slate-500">
              <span>Тип заказа:</span>
              <span
                class="rounded px-2 py-0.5 font-semibold text-xs"
                :class="cartIsOnOrder ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300' : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300'"
              >
                {{ cartIsOnOrder ? '📦 На заказ' : 'В наличии' }}
              </span>
            </div>
            <div class="mt-1 flex items-center justify-between text-xs text-slate-500">
              <span>Всего позиций:</span>
              <span class="font-medium text-slate-700 dark:text-slate-300">{{ totalCartUnits }} шт.</span>
            </div>
            <!-- Строка скидки / экономии если есть скидка -->
            <div v-if="cartTotalSavings > 0" class="mt-1 flex items-center justify-between text-xs text-emerald-600 dark:text-emerald-400 font-bold">
              <span>Скидка / Экономия:</span>
              <span class="tabular-nums">-{{ money(cartTotalSavings) }}</span>
            </div>
            <div class="mt-2 flex items-baseline justify-between border-t border-slate-200 pt-2 dark:border-slate-800">
              <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Итого к оплате:</span>
              <div class="text-right">
                <div v-if="cartTotalSavings > 0" class="text-xs text-slate-400 line-through tabular-nums">
                  {{ money(cartOriginalTotal) }}
                </div>
                <span class="text-2xl font-black text-emerald-600 dark:text-emerald-400 tabular-nums">
                  {{ money(cartTotal) }}
                </span>
              </div>
            </div>
          </div>

          <button
            type="submit"
            class="btn-primary w-full py-3 text-sm font-semibold"
            :disabled="saving"
          >
            <LoaderCircle v-if="saving" class="h-4 w-4 animate-spin" />
            <Check v-else class="h-4 w-4" />
            Оформить чек ({{ money(cartTotal) }})
          </button>
        </form>
      </div>
    </section>

    <!-- Нижняя секция: Улучшенная история оформленных продаж и аналитика по видам -->
    <section class="panel overflow-hidden">
      <!-- Заголовок панели истории -->
      <div class="flex flex-col gap-3 border-b border-slate-200 p-4 dark:border-slate-800 lg:flex-row lg:items-center lg:justify-between">
        <div>
          <div class="flex items-center gap-2">
            <h2 class="font-semibold text-base sm:text-lg text-slate-900 dark:text-white">История продаж и заказов</h2>
            <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">
              {{ sales.meta?.total ?? sales.sales.length }} чеков
            </span>
          </div>
          <p class="text-xs text-slate-500">
            Удобный просмотр и изменение чеков, видимость наличия и заказов, расчет по видам и размерам
          </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <!-- Переключатель режима: Список чеков vs Расчет по видам и размерам -->
          <div class="flex rounded-lg bg-slate-100 p-0.5 text-xs dark:bg-slate-800">
            <button
              type="button"
              class="rounded-md px-3 py-1.5 font-medium transition"
              :class="historyViewMode === 'orders' ? 'bg-white shadow text-slate-900 dark:bg-slate-700 dark:text-white' : 'text-slate-500 hover:text-slate-900 dark:hover:text-white'"
              @click="historyViewMode = 'orders'"
            >
              Список чеков
            </button>
            <button
              type="button"
              class="flex items-center gap-1.5 rounded-md px-3 py-1.5 font-medium transition"
              :class="historyViewMode === 'summary' ? 'bg-white shadow text-emerald-700 dark:bg-slate-700 dark:text-emerald-300' : 'text-slate-500 hover:text-slate-900 dark:hover:text-white'"
              @click="openSummaryView"
            >
              <Layers class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" />
              <span>Расчет по видам и размерам</span>
            </button>
          </div>

          <button
            type="button"
            class="btn-muted h-8 px-2.5 text-xs"
            title="Скачать Excel отчет с полными названиями товаров"
            @click="downloadExcel"
          >
            <Download class="h-3.5 w-3.5" />
            Excel отчет
          </button>
          <button
            type="button"
            class="btn-muted h-8 w-8 px-0"
            title="Обновить историю"
            @click="refreshAll"
          >
            <RefreshCw class="h-3.5 w-3.5" :class="{ 'animate-spin': sales.loading || summaryLoading }" />
          </button>
        </div>
      </div>

      <!-- РЕЖИМ 1: СПИСОК ЧЕКОВ -->
      <div v-if="historyViewMode === 'orders'">
        <!-- Фильтры: Поиск + Тип заказа (Все / В наличии / На заказ) + Статус -->
        <div class="flex flex-col gap-3 border-b border-slate-200 bg-slate-50/60 p-4 dark:border-slate-800 dark:bg-slate-900/40 md:flex-row md:items-center md:justify-between">
          <!-- Поиск -->
          <div class="relative flex-1 max-w-md">
            <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-slate-400" />
            <input
              v-model="historySearch"
              class="input h-9 pl-9 pr-8 text-xs"
              placeholder="Поиск по № чека, названию товара, размеру или кассиру..."
              @input="debouncedHistory"
              @keyup.enter="fetchHistory(1)"
            />
            <button
              v-if="historySearch"
              type="button"
              class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
              @click="historySearch = ''; fetchHistory(1)"
            >
              <X class="h-3.5 w-3.5" />
            </button>
          </div>

          <div class="flex flex-wrap items-center gap-2">
            <!-- Фильтр по типу заказа: Все / В наличии / На заказ -->
            <div class="flex rounded-lg bg-slate-200/70 p-0.5 text-xs dark:bg-slate-800">
              <button
                type="button"
                class="rounded-md px-3 py-1.5 font-medium transition"
                :class="historyOrderType === '' ? 'bg-white shadow text-slate-900 dark:bg-slate-700 dark:text-white' : 'text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white'"
                @click="setHistoryOrderType('')"
              >
                Все
              </button>
              <button
                type="button"
                class="rounded-md px-3 py-1.5 font-medium transition"
                :class="historyOrderType === 'in_stock' ? 'bg-white shadow text-emerald-700 dark:bg-slate-700 dark:text-emerald-300' : 'text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white'"
                @click="setHistoryOrderType('in_stock')"
              >
                В наличии
              </button>
              <button
                type="button"
                class="flex items-center gap-1 rounded-md px-3 py-1.5 font-medium transition"
                :class="historyOrderType === 'on_order' ? 'bg-amber-500 text-white shadow' : 'text-amber-700 hover:text-amber-900 dark:text-amber-400 dark:hover:text-white'"
                @click="setHistoryOrderType('on_order')"
              >
                <span>📦</span>
                <span>На заказ</span>
              </button>
            </div>

            <!-- Фильтр по статусу -->
            <select
              v-model="historyStatus"
              class="select h-9 text-xs sm:w-40"
              @change="fetchHistory(1)"
            >
              <option value="approved">Оформленные</option>
              <option value="">Все статусы</option>
              <option value="rejected">Отменённые</option>
            </select>
          </div>
        </div>

        <!-- Вторая строка фильтров: Периоды дат + Статус выполнения заказа -->
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-slate-50/30 px-4 py-2 text-xs dark:border-slate-800 dark:bg-slate-900/20">
          <div class="flex flex-wrap items-center gap-1.5">
            <span class="text-slate-500 font-medium mr-1">Период:</span>
            <button
              v-for="p in [
                { key: 'all', label: 'Все даты' },
                { key: 'today', label: 'Сегодня' },
                { key: 'yesterday', label: 'Вчера' },
                { key: '7days', label: '7 дней' },
                { key: 'custom', label: 'Период...' },
              ]"
              :key="p.key"
              type="button"
              class="rounded-md px-2.5 py-1 transition font-medium"
              :class="historyDatePreset === p.key ? 'bg-emerald-600 text-white shadow-sm' : 'bg-white text-slate-600 hover:bg-slate-100 border border-slate-200 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300'"
              @click="setHistoryDatePreset(p.key)"
            >
              {{ p.label }}
            </button>

            <!-- Поля произвольного периода -->
            <div v-if="historyDatePreset === 'custom'" class="flex items-center gap-1.5 ml-2">
              <input
                v-model="historyDateFrom"
                type="date"
                class="input h-7 py-0.5 text-xs w-32"
                @change="fetchHistory(1)"
              />
              <span class="text-slate-400">—</span>
              <input
                v-model="historyDateTo"
                type="date"
                class="input h-7 py-0.5 text-xs w-32"
                @change="fetchHistory(1)"
              />
            </div>
          </div>

          <!-- Фильтр по статусу пошива / выполнения (если выбрано На заказ) -->
          <div v-if="historyOrderType === 'on_order'" class="flex items-center gap-1.5">
            <span class="text-slate-500 font-medium">Статус заказа:</span>
            <select
              v-model="historyFulfillment"
              class="select h-7 py-0.5 text-xs w-36"
              @change="fetchHistory(1)"
            >
              <option value="">Все этапы</option>
              <option value="new">Новые</option>
              <option value="in_progress">В пошиве</option>
              <option value="ready">Готовы к выдаче</option>
              <option value="delivered">Выданы</option>
            </select>
          </div>
        </div>

        <!-- Список чеков -->
        <div v-if="sales.loading" class="p-6">
          <SkeletonBlock custom-class="h-44" />
        </div>

        <EmptyState
          v-else-if="!sales.sales.length"
          class="m-6"
          title="Продажи не найдены"
          :text="historySearch || historyOrderType || historyDateFrom ? 'Попробуйте сбросить фильтры поиска.' : 'Оформите первую продажу в форме сверху.'"
        />

        <div v-else class="divide-y divide-slate-200 dark:divide-slate-800">
          <div
            v-for="sale in sales.sales"
            :key="sale.id"
            class="p-4 transition hover:bg-slate-50/50 dark:hover:bg-slate-900/30"
          >
            <!-- Карточка продажи: СВЕРХУ НАЗВАНИЕ ТОВАРА, СНИЗУ НОМЕР И ДАТА ВРЕМЯ -->
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
              <div class="min-w-0 flex-1">
                <!-- Верхняя строка: Название товара (товаров), бейджи типа (В наличии / На заказ) и статуса -->
                <div class="flex flex-wrap items-center gap-2">
                  <h3 class="font-bold text-slate-900 dark:text-white text-base sm:text-lg">
                    {{ getSaleProductTitle(sale) }}
                  </h3>

                  <!-- Бейдж типа заказа: На заказ или В наличии -->
                  <span
                    class="rounded-full px-2.5 py-0.5 text-xs font-semibold inline-flex items-center gap-1 border"
                    :class="sale.is_on_order
                      ? 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-950/70 dark:text-amber-300'
                      : 'border-emerald-300 bg-emerald-50 text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/70 dark:text-emerald-300'"
                  >
                    <Package v-if="sale.is_on_order" class="h-3 w-3" />
                    <Check v-else class="h-3 w-3" />
                    {{ sale.order_type_label || (sale.is_on_order ? 'На заказ' : 'В наличии') }}
                  </span>

                  <!-- Статус выполнения (для предзаказов) -->
                  <span
                    v-if="sale.is_on_order"
                    class="rounded-full px-2 py-0.5 text-[11px] font-bold border"
                    :class="getOrderStatusBadgeClass(sale.fulfillment_status)"
                  >
                    {{ getOrderStatusLabel(sale.fulfillment_status) }}
                  </span>

                  <!-- Срок готовности -->
                  <span
                    v-if="sale.is_on_order && sale.due_date"
                    class="rounded bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-900 border border-amber-200 dark:border-amber-900 dark:bg-amber-950/70 dark:text-amber-300 inline-flex items-center gap-1"
                  >
                    <Calendar class="h-3 w-3 text-amber-600" />
                    Срок: {{ formatDate(sale.due_date) }}
                  </span>

                  <!-- Быстрое продвижение статуса заказа на пошив -->
                  <button
                    v-if="sale.is_on_order && sale.status === 'approved' && (!sale.fulfillment_status || sale.fulfillment_status === 'new')"
                    type="button"
                    class="rounded bg-sky-100 hover:bg-sky-200 text-sky-800 dark:bg-sky-950 dark:text-sky-300 px-2 py-0.5 text-[10px] font-bold"
                    @click="advanceOrderStatus(sale, 'in_progress')"
                  >
                    → В работу
                  </button>
                  <button
                    v-else-if="sale.is_on_order && sale.status === 'approved' && sale.fulfillment_status === 'in_progress'"
                    type="button"
                    class="rounded bg-emerald-100 hover:bg-emerald-200 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 px-2 py-0.5 text-[10px] font-bold"
                    @click="advanceOrderStatus(sale, 'ready')"
                  >
                    → Готов к выдаче
                  </button>
                  <button
                    v-else-if="sale.is_on_order && sale.status === 'approved' && sale.fulfillment_status === 'ready'"
                    type="button"
                    class="rounded bg-slate-200 hover:bg-slate-300 text-slate-800 dark:bg-slate-800 dark:text-slate-200 px-2 py-0.5 text-[10px] font-bold"
                    @click="advanceOrderStatus(sale, 'delivered')"
                  >
                    → Выдан покупателю
                  </button>

                  <!-- Статус продажи -->
                  <span
                    class="badge text-[11px]"
                    :class="sale.status === 'approved' ? 'badge-success' : 'badge-danger'"
                  >
                    {{ sale.status === 'approved' ? 'Оформлен' : 'Отменен' }}
                  </span>
                </div>

                <!-- Нижняя строка (снизу): Номер чека, дата и время, кассир и количество шт -->
                <div class="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                  <span class="font-semibold text-slate-700 dark:text-slate-300">Чек №{{ sale.id }}</span>
                  <span>·</span>
                  <span>{{ date(sale.sold_at || sale.approved_at || sale.created_at) }}</span>
                  <span>·</span>
                  <span>Кассир: <strong class="font-medium text-slate-700 dark:text-slate-300">{{ sale.cashier?.name || '—' }}</strong></span>
                  <span>·</span>
                  <span>{{ (sale.items || []).reduce((acc, i) => acc + (Number(i.quantity) || 1), 0) }} шт.</span>
                </div>
              </div>

              <!-- Итоговая сумма чека и кнопки действий -->
              <div class="flex items-center justify-between sm:justify-end gap-2.5 shrink-0">
                <div class="text-right">
                  <div
                    class="text-lg sm:text-xl font-black tabular-nums"
                    :class="Number(sale.subtotal) === 0 ? 'text-emerald-700 dark:text-emerald-300' : 'text-emerald-600 dark:text-emerald-400'"
                  >
                    {{ money(sale.subtotal) }}
                  </div>
                  <span
                    v-if="Number(sale.subtotal) === 0"
                    class="inline-block rounded bg-emerald-100 px-1.5 py-0.2 text-[10px] font-bold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300"
                  >
                    🎁 Бесплатно
                  </span>
                </div>

                <div class="flex items-center gap-1.5">
                  <!-- Кнопка: Повторить чек (копировать в кассу) -->
                  <button
                    type="button"
                    class="btn-muted h-8 px-2 text-xs flex items-center gap-1 font-semibold text-slate-700 hover:border-sky-400 hover:text-sky-600 dark:text-slate-300 dark:hover:text-sky-400"
                    title="Скопировать товары этого чека в кассу"
                    @click="duplicateSaleToCart(sale)"
                  >
                    <Copy class="h-3.5 w-3.5 text-sky-600 dark:text-sky-400" />
                    <span class="hidden sm:inline">Повторить</span>
                  </button>

                  <!-- Кнопка: Изменить продажу -->
                  <button
                    type="button"
                    class="btn-muted h-8 px-2 text-xs flex items-center gap-1 font-semibold text-slate-700 hover:border-emerald-400 hover:text-emerald-600 dark:text-slate-300 dark:hover:text-emerald-400"
                    title="Изменить продажу"
                    @click="openEditModal(sale)"
                  >
                    <Pencil class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" />
                    <span class="hidden sm:inline">Изменить</span>
                  </button>

                  <!-- Кнопка: Отменить продажу -->
                  <button
                    v-if="sale.status === 'approved'"
                    type="button"
                    class="btn-muted h-8 px-2 text-xs flex items-center gap-1 font-semibold text-slate-700 hover:border-rose-400 hover:text-rose-600 dark:text-slate-300 dark:hover:text-rose-400"
                    title="Безопасно отменить чек"
                    @click="openCancelModal(sale)"
                  >
                    <Ban class="h-3.5 w-3.5 text-rose-500" />
                    <span class="hidden sm:inline">Отменить</span>
                  </button>

                  <!-- Кнопка: Удалить продажу -->
                  <button
                    v-if="auth.isAdmin"
                    type="button"
                    class="btn-danger h-8 w-8 px-0"
                    title="Удалить чек навсегда"
                    @click="confirmDeleteSale(sale)"
                  >
                    <Trash2 class="h-3.5 w-3.5" />
                  </button>
                </div>
              </div>
            </div>

            <!-- Комментарий / примечание кассира (если есть) -->
            <div
              v-if="sale.cashier_note"
              class="mt-2.5 rounded-md border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs text-slate-700 dark:border-slate-800 dark:bg-slate-900/60 dark:text-slate-300"
            >
              <span class="font-medium text-slate-500">Примечание:</span> {{ sale.cashier_note }}
            </div>

            <!-- Детализация товаров в чеке -->
            <div class="mt-3 rounded-lg border border-slate-200/80 bg-white dark:border-slate-800/80 dark:bg-slate-950 p-2.5">
              <div class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-2">
                Товары в этом заказе:
              </div>
              <div class="space-y-1.5">
                <div
                  v-for="item in sale.items"
                  :key="item.id"
                  class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1 text-xs py-1 border-b border-slate-100 last:border-b-0 dark:border-slate-900"
                >
                  <div class="flex flex-wrap items-center gap-1.5 min-w-0">
                    <!-- Название товара -->
                    <span class="font-bold text-slate-900 dark:text-white">
                      {{ item.product_name || item.product?.name || 'Товар' }}
                    </span>

                    <!-- Вид товара -->
                    <span
                      v-if="item.product_type || item.product?.type"
                      class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300"
                    >
                      {{ item.product_type || item.product?.type }}
                    </span>

                    <!-- Размер -->
                    <span
                      v-if="item.variant_size"
                      class="rounded border border-emerald-300 bg-emerald-50 px-2 py-0.5 text-[11px] font-bold text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300"
                    >
                      {{ item.variant_size }}
                    </span>
                  </div>

                  <div class="flex items-center gap-3 text-right">
                    <span v-if="Number(item.sale_price) === 0 || Number(item.line_total) === 0" class="rounded bg-emerald-100 px-2 py-0.5 text-[10px] font-bold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                      🎁 Бесплатно × {{ item.quantity }} шт.
                    </span>
                    <span v-else class="text-slate-500">
                      {{ money(item.sale_price) }} × <strong class="text-slate-800 dark:text-slate-200">{{ item.quantity }} шт.</strong>
                    </span>
                    <span class="font-bold tabular-nums text-slate-900 dark:text-white min-w-20 text-right">
                      = {{ money(item.line_total) }}
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <PaginationBar :meta="sales.meta" @page="fetchHistory" />
      </div>

      <!-- РЕЖИМ 2: СВОДНЫЙ РАСЧЕТ ПРОДАННЫХ ПО ВИДАМ ТОВАРОВ И КАЖДОМУ РАЗМЕРУ -->
      <div v-else-if="historyViewMode === 'summary'" class="p-4 space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 bg-emerald-50/70 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-900/60 p-3 rounded-xl text-xs text-emerald-950 dark:text-emerald-200">
          <div>
            <div class="font-bold text-emerald-900 dark:text-emerald-100 text-sm">
              Расчет проданных товаров по видам и по каждому размеру
            </div>
            <p class="text-[11px] text-emerald-800 dark:text-emerald-300 mt-0.5">
              С наглядным разделением «Из наличия» и «На заказ». Нажмите на вид товара, чтобы раскрыть или скрыть размеры.
            </p>
          </div>
          <button
            type="button"
            class="text-xs font-semibold text-emerald-700 hover:underline dark:text-emerald-300 shrink-0"
            @click="toggleAllSummaryTypes"
          >
            {{ allSummaryExpanded ? 'Свернуть все размеры' : 'Раскрыть все размеры' }}
          </button>
        </div>

        <div v-if="summaryLoading" class="p-6">
          <SkeletonBlock custom-class="h-44" />
        </div>

        <EmptyState
          v-else-if="!summaryTypes.length"
          title="Нет данных по видам"
          text="Оформите продажи, чтобы увидеть статистику по видам и размерам."
        />

        <div v-else class="space-y-3">
          <div
            v-for="item in summaryTypes"
            :key="item.type"
            class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden dark:border-slate-800 dark:bg-slate-900"
          >
            <!-- Заголовок вида товара -->
            <div
              class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between cursor-pointer hover:bg-slate-50/80 dark:hover:bg-slate-800/50 transition"
              @click="toggleSummaryType(item.type)"
            >
              <div class="flex items-center gap-3">
                <button
                  type="button"
                  class="flex h-7 w-7 items-center justify-center rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-500"
                >
                  <ChevronDown
                    class="h-4 w-4 transition-transform duration-200"
                    :class="{ '-rotate-90': !isSummaryTypeExpanded(item.type) }"
                  />
                </button>
                <div>
                  <div class="flex items-center gap-2">
                    <span class="text-base font-bold text-slate-900 dark:text-white">{{ item.type }}</span>
                    <span class="rounded bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                      {{ (item.sizes || []).length }} разм.
                    </span>
                  </div>
                  <div class="text-xs text-slate-500 mt-0.5">
                    Было учтено: <strong>{{ item.initial_quantity }}</strong> шт. · Текущий остаток: <strong>{{ item.stock_quantity }}</strong> шт.
                  </div>
                </div>
              </div>

              <!-- Показатели продаж по виду -->
              <div class="flex flex-wrap items-center gap-2 text-xs sm:justify-end">
                <span class="rounded-lg bg-emerald-50 border border-emerald-200 px-2.5 py-1 font-bold text-emerald-800 dark:bg-emerald-950/60 dark:border-emerald-800 dark:text-emerald-300">
                  Продано всего: {{ item.sold_quantity }} шт.
                </span>
                <span class="rounded-lg bg-white border border-slate-200 px-2.5 py-1 text-slate-700 dark:bg-slate-800 dark:border-slate-700 dark:text-slate-300">
                  ✓ В наличии: <strong class="text-emerald-600 dark:text-emerald-400">{{ item.in_stock_sold || 0 }}</strong> шт.
                </span>
                <span class="rounded-lg bg-amber-50 border border-amber-200 px-2.5 py-1 text-amber-900 dark:bg-amber-950/60 dark:border-amber-800 dark:text-amber-300">
                  📦 На заказ: <strong>{{ item.on_order_sold || 0 }}</strong> шт.
                </span>
                <span class="text-sm font-bold text-slate-900 dark:text-white ml-1">
                  {{ money(item.revenue) }}
                </span>
              </div>
            </div>

            <!-- Таблица размеров данного вида товара -->
            <div
              v-if="isSummaryTypeExpanded(item.type)"
              class="border-t border-slate-200 bg-slate-50/50 p-3.5 dark:border-slate-800 dark:bg-slate-950/40"
            >
              <div v-if="!(item.sizes || []).length" class="text-xs text-slate-400 py-2 text-center">
                Нет данных по размерам
              </div>
              <div v-else class="overflow-x-auto rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <table class="w-full text-left text-xs">
                  <thead class="border-b border-slate-200 bg-slate-50 text-[10px] font-semibold uppercase text-slate-500 dark:border-slate-800 dark:bg-slate-950">
                    <tr>
                      <th class="px-3.5 py-2.5">Размер</th>
                      <th class="px-3.5 py-2.5 text-right font-bold text-slate-900 dark:text-white">Продано всего</th>
                      <th class="px-3.5 py-2.5 text-right text-emerald-700 dark:text-emerald-300">Из наличия</th>
                      <th class="px-3.5 py-2.5 text-right text-amber-700 dark:text-amber-300">📦 На заказ</th>
                      <th class="px-3.5 py-2.5 text-right">Текущий остаток</th>
                      <th class="px-3.5 py-2.5 text-right">Было учтено</th>
                      <th class="px-3.5 py-2.5 text-right">Выручка</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <tr
                      v-for="sz in item.sizes"
                      :key="sz.size"
                      class="hover:bg-slate-50/80 dark:hover:bg-slate-800/40"
                    >
                      <td class="px-3.5 py-2.5 font-bold text-slate-900 dark:text-white">
                        <span class="inline-block rounded border border-emerald-300 bg-emerald-50 px-2.5 py-0.5 text-xs font-black text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/80 dark:text-emerald-300">
                          {{ sz.size }}
                        </span>
                      </td>
                      <td class="px-3.5 py-2.5 text-right font-black text-slate-900 dark:text-white tabular-nums text-sm">
                        {{ sz.sold_quantity }} шт.
                      </td>
                      <td class="px-3.5 py-2.5 text-right tabular-nums">
                        <span class="rounded bg-emerald-50 px-2 py-0.5 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300 font-semibold">
                          {{ sz.in_stock_sold || 0 }} шт.
                        </span>
                      </td>
                      <td class="px-3.5 py-2.5 text-right tabular-nums">
                        <span class="rounded bg-amber-50 px-2 py-0.5 text-amber-800 dark:bg-amber-950/50 dark:text-amber-300 font-semibold">
                          {{ sz.on_order_sold || 0 }} шт.
                        </span>
                      </td>
                      <td class="px-3.5 py-2.5 text-right tabular-nums text-slate-600 dark:text-slate-400">
                        {{ sz.stock_quantity }} шт.
                      </td>
                      <td class="px-3.5 py-2.5 text-right tabular-nums font-semibold text-slate-700 dark:text-slate-300">
                        {{ sz.initial_quantity }} шт.
                      </td>
                      <td class="px-3.5 py-2.5 text-right font-bold text-slate-900 dark:text-white tabular-nums">
                        {{ money(sz.revenue) }}
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- МОДАЛЬНОЕ ОКНО: РЕДАКТИРОВАНИЕ СОЗДАННОЙ ПРОДАЖИ -->
    <div
      v-if="editingSale"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm"
      @click.self="closeEditModal"
    >
      <div class="relative w-full max-w-2xl rounded-2xl bg-white p-5 shadow-2xl dark:bg-slate-900 border border-slate-200 dark:border-slate-800 max-h-[90vh] flex flex-col">
        <!-- Шапка модала -->
        <div class="flex items-center justify-between border-b border-slate-200 pb-3 dark:border-slate-800">
          <div>
            <div class="flex items-center gap-2">
              <h3 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">
                Редактирование чека №{{ editingSale.id }}
              </h3>
              <span
                class="rounded px-2 py-0.5 text-xs font-semibold"
                :class="editForm.is_on_order ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300' : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300'"
              >
                {{ editForm.is_on_order ? '📦 На заказ' : 'В наличии' }}
              </span>
            </div>
            <p class="text-xs text-slate-500 mt-0.5">
              Вы можете изменить товары, размеры, количество, цены и тип продажи. Остатки на складе пересчитаются автоматически.
            </p>
          </div>
          <button
            type="button"
            class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800"
            @click="closeEditModal"
          >
            <X class="h-5 w-5" />
          </button>
        </div>

        <form class="space-y-4 overflow-y-auto py-4 flex-1 pr-1" @submit.prevent="saveEditSale">
          <!-- Тип заказа: В наличии / На заказ -->
          <div>
            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
              Тип заказа
            </label>
            <div class="grid grid-cols-2 gap-2 text-xs">
              <button
                type="button"
                class="flex items-center justify-center gap-2 rounded-lg border p-2.5 font-medium transition"
                :class="!editForm.is_on_order
                  ? 'border-emerald-500 bg-emerald-50 text-emerald-900 font-bold shadow-sm dark:border-emerald-500 dark:bg-emerald-950/60 dark:text-emerald-200'
                  : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400'"
                @click="editForm.is_on_order = false"
              >
                <Check class="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                <span>В наличии</span>
                <span class="text-[10px] text-slate-400">(из магазина)</span>
              </button>

              <button
                type="button"
                class="flex items-center justify-center gap-2 rounded-lg border p-2.5 font-medium transition"
                :class="editForm.is_on_order
                  ? 'border-amber-500 bg-amber-50 text-amber-900 font-bold shadow-sm dark:border-amber-500 dark:bg-amber-950/60 dark:text-amber-200'
                  : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400'"
                @click="editForm.is_on_order = true"
              >
                <Package class="h-4 w-4 text-amber-600 dark:text-amber-400" />
                <span>📦 На заказ</span>
                <span class="text-[10px] text-slate-400">(предзаказ)</span>
              </button>
            </div>
          </div>

          <!-- Дата оформления чека и срок готовности заказа -->
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
              <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                Дата оформления чека <span class="text-rose-500">*</span>
              </label>
              <input
                v-model="editForm.sold_at"
                type="date"
                class="input text-xs font-semibold"
                required
              />
              <p class="mt-0.5 text-[10px] text-slate-400">Дата фиксации продажи в отчетности</p>
            </div>

            <div v-if="editForm.is_on_order">
              <label class="block text-xs font-semibold text-amber-800 dark:text-amber-300 mb-1">
                Срок готовности заказа (план)
              </label>
              <input
                v-model="editForm.due_date"
                type="date"
                class="input text-xs border-amber-300 dark:border-amber-700"
              />
            </div>
          </div>

          <div v-if="editForm.is_on_order">
            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
              Статус выполнения заказа
            </label>
            <select v-model="editForm.fulfillment_status" class="select text-xs">
              <option value="new">Новый заказ</option>
              <option value="in_progress">В пошиве / в работе</option>
              <option value="ready">Готов к выдаче</option>
              <option value="delivered">Выдан покупателю</option>
              <option value="cancelled">Отменен</option>
            </select>
          </div>

          <!-- Товары в чеке -->
          <div>
            <div class="flex items-center justify-between mb-2">
              <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300">
                Товары в этом заказе ({{ editForm.items.length }})
              </label>
              <button
                type="button"
                class="text-xs text-emerald-600 dark:text-emerald-400 font-semibold hover:underline flex items-center gap-1"
                @click="showAddEditItem = !showAddEditItem"
              >
                <span>+ Добавить товар</span>
              </button>
            </div>

            <!-- Добавление нового товара в чек (выбор из каталога) -->
            <div v-if="showAddEditItem" class="mb-3 rounded-xl border border-emerald-300 bg-emerald-50/50 p-3 dark:border-emerald-800 dark:bg-emerald-950/30">
              <div class="text-xs font-semibold text-emerald-900 dark:text-emerald-200 mb-2">
                Добавить позицию из каталога:
              </div>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                <div>
                  <label class="block text-[11px] text-slate-600 dark:text-slate-400 mb-0.5">Выберите товар</label>
                  <select v-model="newItemProductId" class="select text-xs h-8" @change="onEditProductChange">
                    <option value="">-- Выберите товар --</option>
                    <option v-for="p in availableProducts" :key="p.id" :value="p.id">{{ p.name }} ({{ p.type || 'Товар' }})</option>
                  </select>
                </div>
                <div>
                  <label class="block text-[11px] text-slate-600 dark:text-slate-400 mb-0.5">Размер</label>
                  <select v-if="newItemVariants.length" v-model="newItemVariantId" class="select text-xs h-8" @change="onEditVariantChange">
                    <option v-for="v in newItemVariants" :key="v.id" :value="v.id">{{ v.size }} ({{ money(v.sale_price) }})</option>
                  </select>
                  <input v-else v-model="newItemSize" class="input text-xs h-8" placeholder="Размер (например M, 164)" />
                </div>
              </div>
              <div class="mt-2.5 flex justify-end gap-2">
                <button type="button" class="btn-muted h-7 px-2.5 text-xs" @click="showAddEditItem = false">Отмена</button>
                <button type="button" class="btn-primary h-7 px-3 text-xs" :disabled="!newItemProductId" @click="confirmAddEditItem">Добавить в чек</button>
              </div>
            </div>

            <!-- Список редактируемых позиций -->
            <div class="space-y-2">
              <div
                v-for="(item, idx) in editForm.items"
                :key="idx"
                class="rounded-xl border border-slate-200 p-3 bg-slate-50/50 dark:border-slate-800 dark:bg-slate-950/50"
              >
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                  <div class="min-w-0 flex-1">
                    <input
                      v-model="item.product_name"
                      class="input h-8 text-xs font-bold"
                      placeholder="Название товара"
                      required
                    />
                    <div class="mt-1 flex items-center gap-2">
                      <span class="text-[11px] text-slate-500">Вид:</span>
                      <input
                        v-model="item.product_type"
                        class="input h-6 text-[11px] py-0 px-2 w-32"
                        placeholder="Вид товара"
                      />
                    </div>
                  </div>

                  <div class="flex flex-wrap items-center gap-2">
                    <!-- Размер -->
                    <div>
                      <span class="block text-[10px] text-slate-400 mb-0.5">Размер:</span>
                      <input
                        v-model="item.variant_size"
                        class="input h-8 text-xs font-bold w-20 text-center"
                        placeholder="Размер"
                        required
                      />
                    </div>

                    <!-- Цена за шт -->
                    <div>
                      <span class="block text-[10px] text-slate-400 mb-0.5">Цена, ₸:</span>
                      <input
                        v-model.number="item.sale_price"
                        type="number"
                        min="0"
                        step="100"
                        class="input h-8 text-xs font-semibold w-24 text-right"
                        required
                      />
                      <div class="mt-1 flex items-center gap-1">
                        <button
                          type="button"
                          class="rounded px-1 py-0.5 text-[10px] font-semibold bg-blue-50 text-blue-700 hover:bg-blue-100 dark:bg-blue-950 dark:text-blue-300"
                          title="Скидка 10%"
                          @click="item.sale_price = Math.round(Number(item.sale_price || 0) * 0.9)"
                        >
                          -10%
                        </button>
                        <button
                          type="button"
                          class="rounded px-1 py-0.5 text-[10px] font-semibold bg-indigo-50 text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-950 dark:text-indigo-300"
                          title="Скидка 15%"
                          @click="item.sale_price = Math.round(Number(item.sale_price || 0) * 0.85)"
                        >
                          -15%
                        </button>
                        <button
                          type="button"
                          class="rounded px-1 py-0.5 text-[10px] font-bold bg-emerald-50 text-emerald-800 hover:bg-emerald-100 dark:bg-emerald-950 dark:text-emerald-300"
                          title="Бесплатно (для учителей)"
                          @click="item.sale_price = 0"
                        >
                          🎁 0₸
                        </button>
                      </div>
                    </div>

                    <!-- Количество -->
                    <div>
                      <span class="block text-[10px] text-slate-400 mb-0.5">Кол-во:</span>
                      <div class="flex items-center gap-1">
                        <button
                          type="button"
                          class="btn-muted h-8 w-7 px-0 text-xs font-bold"
                          @click="item.quantity = Math.max(1, (item.quantity || 1) - 1)"
                        >
                          -
                        </button>
                        <input
                          v-model.number="item.quantity"
                          type="number"
                          min="1"
                          class="input h-8 w-12 text-center text-xs font-bold p-1"
                          required
                        />
                        <button
                          type="button"
                          class="btn-muted h-8 w-7 px-0 text-xs font-bold"
                          @click="item.quantity = (item.quantity || 1) + 1"
                        >
                          +
                        </button>
                      </div>
                    </div>

                    <!-- Итого по строке -->
                    <div class="text-right min-w-20 pl-2">
                      <span class="block text-[10px] text-slate-400 mb-0.5">Сумма:</span>
                      <span class="text-xs font-bold text-slate-900 dark:text-white">
                        {{ money((Number(item.sale_price) || 0) * (Number(item.quantity) || 1)) }}
                      </span>
                    </div>

                    <!-- Удалить позицию -->
                    <button
                      type="button"
                      class="btn-muted h-8 w-8 px-0 text-rose-500 hover:text-rose-600 mt-3 sm:mt-0"
                      title="Удалить позицию"
                      :disabled="editForm.items.length <= 1"
                      @click="removeEditItem(idx)"
                    >
                      <Trash2 class="h-3.5 w-3.5" />
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Комментарий к чеку -->
          <div>
            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
              Примечание / комментарий к продаже
            </label>
            <textarea
              v-model="editForm.cashier_note"
              class="textarea text-xs"
              rows="2"
              placeholder="Примечание к заказу..."
            />
          </div>

          <!-- Сводка и итоговая сумма чека -->
          <div class="rounded-xl bg-slate-100 p-3.5 dark:bg-slate-800/70">
            <div class="flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
              <span>Всего единиц в заказе:</span>
              <span class="font-bold text-slate-800 dark:text-slate-200">
                {{ editForm.items.reduce((s, i) => s + (Number(i.quantity) || 1), 0) }} шт.
              </span>
            </div>
            <div class="mt-2 flex items-baseline justify-between border-t border-slate-200 pt-2 dark:border-slate-700">
              <span class="text-sm font-semibold text-slate-800 dark:text-slate-200">Итоговая сумма чека:</span>
              <span class="text-2xl font-black text-emerald-600 dark:text-emerald-400 tabular-nums">
                {{ money(editFormTotal) }}
              </span>
            </div>
          </div>

          <!-- Кнопки действий -->
          <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-200 dark:border-slate-800">
            <button
              type="button"
              class="btn-muted px-4 py-2 text-xs"
              @click="closeEditModal"
            >
              Отмена
            </button>
            <button
              type="submit"
              class="btn-primary px-5 py-2 text-xs font-semibold flex items-center gap-1.5"
              :disabled="editSaving"
            >
              <LoaderCircle v-if="editSaving" class="h-4 w-4 animate-spin" />
              <Check v-else class="h-4 w-4" />
              Сохранить изменения
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- МОДАЛЬНОЕ ОКНО: БЕЗОПАСНАЯ ОТМЕНА ЧЕКА -->
    <div
      v-if="cancelModalOpen"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm"
      @click.self="cancelModalOpen = false"
    >
      <div class="relative w-full max-w-md rounded-2xl bg-white p-5 shadow-2xl dark:bg-slate-900 border border-slate-200 dark:border-slate-800 space-y-4">
        <div class="flex items-center gap-2.5 text-rose-600 dark:text-rose-400">
          <Ban class="h-5 w-5" />
          <h3 class="font-bold text-base text-slate-900 dark:text-white">Отмена чека №{{ saleToCancel?.id }}</h3>
        </div>
        <p class="text-xs text-slate-600 dark:text-slate-400 leading-relaxed">
          Чек будет переведен в статус <b>«Отменен»</b>, а выручка (<b>{{ money(saleToCancel?.subtotal) }}</b>) будет безопасно исключена из отчетов смены и аналитики без удаления чека из базы данных.
        </p>
        <div>
          <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
            Причина отмены (необязательно)
          </label>
          <input
            v-model="cancelReason"
            class="input text-xs"
            placeholder="Например: возврат покупателя, ошибка кассира..."
            autofocus
            @keyup.enter="submitCancelSale"
          />
        </div>
        <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-200 dark:border-slate-800">
          <button type="button" class="btn-muted h-8 px-3 text-xs" @click="cancelModalOpen = false">
            Не отменять
          </button>
          <button
            type="button"
            class="btn-danger h-8 px-4 text-xs font-semibold flex items-center gap-1.5"
            :disabled="cancelling"
            @click="submitCancelSale"
          >
            <LoaderCircle v-if="cancelling" class="h-3.5 w-3.5 animate-spin" />
            <span v-else>Подтвердить отмену чека</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import {
  ArrowRight,
  Ban,
  Calendar,
  Check,
  ChevronDown,
  Clock,
  Copy,
  Download,
  Layers,
  LoaderCircle,
  Package,
  Pencil,
  RefreshCw,
  Search,
  ShoppingCart,
  Sparkles,
  Trash2,
  X,
} from 'lucide-vue-next'
import api, { apiError } from '../api/client'
import { resolveApiOrigin } from '../config/api'
import { useAuthStore } from '../stores/auth'
import { useCatalogStore } from '../stores/catalog'
import { useSalesStore } from '../stores/sales'
import { useToastStore } from '../stores/toasts'
import EmptyState from '../components/ui/EmptyState.vue'
import PaginationBar from '../components/ui/PaginationBar.vue'
import SkeletonBlock from '../components/ui/SkeletonBlock.vue'
import { date, formatDate, money } from '../utils/formatters'

function getTodayIsoDate() {
  const d = new Date()
  const year = d.getFullYear()
  const month = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

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

const auth = useAuthStore()
const catalog = useCatalogStore()
const sales = useSalesStore()
const toast = useToastStore()

const searchInputRef = ref(null)
const quickNameRef = ref(null)

const activeMode = ref('catalog') // 'catalog' | 'quick'
const productSearch = ref('')
const historySearch = ref('')
const historyStatus = ref('approved')
const historyOrderType = ref('') // '' (all) | 'in_stock' | 'on_order'
const historyDatePreset = ref('all') // 'all' | 'today' | 'yesterday' | '7days' | 'custom'
const historyDateFrom = ref('')
const historyDateTo = ref('')
const historyFulfillment = ref('')

const historyViewMode = ref('orders') // 'orders' | 'summary'
const summaryLoading = ref(false)
const summaryTypes = ref([])
const expandedSummaryTypes = ref(new Set())
const allSummaryExpanded = ref(true)

const editingSale = ref(null)
const editForm = ref({
  is_on_order: false,
  sold_at: '',
  due_date: '',
  fulfillment_status: 'new',
  cashier_note: '',
  items: [],
})
const editSaving = ref(false)
const showAddEditItem = ref(false)
const newItemProductId = ref('')
const newItemVariantId = ref('')
const newItemSize = ref('')
const newItemVariants = ref([])

const cart = ref([])
const cartIsOnOrder = ref(false)

// Управление фиксированной датой продажи (сохраняется с прошлой добавленной продажи)
const lastUsedSaleDate = ref(localStorage.getItem('clothing_store_last_sale_date') || getTodayIsoDate())

function updateLastUsedDate(val) {
  if (val) {
    lastUsedSaleDate.value = val
    try {
      localStorage.setItem('clothing_store_last_sale_date', val)
    } catch (e) {}
  }
}

const saleDate = ref(lastUsedSaleDate.value || getTodayIsoDate())
const saleDueDate = ref('')
const cashierNote = ref('')
const saving = ref(false)
const quickSaving = ref(false)
const quickCustomCategory = ref(false)

watch(saleDate, (val) => {
  if (val) updateLastUsedDate(val)
})

const cancelModalOpen = ref(false)
const saleToCancel = ref(null)
const cancelReason = ref('')
const cancelling = ref(false)

const saleStep = ref('products')
const selectedProduct = ref(null)
const selectedVariant = ref(null)
const productDetail = ref(null)
let debounce
let historyDebounce
let timer

// Быстрые названия товаров: Брюки.спорт, Поло к/р, Поло д/р, Бомбер, Худи, Футболка, Шорты
const commonNames = [
  'Брюки.спорт',
  'Поло к/р',
  'Поло д/р',
  'Бомбер',
  'Худи',
  'Футболка',
  'Шорты',
]

function setQuickName(name) {
  quickForm.value.name = name
  const lower = name.toLowerCase()
  if (lower.includes('поло')) {
    quickForm.value.type = 'Поло'
  } else if (lower.includes('брюки')) {
    quickForm.value.type = 'Брюки'
  } else if (lower.includes('бомбер')) {
    quickForm.value.type = 'Бомбер'
  } else if (lower.includes('худи')) {
    quickForm.value.type = 'Худи'
  } else if (lower.includes('футболк')) {
    quickForm.value.type = 'Футболка'
  } else if (lower.includes('шорт')) {
    quickForm.value.type = 'Шорты'
  }
}

// Быстрые цвета: Голубой, Синий, Темно-синий, Белый, Черный, Серый
const commonColors = [
  'Голубой',
  'Синий',
  'Темно-синий',
  'Белый',
  'Черный',
  'Серый',
]

// Виды товаров (существующие + формы)
const popularTypes = [
  'Футболка',
  'Худи',
  'Свитшот',
  'Джинсы',
  'Штаны',
  'Шорты',
  'Поло',
  'Брюки',
  'Бомбер',
  'Рубашка',
  'Куртка',
  'Платье',
  'Юбка',
  'Костюм',
]

// Размеры: 110 + 116-164 + 170, 176, 182, 188, 194 + XS, S, M, L, XL, XXL
const commonSizes = [
  '110', '116', '122', '128', '134', '140', '146', '152', '158', '164',
  '170', '176', '182', '188', '194',
  'XS', 'S', 'M', 'L', 'XL', 'XXL',
]

const quickForm = ref({
  name: '',
  type: 'Футболка',
  category_id: '',
  category_name: '',
  size: '128',
  color: '',
  sale_price: '',
  purchase_price: 0,
  sold_at: lastUsedSaleDate.value || getTodayIsoDate(),
  due_date: '',
  is_on_order: false,
  quantity: 1,
  cashier_note: '',
})

watch(() => quickForm.value.sold_at, (val) => {
  if (val) updateLastUsedDate(val)
})

// Быстрые скидки для быстрой продажи
const quickDiscountType = ref(null)
const quickBasePrice = ref(null)

function applyQuickDiscount(discountType) {
  const currentPrice = Number(quickForm.value.sale_price || 0)
  if (quickBasePrice.value === null || quickBasePrice.value === 0) {
    if (currentPrice > 0) {
      quickBasePrice.value = currentPrice
    }
  }

  // Toggle при повторном клике
  if (quickDiscountType.value === discountType && discountType !== null) {
    discountType = null
  }

  quickDiscountType.value = discountType
  const base = quickBasePrice.value || currentPrice || 0

  if (discountType === 'free') {
    quickForm.value.sale_price = 0
  } else if (discountType === '10') {
    quickForm.value.sale_price = Math.round(base * 0.9)
  } else if (discountType === '15') {
    quickForm.value.sale_price = Math.round(base * 0.85)
  } else {
    // Сброс на базовую цену
    quickForm.value.sale_price = base > 0 ? base : ''
  }
}

const apiBase = computed(() => resolveApiOrigin())

const stepHint = computed(() => {
  if (saleStep.value === 'products') return 'Шаг 1: выберите модель товара'
  return 'Шаг 2: выберите размер для добавления в чек'
})

const availableProducts = computed(() => catalog.products || [])

const allVariantsOfSelected = computed(() => {
  return productDetail.value?.variants || selectedProduct.value?.variants || []
})

const totalCartUnits = computed(() =>
  cart.value.reduce((sum, row) => sum + Number(row.quantity || 1), 0),
)

const cartTotal = computed(() =>
  cart.value.reduce((sum, row) => sum + Number(row.price || 0) * Number(row.quantity || 1), 0),
)

// Исходная сумма корзины без скидок
const cartOriginalTotal = computed(() =>
  cart.value.reduce(
    (sum, row) => sum + Number(row.original_price ?? row.price ?? 0) * Number(row.quantity || 1),
    0,
  ),
)

// Суммарная экономия (скидка) по всему чеку
const cartTotalSavings = computed(() => {
  return Math.max(0, cartOriginalTotal.value - cartTotal.value)
})

// Есть ли хоть одна позиция со скидкой
const hasAnyDiscount = computed(() => {
  return cart.value.some(
    (row) =>
      row.discount_type ||
      (row.original_price !== undefined && Number(row.price) !== Number(row.original_price)),
  )
})

// Применение скидки к конкретной позиции в чеке
function applyItemDiscount(item, discountType) {
  if (item.original_price === undefined || item.original_price === null) {
    item.original_price = Number(item.price || item.variant?.sale_price || item.product?.sale_price || 0)
  }

  // Toggle: если нажали ту же кнопку повторно — снимаем скидку
  if (item.discount_type === discountType && discountType !== null) {
    discountType = null
  }

  item.discount_type = discountType
  item.customPriceActive = false

  if (discountType === 'free') {
    item.price = 0
  } else if (discountType === '10') {
    item.price = Math.round(item.original_price * 0.9)
  } else if (discountType === '15') {
    item.price = Math.round(item.original_price * 0.85)
  } else {
    // 0% / сброс
    item.price = item.original_price
  }
}

// Применение скидки ко всем позициям в чеке
function applyAllDiscount(discountType) {
  cart.value.forEach((item) => {
    applyItemDiscount(item, discountType)
  })
  if (discountType === 'free') {
    toast.push('Все позиции сделаны бесплатными (0 ₸, например для учителей)', 'success')
  } else if (discountType === '10') {
    toast.push('Скидка 10% применена ко всем позициям', 'info')
  } else if (discountType === '15') {
    toast.push('Скидка 15% применена ко всем позициям', 'info')
  } else if (discountType === null) {
    toast.push('Скидки сброшены на обычные цены', 'info')
  }
}

const editFormTotal = computed(() => {
  return (editForm.value?.items || []).reduce(
    (sum, item) => sum + (Number(item.sale_price) || 0) * (Number(item.quantity) || 1),
    0,
  )
})

function switchMode(mode) {
  activeMode.value = mode
  if (mode === 'quick' && productSearch.value) {
    quickForm.value.name = productSearch.value
  }
}

function openQuickWithSearch() {
  activeMode.value = 'quick'
  if (productSearch.value) {
    quickForm.value.name = productSearch.value
  }
}

function toggleCustomCategory() {
  quickCustomCategory.value = !quickCustomCategory.value
  if (!quickCustomCategory.value) {
    quickForm.value.category_name = ''
  } else {
    quickForm.value.category_id = ''
  }
}

function variantStock(v) {
  return Number(v.stock_store ?? 0) + Number(v.stock_warehouse ?? 0)
}

function photoUrl(path) {
  if (!path) return ''
  return path.startsWith('http') ? path : `${apiBase.value}${path}`
}

function productLineMeta(product) {
  const parts = []
  if (product.type) parts.push(product.type)
  if (product.category?.name) parts.push(product.category.name)
  if (product.color) parts.push(product.color)
  const vars = product.variants || []
  if (vars.length) parts.push(vars.map((v) => v.size).filter(Boolean).slice(0, 5).join(', '))
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
  const product = productDetail.value || selectedProduct.value
  const existing = cart.value.find((c) => c.product_variant_id === full.id)
  const initialPrice = Number(full.sale_price || product.sale_price || 0)

  if (existing) {
    existing.quantity++
    toast.push(`Количество увеличено: ${product.name} (${full.size})`)
  } else {
    cart.value.push({
      product_id: product.id,
      product_variant_id: full.id,
      product,
      variant: full,
      original_price: initialPrice,
      price: initialPrice,
      discount_type: null,
      customPriceActive: false,
      quantity: 1,
    })
    toast.push(`Добавлено в чек: ${product.name} (${full.size})`)
  }
  saleStep.value = 'products'
  selectedProduct.value = null
  selectedVariant.value = null
  productDetail.value = null
}

function increaseQty(item) {
  item.quantity++
}

function decreaseQty(item) {
  if (item.quantity > 1) {
    item.quantity--
  } else {
    removeFromCart(item.product_variant_id)
  }
}

function removeFromCart(variantId) {
  cart.value = cart.value.filter((c) => c.product_variant_id !== variantId)
}

function goBackStep() {
  saleStep.value = 'products'
  selectedProduct.value = null
  selectedVariant.value = null
  productDetail.value = null
}

async function createSale() {
  if (!cart.value.length || saving.value) return
  saving.value = true
  try {
    const hasFreeItems = cart.value.some((r) => Number(r.price) === 0)
    let note = cashierNote.value ? cashierNote.value.trim() : null
    if (!note && hasFreeItems) {
      const allFree = cart.value.every((r) => Number(r.price) === 0)
      note = allFree ? 'Бесплатно (для учителей)' : 'В чеке есть бесплатные позиции (для учителей)'
    }

    await sales.create({
      items: cart.value.map((row) => ({
        product_id: row.product.id,
        product_variant_id: row.product_variant_id,
        quantity: row.quantity,
        sale_price: Number(row.price) || 0,
        source: 'display',
      })),
      sold_at: saleDate.value || undefined,
      due_date: cartIsOnOrder.value && saleDueDate.value ? saleDueDate.value : undefined,
      fulfillment_status: cartIsOnOrder.value ? 'new' : undefined,
      is_on_order: Boolean(cartIsOnOrder.value),
      cashier_note: note,
    })
    const lastDate = saleDate.value || lastUsedSaleDate.value
    updateLastUsedDate(lastDate)

    toast.push('Продажа успешно оформлена!')
    cart.value = []
    cartIsOnOrder.value = false
    saleDate.value = lastDate
    saleDueDate.value = ''
    cashierNote.value = ''
    try {
      localStorage.removeItem('clothing_store_cart_v1')
    } catch (e) {}
    await refreshAll()
  } catch (error) {
    toast.push(apiError(error), 'error')
  } finally {
    saving.value = false
  }
}

async function submitQuickSale() {
  if (quickSaving.value) return
  if (!quickForm.value.name || quickForm.value.sale_price === '' || quickForm.value.sale_price === null || quickForm.value.sale_price === undefined) {
    toast.push('Укажите название и цену продажи', 'warning')
    return
  }

  quickSaving.value = true
  try {
    let note = quickForm.value.cashier_note ? quickForm.value.cashier_note.trim() : null
    if (!note && Number(quickForm.value.sale_price) === 0) {
      note = 'Бесплатно (для учителей)'
    }

    const payload = {
      name: quickForm.value.name.trim(),
      type: quickForm.value.type ? quickForm.value.type.trim() : null,
      category_id: quickForm.value.category_id || undefined,
      category_name: quickForm.value.category_name ? quickForm.value.category_name.trim() : undefined,
      size: quickForm.value.size ? quickForm.value.size.trim() : '128',
      color: quickForm.value.color ? quickForm.value.color.trim() : null,
      sale_price: Number(quickForm.value.sale_price) || 0,
      purchase_price: 0,
      sold_at: quickForm.value.sold_at || undefined,
      due_date: quickForm.value.is_on_order && quickForm.value.due_date ? quickForm.value.due_date : undefined,
      fulfillment_status: quickForm.value.is_on_order ? 'new' : undefined,
      is_on_order: Boolean(quickForm.value.is_on_order),
      quantity: Math.max(1, Number(quickForm.value.quantity || 1)),
      cashier_note: note,
    }

    await sales.createQuick(payload)
    toast.push(`Товар «${payload.name}» оформлен!`)

    const lastType = quickForm.value.type
    const lastCatId = quickForm.value.category_id
    const lastSoldAt = quickForm.value.sold_at || lastUsedSaleDate.value
    updateLastUsedDate(lastSoldAt)

    quickDiscountType.value = null
    quickBasePrice.value = null

    quickForm.value = {
      name: '',
      type: lastType,
      category_id: lastCatId,
      category_name: '',
      size: '128',
      color: '',
      sale_price: '',
      purchase_price: 0,
      sold_at: lastSoldAt,
      due_date: '',
      is_on_order: false,
      quantity: 1,
      cashier_note: '',
    }

    await refreshAll()
  } catch (error) {
    toast.push(apiError(error), 'error')
  } finally {
    quickSaving.value = false
  }
}

async function fetchProducts() {
  await catalog.fetchProducts({ search: productSearch.value || undefined, per_page: 40 })
}

function debouncedProducts() {
  window.clearTimeout(debounce)
  debounce = window.setTimeout(fetchProducts, 300)
}

function debouncedHistory() {
  window.clearTimeout(historyDebounce)
  historyDebounce = window.setTimeout(() => fetchHistory(1), 300)
}

function setHistoryOrderType(val) {
  historyOrderType.value = val
  fetchHistory(1)
}

function setHistoryDatePreset(key) {
  historyDatePreset.value = key
  const today = new Date()
  const toIso = (d) => {
    const y = d.getFullYear()
    const m = String(d.getMonth() + 1).padStart(2, '0')
    const day = String(d.getDate()).padStart(2, '0')
    return `${y}-${m}-${day}`
  }

  if (key === 'all') {
    historyDateFrom.value = ''
    historyDateTo.value = ''
  } else if (key === 'today') {
    historyDateFrom.value = toIso(today)
    historyDateTo.value = toIso(today)
  } else if (key === 'yesterday') {
    const yest = new Date(today)
    yest.setDate(yest.getDate() - 1)
    historyDateFrom.value = toIso(yest)
    historyDateTo.value = toIso(yest)
  } else if (key === '7days') {
    const past = new Date(today)
    past.setDate(past.getDate() - 6)
    historyDateFrom.value = toIso(past)
    historyDateTo.value = toIso(today)
  }
  fetchHistory(1)
}

async function fetchHistory(page = 1) {
  const params = {
    page,
    status: historyStatus.value || undefined,
    search: historySearch.value ? historySearch.value.trim() : undefined,
  }
  if (historyOrderType.value === 'on_order') {
    params.is_on_order = true
  } else if (historyOrderType.value === 'in_stock') {
    params.is_on_order = false
  }
  if (historyFulfillment.value) {
    params.fulfillment_status = historyFulfillment.value
  }
  if (historyDateFrom.value) {
    params.date_from = historyDateFrom.value
  }
  if (historyDateTo.value) {
    params.date_to = historyDateTo.value
  }
  await sales.fetch(params)
}

function duplicateSaleToCart(sale) {
  if (!sale || !sale.items?.length) return

  const itemsToAdd = sale.items.map((i) => {
    const salePrice = Number(i.sale_price) || 0
    const origPrice = Number(i.product?.sale_price || salePrice)
    return {
      product: i.product || {
        id: i.product_id,
        name: i.product_name || 'Товар',
        type: i.product_type,
        sale_price: i.sale_price,
      },
      product_variant_id: i.product_variant_id,
      variant: {
        id: i.product_variant_id,
        size: i.variant_size || '—',
        sale_price: i.sale_price,
      },
      original_price: origPrice,
      price: salePrice,
      discount_type: salePrice === 0 ? 'free' : null,
      customPriceActive: false,
      quantity: Number(i.quantity) || 1,
    }
  })

  cart.value = [...cart.value, ...itemsToAdd]
  cartIsOnOrder.value = Boolean(sale.is_on_order)
  if (sale.cashier_note) {
    cashierNote.value = `[Повтор чека №${sale.id}] ${sale.cashier_note}`
  }

  toast.push(`Позиции из чека №${sale.id} добавлены в корзину кассы!`, 'success')
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

function openCancelModal(sale) {
  saleToCancel.value = sale
  cancelReason.value = ''
  cancelModalOpen.value = true
}

async function submitCancelSale() {
  if (!saleToCancel.value) return
  cancelling.value = true
  try {
    await sales.cancelSale(saleToCancel.value.id, cancelReason.value)
    toast.push(`Чек №${saleToCancel.value.id} успешно отменен. Выручка пересчитана.`)
    cancelModalOpen.value = false
    saleToCancel.value = null
    await refreshAll()
  } catch (err) {
    toast.push(apiError(err), 'error')
  } finally {
    cancelling.value = false
  }
}

async function advanceOrderStatus(sale, nextStatus) {
  try {
    await api.put(`/sales/${sale.id}`, {
      is_on_order: true,
      fulfillment_status: nextStatus,
      items: (sale.items || []).map((i) => ({
        product_id: i.product_id || undefined,
        product_variant_id: i.product_variant_id || undefined,
        product_name: i.product_name,
        product_type: i.product_type,
        variant_size: i.variant_size,
        quantity: i.quantity,
        sale_price: i.sale_price,
      })),
    })
    toast.push(`Статус заказа №${sale.id} обновлен!`)
    await fetchHistory(sales.meta?.current_page || 1)
  } catch (err) {
    toast.push(apiError(err), 'error')
  }
}

async function refreshAll() {
  await Promise.all([
    fetchProducts(),
    fetchHistory(sales.meta.current_page || 1),
    catalog.fetchCategories(),
  ])
}

function formatSaleNumber(sale) {
  if (sale.number && !sale.number.startsWith('draft') && !sale.number.startsWith('S-')) {
    return sale.number
  }
  return `Чек №${sale.id}`
}

async function downloadExcel() {
  try {
    const params = new URLSearchParams()
    if (historyStatus.value) params.set('status', historyStatus.value)
    if (historyOrderType.value === 'on_order') params.set('is_on_order', '1')
    if (historyOrderType.value === 'in_stock') params.set('is_on_order', '0')

    const res = await api.get(`/reports/sales.xlsx?${params.toString()}`, { responseType: 'blob' })
    const blob = new Blob([res.data])
    const link = document.createElement('a')
    link.href = URL.createObjectURL(blob)
    link.download = `sales-report-${new Date().toISOString().slice(0, 10)}.xlsx`
    link.click()
    URL.revokeObjectURL(link.href)
    toast.push('Excel отчет успешно сформирован и скачан!')
  } catch (error) {
    toast.push(apiError(error), 'error')
  }
}

async function confirmDeleteSale(sale) {
  const label = formatSaleNumber(sale)
  if (!confirm(`Вы действительно хотите удалить ${label}?`)) return
  try {
    const { data } = await sales.deleteSale(sale.id)
    toast.push(data?.message || 'Удалено')
    await refreshAll()
  } catch (error) {
    toast.push(apiError(error), 'error')
  }
}

function getSaleProductTitle(sale) {
  if (!sale) return 'Продажа'
  const items = sale.items || []
  if (items.length === 0) {
    return sale.cashier_note || `Чек №${sale.id}`
  }
  const names = items
    .map((i) => (i.product_name || i.product?.name || '').trim())
    .filter(Boolean)
  const uniqueNames = [...new Set(names)]
  if (uniqueNames.length > 0) {
    return uniqueNames.join(', ')
  }
  return `Чек №${sale.id}`
}

function openEditModal(sale) {
  editingSale.value = sale
  const at = sale.sold_at || sale.approved_at || sale.created_at
  const initialDate = at ? at.slice(0, 10) : getTodayIsoDate()
  editForm.value = {
    is_on_order: Boolean(sale.is_on_order),
    sold_at: initialDate,
    due_date: sale.due_date ? sale.due_date.slice(0, 10) : '',
    fulfillment_status: sale.fulfillment_status || 'new',
    cashier_note: sale.cashier_note || '',
    items: (sale.items || []).map((it) => ({
      product_id: it.product_id,
      product_variant_id: it.product_variant_id,
      product_name: it.product_name || it.product?.name || 'Товар',
      product_type: it.product_type || it.product?.type || '',
      variant_size: it.variant_size || '',
      quantity: Number(it.quantity) || 1,
      sale_price: Number(it.sale_price) || 0,
      source: it.source_location || 'display',
    })),
  }
  showAddEditItem.value = false
  newItemProductId.value = ''
  newItemVariantId.value = ''
  newItemSize.value = ''
  newItemVariants.value = []
}

function closeEditModal() {
  editingSale.value = null
  showAddEditItem.value = false
}

function removeEditItem(idx) {
  if (editForm.value.items.length <= 1) return
  editForm.value.items.splice(idx, 1)
}

function onEditProductChange() {
  const prod = availableProducts.value.find((p) => Number(p.id) === Number(newItemProductId.value))
  newItemVariants.value = prod?.variants || []
  if (newItemVariants.value.length > 0) {
    newItemVariantId.value = newItemVariants.value[0].id
    newItemSize.value = newItemVariants.value[0].size
  } else {
    newItemVariantId.value = ''
    newItemSize.value = ''
  }
}

function onEditVariantChange() {
  const v = newItemVariants.value.find((v) => Number(v.id) === Number(newItemVariantId.value))
  if (v) newItemSize.value = v.size
}

function confirmAddEditItem() {
  const prod = availableProducts.value.find((p) => Number(p.id) === Number(newItemProductId.value))
  if (!prod) return
  const v = newItemVariants.value.find((v) => Number(v.id) === Number(newItemVariantId.value))
  editForm.value.items.push({
    product_id: prod.id,
    product_variant_id: v?.id || null,
    product_name: prod.name,
    product_type: prod.type || '',
    variant_size: newItemSize.value || v?.size || 'M',
    quantity: 1,
    sale_price: Number(v?.sale_price || prod.sale_price || 0),
    source: 'display',
  })
  showAddEditItem.value = false
  newItemProductId.value = ''
  newItemVariantId.value = ''
  newItemSize.value = ''
  newItemVariants.value = []
}

async function saveEditSale() {
  if (!editingSale.value) return
  if (!editForm.value.items.length) {
    toast.push('В чеке должен быть минимум 1 товар', 'warning')
    return
  }
  for (const it of editForm.value.items) {
    if (!it.variant_size) {
      toast.push(`Укажите размер для «${it.product_name}»`, 'warning')
      return
    }
  }

  editSaving.value = true
  try {
    await sales.updateSale(editingSale.value.id, {
      is_on_order: Boolean(editForm.value.is_on_order),
      sold_at: editForm.value.sold_at || undefined,
      due_date: editForm.value.is_on_order && editForm.value.due_date ? editForm.value.due_date : null,
      fulfillment_status: editForm.value.is_on_order ? editForm.value.fulfillment_status : null,
      cashier_note: editForm.value.cashier_note ? editForm.value.cashier_note.trim() : null,
      items: editForm.value.items.map((it) => ({
        product_id: it.product_id || undefined,
        product_variant_id: it.product_variant_id || undefined,
        product_name: it.product_name.trim(),
        product_type: it.product_type ? it.product_type.trim() : undefined,
        variant_size: it.variant_size.trim(),
        quantity: Math.max(1, Number(it.quantity) || 1),
        sale_price: Math.max(0, Number(it.sale_price) || 0),
        source: it.source || 'display',
      })),
    })
    toast.push(`Чек №${editingSale.value.id} успешно изменен!`)
    closeEditModal()
    await refreshAll()
    if (historyViewMode.value === 'summary') {
      await fetchSummaryData()
    }
  } catch (error) {
    toast.push(apiError(error), 'error')
  } finally {
    editSaving.value = false
  }
}

async function fetchSummaryData() {
  summaryLoading.value = true
  try {
    const { data } = await api.get('/reports/analytics')
    summaryTypes.value = data.by_type || []
    const set = new Set()
    summaryTypes.value.forEach((t) => set.add(t.type))
    expandedSummaryTypes.value = set
    allSummaryExpanded.value = true
  } catch (error) {
    toast.push(apiError(error), 'error')
  } finally {
    summaryLoading.value = false
  }
}

function openSummaryView() {
  historyViewMode.value = 'summary'
  fetchSummaryData()
}

function toggleSummaryType(type) {
  const set = new Set(expandedSummaryTypes.value)
  if (set.has(type)) {
    set.delete(type)
  } else {
    set.add(type)
  }
  expandedSummaryTypes.value = set
}

function isSummaryTypeExpanded(type) {
  return expandedSummaryTypes.value.has(type)
}

function toggleAllSummaryTypes() {
  if (allSummaryExpanded.value) {
    expandedSummaryTypes.value = new Set()
    allSummaryExpanded.value = false
  } else {
    const set = new Set()
    summaryTypes.value.forEach((t) => set.add(t.type))
    expandedSummaryTypes.value = set
    allSummaryExpanded.value = true
  }
}

onMounted(() => {
  // Restore cart draft from localStorage
  try {
    const saved = localStorage.getItem('clothing_store_cart_v1')
    if (saved) {
      const parsed = JSON.parse(saved)
      if (Array.isArray(parsed) && parsed.length > 0) {
        cart.value = parsed.map((item) => ({
          ...item,
          original_price: Number(item.original_price ?? item.price ?? 0),
          price: Number(item.price ?? item.original_price ?? 0),
          discount_type: item.discount_type ?? null,
          customPriceActive: false,
        }))
      }
    }
  } catch (e) {}

  // Auto focus on search input
  nextTick(() => {
    searchInputRef.value?.focus()
  })

  // Global hotkeys
  const handleKeydown = (e) => {
    const isEditing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target?.tagName)

    // Ctrl+K or / (when not typing) -> Focus search
    if ((e.ctrlKey && e.key.toLowerCase() === 'k') || (e.key === '/' && !isEditing)) {
      e.preventDefault()
      if (activeMode.value !== 'catalog') {
        switchMode('catalog')
      }
      nextTick(() => searchInputRef.value?.focus())
    }

    // F2 or Ctrl+Enter -> Checkout / submit active sale
    if (e.key === 'F2' || (e.ctrlKey && e.key === 'Enter')) {
      e.preventDefault()
      if (activeMode.value === 'catalog' && cart.value.length) {
        createSale()
      } else if (activeMode.value === 'quick' && quickForm.value.name && quickForm.value.sale_price) {
        submitQuickSale()
      }
    }

    // Alt+1 -> Catalog mode, Alt+2 -> Quick mode
    if (e.altKey && e.key === '1') {
      e.preventDefault()
      switchMode('catalog')
      nextTick(() => searchInputRef.value?.focus())
    } else if (e.altKey && e.key === '2') {
      e.preventDefault()
      switchMode('quick')
      nextTick(() => quickNameRef.value?.focus())
    }

    // Esc -> Clear search or close modals
    if (e.key === 'Escape') {
      if (cancelModalOpen.value) {
        cancelModalOpen.value = false
      } else if (editingSale.value) {
        closeEditModal()
      } else if (productSearch.value) {
        productSearch.value = ''
        fetchProducts()
      }
    }
  }

  window.addEventListener('keydown', handleKeydown)
  refreshAll()
  timer = window.setInterval(refreshAll, 15000)

  onUnmounted(() => {
    window.removeEventListener('keydown', handleKeydown)
    window.clearInterval(timer)
  })
})

// Auto-save cart to localStorage
watch(
  cart,
  (newCart) => {
    try {
      localStorage.setItem('clothing_store_cart_v1', JSON.stringify(newCart))
    } catch (e) {}
  },
  { deep: true },
)
</script>
