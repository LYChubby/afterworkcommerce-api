<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreColorRequest;
use App\Http\Requests\UpdateColorRequest;
use App\Http\Resources\ColorResource;
use App\Services\ColorService;
use Illuminate\Http\JsonResponse;

class AdminColorController extends Controller
{
    public function __construct(
        private readonly ColorService $colorService
    ) {}

    public function index(): JsonResponse
    {
        $colors = $this->colorService->getColors();

        $colors->loadCount('productVariants');

        return response()->json([
            'success' => true,
            'message' => 'Daftar warna berhasil diambil.',
            'data' => ColorResource::collection($colors)->resolve(),
        ]);
    }

    public function show(string $uuid): JsonResponse
    {
        $color = $this->colorService->getColorByUuid($uuid);

        $color->loadCount('productVariants');

        return response()->json([
            'success' => true,
            'message' => 'Detail warna berhasil diambil.',
            'data' => (new ColorResource($color))->resolve(),
        ]);
    }

    public function store(StoreColorRequest $request): JsonResponse
    {
        $color = $this->colorService->createColor($request->validated());

        $color->loadCount('productVariants');

        return response()->json([
            'success' => true,
            'message' => 'Warna berhasil dibuat.',
            'data' => (new ColorResource($color))->resolve(),
        ], 201);
    }

    public function update(
        UpdateColorRequest $request,
        string $uuid
    ): JsonResponse {
        $color = $this->colorService->getColorByUuid($uuid);

        $color = $this->colorService->updateColor(
            $color,
            $request->validated()
        );

        $color->loadCount('productVariants');

        return response()->json([
            'success' => true,
            'message' => 'Warna berhasil diperbarui.',
            'data' => (new ColorResource($color))->resolve(),
        ]);
    }

    public function destroy(string $uuid): JsonResponse
    {
        $color = $this->colorService->getColorByUuid($uuid);

        $this->colorService->deleteColor($color);

        return response()->json([
            'success' => true,
            'message' => 'Warna berhasil dihapus.',
            'data' => null,
        ]);
    }
}
