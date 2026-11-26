<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'variant_uuid' => [
                'required',
                'string',
                'uuid',
                'exists:product_variants,uuid',
            ],
            'quantity' => [
                'required',
                'integer',
                'min:1',
                'max:2147483647',
            ],
        ];
    }
}
