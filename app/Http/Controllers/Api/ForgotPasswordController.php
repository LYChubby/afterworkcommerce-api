<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

class ForgotPasswordController extends Controller
{
    public function __invoke(
        ForgotPasswordRequest $request
    ): JsonResponse {
        Password::sendResetLink([
            'email' => $request->validated('email'),
        ]);

        return ApiResponse::success(
            message: 'Jika email terdaftar, instruksi reset password akan dikirim.'
        );
    }
}
