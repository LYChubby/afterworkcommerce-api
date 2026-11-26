<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCartItemRequest;
use App\Http\Requests\UpdateCartItemRequest;
use App\Http\Resources\CartResource;
use App\Models\User;
use App\Services\CartService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(
        protected CartService $cartService
    ) {}

    /**
     * Mengambil Cart milik pengguna yang sedang login.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $cart = $this->cartService->getCart($user);

        return ApiResponse::success(
            data: new CartResource($cart),
            message: 'Cart retrieved successfully.',
        );
    }

    /**
     * Menambahkan varian produk ke Cart.
     */
    public function store(StoreCartItemRequest $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $data = $request->validated();

        $cart = $this->cartService->addItem(
            $user,
            $data['variant_uuid'],
            (int) $data['quantity'],
        );

        return ApiResponse::success(
            data: new CartResource($cart),
            message: 'Product added to cart successfully.',
            status: 201,
        );
    }

    /**
     * Mengubah kuantitas item Cart.
     */
    public function update(
        UpdateCartItemRequest $request,
        string $variantUuid
    ): JsonResponse {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $data = $request->validated();

        $cart = $this->cartService->updateItem(
            $user,
            $variantUuid,
            (int) $data['quantity'],
        );

        return ApiResponse::success(
            data: new CartResource($cart),
            message: 'Cart item updated successfully.',
        );
    }

    /**
     * Menghapus item dari Cart.
     */
    public function destroy(
        Request $request,
        string $variantUuid
    ): JsonResponse {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $cart = $this->cartService->removeItem(
            $user,
            $variantUuid,
        );

        return ApiResponse::success(
            data: new CartResource($cart),
            message: 'Cart item removed successfully.',
        );
    }
}
