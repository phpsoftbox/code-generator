<?php

declare(strict_types=1);

namespace PhpSoftBox\CodeGenerator\Tests;

use PhpSoftBox\CliApp\Io\NullIo;
use PhpSoftBox\CliApp\Request\Request;
use PhpSoftBox\CliApp\Response;
use PhpSoftBox\CliApp\Runner\RunnerInterface;
use PhpSoftBox\CodeGenerator\Cli\AbstractMakeCommandHandler;
use PhpSoftBox\CodeGenerator\Tests\Support\TestMakeCommandHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function glob;
use function is_dir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

#[CoversClass(AbstractMakeCommandHandler::class)]
#[CoversMethod(AbstractMakeCommandHandler::class, 'run')]
final class AbstractMakeCommandHandlerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/' . uniqid('psb-make-handler-', true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);

        parent::tearDown();
    }

    /**
     * Проверим, что класс по корректному FQCN создаётся в каталоге --path с namespace из имени.
     *
     * @see AbstractMakeCommandHandler::run()
     */
    #[Test]
    public function runCreatesClassForValidName(): void
    {
        $result = new TestMakeCommandHandler()->run($this->runner('App\\Listeners\\WelcomeListener'));

        // Имя класса и namespace берутся из FQCN, подкаталог — из части namespace после App.
        self::assertSame(Response::SUCCESS, $result);
        self::assertStringContainsString(
            "namespace App\\Listeners;\n\nfinal class WelcomeListener\n",
            (string) file_get_contents($this->directory . '/Listeners/WelcomeListener.php'),
        );
    }

    /**
     * Проверим, что имя, не являющееся идентификатором PHP, отклоняется до создания каталогов и файлов.
     *
     * @see AbstractMakeCommandHandler::run()
     */
    #[Test]
    public function runRejectsNameThatIsNotPhpIdentifier(): void
    {
        $result = new TestMakeCommandHandler()->run($this->runner('Foo{}echo(1);//'));

        self::assertSame(Response::FAILURE, $result);
        self::assertDirectoryDoesNotExist($this->directory);
    }

    private function removeDirectory(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $path) {
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }

    private function runner(string $name): RunnerInterface
    {
        $runner = $this->createStub(RunnerInterface::class);
        $runner->method('request')->willReturn(new Request(
            ['name' => $name],
            ['path' => $this->directory, 'namespace' => 'App'],
        ));
        $runner->method('io')->willReturn(new NullIo());

        return $runner;
    }
}
