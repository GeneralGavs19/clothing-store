<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductItem;
use App\Models\ProductVariant;
use App\Services\ActivityLogger;
use App\Services\ProductItemService;
use App\Support\ApiPagination;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::query()
            ->with(['category', 'variants'])
            ->withCount([
                'variants as variants_count',
            ])
            ->withSum([
                'saleItems as total_sold' => fn ($q) => $q->whereHas('sale', fn ($sq) => $sq->where('status', 'approved')),
            ], 'quantity')
            ->withSum([
                'saleItems as new_batch_sold' => fn ($q) => $q->whereHas('sale', fn ($sq) => $sq->where('status', 'approved')->where(function ($ssq) {
                    $ssq->where('cashier_note', 'like', '%завоз%')
                        ->orWhere('cashier_note', 'like', '%Завоз%');
                })),
            ], 'quantity');

        $batchType = $request->input('batch_type', 'standard'); // 'standard' | 'new_batch' | 'all'
        if ($batchType === 'all') {
            $query->where('status', '!=', 'archived');
        } elseif ($batchType === 'new_batch') {
            $query->where('batch_type', 'new_batch')->where('status', '!=', 'archived');
        } else {
            // Default to 'standard' (regular catalog)
            $query->where(function ($q) {
                $q->where('batch_type', 'standard')
                  ->orWhereNull('batch_type');
            })->where('status', '!=', 'archived');
        }

        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('type', 'like', "%{$search}%")
                ->orWhere('brand', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")
                ->orWhere('color', 'like', "%{$search}%")
                ->orWhere('batch_note', 'like', "%{$search}%")
                ->orWhereHas('variants', function ($vq) use ($search) {
                    $vq->where('sku', 'like', "%{$search}%")
                        ->orWhere('size', 'like', "%{$search}%")
                        ->orWhereHas('items', fn ($iq) => $iq->where('code', 'like', "%{$search}%"));
                }));
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type')->toString());
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->boolean('low_stock')) {
            $query->where(function ($sub) {
                $sub->whereRaw('(stock_quantity + display_quantity) <= COALESCE(low_stock_threshold, 0)')
                    ->orWhereHas('variants', function ($vq) {
                        $vq->whereRaw(
                            '(stock_warehouse + stock_store) <= low_stock_threshold AND (stock_warehouse + stock_store) > 0'
                        );
                    });
            });
        }

        $sort = $request->string('sort', 'updated_at')->toString();
        $direction = $request->string('direction', 'desc')->lower()->toString() === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['name', 'sale_price', 'stock_quantity', 'display_quantity', 'updated_at', 'created_at', 'total_sold', 'new_batch_sold', 'batch_period'];
        $query->orderBy(in_array($sort, $allowedSorts, true) ? $sort : 'updated_at', $direction);

        $paginated = $query->paginate($request->integer('per_page', 12));
        $result = ApiPagination::format($paginated);
        $result['meta']['batch_counters'] = [
            'standard' => Product::where(function ($q) {
                $q->where('batch_type', 'standard')->orWhereNull('batch_type');
            })->where('status', '!=', 'archived')->count(),
            'new_batch' => Product::where('batch_type', 'new_batch')->where('status', '!=', 'archived')->count(),
        ];

        return response()->json($result);
    }

    public function store(Request $request, ActivityLogger $logger)
    {
        $payload = $this->validatedProductAndVariants($request);

        $name = trim($payload['name'] ?? '');
        $color = trim($payload['color'] ?? '');
        $catId = $payload['category_id'] ?? null;
        $targetBatchType = $payload['batch_type'] ?? 'standard';

        $existingQuery = Product::query()
            ->where('status', '!=', 'archived')
            ->where('batch_type', $targetBatchType)
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)]);
        if ($catId) {
            $existingQuery->where('category_id', $catId);
        }
        if ($color !== '') {
            $existingQuery->whereRaw('LOWER(TRIM(COALESCE(color, ""))) = ?', [mb_strtolower($color)]);
        }
        $existing = $existingQuery->first();

        if ($existing) {
            $batchLabel = $targetBatchType === 'new_batch' ? 'новом завозе' : 'основном каталоге';
            throw ValidationException::withMessages([
                'name' => "Товар с названием «{$existing->name}» уже существует в {$batchLabel} (ID: {$existing->id}). Добавьте нужные размеры в существующую модель, чтобы не создавать дубликаты.",
            ]);
        }

        if ($targetBatchType === 'new_batch' && empty($payload['batch_period'])) {
            $payload['batch_period'] = now()->format('d.m.Y');
        }

        $product = DB::transaction(function () use ($request, $payload) {
            $variantRows = $payload['variants'];
            unset($payload['variants']);

            if ($request->hasFile('photo')) {
                $payload['photo_path'] = $request->file('photo')->store('products', 'public');
            }

            $product = new Product($payload + ['created_by' => $request->user()->id, 'sale_price' => $payload['sale_price'] ?? 0]);
            $product->save();

            $this->syncVariants($product, $variantRows, $request, app(ProductItemService::class));
            $product->applyAggregatesFromVariants();
            $product->save();

            return $product;
        });

        $logger->log('products.created', $product, ['name' => $product->name], $request);

        return response()->json($product->load(['category', 'variants']), 201);
    }

    public function show(Product $product)
    {
        $product->load([
            'category',
            'variants' => fn ($q) => $q->withCount([
                'items as items_total',
                'items as items_available' => fn ($iq) => $iq->where('status', ProductItem::STATUS_AVAILABLE),
            ])->with(['items' => fn ($q) => $q->orderBy('status')->orderBy('location')->orderBy('code')]),
        ]);

        return response()->json($product);
    }

    public function lookupItem(string $code, ProductItemService $items)
    {
        $item = $items->findAvailableByCode($code);
        if (! $item) {
            return response()->json(['message' => 'Единица товара не найдена или уже продана.'], 404);
        }

        $item->load(['variant.product.category']);

        return response()->json([
            'item' => $item,
            'variant' => $item->variant,
            'product' => $item->variant?->product,
        ]);
    }

    public function update(Request $request, Product $product, ActivityLogger $logger)
    {
        $product = DB::transaction(function () use ($request, $product) {
            $payload = $this->validatedProductAndVariants($request, $product);
            $variantRows = $payload['variants'];
            unset($payload['variants']);

            if ($request->hasFile('photo')) {
                if ($product->photo_path) {
                    Storage::disk('public')->delete($product->photo_path);
                }
                $payload['photo_path'] = $request->file('photo')->store('products', 'public');
            }

            $product->fill($payload);

            $this->syncVariants($product, $variantRows, $request, app(ProductItemService::class));
            $product->applyAggregatesFromVariants();
            $product->save();

            return $product;
        });

        $logger->log('products.updated', $product, ['name' => $product->name], $request);

        return response()->json($product->load(['category', 'variants']));
    }

    public function destroy(Request $request, Product $product, ActivityLogger $logger)
    {
        $product->load(['category', 'variants']);
        $hadSales = $product->saleItems()->exists();

        $meta = [
            'product_id' => $product->id,
            'name' => $product->name,
            'category' => $product->category?->name,
            'had_sales' => $hadSales,
            'variants' => $product->variants->map(fn ($v) => ['sku' => $v->sku, 'barcode' => $v->barcode])->values()->all(),
            'deleted_by' => $request->user()->only(['id', 'name', 'email', 'role']),
        ];

        if ($product->photo_path) {
            Storage::disk('public')->delete($product->photo_path);
        }

        $logger->log('products.deleted', $product, $meta, $request);
        $product->delete();

        return response()->json([
            'message' => $hadSales
                ? 'Товар удалён. История продаж сохранена.'
                : 'Товар удалён.',
        ]);
    }

    /**
     * Счётчики товаров по типу завоза (основной ассортимент vs новый завоз).
     */
    public function batchCounters()
    {
        return response()->json([
            'standard' => Product::where(function ($q) {
                $q->where('batch_type', 'standard')->orWhereNull('batch_type');
            })->where('status', '!=', 'archived')->count(),
            'new_batch' => Product::where('batch_type', 'new_batch')->where('status', '!=', 'archived')->count(),
        ]);
    }

    /**
     * Поиск кандидатов на объединение в основном ассортименте для товара нового завоза.
     */
    public function mergeCandidates(Product $product)
    {
        $type = trim((string) $product->type);

        $candidates = Product::query()
            ->where(function ($q) {
                $q->where('batch_type', 'standard')->orWhereNull('batch_type');
            })
            ->where('status', '!=', 'archived')
            ->where('id', '!=', $product->id)
            ->with(['category', 'variants'])
            ->withSum([
                'saleItems as total_sold' => fn ($q) => $q->whereHas('sale', fn ($sq) => $sq->where('status', 'approved')),
            ], 'quantity')
            ->when($type !== '', function ($q) use ($type) {
                $q->orderByRaw("CASE WHEN LOWER(TRIM(type)) = LOWER(?) THEN 0 ELSE 1 END", [$type]);
            })
            ->orderBy('name')
            ->limit(30)
            ->get();

        return response()->json($candidates);
    }

    /**
     * Перенос товара из нового завоза в основной завоз (как новый товар либо объединение с существующим).
     */
    public function transferToStandard(Request $request, Product $product, ActivityLogger $logger)
    {
        $mode = $request->input('mode', 'as_new'); // 'as_new' | 'merge'

        if ($mode === 'merge') {
            $targetId = $request->integer('target_product_id');
            $targetProduct = Product::with('variants')->findOrFail($targetId);

            if ($targetProduct->id === $product->id) {
                throw ValidationException::withMessages([
                    'target_product_id' => 'Нельзя объединить товар с самим собой.',
                ]);
            }

            return DB::transaction(function () use ($product, $targetProduct, $request, $logger) {
                $product->load('variants');

                foreach ($product->variants as $srcVar) {
                    $existingVar = $targetProduct->variants->first(function ($tv) use ($srcVar) {
                        return mb_strtolower(trim($tv->size ?? '')) === mb_strtolower(trim($srcVar->size ?? ''));
                    });

                    if (! $existingVar) {
                        $newVar = ProductVariant::create([
                            'product_id' => $targetProduct->id,
                            'size' => $srcVar->size,
                            'sku' => $this->ensureUniqueSku($srcVar->sku ? $srcVar->sku . '-M' : "SKU-{$targetProduct->id}-{$srcVar->size}"),
                            'barcode' => null,
                            'stock_warehouse' => (int) $srcVar->stock_warehouse,
                            'stock_store' => (int) $srcVar->stock_store,
                            'sale_price' => $srcVar->sale_price ?: $targetProduct->sale_price,
                            'purchase_price' => $srcVar->purchase_price ?: $targetProduct->purchase_price,
                            'position' => $targetProduct->variants->count() + 1,
                        ]);
                        $destVarId = $newVar->id;
                    } else {
                        $destVarId = $existingVar->id;
                    }

                    // Move product items if any
                    DB::table('product_items')
                        ->where('product_variant_id', $srcVar->id)
                        ->update(['product_variant_id' => $destVarId]);

                    // Move sale items safely
                    DB::table('sale_items')
                        ->where('product_id', $product->id)
                        ->where('product_variant_id', $srcVar->id)
                        ->update([
                            'product_id' => $targetProduct->id,
                            'product_variant_id' => $destVarId,
                        ]);
                }

                // Move any remaining sale items for this product
                DB::table('sale_items')
                    ->where('product_id', $product->id)
                    ->update([
                        'product_id' => $targetProduct->id,
                    ]);

                // Move stock movements
                DB::table('stock_movements')
                    ->where('product_id', $product->id)
                    ->update([
                        'product_id' => $targetProduct->id,
                    ]);

                // Archive the source product and record audit metadata
                $product->update([
                    'batch_type' => 'merged',
                    'status' => 'archived',
                    'merged_into_id' => $targetProduct->id,
                    'transferred_at' => now(),
                    'transferred_by' => $request->user()->id,
                ]);

                // Re-apply aggregates on target
                $targetProduct->refresh();
                $targetProduct->load('variants');
                $targetProduct->applyAggregatesFromVariants();
                $targetProduct->save();

                \App\Services\AnalyticsCacheService::invalidate();

                $logger->log('products.merged', $targetProduct, [
                    'source_product_id' => $product->id,
                    'source_product_name' => $product->name,
                    'target_product_id' => $targetProduct->id,
                    'target_product_name' => $targetProduct->name,
                ], $request);

                return response()->json([
                    'message' => "Товар «{$product->name}» успешно объединён с «{$targetProduct->name}».",
                    'product' => $targetProduct,
                ]);
            });
        }

        // Mode: 'as_new'
        return DB::transaction(function () use ($product, $request, $logger) {
            $updateData = [
                'batch_type' => 'standard',
                'transferred_at' => now(),
                'transferred_by' => $request->user()->id,
            ];

            if ($request->filled('name')) {
                $updateData['name'] = trim($request->string('name')->toString());
            }
            if ($request->filled('type')) {
                $updateData['type'] = trim($request->string('type')->toString());
            }
            if ($request->filled('color')) {
                $updateData['color'] = trim($request->string('color')->toString());
            }
            if ($request->filled('sale_price')) {
                $updateData['sale_price'] = (float) $request->input('sale_price');
            }
            if ($request->filled('category_id')) {
                $updateData['category_id'] = $request->integer('category_id');
            }

            $product->update($updateData);

            if ($request->has('variants') && is_array($request->input('variants'))) {
                $this->syncVariants($product, $request->input('variants'), $request, app(ProductItemService::class));
                $product->applyAggregatesFromVariants();
                $product->save();
            }

            \App\Services\AnalyticsCacheService::invalidate();

            $logger->log('products.transferred_to_standard', $product, [
                'product_id' => $product->id,
                'name' => $product->name,
            ], $request);

            return response()->json([
                'message' => "Товар «{$product->name}» успешно перенесён в основной завоз.",
                'product' => $product->load(['category', 'variants']),
            ]);
        });
    }

    /**
     * Массовый перенос нескольких выбранных товаров из нового завоза в основной.
     */
    public function bulkTransfer(Request $request, ActivityLogger $logger)
    {
        $request->validate([
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer', 'exists:products,id'],
        ]);

        $ids = $request->input('product_ids');

        return DB::transaction(function () use ($ids, $request, $logger) {
            $count = Product::whereIn('id', $ids)
                ->where('batch_type', 'new_batch')
                ->update([
                    'batch_type' => 'standard',
                    'transferred_at' => now(),
                    'transferred_by' => $request->user()->id,
                ]);

            \App\Services\AnalyticsCacheService::invalidate();

            $logger->log('products.bulk_transferred', null, [
                'count' => $count,
                'product_ids' => $ids,
            ], $request);

            return response()->json([
                'message' => "Перенесено товаров в основной завоз: {$count}.",
                'count' => $count,
            ]);
        });
    }

    /**
     * Ручное переключение статуса между основным и новым завозом.
     */
    public function toggleBatchType(Request $request, Product $product, ActivityLogger $logger)
    {
        $newType = $request->input('batch_type');
        if (! in_array($newType, ['standard', 'new_batch'], true)) {
            $newType = $product->batch_type === 'new_batch' ? 'standard' : 'new_batch';
        }

        $product->update([
            'batch_type' => $newType,
            'transferred_at' => $newType === 'standard' ? now() : null,
            'transferred_by' => $newType === 'standard' ? $request->user()->id : null,
        ]);

        \App\Services\AnalyticsCacheService::invalidate();

        $logger->log('products.batch_type_toggled', $product, [
            'batch_type' => $newType,
        ], $request);

        return response()->json([
            'message' => $newType === 'new_batch' ? 'Товар помечен как новый завоз.' : 'Товар перенесён в основной завоз.',
            'product' => $product->load(['category', 'variants']),
        ]);
    }

    public function import(Request $request, ActivityLogger $logger)
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'category_id' => ['nullable', 'exists:categories,id'],
        ]);

        $file = $data['file'];
        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'Не удалось прочитать файл.']);
        }

        $rows = [];
        $lineNumber = 0;
        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            $lineNumber++;
            if (count($row) <= 1) {
                $row = str_getcsv(implode('', $row), ',');
            }
            if (! $row || ! array_filter($row, fn ($value) => trim((string) $value) !== '')) {
                continue;
            }
            if ($lineNumber === 1 && str_contains(strtolower(implode(' ', $row)), 'name')) {
                continue;
            }
            $rows[] = $row;
        }
        fclose($handle);

        if (empty($rows)) {
            throw ValidationException::withMessages(['file' => 'Файл пустой или в неверном формате.']);
        }

        $created = DB::transaction(function () use ($rows, $request, $data, $logger) {
            $itemService = app(ProductItemService::class);
            $createdProducts = [];

            foreach ($rows as $index => $row) {
                $name = strip_tags(trim((string) ($row[0] ?? '')));
                if ($name === '') {
                    throw ValidationException::withMessages([
                        'file' => 'Ошибка в строке '.($index + 1).': поле name обязательно.',
                    ]);
                }

                // Опционально: name, sku, barcode, color, size, price, warehouse, store, threshold, purchase, description (+ brand можно первой колонкой расширить позже)
                $wide = count($row) >= 11;
                $sku = strip_tags(trim((string) ($row[1] ?? '')));
                $barcode = strip_tags(trim((string) ($row[2] ?? '')));
                $color = strip_tags(trim((string) ($row[3] ?? '')));
                $size = strip_tags(trim((string) ($row[4] ?? '')));
                $price = (float) ($row[5] ?? 0);
                $wh = max(0, (int) ($row[6] ?? 0));
                $st = max(0, (int) ($row[7] ?? 0));
                $threshold = isset($row[8]) ? max(0, (int) $row[8]) : 0;
                $purchase = isset($row[9]) ? (float) $row[9] : null;
                $description = strip_tags(trim((string) ($row[10] ?? '')));

                if (! $wide) {
                    // legacy short: name, sku, barcode?, size, sale_price, stock, display, threshold, description
                    $hasBarcode = count($row) >= 9;
                    $sku = strip_tags(trim((string) ($row[1] ?? '')));
                    $barcode = strip_tags(trim((string) ($hasBarcode ? ($row[2] ?? '') : '')));
                    $color = '';
                    $size = strip_tags(trim((string) ($hasBarcode ? ($row[3] ?? '') : ($row[2] ?? ''))));
                    $price = (float) ($hasBarcode ? ($row[4] ?? 0) : ($row[3] ?? 0));
                    $wh = max(0, (int) ($hasBarcode ? ($row[5] ?? 0) : ($row[4] ?? 0)));
                    $st = max(0, (int) ($hasBarcode ? ($row[6] ?? 0) : ($row[5] ?? 0)));
                    $threshold = isset($row[$hasBarcode ? 7 : 6]) ? max(0, (int) $row[$hasBarcode ? 7 : 6]) : 0;
                    $purchase = null;
                    $description = strip_tags(trim((string) ($row[$hasBarcode ? 8 : 7] ?? '')));
                }

                $sizeFinal = $size !== '' ? $size : '—';

                $category = isset($data['category_id'])
                    ? Category::query()->find($data['category_id'])
                    : null;

                $product = new Product([
                    'category_id' => $data['category_id'] ?? null,
                    'name' => $name,
                    'color' => $color !== '' ? $color : null,
                    'description' => $description !== '' ? $description : null,
                    'purchase_price' => $purchase ?? 0,
                    'base_sale_price' => $price,
                    'created_by' => $request->user()->id,
                ]);
                $product->save();

                $variantSku = $sku !== '' ? $sku : $this->proposeSku($category, $product->name, $sizeFinal);

                $variant = ProductVariant::query()->create([
                    'product_id' => $product->id,
                    'size' => $sizeFinal,
                    'sku' => $this->ensureUniqueSku($variantSku),
                    'stock_warehouse' => 0,
                    'stock_store' => 0,
                    'low_stock_threshold' => $threshold,
                    'sale_price' => $price,
                    'purchase_price' => $purchase,
                    'position' => 0,
                ]);

                $itemService->syncVariantInventory($variant, $wh, $st);

                $product->applyAggregatesFromVariants();
                $product->save();

                $createdProducts[] = $product;
                $logger->log('products.imported', $product, ['name' => $product->name], $request);
            }

            return $createdProducts;
        });

        return response()->json([
            'message' => 'Импорт завершён.',
            'count' => count($created),
        ], 201);
    }

    private function validatedProductAndVariants(Request $request, ?Product $product = null): array
    {
        $isHat = (
            mb_stripos((string) ($request->input('type') ?? $product?->type ?? ''), 'шапк') !== false ||
            mb_stripos((string) ($request->input('name') ?? $product?->name ?? ''), 'шапк') !== false
        );

        $productRules = [
            'category_id' => ['nullable', 'exists:categories,id'],
            'type' => ['nullable', 'string', 'max:100'],
            'name' => [$product ? 'sometimes' : 'required', 'string', 'max:180'],
            'color' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:3000'],
            'sale_price' => [$product ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'base_sale_price' => ['nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', Rule::in(['active', 'low_stock', 'out_of_stock'])],
            'photo' => ['nullable', 'image', 'max:4096'],
            'batch_type' => ['nullable', 'string', Rule::in(['standard', 'new_batch'])],
            'batch_note' => ['nullable', 'string', 'max:255'],
            'batch_period' => ['nullable', 'string', 'max:100'],
            'variants' => ['required', 'array', 'min:1'],
            'variants.*.id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'variants.*.size' => [$isHat ? 'nullable' : 'required', 'string', 'max:64'],
            'variants.*.stock_store' => ['nullable', 'integer', 'min:0'],
            'variants.*.stock_warehouse' => ['nullable', 'integer', 'min:0'],
            'variants.*.quantity' => ['nullable', 'integer', 'min:0'],
            'variants.*.low_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'variants.*.items' => ['nullable', 'array'],
            'variants.*.items.*' => ['nullable', 'string', 'max:64'],
            'variants.*.position' => ['sometimes', 'integer', 'min:0'],
        ];

        $data = $request->validate($productRules);

        if (! isset($data['sale_price']) && isset($data['base_sale_price'])) {
            $data['sale_price'] = $data['base_sale_price'];
        }
        unset($data['base_sale_price']);

        foreach (['name', 'type', 'color', 'description', 'batch_note', 'batch_period'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = strip_tags((string) $data[$field]);
            }
        }

        if (isset($data['variants'])) {
            $normalized = [];
            $seenSizes = [];
            foreach ($data['variants'] as $row) {
                $row = $this->camelToSnakeVariantRow(is_array($row) ? $row : []);
                $sizeKey = mb_strtolower(trim((string) ($row['size'] ?? '')));
                if ($sizeKey !== '') {
                    if (isset($seenSizes[$sizeKey])) {
                        $idx = $seenSizes[$sizeKey];
                        $normalized[$idx]['stock_store'] = ((int) ($normalized[$idx]['stock_store'] ?? 0)) + ((int) ($row['stock_store'] ?? 0));
                        $normalized[$idx]['stock_warehouse'] = ((int) ($normalized[$idx]['stock_warehouse'] ?? 0)) + ((int) ($row['stock_warehouse'] ?? 0));
                        continue;
                    }
                    $seenSizes[$sizeKey] = count($normalized);
                }
                $normalized[] = $row;
            }
            $data['variants'] = $normalized;
        }

        unset($data['photo']);

        return $data;
    }

    private function camelToSnakeVariantRow(array $row): array
    {
        $map = [
            'stockWarehouse' => 'stock_warehouse',
            'stockStore' => 'stock_store',
            'lowStockThreshold' => 'low_stock_threshold',
            'salePrice' => 'sale_price',
            'purchasePrice' => 'purchase_price',
            'costPrice' => 'purchase_price',
        ];
        foreach ($map as $from => $to) {
            if (array_key_exists($from, $row) && ! array_key_exists($to, $row)) {
                $row[$to] = $row[$from];
            }
        }

        if (! array_key_exists('stock_warehouse', $row) && array_key_exists('stock_quantity', $row)) {
            $row['stock_warehouse'] = $row['stock_quantity'];
        }
        if (! array_key_exists('stock_store', $row) && array_key_exists('display_quantity', $row)) {
            $row['stock_store'] = $row['display_quantity'];
        }
        if ((! array_key_exists('sale_price', $row) || $row['sale_price'] === null || $row['sale_price'] === '') && array_key_exists('price', $row)) {
            $row['sale_price'] = $row['price'];
        }
        if (array_key_exists('qty', $row) && ! array_key_exists('quantity', $row)) {
            $row['quantity'] = $row['qty'];
        }

        return $row;
    }

    private function syncVariants(Product $product, array $variantRows, Request $request, ProductItemService $itemService): void
    {
        $category = $product->category_id ? Category::query()->find($product->category_id) : null;
        if (! $category && $request->filled('category_id')) {
            $category = Category::query()->find($request->integer('category_id'));
        }

        $keptIds = [];

        foreach ($variantRows as $index => $row) {
            $row = is_array($row) ? $row : [];
            $id = isset($row['id']) ? (int) $row['id'] : null;
            $size = strip_tags(trim((string) ($row['size'] ?? '')));
            if ($size === '') {
                throw ValidationException::withMessages(['variants' => 'Укажите размер для каждого варианта.']);
            }

            $items = [];
            if (isset($row['items']) && is_array($row['items'])) {
                $items = $row['items'];
            }

            $sku = $this->ensureUniqueSku($this->proposeSku($category, $product->name, $size), $id);
            $productPrice = (float) $product->sale_price;
            $position = isset($row['position']) ? (int) $row['position'] : $index;

            $stockStore = isset($row['stock_store']) ? (int) $row['stock_store'] : (int) ($row['quantity'] ?? 0);
            $stockWarehouse = isset($row['stock_warehouse']) ? (int) $row['stock_warehouse'] : 0;
            $threshold = isset($row['low_stock_threshold']) ? (int) $row['low_stock_threshold'] : 0;

            $payload = [
                'size' => $size,
                'sku' => $sku,
                'stock_warehouse' => $stockWarehouse,
                'stock_store' => $stockStore,
                'low_stock_threshold' => $threshold,
                'sale_price' => $productPrice,
                'purchase_price' => 0,
                'position' => $position,
            ];

            if ($id) {
                $variant = ProductVariant::query()
                    ->where('product_id', $product->id)
                    ->whereKey($id)
                    ->first();
                if (! $variant) {
                    throw ValidationException::withMessages(['variants' => 'Вариант #'.$id.' не найден.']);
                }

                $variant->fill($payload);
                $variant->save();
            } else {
                $variant = $product->variants()->create($payload);
            }

            if (! empty($items)) {
                $itemService->syncVariantItems($variant, $items);
            }

            $keptIds[] = $variant->id;
        }

        ProductVariant::query()->where('product_id', $product->id)->whereNotIn('id', $keptIds)->delete();
    }

    private function proposeSku(?Category $category, string $productName, string $size): string
    {
        $prefix = $this->categoryCode($category?->name);
        $abbr = $this->nameAbbrev($productName);

        $sizeSlug = preg_replace('/\s+/u', '', mb_strtoupper($size));

        return strtoupper($prefix.'-'.$abbr.'-'.($sizeSlug !== '' ? $sizeSlug : 'SZ'));
    }

    private function categoryCode(?string $categoryName): string
    {
        $n = mb_strtolower((string) ($categoryName ?? ''), 'UTF-8');

        return match (true) {
            str_contains($n, 'hoodie') || str_contains($n, 'худи') || str_contains($n, 'hudi') => 'HD',
            str_contains($n, 'футбол') || str_contains($n, 't-shirt') || str_contains($n, 'tshirt') || str_contains($n, 't shirt') => 'TS',
            str_contains($n, 'брюк') || str_contains($n, 'штаны') || str_contains($n, 'pant') => 'PT',
            str_contains($n, 'обув') || str_contains($n, 'shoe') || str_contains($n, 'sneaker') => 'SH',
            str_contains($n, 'куртк') || str_contains($n, 'jacket') => 'JK',
            str_contains($n, 'электроник') || str_contains($n, 'electronic') => 'EL',
            str_contains($n, 'одежда') || str_contains($n, 'cloth') => 'CL',
            str_contains($n, 'продукт') || str_contains($n, 'еда') || str_contains($n, 'food') => 'FD',
            default => 'GN',
        };
    }

    private function nameAbbrev(string $productName): string
    {
        $words = array_values(array_filter(preg_split('/\s+/u', trim(strip_tags($productName))), fn ($x) => $x !== ''));

        $map = ['black' => 'BLK', 'white' => 'WHT', 'red' => 'RED', 'blue' => 'BLU'];

        $parts = [];

        foreach (array_slice($words, 0, 2) as $wordRaw) {
            $w = preg_replace('/\d+/','', strtolower(Str::ascii($wordRaw)));

            foreach ($map as $k => $code) {
                if (str_contains($w, $k)) {
                    $parts[] = $code;
                    continue 2;
                }
            }
            $compact = preg_replace('/[^a-z]/', '', $w);
            if ($compact !== '') {
                $parts[] = strtoupper(mb_substr($compact, 0, 4));
            }
        }

        if (empty($parts)) {
            $parts[] = substr(strtoupper(Str::slug($productName, '')), 0, 6) ?: 'ITM';
        }

        return implode('-', array_slice($parts, 0, 2));
    }

    private function ensureUniqueSku(string $base, ?int $ignoreVariantId = null): string
    {
        $candidate = substr($base, 0, 118);
        $n = 0;

        do {
            $try = $n === 0 ? $candidate : substr($candidate, 0, 110).'-'.$n;

            $q = ProductVariant::query()->where('sku', $try);
            if ($ignoreVariantId) {
                $q->where('id', '<>', $ignoreVariantId);
            }
            if (! $q->exists()) {
                return $try;
            }
            $n++;
        } while ($n < 1000);

        return strtoupper(substr(Str::slug($try, '-'), 0, 118));
    }
}
