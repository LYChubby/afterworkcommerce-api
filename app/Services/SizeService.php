<?php

namespace App\Services;

use App\Models\Size;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class SizeService
{
    public function getSizes(): Collection
    {
        return Size::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function getSizeByUuid(string $uuid): Size
    {
        return Size::query()
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    public function createSize(array $data): Size
    {
        $size = Size::create($data);

        return $size->refresh();
    }

    public function updateSize(Size $size, array $data): Size
    {
        $size->update($data);

        return $size->refresh();
    }

    public function deleteSize(Size $size): void
    {
        if ($size->productVariants()->exists()) {
            throw ValidationException::withMessages([
                'size' => [
                    'Ukuran tidak dapat dihapus karena masih digunakan oleh varian produk.',
                ],
            ]);
        }

        $size->delete();
    }
}
