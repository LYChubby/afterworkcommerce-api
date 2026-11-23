<?php

namespace App\Services;

use App\Models\OAuthLoginCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;
use RuntimeException;

class OAuthLoginCodeService
{
    private const CODE_TTL_MINUTES = 2;

    /**
     * Generate a one-time OAuth login code.
     */
    public function create(User $user): string
    {
        $plainTextCode = bin2hex(random_bytes(32));

        OAuthLoginCode::create([
            'user_id' => $user->id,
            'code_hash' => hash('sha256', $plainTextCode),
            'expires_at' => now()->addMinutes(
                self::CODE_TTL_MINUTES
            ),
        ]);

        return $plainTextCode;
    }

    /**
     * Exchange a one-time OAuth code for a Sanctum token.
     */
    public function exchange(string $plainTextCode): NewAccessToken
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/', $plainTextCode)) {
            throw new RuntimeException('Invalid or expired login code.');
        }

        return DB::transaction(function () use ($plainTextCode) {
            $loginCode = OAuthLoginCode::query()
                ->where(
                    'code_hash',
                    hash('sha256', $plainTextCode)
                )
                ->lockForUpdate()
                ->first();

            if (
                ! $loginCode
                || $loginCode->consumed_at !== null
                || $loginCode->expires_at->isPast()
            ) {
                throw new RuntimeException(
                    'Invalid or expired login code.'
                );
            }

            $loginCode->forceFill([
                'consumed_at' => now(),
            ])->save();

            $tokenName = 'google-oauth-'.Str::uuid();

            return $loginCode->user->createToken($tokenName);
        });
    }
}
