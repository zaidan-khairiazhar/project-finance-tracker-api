<?php

namespace App\Http\Controllers;

use App\Enums\CategoryType;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CategoryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate(['type' => ['sometimes', Rule::enum(CategoryType::class)]]);

        return CategoryResource::collection(
            Category::query()
                ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
                ->orderBy('type')->orderBy('name')->get()
        );
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = Category::create($request->validated());

        return (new CategoryResource($category))->response()->setStatusCode(201);
    }

    public function update(UpdateCategoryRequest $request, Category $category): CategoryResource
    {
        Gate::authorize('update', $category);

        $category->update($request->validated());

        return new CategoryResource($category);
    }

    public function destroy(Category $category): Response
    {
        Gate::authorize('delete', $category);

        if ($category->children()->exists() || $category->transactions()->exists()) {
            throw ValidationException::withMessages([
                'category' => 'Kategori yang masih dipakai transaksi atau punya subkategori tidak bisa dihapus.',
            ]);
        }

        $category->delete();

        return response()->noContent();
    }
}
