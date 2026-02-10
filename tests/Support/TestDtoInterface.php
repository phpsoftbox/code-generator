<?php

declare(strict_types=1);

namespace PhpSoftBox\CodeGenerator\Tests\Support;

interface TestDtoInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): static;
}
