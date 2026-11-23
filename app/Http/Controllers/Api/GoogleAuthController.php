<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\OAuthLoginCodeService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use RuntimeException;
use Throwable;

class GoogleAuthController extends Controller
{
    /**
     * Arahkan pengguna ke Google OAuth.
     */
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * Tangani callback Google OAuth.
     */
    public function callback(
        OAuthLoginCodeService $loginCodeService
    ): RedirectResponse {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable $exception) {
            report($exception);

            return $this->frontendError('google_auth_failed');
        }

        $googleId = $googleUser->getId();
        $email = $googleUser->getEmail();
        $googleData = $googleUser->user;

        $emailVerified = filter_var(
            $googleData['verified_email']
                ?? $googleData['email_verified']
                ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        if (
            empty($googleId)
            || empty($email)
            || ! filter_var($email, FILTER_VALIDATE_EMAIL)
            || ! $emailVerified
        ) {
            return $this->frontendError('invalid_google_account');
        }

        try {
            $code = DB::transaction(function () use (
                $googleId,
                $email,
                $googleUser,
                $loginCodeService
            ) {
                $socialAccount = SocialAccount::query()
                    ->where('provider', 'google')
                    ->where('provider_user_id', $googleId)
                    ->first();

                if ($socialAccount) {
                    $user = $socialAccount->user;
                } else {
                    $existingUser = User::query()
                        ->where('email', $email)
                        ->first();

                    if ($existingUser) {
                        throw new RuntimeException(
                            'An account with this email already exists.'
                        );
                    }

                    $user = User::create([
                        'name' => $googleUser->getName()
                            ?: Str::before($email, '@'),
                        'email' => $email,
                        'password' => Str::random(64),
                        'avatar' => $googleUser->getAvatar(),
                    ]);

                    $user->forceFill([
                        'email_verified_at' => now(),
                    ])->save();

                    $user->socialAccounts()->create([
                        'provider' => 'google',
                        'provider_user_id' => $googleId,
                        'email' => $email,
                    ]);
                }

                return $loginCodeService->create($user);
            });
        } catch (Throwable $exception) {
            if (
                $exception instanceof RuntimeException
                && $exception->getMessage()
                === 'An account with this email already exists.'
            ) {
                return $this->frontendError('account_conflict');
            }

            report($exception);

            return $this->frontendError('google_auth_failed');
        }

        return redirect()->away(
            rtrim(config('services.frontend.url'), '/')
                .'/auth/callback?'
                .http_build_query(['code' => $code])
        );
    }

    /**
     * Tukar kode login OAuth sekali pakai menjadi token Sanctum.
     */
    public function exchange(
        Request $request,
        OAuthLoginCodeService $loginCodeService
    ): JsonResponse {
        $validator = Validator::make($request->all(), [
            'code' => [
                'required',
                'string',
                'regex:/\A[a-f0-9]{64}\z/',
            ],
        ]);

        if ($validator->fails()) {
            return ApiResponse::error(
                message: 'Invalid login code.',
                status: 422,
                errors: $validator->errors()->toArray(),
            );
        }

        try {
            $result = $loginCodeService->exchange(
                $validator->validated()['code']
            );

            $user = $result->accessToken->tokenable;

            if (! $user instanceof User) {
                return ApiResponse::error(
                    message: 'Unable to authenticate user.',
                    status: 401,
                );
            }

            return ApiResponse::success(
                data: [
                    'access_token' => $result->plainTextToken,
                    'token_type' => 'Bearer',
                    'user' => [
                        'uuid' => $user->uuid,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->role,
                        'avatar' => $user->avatar,
                    ],
                ],
                message: 'Login berhasil.',
            );
        } catch (RuntimeException $exception) {
            return ApiResponse::error(
                message: 'Invalid or expired login code.',
                status: 401,
            );
        }
    }

    /**
     * Arahkan ke frontend dengan identifier error yang aman.
     */
    private function frontendError(string $error): RedirectResponse
    {
        return redirect()->away(
            rtrim(config('services.frontend.url'), '/')
                .'/login?'
                .http_build_query(['error' => $error])
        );
    }
}
