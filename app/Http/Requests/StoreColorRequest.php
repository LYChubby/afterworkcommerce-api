<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreColorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50', 'unique:colors,name'],
            'hex_code' => [
                'required',
                'string',
                'regex:/^#[0-9A-Fa-f]{6}$/',
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama warna wajib diisi.',
            'name.max' => 'Nama warna maksimal 50 karakter.',
            'name.unique' => 'Nama warna sudah digunakan.',
            'hex_code.required' => 'Kode warna HEX wajib diisi.',
            'hex_code.regex' => 'Format kode warna harus berupa HEX, contoh #112233.',
            'is_active.boolean' => 'Status aktif warna harus berupa boolean.',
        ];
    }
}
