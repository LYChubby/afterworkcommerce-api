<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductIndexRequest;
use App\Http\Resources\ProductResource;
use App\Services\ProductService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    /**
     * Menampilkan daftar produk publik.
     */
    public function index(
        ProductIndexRequest $request,
        ProductService $productService
    ): JsonResponse {
        $products = $productService->getProducts($request);

        return ApiResponse::success(
            data: ProductResource::collection($products)->response()->getData(true)['data'],
            message: 'Daftar produk berhasil diambil.',
            meta: [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'from' => $products->firstItem(),
                'to' => $products->lastItem(),
            ],
        );
    }

    /**
     * Menampilkan detail produk berdasarkan slug.
     */
    public function show(
        string $slug,
        ProductService $productService
    ): JsonResponse {
        $product = $productService->getProductBySlug($slug);

        return ApiResponse::success(
            data: (new ProductResource($product))
                ->response()
                ->getData(true)['data'],
            message: 'Detail produk berhasil diambil.',
        );
    }
}
