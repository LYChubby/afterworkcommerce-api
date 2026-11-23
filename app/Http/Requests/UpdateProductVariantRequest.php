<?php

namespace App\Http\Requests;

use App\Models\Color;
use App\Models\ProductVariant;
use App\Models\Size;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProductVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $variant = $this->getVariant();

        return [
            'size_uuid' => [
                'sometimes',
                'required',
                'string',
                Rule::exists('sizes', 'uuid')
                    ->where('is_active', true),
            ],

            'color_uuid' => [
                'sometimes',
                'required',
                'string',
                Rule::exists('colors', 'uuid')
                    ->where('is_active', true),
            ],

            'sku' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('product_variants', 'sku')
                    ->ignore($variant?->id),
            ],

            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $variant = $this->getVariant();

                if (! $variant) {
                    return;
                }

                $sizeUuid = $this->input(
                    'size_uuid',
                    $variant->size?->uuid
                );

                $colorUuid = $this->input(
                    'color_uuid',
                    $variant->color?->uuid
                );

                if (! $sizeUuid || ! $colorUuid) {
                    return;
                }

                $size = Size::query()
                    ->where('uuid', $sizeUuid)
                    ->first();

                $color = Color::query()
                    ->where('uuid', $colorUuid)
                    ->first();

                if (! $size || ! $color) {
                    return;
                }

                $combinationExists = ProductVariant::query()
                    ->where('product_id', $variant->product_id)
                    ->where('size_id', $size->id)
                    ->where('color_id', $color->id)
                    ->whereKeyNot($variant->id)
                    ->exists();

                if ($combinationExists) {
                    $validator->errors()->add(
                        'size_uuid',
                        'Kombinasi ukuran dan warna sudah digunakan oleh varian lain.'
                    );
                }
            },
        ];
    }

    private function getVariant(): ?ProductVariant
    {
        $uuid = $this->route('variant');

        if (! is_string($uuid)) {
            return null;
        }

        return ProductVariant::query()
            ->with(['size', 'color'])
            ->where('uuid', $uuid)
            ->first();
    }

    public function messages(): array
    {
        return [
            'size_uuid.required' => 'Ukuran produk wajib dipilih.',
            'size_uuid.exists' => 'Ukuran tidak ditemukan atau tidak aktif.',

            'color_uuid.required' => 'Warna produk wajib dipilih.',
            'color_uuid.exists' => 'Warna tidak ditemukan atau tidak aktif.',

            'sku.required' => 'SKU wajib diisi.',
            'sku.max' => 'SKU maksimal 100 karakter.',
            'sku.unique' => 'SKU sudah digunakan.',

            'is_active.boolean' => 'Status aktif harus berupa boolean.',
        ];
    }
}
