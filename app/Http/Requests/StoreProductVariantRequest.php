<?php

namespace App\Http\Requests;

use App\Models\Color;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreProductVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'size_uuid' => [
                'required',
                'string',
                Rule::exists('sizes', 'uuid')
                    ->where('is_active', true),
            ],

            'color_uuid' => [
                'required',
                'string',
                Rule::exists('colors', 'uuid')
                    ->where('is_active', true),
            ],

            'sku' => [
                'required',
                'string',
                'max:100',
                'unique:product_variants,sku',
            ],

            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ];
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

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $productUuid = $this->route('product');

                $product = Product::query()
                    ->where('uuid', $productUuid)
                    ->first();

                $size = Size::query()
                    ->where('uuid', $this->input('size_uuid'))
                    ->where('is_active', true)
                    ->first();

                $color = Color::query()
                    ->where('uuid', $this->input('color_uuid'))
                    ->where('is_active', true)
                    ->first();

                if (! $product || ! $size || ! $color) {
                    return;
                }

                $combinationExists = ProductVariant::query()
                    ->where('product_id', $product->id)
                    ->where('size_id', $size->id)
                    ->where('color_id', $color->id)
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
}
