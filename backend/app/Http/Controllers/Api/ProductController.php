<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\ActivityLogger;
use App\Support\ApiPagination;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::query()->with('category');

        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%")
                ->orWhere('size', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%"));
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->boolean('low_stock')) {
            $query->whereRaw('(stock_quantity + display_quantity) <= low_stock_threshold');
        }

        $sort = $request->string('sort', 'updated_at')->toString();
        $direction = $request->string('direction', 'desc')->lower()->toString() === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['name', 'sku', 'size', 'sale_price', 'stock_quantity', 'display_quantity', 'updated_at', 'created_at'];
        $query->orderBy(in_array($sort, $allowedSorts, true) ? $sort : 'updated_at', $direction);

        return response()->json(ApiPagination::format($query->paginate($request->integer('per_page', 12))));
    }

    public function store(Request $request, ActivityLogger $logger)
    {
        $data = $this->validated($request);

        if ($request->hasFile('photo')) {
            $data['photo_path'] = $request->file('photo')->store('products', 'public');
        }

        $product = new Product($data + ['created_by' => $request->user()->id]);
        $product->refreshStatus();
        $product->save();

        $logger->log('products.created', $product, ['sku' => $product->sku], $request);

        return response()->json($product->load('category'), 201);
    }

    public function show(Product $product)
    {
        return response()->json($product->load(['category', 'saleItems.sale']));
    }

    public function update(Request $request, Product $product, ActivityLogger $logger)
    {
        $data = $this->validated($request, $product);

        if ($request->hasFile('photo')) {
            if ($product->photo_path) {
                Storage::disk('public')->delete($product->photo_path);
            }
            $data['photo_path'] = $request->file('photo')->store('products', 'public');
        }

        $product->fill($data);
        $product->refreshStatus();
        $product->save();

        $logger->log('products.updated', $product, ['sku' => $product->sku], $request);

        return response()->json($product->load('category'));
    }

    public function destroy(Request $request, Product $product, ActivityLogger $logger)
    {
        $product->load('category');
        $hadSales = $product->saleItems()->exists();

        $meta = [
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'category' => $product->category?->name,
            'size' => $product->size,
            'sale_price' => (float) $product->sale_price,
            'stock_quantity' => (int) $product->stock_quantity,
            'display_quantity' => (int) $product->display_quantity,
            'had_sales' => $hadSales,
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
            if (!$row || !array_filter($row, fn ($value) => trim((string) $value) !== '')) {
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
            $createdProducts = [];
            foreach ($rows as $index => $row) {
                $name = strip_tags(trim((string) ($row[0] ?? '')));
                $sku = strip_tags(trim((string) ($row[1] ?? '')));
                $size = strip_tags(trim((string) ($row[2] ?? '')));
                $salePrice = (float) ($row[3] ?? 0);
                $stockQty = max(0, (int) ($row[4] ?? 0));
                $displayQty = max(0, (int) ($row[5] ?? 0));
                $threshold = isset($row[6]) ? max(0, (int) $row[6]) : 0;
                $description = strip_tags(trim((string) ($row[7] ?? '')));

                if ($name === '') {
                    throw ValidationException::withMessages([
                        'file' => 'Ошибка в строке '.($index + 1).': поле name обязательно.',
                    ]);
                }

                if ($sku !== '' && Product::query()->where('sku', $sku)->exists()) {
                    throw ValidationException::withMessages([
                        'file' => 'Ошибка в строке '.($index + 1).': артикул уже существует ('.$sku.').',
                    ]);
                }

                $product = new Product([
                    'category_id' => $data['category_id'] ?? null,
                    'name' => $name,
                    'sku' => $sku !== '' ? $sku : null,
                    'size' => $size !== '' ? $size : null,
                    'description' => $description !== '' ? $description : null,
                    'sale_price' => $salePrice,
                    'stock_quantity' => $stockQty,
                    'display_quantity' => $displayQty,
                    'low_stock_threshold' => $threshold,
                    'created_by' => $request->user()->id,
                ]);
                $product->refreshStatus();
                $product->save();
                $createdProducts[] = $product;

                $logger->log('products.imported', $product, ['sku' => $product->sku], $request);
            }
            return $createdProducts;
        });

        return response()->json([
            'message' => 'Импорт завершён.',
            'count' => count($created),
        ], 201);
    }

    private function validated(Request $request, ?Product $product = null): array
    {
        $rules = [
            'category_id' => ['nullable', 'exists:categories,id'],
            'name' => [$product ? 'sometimes' : 'required', 'string', 'max:180'],
            'sku' => [$product ? 'sometimes' : 'nullable', 'string', 'max:80', Rule::unique('products', 'sku')->ignore($product)],
            'size' => ['nullable', 'string', 'max:32'],
            'description' => ['nullable', 'string', 'max:3000'],
            'sale_price' => [$product ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'stock_quantity' => [$product ? 'sometimes' : 'required', 'integer', 'min:0'],
            'display_quantity' => [$product ? 'sometimes' : 'required', 'integer', 'min:0'],
            'low_stock_threshold' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', Rule::in(['active', 'low_stock', 'out_of_stock'])],
            'photo' => ['nullable', 'image', 'max:4096'],
        ];

        $data = $request->validate($rules);

        foreach (['name', 'sku', 'size', 'description'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = strip_tags((string) $data[$field]);
            }
        }

        if (array_key_exists('sku', $data) && trim((string) $data['sku']) === '') {
            $data['sku'] = null;
        }

        unset($data['photo']);

        return $data;
    }
}
