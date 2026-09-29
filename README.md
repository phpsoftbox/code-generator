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

## OpenAPI DTO
`OpenApiDtoGenerator` генерирует DTO-классы и response-map из локальных OpenAPI документов. Он не привязан к конкретному маркетплейсу: компонент-потребитель передает namespace, DTO-интерфейс, value-helper и путь для карты ответов.

```php
use PhpSoftBox\CodeGenerator\OpenApi\OpenApiDtoGenerator;
use PhpSoftBox\CodeGenerator\OpenApi\OpenApiDtoGeneratorDocument;
use PhpSoftBox\CodeGenerator\OpenApi\OpenApiDtoGeneratorOptions;

$result = (new OpenApiDtoGenerator())->generate(new OpenApiDtoGeneratorOptions(
    documents: [
        new OpenApiDtoGeneratorDocument('partner', $openApi),
    ],
    dtoDirectory: __DIR__ . '/src/Dto',
    dtoNamespace: 'App\\Dto',
    dtoInterface: App\Dto\DtoInterface::class,
    dtoValueClass: App\Dto\DtoValue::class,
    responseMapPath: __DIR__ . '/src/Dto/ResponseDtoMap.php',
    responseMapNamespace: 'App\\Dto',
    responseMapClassName: 'ResponseDtoMap',
    normalizePathFunctionName: 'normalizeApiPath',
    normalizePatternFunctionName: 'normalizeApiPathPattern',
    generatedComment: 'App OpenAPI DTO',
));
```

Генератор поддерживает несколько документов за один запуск и изолирует одинаковые schema names по имени документа. Это нужно для спецификаций, где в разных YAML-файлах встречаются одинаковые DTO вроде `Error` или `Order`.

Имена классов, свойств и namespace строятся только из латинских букв и цифр имени схемы или поля; ключи JSON
попадают в код строковыми литералами с экранированием, поэтому кавычки и `\` в именах полей не искажают ключ.
Параметр конструктора `$extra` собирает неописанные ключи ответа; поле схемы с именем `extra` получает
свойство `extra2` (как и другие совпадения имён после нормализации).

### Корневые массивы и ответы без JSON

Если response schema описывает корневой JSON-массив, сгенерированный DTO хранит
его в свойстве `value`. В `fromArray()` передаётся сам массив, без дополнительной
обёртки: `$dto = ReportList::fromArray($items)`, затем `$dto->value`.
Для массива объектов по `$ref` элементы преобразуются в соответствующие DTO.
У обычного JSON-объекта с настоящим полем `value` структура входа не меняется.

Для операции с описанным успешным 2xx-ответом выбирается успешная JSON-схема.
Если у успешных ответов нет JSON, например 204 или скачивание файла,
генерируется пустой response DTO, а не DTO ошибки из 400/401/default.
Транспорт компонента-потребителя по-прежнему отвечает за HTTP-ошибки и бинарные данные.

После обновления генератора пересоздайте DTO компонента-потребителя. Изменятся
классы в response-map там, где ранее ошибочно использовалась схема ошибки;
проверьте явные импорты таких классов и ручные обёртки корневых массивов.
