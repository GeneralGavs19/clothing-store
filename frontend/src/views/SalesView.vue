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

    <!-- Нижняя секция: Улучшенная история оформленных продаж -->
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
            Удобный просмотр чеков, товаров, размеров и типов заказов (в наличии / на заказ)
          </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
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
            @click="fetchHistory(1)"
          >
            <RefreshCw class="h-3.5 w-3.5" :class="{ 'animate-spin': sales.loading }" />
          </button>
        </div>
      </div>

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
          <!-- Верхняя строка карточки продажи: Номер, дата, бейдж типа, статус и сумма -->
          <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex flex-wrap items-center gap-2">
              <span class="font-bold text-slate-900 dark:text-white text-base">
                {{ formatSaleNumber(sale) }}
              </span>

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

              <span class="text-xs text-slate-400">·</span>
              <span class="text-xs text-slate-500">{{ date(sale.sold_at || sale.approved_at) }}</span>
              <span class="text-xs text-slate-400">·</span>
              <span class="text-xs text-slate-600 dark:text-slate-400 font-medium">Кассир: {{ sale.cashier?.name || '—' }}</span>
            </div>

            <!-- Итоговая сумма чека -->
            <div class="flex items-center justify-between sm:justify-end gap-3">
              <div class="text-right">
                <div class="text-lg font-black tabular-nums text-emerald-600 dark:text-emerald-400">
                  {{ money(sale.subtotal) }}
                </div>
                <div class="text-[10px] text-slate-400">
                  {{ (sale.items || []).reduce((acc, i) => acc + (Number(i.quantity) || 1), 0) }} шт.
                </div>
              </div>

              <button
                v-if="auth.isAdmin"
                type="button"
                class="btn-danger h-7 px-2 text-xs"
                title="Удалить продажу"
                @click="confirmDeleteSale(sale)"
              >
                <Trash2 class="h-3.5 w-3.5" />
              </button>
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
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import {
  Check,
  Download,
  LoaderCircle,
  Package,
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

const auth = useAuthStore()
const catalog = useCatalogStore()
const sales = useSalesStore()
const toast = useToastStore()

const activeMode = ref('catalog') // 'catalog' | 'quick'
const productSearch = ref('')
const historySearch = ref('')
const historyStatus = ref('approved')
const historyOrderType = ref('') // '' (all) | 'in_stock' | 'on_order'
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

function money(value) {
  return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'KZT', maximumFractionDigits: 0 }).format(Number(value || 0))
}

function date(value) {
  return value ? new Date(value).toLocaleString('ru-RU') : '—'
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

onMounted(() => {
  refreshAll()
  timer = window.setInterval(refreshAll, 15000)
})

onUnmounted(() => window.clearInterval(timer))
</script>
