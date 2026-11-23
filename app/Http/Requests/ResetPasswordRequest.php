<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => [
                'required',
                'string',
            ],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
            ],
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'token.required' => 'Token reset password wajib diisi.',

            'email.required' => 'Email wajib diisi.',

            'email.email' => 'Format email tidak valid.',

            'password.required' => 'Password baru wajib diisi.',

            'password.confirmed' => 'Konfirmasi password tidak cocok.',
        ];
    }
}
