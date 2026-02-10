<?php

declare(strict_types=1);

namespace PhpSoftBox\CodeGenerator\OpenApi;

final readonly class OpenApiDtoGeneratorOptions
{
    /**
     * @param non-empty-list<OpenApiDtoGeneratorDocument> $documents
     */
    public function __construct(
        public array $documents,
        public string $dtoDirectory,
        public string $dtoNamespace,
        public string $dtoInterface,
        public string $dtoValueClass,
        public string $responseMapPath,
        public string $responseMapNamespace,
        public string $responseMapClassName,
        public string $normalizePathFunctionName,
        public string $normalizePatternFunctionName,
        public string $generatedComment,
        public bool $cleanDtoDirectory = true,
    ) {
    }
}
