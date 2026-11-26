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

            'items.*.variant_uuid' => [
                'required',
                'uuid',
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
            'items.max' => 'Maksimal 50 produk dapat dihitung sekaligus.',

            'items.*.variant_uuid.required' => 'Variasi produk wajib dipilih.',
            'items.*.variant_uuid.uuid' => 'Identitas variasi produk tidak valid.',

            'items.*.quantity.required' => 'Jumlah produk wajib diisi.',
            'items.*.quantity.integer' => 'Jumlah produk harus berupa bilangan bulat.',
            'items.*.quantity.min' => 'Jumlah produk minimal 1.',
            'items.*.quantity.max' => 'Jumlah produk maksimal 100.',
        ];
    }
}
