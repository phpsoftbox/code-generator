# Quick Start

## Базовый CLI-генератор

```php
use PhpSoftBox\CodeGenerator\Cli\AbstractMakeCommandHandler;
use PhpSoftBox\CodeGenerator\CodeGenerator;
use PhpSoftBox\CodeGenerator\GeneratorTarget;
use PhpSoftBox\CliApp\Runner\RunnerInterface;

final class MakeServiceHandler extends AbstractMakeCommandHandler
{
    protected function missingNameMessage(): string
    {
        return 'Имя сервиса не задано.';
    }

    protected function successMessage(GeneratorTarget $target): string
    {
        return 'Создан сервис: ' . $target->path;
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

## Параметры по умолчанию

`AbstractMakeCommandHandler` ожидает:
- аргумент `name` (namespace или путь);
- опции `--path` и `--namespace` (по умолчанию `src` и `App`).

## Генерация класса с атрибутами

`CodeGenerator` формирует только класс. Атрибуты, переданные через `classAttributes`, будут применены к классу:

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

Если нужен атрибут для метода, добавьте его напрямую в `bodyLines` перед методом.
