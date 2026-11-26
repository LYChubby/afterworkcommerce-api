<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source' => [
                'required',
                Rule::in(['direct', 'cart']),
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
                'required_if:source,direct',
                'prohibited_if:source,cart',
                'integer',
                'min:1',
                'max:100',
            ],

            'address_uuid' => [
                'required',
                'uuid',
            ],

            'shipping_quote_uuid' => [
                'required',
                'uuid',
            ],

            'customer_note' => [
                'nullable',
                'string',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'source.required' => 'Sumber pembelian wajib ditentukan.',
            'source.in' => 'Sumber pembelian tidak valid.',

            'items.required' => 'Daftar produk wajib diisi.',
            'items.array' => 'Format daftar produk tidak valid.',
            'items.min' => 'Minimal satu produk harus dipilih.',
            'items.max' => 'Maksimal 50 produk dapat dibeli sekaligus.',

            'items.*.variant_uuid.required' => 'Variasi produk wajib dipilih.',
            'items.*.variant_uuid.uuid' => 'Identitas variasi produk tidak valid.',

            'items.*.quantity.required_if' => 'Jumlah produk wajib diisi untuk pembelian langsung.',
            'items.*.quantity.prohibited_if' => 'Jumlah produk Cart harus menggunakan kuantitas yang tersimpan.',
            'items.*.quantity.integer' => 'Jumlah produk harus berupa bilangan bulat.',
            'items.*.quantity.min' => 'Jumlah produk minimal 1.',
            'items.*.quantity.max' => 'Jumlah produk maksimal 100.',

            'address_uuid.required' => 'Alamat pengiriman wajib dipilih.',
            'address_uuid.uuid' => 'Identitas alamat tidak valid.',

            'shipping_quote_uuid.required' => 'Layanan pengiriman wajib dipilih.',
            'shipping_quote_uuid.uuid' => 'Identitas pilihan pengiriman tidak valid.',

            'customer_note.string' => 'Catatan pelanggan harus berupa teks.',
        ];
    }
}
