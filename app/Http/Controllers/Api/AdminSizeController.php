<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSizeRequest;
use App\Http\Requests\UpdateSizeRequest;
use App\Http\Resources\SizeResource;
use App\Services\SizeService;
use Illuminate\Http\JsonResponse;

class AdminSizeController extends Controller
{
    public function __construct(
        private readonly SizeService $sizeService
    ) {}

    public function index(): JsonResponse
    {
        $sizes = $this->sizeService->getSizes();

        $sizes->loadCount('productVariants');

        return response()->json([
            'success' => true,
            'message' => 'Daftar ukuran berhasil diambil.',
            'data' => SizeResource::collection($sizes)->resolve(),
        ]);
    }

    public function show(string $uuid): JsonResponse
    {
        $size = $this->sizeService->getSizeByUuid($uuid);

        $size->loadCount('productVariants');

        return response()->json([
            'success' => true,
            'message' => 'Detail ukuran berhasil diambil.',
            'data' => (new SizeResource($size))->resolve(),
        ]);
    }

    public function store(StoreSizeRequest $request): JsonResponse
    {
        $size = $this->sizeService->createSize($request->validated());

        $size->loadCount('productVariants');

        return response()->json([
            'success' => true,
            'message' => 'Ukuran berhasil dibuat.',
            'data' => (new SizeResource($size))->resolve(),
        ], 201);
    }

    public function update(
        UpdateSizeRequest $request,
        string $uuid
    ): JsonResponse {
        $size = $this->sizeService->getSizeByUuid($uuid);

        $size = $this->sizeService->updateSize(
            $size,
            $request->validated()
        );

        $size->loadCount('productVariants');

        return response()->json([
            'success' => true,
            'message' => 'Ukuran berhasil diperbarui.',
            'data' => (new SizeResource($size))->resolve(),
        ]);
    }

    public function destroy(string $uuid): JsonResponse
    {
        $size = $this->sizeService->getSizeByUuid($uuid);

        $this->sizeService->deleteSize($size);

        return response()->json([
            'success' => true,
            'message' => 'Ukuran berhasil dihapus.',
            'data' => null,
        ]);
    }
}
