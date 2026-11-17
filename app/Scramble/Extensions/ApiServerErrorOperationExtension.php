<?php

namespace App\Scramble\Extensions;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types as OpenApiTypes;
use Dedoc\Scramble\Support\RouteInfo;

class ApiServerErrorOperationExtension extends OperationExtension
{
    public function handle(
        Operation $operation,
        RouteInfo $routeInfo
    ): void {
        foreach ($operation->responses ?? [] as $existingResponse) {
            if (
                $existingResponse instanceof Response
                && (string) $existingResponse->code === '500'
            ) {
                return;
            }
        }

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
                    ->examples([
                        'An unexpected server error occurred.',
                    ])
            )
            ->addProperty(
                'data',
                new OpenApiTypes\NullType
            )
            ->addProperty(
                'errors',
                (new OpenApiTypes\NullType)
                    ->setDescription('No field validation errors.')
            )
            ->setRequired([
                'success',
                'message',
                'data',
                'errors',
            ]);

        $operation->addResponse(
            Response::make(500)
                ->setDescription('Internal server error')
                ->setContent(
                    'application/json',
                    Schema::fromType($responseBodyType),
                )
        );
    }
}
