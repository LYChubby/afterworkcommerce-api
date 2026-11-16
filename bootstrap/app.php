<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        api: __DIR__ . '/../routes/api.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn(Request $request) => $request->is('api/*')
                || $request->expectsJson(),
        );

        $exceptions->render(function (
            Throwable $exception,
            Request $request
        ) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = match (true) {
                $exception instanceof ValidationException => 422,
                $exception instanceof AuthenticationException => 401,
                $exception instanceof AuthorizationException => 403,
                $exception instanceof HttpExceptionInterface =>
                $exception->getStatusCode(),
                default => 500,
            };

            $message = match ($status) {
                401 => 'Unauthenticated.',
                403 => 'Forbidden.',
                404 => 'Resource not found.',
                405 => 'Method not allowed.',
                422 => 'Validation failed.',
                429 => 'Too many requests.',
                default => $status >= 500
                    ? 'An unexpected server error occurred.'
                    : 'Request failed.',
            };

            return response()->json([
                'success' => false,
                'message' => $message,
                'data' => null,
                'errors' => $exception instanceof ValidationException
                    ? $exception->errors()
                    : null,
            ], $status);
        });
    })
    ->create();
