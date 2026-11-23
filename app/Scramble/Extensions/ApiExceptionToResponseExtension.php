<?php

namespace App\Scramble\Extensions;

use Dedoc\Scramble\Extensions\ExceptionToResponseExtension;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types as OpenApiTypes;
use Dedoc\Scramble\Support\Type\Literal\LiteralIntegerType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class ApiExceptionToResponseExtension extends ExceptionToResponseExtension
{
    public function shouldHandle(Type $type): bool
    {
        return $type instanceof ObjectType
            && (
                $type->isInstanceOf(ValidationException::class)
                || $type->isInstanceOf(AuthenticationException::class)
                || $type->isInstanceOf(AuthorizationException::class)
                || $type->isInstanceOf(RecordsNotFoundException::class)
                || $type->isInstanceOf(NotFoundHttpException::class)
                || $type->isInstanceOf(HttpException::class)
            );
    }

    public function toResponse(Type $type): ?Response
    {
        $status = $this->getStatusCode($type);

        if ($status === null) {
            return null;
        }

        $errorsType = (new OpenApiTypes\ObjectType)
            ->setDescription('Validation errors grouped by field.')
            ->additionalProperties(
                (new OpenApiTypes\ArrayType)
                    ->setItems(new OpenApiTypes\StringType)
            )
            ->nullable(true);

        $responseBodyType = (new OpenApiTypes\ObjectType)
            ->addProperty(
                'success',
                (new OpenApiTypes\BooleanType)
                    ->setDescription('Always false for error responses.')
            )
            ->addProperty(
                'message',
                (new OpenApiTypes\StringType)
                    ->setDescription('Error message.')
            )
            ->addProperty(
                'data',
                new OpenApiTypes\NullType
            )
            ->addProperty(
                'errors',
                $errorsType
            )
            ->setRequired([
                'success',
                'message',
                'data',
                'errors',
            ]);

        return Response::make($status)
            ->setDescription($this->getDescription($status))
            ->setContent(
                'application/json',
                Schema::fromType($responseBodyType),
            );
    }

    protected function getStatusCode(Type $type): ?int
    {
        if ($type->isInstanceOf(ValidationException::class)) {
            return 422;
        }

        if ($type->isInstanceOf(AuthenticationException::class)) {
            return 401;
        }

        if ($type->isInstanceOf(AuthorizationException::class)) {
            return 403;
        }

        if (
            $type->isInstanceOf(RecordsNotFoundException::class)
            || $type->isInstanceOf(NotFoundHttpException::class)
        ) {
            return 404;
        }

        if ($type->isInstanceOf(HttpException::class)) {
            $codeType = count($type->templateTypes ?? []) > 3
                ? ($type->templateTypes[7] ?? null)
                : ($type->templateTypes[0] ?? null);

            if ($codeType instanceof LiteralIntegerType) {
                return $codeType->value;
            }

            return match (true) {
                $type->isInstanceOf(
                    MethodNotAllowedHttpException::class
                ) => 405,

                $type->isInstanceOf(
                    TooManyRequestsHttpException::class
                ) => 429,

                default => null,
            };
        }

        return null;
    }

    protected function getDescription(int $status): string
    {
        return match ($status) {
            401 => 'Unauthenticated',
            403 => 'Forbidden',
            404 => 'Resource not found',
            405 => 'Method not allowed',
            422 => 'Validation error',
            429 => 'Too many requests',
            default => 'HTTP error',
        };
    }
}
