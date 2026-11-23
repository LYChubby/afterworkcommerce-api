<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to access this resource.',
                'data' => null,
                'errors' => null,
            ], 403);
        }

        return $next($request);
    }
}
