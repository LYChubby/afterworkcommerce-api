<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShippingRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'postal_code' => [
                'required',
                'string',
                'regex:/^\d{5}$/',
            ],

            'items' => [
                'required',
                'array',
                'min:1',
                'max:50',
            ],

            'items.*.variant_id' => [
                'required',
                'integer',
                'min:1',
            ],

            'items.*.quantity' => [
                'required',
                'integer',
                'min:1',
                'max:100',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'postal_code.required' => 'Kode pos tujuan wajib diisi.',

            'postal_code.regex' => 'Kode pos harus terdiri dari 5 digit.',

            'items.required' => 'Daftar produk wajib diisi.',

            'items.min' => 'Keranjang belanja tidak boleh kosong.',

            'items.*.variant_id.required' => 'Variasi produk wajib dipilih.',

            'items.*.quantity.min' => 'Jumlah produk minimal 1.',
        ];
    }
}
