<?php

declare(strict_types=1);

namespace PhpSoftBox\CodeGenerator\OpenApi;

final readonly class OpenApiDtoGeneratorResult
{
    public function __construct(
        public int $generatedClasses,
        public int $responseMappings,
    ) {
    }
}
