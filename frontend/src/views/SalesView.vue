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
                v-model="quickForm.name"
                class="input mt-1"
                placeholder="Например: Футболка Oversize Vintage"
                required
                autofocus
              />
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
                    v-for="t in popularTypes.slice(0, 6)"
                    :key="t"
                    type="button"
                    class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-600 hover:bg-emerald-100 hover:text-emerald-800 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-emerald-900/50"
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
                  placeholder="S, M, L, XL, 42..."
                  required
                />
                <!-- Быстрые чипы размеров -->
                <div class="mt-1.5 flex flex-wrap gap-1">
                  <button
                    v-for="sz in commonSizes"
                    :key="sz"
                    type="button"
                    class="rounded border border-slate-200 bg-white px-2 py-0.5 text-[11px] font-medium text-slate-700 hover:border-emerald-400 hover:text-emerald-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300"
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
                  placeholder="Черный, Белый, Серый..."
                />
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

            <!-- Остаток после продажи (себестоимость полностью убрана) -->
            <div>
              <label class="block text-xs font-medium text-slate-700 dark:text-slate-300">
                Остаток после продажи (шт.)
              </label>
              <input
                v-model.number="quickForm.remaining_stock"
                type="number"
                min="0"
                class="input mt-1 text-slate-600 dark:text-slate-400"
                placeholder="0"
              />
              <p class="mt-1 text-[11px] text-slate-500">
                Сколько штук осталось на руках/складе. Если склад пока не знаете — оставьте 0.
              </p>
            </div>

            <!-- Интерактивный расчет "Было" -->
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3.5 dark:border-slate-800 dark:bg-slate-900/60">
              <div class="flex items-center justify-between text-xs">
                <span class="text-slate-500">Авторасчет по формуле:</span>
                <span class="font-medium text-slate-700 dark:text-slate-300">Было = Продано + Остаток</span>
              </div>
              <div class="mt-2 grid grid-cols-3 gap-2 text-center text-xs">
                <div class="rounded bg-white p-2 dark:bg-slate-800">
                  <div class="text-[11px] text-slate-500">Продано</div>
                  <div class="text-base font-bold text-emerald-600 dark:text-emerald-400">{{ quickForm.quantity || 1 }} шт.</div>
                </div>
                <div class="rounded bg-white p-2 dark:bg-slate-800">
                  <div class="text-[11px] text-slate-500">Остаток</div>
                  <div class="text-base font-bold text-slate-700 dark:text-slate-300">{{ quickForm.remaining_stock || 0 }} шт.</div>
                </div>
                <div class="rounded border border-emerald-300 bg-emerald-50 p-2 dark:border-emerald-800 dark:bg-emerald-950/60">
                  <div class="text-[11px] font-medium text-emerald-800 dark:text-emerald-300">Было учтено</div>
                  <div class="text-base font-extrabold text-emerald-700 dark:text-emerald-300">
                    {{ (Number(quickForm.quantity) || 1) + (Number(quickForm.remaining_stock) || 0) }} шт.
                  </div>
                </div>
              </div>
              <div class="mt-2.5 flex items-center justify-between border-t border-slate-200 pt-2 text-xs dark:border-slate-800">
                <div class="flex items-center gap-1.5">
                  <span class="text-slate-500">Тип:</span>
                  <span
                    class="rounded px-1.5 py-0.5 text-[11px] font-semibold"
                    :class="quickForm.is_on_order ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300' : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300'"
                  >
                    {{ quickForm.is_on_order ? '📦 На заказ' : 'В наличии' }}
                  </span>
                </div>
                <div>
                  <span class="text-slate-500 mr-1.5">Сумма продажи:</span>
                  <span class="text-sm font-bold text-slate-900 dark:text-white">
                    {{ money((Number(quickForm.sale_price) || 0) * (Number(quickForm.quantity) || 1)) }}
                  </span>
                </div>
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
              v-model="productSearch"
              class="input pr-10"
              placeholder="Поиск товара по названию, виду, цвету..."
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
                <div class="mt-1 flex flex-wrap items-center gap-2 text-[11px] text-slate-500">
                  <span class="rounded bg-emerald-50 px-1.5 py-0.5 font-medium text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
                    Было: {{ product.initial_quantity || (product.display_quantity + product.stock_quantity) }} шт.
                  </span>
                  <span>Продано: {{ product.total_sold || 0 }} шт.</span>
                  <span>Остаток: {{ product.display_quantity + product.stock_quantity }} шт.</span>
                </div>
              </div>
              <div class="text-right">
                <div class="text-sm font-semibold">{{ money(product.sale_price) }}</div>
                <span class="text-[11px] text-emerald-600 dark:text-emerald-400 font-medium">Выбрать →</span>
              </div>
            </button>
          </div>

          <!-- Шаг 2: выбор размера -->
          <div v-else-if="saleStep === 'variant'" class="mt-4 space-y-3">
            <div class="flex items-center justify-between border-b border-slate-200 pb-2 dark:border-slate-800">
              <div>
                <p class="text-sm font-semibold">{{ selectedProduct?.name }}</p>
                <p class="text-xs text-slate-500">Выберите размер для добавления в чек</p>
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
                <div class="mt-1 flex items-center justify-between text-[11px] text-slate-500">
                  <span>Остаток: <strong class="text-slate-700 dark:text-slate-300">{{ variantStock(v) }}</strong> шт.</span>
                  <span>Было: <strong class="text-emerald-700 dark:text-emerald-300">{{ v.initial_quantity || variantStock(v) }}</strong></span>
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
            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">
              Тип заказа
            </label>
            <div class="grid grid-cols-2 gap-2 text-xs">
              <button
                type="button"
                class="flex items-center justify-center gap-1.5 rounded-lg border p-2 font-medium transition"
                :class="!cartIsOnOrder
                  ? 'border-emerald-500 bg-emerald-50 text-emerald-900 font-semibold shadow-sm dark:border-emerald-500 dark:bg-emerald-950/60 dark:text-emerald-200'
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
                  ? 'border-amber-500 bg-amber-50 text-amber-900 font-semibold shadow-sm dark:border-amber-500 dark:bg-amber-950/60 dark:text-amber-200'
                  : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400'"
                @click="cartIsOnOrder = true"
              >
                <Package class="h-3.5 w-3.5 text-amber-600 dark:text-amber-400" />
                📦 На заказ
              </button>
            </div>
          </div>

          <div class="max-h-[24rem] space-y-2 overflow-y-auto pr-1">
            <div
              v-for="item in cart"
              :key="item.product_variant_id"
              class="rounded-lg border border-slate-200 p-3 transition hover:border-slate-300 dark:border-slate-800 dark:hover:border-slate-700"
            >
              <div class="flex items-start justify-between gap-2">
                <div class="min-w-0 flex-1">
                  <div class="truncate text-sm font-medium">{{ item.product.name }}</div>
                  <div class="mt-0.5 flex items-center gap-2 text-xs text-slate-500">
                    <span>Размер: <strong class="text-slate-800 dark:text-slate-200">{{ item.variant.size }}</strong></span>
                    <span v-if="item.product.type" class="rounded bg-slate-100 px-1.5 py-0.2 text-[10px] dark:bg-slate-800">{{ item.product.type }}</span>
                  </div>
                  <div class="mt-1 text-sm font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">
                    {{ money(item.price * item.quantity) }}
                    <span v-if="item.quantity > 1" class="text-xs font-normal text-slate-500">
                      ({{ money(item.price) }} / шт.)
                    </span>
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
            </div>
          </div>

          <label class="block">
            <span class="mb-1 block text-xs font-medium text-slate-500">Комментарий к продаже</span>
            <textarea
              v-model="cashierNote"
              class="textarea"
              rows="2"
              placeholder="Необязательно (номер транзакции, способ оплаты, данные покупателя...)"
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
            <div class="mt-2 flex items-baseline justify-between border-t border-slate-200 pt-2 dark:border-slate-800">
              <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Итого к оплате:</span>
              <span class="text-2xl font-black text-emerald-600 dark:text-emerald-400 tabular-nums">
                {{ money(cartTotal) }}
              </span>
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

        <!-- Список чеков -->
        <div v-if="sales.loading" class="p-6">
          <SkeletonBlock custom-class="h-44" />
        </div>

        <EmptyState
          v-else-if="!sales.sales.length"
          class="m-6"
          title="Продажи не найдены"
          :text="historySearch || historyOrderType ? 'Попробуйте сбросить фильтры поиска.' : 'Оформите первую продажу в форме сверху.'"
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

                  <!-- Статус -->
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

              <!-- Итоговая сумма чека и кнопки действий (Изменить, Удалить) -->
              <div class="flex items-center justify-between sm:justify-end gap-2.5 shrink-0">
                <div class="text-right">
                  <div class="text-lg sm:text-xl font-black tabular-nums text-emerald-600 dark:text-emerald-400">
                    {{ money(sale.subtotal) }}
                  </div>
                </div>

                <div class="flex items-center gap-1.5">
                  <!-- Кнопка: Изменить продажу -->
                  <button
                    type="button"
                    class="btn-muted h-8 px-2.5 text-xs flex items-center gap-1 font-semibold text-slate-700 hover:border-emerald-400 hover:text-emerald-600 dark:text-slate-300 dark:hover:text-emerald-400"
                    title="Изменить продажу"
                    @click="openEditModal(sale)"
                  >
                    <Pencil class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" />
                    <span>Изменить</span>
                  </button>

                  <!-- Кнопка: Удалить продажу -->
                  <button
                    v-if="auth.isAdmin"
                    type="button"
                    class="btn-danger h-8 w-8 px-0"
                    title="Удалить продажу"
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
                    <span class="text-slate-500">
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
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import {
  Check,
  ChevronDown,
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
import { date, money } from '../utils/formatters'

const auth = useAuthStore()
const catalog = useCatalogStore()
const sales = useSalesStore()
const toast = useToastStore()

const activeMode = ref('catalog') // 'catalog' | 'quick'
const productSearch = ref('')
const historySearch = ref('')
const historyStatus = ref('approved')
const historyOrderType = ref('') // '' (all) | 'in_stock' | 'on_order'
const historyViewMode = ref('orders') // 'orders' | 'summary'
const summaryLoading = ref(false)
const summaryTypes = ref([])
const expandedSummaryTypes = ref(new Set())
const allSummaryExpanded = ref(true)

const editingSale = ref(null)
const editForm = ref({
  is_on_order: false,
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
const cashierNote = ref('')
const saving = ref(false)
const quickSaving = ref(false)
const quickCustomCategory = ref(false)

const saleStep = ref('products')
const selectedProduct = ref(null)
const selectedVariant = ref(null)
const productDetail = ref(null)
let debounce
let historyDebounce
let timer

const popularTypes = [
  'Футболка',
  'Худи',
  'Свитшот',
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

const commonSizes = ['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL', 'One Size']

const quickForm = ref({
  name: '',
  type: 'Футболка',
  category_id: '',
  category_name: '',
  size: 'M',
  color: '',
  sale_price: '',
  purchase_price: 0,
  is_on_order: false,
  quantity: 1,
  remaining_stock: 0,
  cashier_note: '',
})

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

  if (existing) {
    existing.quantity++
    toast.push(`Количество увеличено: ${product.name} (${full.size})`)
  } else {
    cart.value.push({
      product_id: product.id,
      product_variant_id: full.id,
      product,
      variant: full,
      price: Number(full.sale_price || product.sale_price || 0),
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
  if (!cart.value.length) return
  saving.value = true
  try {
    await sales.create({
      items: cart.value.map((row) => ({
        product_id: row.product.id,
        product_variant_id: row.product_variant_id,
        quantity: row.quantity,
        source: 'display',
      })),
      is_on_order: Boolean(cartIsOnOrder.value),
      cashier_note: cashierNote.value ? cashierNote.value.trim() : null,
    })
    toast.push('Продажа успешно оформлена!')
    cart.value = []
    cartIsOnOrder.value = false
    cashierNote.value = ''
    await refreshAll()
  } catch (error) {
    toast.push(apiError(error), 'error')
  } finally {
    saving.value = false
  }
}

async function submitQuickSale() {
  if (!quickForm.value.name || !quickForm.value.sale_price) {
    toast.push('Укажите название и цену продажи', 'warning')
    return
  }

  quickSaving.value = true
  try {
    const payload = {
      name: quickForm.value.name.trim(),
      type: quickForm.value.type ? quickForm.value.type.trim() : null,
      category_id: quickForm.value.category_id || undefined,
      category_name: quickForm.value.category_name ? quickForm.value.category_name.trim() : undefined,
      size: quickForm.value.size ? quickForm.value.size.trim() : 'M',
      color: quickForm.value.color ? quickForm.value.color.trim() : null,
      sale_price: Number(quickForm.value.sale_price),
      purchase_price: 0, // Себестоимость убрана из формы
      is_on_order: Boolean(quickForm.value.is_on_order),
      quantity: Math.max(1, Number(quickForm.value.quantity || 1)),
      remaining_stock: Math.max(0, Number(quickForm.value.remaining_stock || 0)),
      cashier_note: quickForm.value.cashier_note ? quickForm.value.cashier_note.trim() : null,
    }

    await sales.createQuick(payload)
    toast.push(`Товар «${payload.name}» создан и продажа успешно оформлена!`)

    // Сброс формы для следующей продажи
    const lastType = quickForm.value.type
    const lastCatId = quickForm.value.category_id
    quickForm.value = {
      name: '',
      type: lastType,
      category_id: lastCatId,
      category_name: '',
      size: 'M',
      color: '',
      sale_price: '',
      purchase_price: 0,
      is_on_order: false,
      quantity: 1,
      remaining_stock: 0,
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
  await sales.fetch(params)
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
  editForm.value = {
    is_on_order: Boolean(sale.is_on_order),
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
  refreshAll()
  timer = window.setInterval(refreshAll, 15000)
})

onUnmounted(() => window.clearInterval(timer))
</script>
