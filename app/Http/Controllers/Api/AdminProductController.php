<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;

class AdminProductController extends Controller
{
    public function __construct(
        private readonly ProductService $productService
    ) {}

    public function index(): JsonResponse
    {
        $products = $this->productService->getAdminProducts();

        return response()->json([
            'success' => true,
            'message' => 'Daftar produk berhasil diambil.',
            'data' => ProductResource::collection($products->items()),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'from' => $products->firstItem(),
                'to' => $products->lastItem(),
            ],
        ]);
    }

    public function show(string $uuid): JsonResponse
    {
        $product = $this->productService->getProductByUuid($uuid);

        return response()->json([
            'success' => true,
            'message' => 'Detail produk berhasil diambil.',
            'data' => (new ProductResource($product))->resolve(),
        ]);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->productService->createProduct(
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'message' => 'Produk berhasil dibuat.',
            'data' => (new ProductResource($product))->resolve(),
        ], 201);
    }

    public function update(
        UpdateProductRequest $request,
        string $uuid
    ): JsonResponse {
        $product = $this->productService->getProductByUuid($uuid);

        $product = $this->productService->updateProduct(
            $product,
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'message' => 'Produk berhasil diperbarui.',
            'data' => (new ProductResource($product))->resolve(),
        ]);
    }

    public function destroy(string $uuid): JsonResponse
    {
        $product = $this->productService->getProductByUuid($uuid);

        $this->productService->deleteProduct($product);

        return response()->json([
            'success' => true,
            'message' => 'Produk berhasil dihapus.',
            'data' => null,
        ]);
    }
}
