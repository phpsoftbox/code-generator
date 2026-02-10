<?php

declare(strict_types=1);

namespace PhpSoftBox\CodeGenerator\Tests;

use PhpSoftBox\CodeGenerator\CodeGenerator;
use PhpSoftBox\CodeGenerator\FileWriter;
use PhpSoftBox\CodeGenerator\GeneratedFile;
use PhpSoftBox\CodeGenerator\GeneratedFileSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function sys_get_temp_dir;
use function uniqid;

#[CoversClass(CodeGenerator::class)]
final class CodeGeneratorTest extends TestCase
{
    /**
     * Проверяет базовую сборку PHP-класса.
     */
    #[Test]
    public function testRenderClassBuildsPhpFile(): void
    {
        $generator = new CodeGenerator();

        $result = $generator->renderClass(
            className: 'WelcomeListener',
            namespace: 'App\\Listeners',
            uses: [
                'PhpSoftBox\\Events\\Attributes\\ListenTo',
                'App\\Events\\UserRegistered',
            ],
            classAttributes: ['#[ListenTo(UserRegistered::class)]'],
            bodyLines: [
                'public function handle(UserRegistered $event): void',
                '{',
                '}',
            ],
        );

        $expected = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\UserRegistered;
use PhpSoftBox\Events\Attributes\ListenTo;

#[ListenTo(UserRegistered::class)]
final class WelcomeListener
{
    public function handle(UserRegistered $event): void
    {
    }
}

PHP;

        $this->assertSame($expected, $result);
    }

    /**
     * Проверяет генерацию readonly-класса с наследованием и интерфейсами.
     */
    #[Test]
    public function testRenderClassSupportsExtendsAndImplements(): void
    {
        $generator = new CodeGenerator();

        $result = $generator->renderClass(
            className: 'UserDto',
            namespace: 'App\\Dto',
            uses: [
                'JsonSerializable',
                'Stringable',
            ],
            bodyLines: [
                'public function jsonSerialize(): array',
                '{',
                '    return [];',
                '}',
            ],
            readonly: true,
            extends: 'BaseDto',
            implements: ['JsonSerializable', 'Stringable'],
        );

        $expected = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Dto;

use JsonSerializable;
use Stringable;

final readonly class UserDto extends BaseDto implements JsonSerializable, Stringable
{
    public function jsonSerialize(): array
    {
        return [];
    }
}

PHP;

        $this->assertSame($expected, $result);
    }

    /**
     * Проверяет пакетную запись с автоматическим созданием директорий.
     */
    #[Test]
    public function testFileWriterWritesGeneratedFileSet(): void
    {
        $dir = sys_get_temp_dir() . '/' . uniqid('psb-code-generator-', true);

        $files = new GeneratedFileSet()
            ->add(new GeneratedFile($dir . '/src/Foo.php', '<?php echo "foo";'))
            ->add(new GeneratedFile($dir . '/src/Bar.php', '<?php echo "bar";'));

        FileWriter::writeGeneratedFiles($files);

        $this->assertSame('<?php echo "foo";', file_get_contents($dir . '/src/Foo.php'));
        $this->assertSame('<?php echo "bar";', file_get_contents($dir . '/src/Bar.php'));
        $this->assertCount(2, $files);
    }
}
