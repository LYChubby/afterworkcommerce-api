<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSizeRequest extends FormRequest
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
                'max:30',
                Rule::unique('sizes', 'name')->ignore(
                    $uuid,
                    'uuid'
                ),
            ],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama ukuran wajib diisi.',
            'name.max' => 'Nama ukuran maksimal 30 karakter.',
            'name.unique' => 'Nama ukuran sudah digunakan.',
            'sort_order.integer' => 'Urutan ukuran harus berupa angka.',
            'sort_order.min' => 'Urutan ukuran tidak boleh kurang dari 0.',
            'is_active.boolean' => 'Status aktif ukuran harus berupa boolean.',
        ];
    }
}
