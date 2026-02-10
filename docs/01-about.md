# About

`phpsoftbox/code-generator` — компонент утилит для генерации кода. Он предоставляет базовые классы для CLI-команд `make:*`, а также простые средства сборки PHP-файлов.

Основные элементы:
- `AbstractMakeCommandHandler` — общий сценарий генерации файлов для CLI.
- `CodeGenerator` — сборка PHP-класса в строку (namespace, use, атрибуты класса, тело).
- `GeneratorTarget` — результат резолвинга имени: путь/класс/namespace.
- `FileWriter` — безопасная запись файла на диск.

## Как работает AbstractMakeCommandHandler

Класс ожидает:
- аргумент `name` — может быть namespace (`App\\Events\\UserRegistered`) или путь (`src/Events/UserRegistered.php`);
- опции `--path` и `--namespace` — базовые значения для перевода namespace в путь.

Внутри:
1) `name` преобразуется в `GeneratorTarget`;
2) создаются директории, если нужно;
3) вызывается `renderEvent()` для генерации содержимого;
4) файл записывается через `FileWriter`.
