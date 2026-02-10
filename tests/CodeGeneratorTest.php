<?php

declare(strict_types=1);

namespace PhpSoftBox\CodeGenerator\Tests;

use PhpSoftBox\CodeGenerator\CodeGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
}
