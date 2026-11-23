<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_uuid' => [
                'required',
                'string',
                Rule::exists('categories', 'uuid')
                    ->where('is_active', true),
            ],

            'name' => ['required', 'string', 'max:150'],
            'slug' => [
                'nullable',
                'string',
                'max:180',
                'alpha_dash',
            ],

            'description' => ['nullable', 'string'],

            'price' => ['required', 'numeric', 'gt:0'],
            'discount_price' => [
                'nullable',
                'numeric',
                'gt:0',
                'lt:price',
            ],

            'is_active' => ['sometimes', 'boolean'],

            'weight_grams' => ['nullable', 'integer', 'min:1'],
            'length_cm' => ['nullable', 'integer', 'min:1'],
            'width_cm' => ['nullable', 'integer', 'min:1'],
            'height_cm' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'category_uuid.required' => 'Kategori produk wajib dipilih.',
            'category_uuid.exists' => 'Kategori tidak ditemukan atau tidak aktif.',
            'name.required' => 'Nama produk wajib diisi.',
            'name.max' => 'Nama produk maksimal 150 karakter.',
            'slug.unique' => 'Slug produk sudah digunakan.',
            'slug.alpha_dash' => 'Slug hanya boleh berisi huruf, angka, tanda hubung, dan garis bawah.',
            'price.required' => 'Harga produk wajib diisi.',
            'price.gt' => 'Harga produk harus lebih besar dari 0.',
            'discount_price.lt' => 'Harga diskon harus lebih rendah dari harga normal.',
            'weight_grams.min' => 'Berat produk minimal 1 gram.',
        ];
    }
}
