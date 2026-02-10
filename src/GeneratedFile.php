<?php

declare(strict_types=1);

namespace PhpSoftBox\CodeGenerator;

final readonly class GeneratedFile
{
    public function __construct(
        public string $path,
        public string $contents,
        public int $chmod = 0666,
    ) {
    }
}
