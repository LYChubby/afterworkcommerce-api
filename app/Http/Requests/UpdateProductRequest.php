<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_uuid' => [
                'sometimes',
                'required',
                'string',
                Rule::exists('categories', 'uuid')
                    ->where('is_active', true),
            ],

            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'slug' => [
                'sometimes',
                'nullable',
                'string',
                'max:180',
                'alpha_dash',
                Rule::unique('products', 'slug')
                    ->ignore($this->route('uuid'), 'uuid'),
            ],

            'description' => ['sometimes', 'nullable', 'string'],

            'price' => ['sometimes', 'required', 'numeric', 'gt:0'],
            'discount_price' => ['sometimes', 'nullable', 'numeric', 'gt:0'],

            'is_active' => ['sometimes', 'boolean'],

            'weight_grams' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'length_cm' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'width_cm' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'height_cm' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $product = Product::query()
                    ->where('uuid', $this->route('uuid'))
                    ->first();

                if (! $product) {
                    return;
                }

                $price = $this->input('price', $product->price);
                $discountPrice = $this->input(
                    'discount_price',
                    $product->discount_price
                );

                if (
                    $discountPrice !== null
                    && (float) $discountPrice >= (float) $price
                ) {
                    $validator->errors()->add(
                        'discount_price',
                        'Harga diskon harus lebih rendah dari harga normal.'
                    );
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'category_uuid.exists' => 'Kategori tidak ditemukan atau tidak aktif.',
            'name.required' => 'Nama produk tidak boleh kosong.',
            'name.max' => 'Nama produk maksimal 150 karakter.',
            'slug.unique' => 'Slug produk sudah digunakan.',
            'slug.alpha_dash' => 'Slug hanya boleh berisi huruf, angka, tanda hubung, dan garis bawah.',
            'price.gt' => 'Harga produk harus lebih besar dari 0.',
            'discount_price.gt' => 'Harga diskon harus lebih besar dari 0.',
            'weight_grams.min' => 'Berat produk minimal 1 gram.',
        ];
    }
}
