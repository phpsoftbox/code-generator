<?php

declare(strict_types=1);

namespace PhpSoftBox\CodeGenerator;

final readonly class GeneratorTarget
{
    public function __construct(
        public string $path,
        public string $className,
        public ?string $namespace,
    ) {
    }
}
