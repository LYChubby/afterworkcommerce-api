<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
}
