<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\CategoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class AdminCategoryController extends Controller
{
    public function __construct(
        private readonly CategoryService $categoryService
    ) {}

    /**
     * Menampilkan seluruh kategori untuk admin.
     */
    public function index(): JsonResponse
    {
        $categories = $this->categoryService->getCategories();

        return ApiResponse::success(
            data: CategoryResource::collection($categories)
                ->response()
                ->getData(true)['data'],
            message: 'Daftar kategori berhasil diambil.',
        );
    }

    /**
     * Menampilkan detail kategori.
     */
    public function show(string $uuid): JsonResponse
    {
        $category = $this->categoryService->getCategoryByUuid($uuid);

        return ApiResponse::success(
            data: (new CategoryResource($category))
                ->response()
                ->getData(true)['data'],
            message: 'Detail kategori berhasil diambil.',
        );
    }

    /**
     * Membuat kategori baru.
     */
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = $this->categoryService->createCategory(
            $request->validated()
        );

        return ApiResponse::success(
            data: (new CategoryResource($category))
                ->response()
                ->getData(true)['data'],
            message: 'Kategori berhasil dibuat.',
            status: 201,
        );
    }

    /**
     * Memperbarui kategori.
     */
    public function update(
        UpdateCategoryRequest $request,
        string $uuid
    ): JsonResponse {
        $category = $this->categoryService->getCategoryByUuid($uuid);

        $category = $this->categoryService->updateCategory(
            $category,
            $request->validated()
        );

        return ApiResponse::success(
            data: (new CategoryResource($category))
                ->response()
                ->getData(true)['data'],
            message: 'Kategori berhasil diperbarui.',
        );
    }

    /**
     * Menghapus kategori.
     */
    public function destroy(string $uuid): JsonResponse
    {
        $category = $this->categoryService->getCategoryByUuid($uuid);

        $this->categoryService->deleteCategory($category);

        return ApiResponse::success(
            message: 'Kategori berhasil dihapus.',
        );
    }
}
