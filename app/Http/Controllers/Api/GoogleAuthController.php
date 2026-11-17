<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\OAuthLoginCodeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Str;
use Throwable;

class GoogleAuthController extends Controller
{
    /**
     * Redirect the user to Google OAuth.
     */
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * Handle the Google OAuth callback.
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
                        throw new \RuntimeException(
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
                $exception instanceof \RuntimeException
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
                . '/auth/callback?'
                . http_build_query(['code' => $code])
        );
    }

    /**
     * Redirect to the frontend with a safe error identifier.
     */
    private function frontendError(string $error): RedirectResponse
    {
        return redirect()->away(
            rtrim(config('services.frontend.url'), '/')
                . '/login?'
                . http_build_query(['error' => $error])
        );
    }
}
