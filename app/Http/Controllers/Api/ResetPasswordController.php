<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResetPasswordRequest;
use App\Support\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class ResetPasswordController extends Controller
{
    public function __invoke(
        ResetPasswordRequest $request
    ): JsonResponse {
        $status = Password::reset(
            $request->validated(),
            function ($user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                // Invalidate all existing Sanctum tokens.
                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return ApiResponse::error(
                message: 'Token reset password tidak valid atau sudah kedaluwarsa.',
                status: 422
            );
        }

        return ApiResponse::success(
            message: 'Password berhasil direset. Silakan login kembali.'
        );
    }
}
