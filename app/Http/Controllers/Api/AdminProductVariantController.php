<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductVariantRequest;
use App\Http\Requests\UpdateProductVariantRequest;
use App\Http\Resources\AdminProductVariantResource;
use App\Models\Product;
use App\Services\ProductVariantService;
use Illuminate\Http\JsonResponse;

class AdminProductVariantController extends Controller
{
    public function __construct(
        private readonly ProductVariantService $variantService
    ) {}

    public function index(string $product): JsonResponse
    {
        $product = Product::query()
            ->where('uuid', $product)
            ->firstOrFail();

        $variants = $this->variantService
            ->getProductVariants($product);

        return response()->json([
            'success' => true,
            'message' => 'Daftar varian produk berhasil diambil.',
            'data' => AdminProductVariantResource::collection($variants)
                ->resolve(),
        ]);
    }

    public function store(
        StoreProductVariantRequest $request,
        string $product
    ): JsonResponse {
        $product = Product::query()
            ->where('uuid', $product)
            ->firstOrFail();

        $variant = $this->variantService->createVariant(
            $product,
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'message' => 'Varian produk berhasil dibuat.',
            'data' => (new AdminProductVariantResource($variant))
                ->resolve(),
        ], 201);
    }

    public function show(
        string $product,
        string $variant
    ): JsonResponse {
        $product = Product::query()
            ->where('uuid', $product)
            ->firstOrFail();

        $variant = $this->variantService
            ->getVariantByUuid($product, $variant);

        return response()->json([
            'success' => true,
            'message' => 'Detail varian produk berhasil diambil.',
            'data' => (new AdminProductVariantResource($variant))
                ->resolve(),
        ]);
    }

    public function update(
        UpdateProductVariantRequest $request,
        string $product,
        string $variant
    ): JsonResponse {
        $product = Product::query()
            ->where('uuid', $product)
            ->firstOrFail();

        $variant = $this->variantService
            ->getVariantByUuid($product, $variant);

        $variant = $this->variantService->updateVariant(
            $variant,
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'message' => 'Varian produk berhasil diperbarui.',
            'data' => (new AdminProductVariantResource($variant))
                ->resolve(),
        ]);
    }

    public function destroy(
        string $product,
        string $variant
    ): JsonResponse {
        $product = Product::query()
            ->where('uuid', $product)
            ->firstOrFail();

        $variant = $this->variantService
            ->getVariantByUuid($product, $variant);

        $this->variantService->deleteVariant($variant);

        return response()->json([
            'success' => true,
            'message' => 'Varian produk berhasil dihapus.',
            'data' => null,
        ]);
    }
}
