<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\ActivityLogger;
use App\Support\ApiPagination;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = Category::query()->withCount('products')->latest();

        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%"));
        }

        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        return response()->json(ApiPagination::format($query->paginate($request->integer('per_page', 15))));
    }

    public function store(Request $request, ActivityLogger $logger)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('categories', 'name')],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $category = Category::create([
            'name' => strip_tags($data['name']),
            'slug' => Str::slug($data['name']),
            'description' => isset($data['description']) ? strip_tags($data['description']) : null,
            'is_active' => $data['is_active'] ?? true,
            'created_by' => $request->user()->id,
        ]);

        $logger->log('categories.created', $category, [], $request);

        return response()->json($category, 201);
    }

    public function show(Category $category)
    {
        return response()->json($category->loadCount('products'));
    }

    public function update(Request $request, Category $category, ActivityLogger $logger)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120', Rule::unique('categories', 'name')->ignore($category)],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['name'])) {
            $data['name'] = strip_tags($data['name']);
            $data['slug'] = Str::slug($data['name']);
        }
        if (array_key_exists('description', $data)) {
            $data['description'] = $data['description'] ? strip_tags($data['description']) : null;
        }

        $category->update($data);
        $logger->log('categories.updated', $category, [], $request);

        return response()->json($category);
    }

    public function destroy(Request $request, Category $category, ActivityLogger $logger)
    {
        $logger->log('categories.deleted', $category, ['name' => $category->name], $request);
        $category->delete();

        return response()->json(['message' => 'Category deleted.']);
    }
}
