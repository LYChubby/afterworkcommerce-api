<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAddressRequest;
use App\Http\Requests\UpdateAddressRequest;
use App\Http\Resources\AddressResource;
use App\Models\User;
use App\Services\AddressService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AddressController extends Controller
{
    public function __construct(
        protected AddressService $addressService
    ) {}

    /**
     * Display all addresses belonging to the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return ApiResponse::success(
            data: AddressResource::collection(
                $this->addressService->getAddresses($user)
            ),
            message: 'Addresses retrieved successfully.',
        );
    }

    /**
     * Store a new address.
     */
    public function store(StoreAddressRequest $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $address = $this->addressService->createAddress(
            $user,
            $request->validated(),
        );

        return ApiResponse::success(
            data: new AddressResource($address),
            message: 'Address created successfully.',
            status: 201,
        );
    }

    /**
     * Display an address belonging to the authenticated user.
     */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $address = $this->addressService->getAddressByUuid(
            $user,
            $uuid,
        );

        return ApiResponse::success(
            data: new AddressResource($address),
            message: 'Address retrieved successfully.',
        );
    }

    /**
     * Update an address belonging to the authenticated user.
     */
    public function update(
        UpdateAddressRequest $request,
        string $uuid
    ): JsonResponse {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $address = $this->addressService->updateAddress(
            $user,
            $uuid,
            $request->validated(),
        );

        return ApiResponse::success(
            data: new AddressResource($address),
            message: 'Address updated successfully.',
        );
    }

    /**
     * Delete an address belonging to the authenticated user.
     */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $this->addressService->deleteAddress($user, $uuid);

        return ApiResponse::success(
            message: 'Address deleted successfully.',
        );
    }
}
