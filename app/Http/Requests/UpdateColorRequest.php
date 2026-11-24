<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateColorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $uuid = $this->route('uuid');

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('colors', 'name')->ignore(
                    $uuid,
                    'uuid'
                ),
            ],
            'hex_code' => [
                'sometimes',
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
