# PhpSoftBox CodeGenerator

## About
`phpsoftbox/code-generator` — набор утилит для генерации кода и базовых CLI-команд. Включает `AbstractMakeCommandHandler`, `CodeGenerator`, `GeneratorTarget` и `FileWriter`.

## Quick Start
```php
use PhpSoftBox\CodeGenerator\Cli\AbstractMakeCommandHandler;
use PhpSoftBox\CodeGenerator\CodeGenerator;
use PhpSoftBox\CodeGenerator\GeneratorTarget;
use PhpSoftBox\CliApp\Runner\RunnerInterface;

final class MakeFooHandler extends AbstractMakeCommandHandler
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
        $generator = new CodeGenerator();

        return $generator->renderClass(
            className: $target->className,
            namespace: $target->namespace,
        );
    }
}
```

Пример генерации класса с атрибутом:

```php
$generator = new CodeGenerator();

$code = $generator->renderClass(
    className: 'WelcomeListener',
    namespace: 'App\\Listeners',
    uses: [
        'App\\Events\\UserRegistered',
        'PhpSoftBox\\Events\\Attributes\\ListenTo',
    ],
    classAttributes: ['#[ListenTo(UserRegistered::class)]'],
    bodyLines: [
        'public function handle(UserRegistered $event): void',
        '{',
        '}',
    ],
);
```

## Оглавление
- [Документация](docs/index.md)
- [About](docs/01-about.md)
- [Quick Start](docs/02-quick-start.md)
