<?php

declare(strict_types=1);

namespace PhpSoftBox\CodeGenerator;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function implode;
use function sort;
use function str_repeat;
use function trim;

final class CodeGenerator
{
    /**
     * @param list<string> $uses
     * @param list<string> $classAttributes
     * @param list<string> $bodyLines
     */
    public function renderClass(
        string $className,
        ?string $namespace = null,
        array $uses = [],
        array $classAttributes = [],
        array $bodyLines = [],
        bool $final = true,
        bool $readonly = false,
    ): string {
        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
        ];

        if ($namespace !== null && $namespace !== '') {
            $lines[] = 'namespace ' . $namespace . ';';
            $lines[] = '';
        }

        $uses = $this->normalizeUses($uses);
        foreach ($uses as $use) {
            $lines[] = 'use ' . $use . ';';
        }

        if ($uses !== []) {
            $lines[] = '';
        }

        foreach ($classAttributes as $attribute) {
            $lines[] = $attribute;
        }

        $classLine = '';
        if ($final) {
            $classLine .= 'final ';
        }
        if ($readonly) {
            $classLine .= 'readonly ';
        }
        $classLine .= 'class ' . $className;

        $lines[] = $classLine;
        $lines[] = '{';

        foreach ($bodyLines as $line) {
            if ($line === '') {
                $lines[] = '';
                continue;
            }
            $lines[] = '    ' . $line;
        }

        $lines[] = '}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    public function indent(array $lines, int $level = 1): array
    {
        $prefix = str_repeat('    ', $level);
        $out    = [];
        foreach ($lines as $line) {
            $out[] = $line === '' ? '' : $prefix . $line;
        }

        return $out;
    }

    /**
     * @param list<string> $uses
     * @return list<string>
     */
    private function normalizeUses(array $uses): array
    {
        $uses = array_map(static fn (string $use): string => trim($use, ' '), $uses);
        $uses = array_filter($uses, static fn (string $use): bool => $use !== '');
        $uses = array_values(array_unique($uses));
        sort($uses);

        return $uses;
    }
}
