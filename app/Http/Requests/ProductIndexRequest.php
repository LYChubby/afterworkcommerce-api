<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => [
                'sometimes',
                'string',
                'max:100',
            ],

            'category' => [
                'sometimes',
                'string',
                'max:100',
            ],

            'min_price' => [
                'sometimes',
                'numeric',
                'min:0',
            ],

            'max_price' => [
                'sometimes',
                'numeric',
                'min:0',
                'gte:min_price',
            ],

            'availability' => [
                'sometimes',
                'in:available,out_of_stock',
            ],

            'sort' => [
                'sometimes',
                'in:latest,price_asc,price_desc,name_asc',
            ],

            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:50',
            ],

            'page' => [
                'sometimes',
                'integer',
                'min:1',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'search.max' => 'Pencarian maksimal 100 karakter.',

            'min_price.numeric' => 'Harga minimum harus berupa angka.',

            'min_price.min' => 'Harga minimum tidak boleh negatif.',

            'max_price.numeric' => 'Harga maksimum harus berupa angka.',

            'max_price.min' => 'Harga maksimum tidak boleh negatif.',

            'max_price.gte' => 'Harga maksimum harus lebih besar atau sama dengan harga minimum.',

            'availability.in' => 'Filter ketersediaan produk tidak valid.',

            'sort.in' => 'Pilihan pengurutan produk tidak valid.',

            'per_page.integer' => 'Jumlah produk per halaman harus berupa bilangan bulat.',

            'per_page.min' => 'Jumlah produk per halaman minimal 1.',

            'per_page.max' => 'Jumlah produk per halaman maksimal 50.',

            'page.integer' => 'Nomor halaman harus berupa bilangan bulat.',

            'page.min' => 'Nomor halaman minimal 1.',
        ];
    }
}
