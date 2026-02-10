<?php

declare(strict_types=1);

namespace PhpSoftBox\CodeGenerator\OpenApi;

final readonly class OpenApiDtoGeneratorDocument
{
    /**
     * @param array<string, mixed> $openApi
     */
    public function __construct(
        public string $name,
        public array $openApi,
    ) {
    }
}
