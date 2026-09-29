<?php

declare(strict_types=1);

namespace PhpSoftBox\CodeGenerator\Tests\Support;

use PhpSoftBox\CliApp\Runner\RunnerInterface;
use PhpSoftBox\CodeGenerator\Cli\AbstractMakeCommandHandler;
use PhpSoftBox\CodeGenerator\CodeGenerator;
use PhpSoftBox\CodeGenerator\GeneratorTarget;

final class TestMakeCommandHandler extends AbstractMakeCommandHandler
{
    protected function missingNameMessage(): string
    {
        return 'Имя класса не задано.';
    }

    protected function successMessage(GeneratorTarget $target): string
    {
        return 'Создан класс: ' . $target->path;
    }

    protected function renderEvent(RunnerInterface $runner, GeneratorTarget $target): string
    {
        return new CodeGenerator()->renderClass($target->className, $target->namespace);
    }
}
