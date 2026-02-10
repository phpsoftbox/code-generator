<?php

declare(strict_types=1);

namespace PhpSoftBox\CodeGenerator\OpenApi;

final readonly class OpenApiOperation
{
    public function __construct(
        public string $key,
        public string $method,
        public string $path,
        public string $class,
        public string $schemaName,
        public string $documentName,
    ) {
    }
}
