<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\ActivityLogger;
use App\Support\ApiPagination;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::query()->with('category');

        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%")
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
        $allowedSorts = ['name', 'sku', 'sale_price', 'purchase_price', 'stock_quantity', 'display_quantity', 'updated_at', 'created_at'];
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
            'purchase_price' => (float) $product->purchase_price,
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

    private function validated(Request $request, ?Product $product = null): array
    {
        $rules = [
            'category_id' => ['nullable', 'exists:categories,id'],
            'name' => [$product ? 'sometimes' : 'required', 'string', 'max:180'],
            'sku' => [$product ? 'sometimes' : 'required', 'string', 'max:80', Rule::unique('products', 'sku')->ignore($product)],
            'description' => ['nullable', 'string', 'max:3000'],
            'purchase_price' => [$product ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'sale_price' => [$product ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'stock_quantity' => [$product ? 'sometimes' : 'required', 'integer', 'min:0'],
            'display_quantity' => [$product ? 'sometimes' : 'required', 'integer', 'min:0'],
            'low_stock_threshold' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', Rule::in(['active', 'low_stock', 'out_of_stock'])],
            'photo' => ['nullable', 'image', 'max:4096'],
        ];

        $data = $request->validate($rules);

        foreach (['name', 'sku', 'description'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = strip_tags((string) $data[$field]);
            }
        }

        unset($data['photo']);

        return $data;
    }
}
