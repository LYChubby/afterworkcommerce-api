<?php

namespace App\Services;

use App\Models\Color;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class ColorService
{
    public function getColors(): Collection
    {
        return Color::query()
            ->orderBy('name')
            ->get();
    }

    public function getColorByUuid(string $uuid): Color
    {
        return Color::query()
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    public function createColor(array $data): Color
    {
        $color = Color::create($data);

        return $color->refresh();
    }

    public function updateColor(Color $color, array $data): Color
    {
        $color->update($data);

        return $color->refresh();
    }

    public function deleteColor(Color $color): void
    {
        if ($color->productVariants()->exists()) {
            throw ValidationException::withMessages([
                'color' => [
                    'Warna tidak dapat dihapus karena masih digunakan oleh varian produk.',
                ],
            ]);
        }

        $color->delete();
    }
}
