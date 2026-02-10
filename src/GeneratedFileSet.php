<?php

declare(strict_types=1);

namespace PhpSoftBox\CodeGenerator;

use Countable;
use IteratorAggregate;
use Traversable;

use function count;

/**
 * @implements IteratorAggregate<int, GeneratedFile>
 */
final class GeneratedFileSet implements Countable, IteratorAggregate
{
    /** @var list<GeneratedFile> */
    private array $files = [];

    public function add(GeneratedFile $file): self
    {
        $this->files[] = $file;

        return $this;
    }

    /**
     * @return list<GeneratedFile>
     */
    public function all(): array
    {
        return $this->files;
    }

    public function count(): int
    {
        return count($this->files);
    }

    public function getIterator(): Traversable
    {
        yield from $this->files;
    }
}
