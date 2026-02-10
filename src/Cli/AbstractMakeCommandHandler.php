<?php

declare(strict_types=1);

namespace PhpSoftBox\CodeGenerator\Cli;

use PhpSoftBox\CliApp\Command\HandlerInterface;
use PhpSoftBox\CliApp\Response;
use PhpSoftBox\CliApp\Runner\RunnerInterface;
use PhpSoftBox\CodeGenerator\FileWriter;
use PhpSoftBox\CodeGenerator\GeneratorTarget;
use Throwable;

use function array_pop;
use function basename;
use function dirname;
use function explode;
use function file_exists;
use function getcwd;
use function implode;
use function is_dir;
use function is_string;
use function ltrim;
use function mkdir;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

abstract class AbstractMakeCommandHandler implements HandlerInterface
{
    final public function run(RunnerInterface $runner): int|Response
    {
        $name = $runner->request()->param('name');
        if (!is_string($name) || trim($name) === '') {
            $runner->io()->writeln($this->missingNameMessage(), 'error');

            return Response::FAILURE;
        }

        $basePath      = $runner->request()->option('path', $this->defaultPath());
        $baseNamespace = $runner->request()->option('namespace', $this->defaultNamespace());

        $target = $this->resolveTarget($name, $basePath, $baseNamespace);
        if ($target === null) {
            $runner->io()->writeln($this->resolveTargetErrorMessage(), 'error');

            return Response::FAILURE;
        }

        $dir = dirname($target->path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $runner->io()->writeln('Не удалось создать директорию: ' . $dir, 'error');

            return Response::FAILURE;
        }

        if (file_exists($target->path)) {
            $runner->io()->writeln('Файл уже существует: ' . $target->path, 'error');

            return Response::FAILURE;
        }

        try {
            $contents = $this->renderEvent($runner, $target);
        } catch (Throwable $exception) {
            $runner->io()->writeln('Ошибка генерации: ' . $exception->getMessage(), 'error');

            return Response::FAILURE;
        }

        try {
            FileWriter::writeFile($target->path, $contents);
        } catch (Throwable $exception) {
            $runner->io()->writeln('Ошибка записи файла: ' . $exception->getMessage(), 'error');

            return Response::FAILURE;
        }

        $runner->io()->writeln($this->successMessage($target), 'success');

        return Response::SUCCESS;
    }

    protected function defaultPath(): string
    {
        return 'src';
    }

    protected function defaultNamespace(): string
    {
        return 'App';
    }

    abstract protected function missingNameMessage(): string;

    protected function resolveTargetErrorMessage(): string
    {
        return 'Не удалось определить путь и namespace.';
    }

    abstract protected function successMessage(GeneratorTarget $target): string;

    abstract protected function renderEvent(RunnerInterface $runner, GeneratorTarget $target): string;

    private function resolveTarget(string $name, mixed $basePath, mixed $baseNamespace): ?GeneratorTarget
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $baseNamespace = $this->normalizeNamespace($baseNamespace);
        if (!is_string($basePath) || trim($basePath) === '') {
            $basePath = $this->defaultPath();
        }

        $isPath = str_contains($name, '/') || str_ends_with($name, '.php');
        if ($isPath) {
            $path = $this->normalizePath($name);
            if ($path === null) {
                return null;
            }
            if (!str_ends_with($path, '.php')) {
                $path .= '.php';
            }
            $className = basename($path, '.php');
            if ($className === '') {
                return null;
            }

            $namespace = $this->namespaceFromPath($path, $basePath, $baseNamespace);

            return new GeneratorTarget($path, $className, $namespace);
        }

        if (str_contains($name, '\\')) {
            return $this->resolveFromFqcn($name, (string) $basePath, $baseNamespace);
        }

        $baseDir = $this->normalizePath((string) $basePath);
        if ($baseDir === null) {
            return null;
        }

        return new GeneratorTarget(
            rtrim($baseDir, '/') . '/' . $name . '.php',
            $name,
            $baseNamespace,
        );
    }

    private function resolveFromFqcn(string $name, string $basePath, ?string $baseNamespace): ?GeneratorTarget
    {
        $fqcn = ltrim($name, '\\');
        if ($fqcn === '') {
            return null;
        }

        $parts     = explode('\\', $fqcn);
        $className = trim((string) array_pop($parts));
        if ($className === '') {
            return null;
        }

        $namespace         = implode('\\', $parts);
        $relativeNamespace = $namespace;
        if ($baseNamespace !== null && $namespace !== '') {
            $prefix = $baseNamespace . '\\';
            if ($namespace === $baseNamespace) {
                $relativeNamespace = '';
            } elseif (str_starts_with($namespace, $prefix)) {
                $relativeNamespace = substr($namespace, strlen($prefix));
            }
        }

        $baseDir = $this->normalizePath($basePath);
        if ($baseDir === null) {
            return null;
        }

        $path = rtrim($baseDir, '/');
        if ($relativeNamespace !== '') {
            $path .= '/' . str_replace('\\', '/', $relativeNamespace);
        }
        $path .= '/' . $className . '.php';

        return new GeneratorTarget(
            $path,
            $className,
            $namespace !== '' ? $namespace : null,
        );
    }

    private function normalizeNamespace(mixed $namespace): ?string
    {
        if (!is_string($namespace) || trim($namespace) === '') {
            return null;
        }

        $namespace = trim($namespace);
        $namespace = trim($namespace, '\\');

        return $namespace === '' ? null : $namespace;
    }

    private function namespaceFromPath(string $path, string $basePath, ?string $baseNamespace): ?string
    {
        if ($baseNamespace === null) {
            return null;
        }

        $baseDir = $this->normalizePath($basePath);
        if ($baseDir === null) {
            return $baseNamespace;
        }

        $baseDir = rtrim($baseDir, '/');
        if (!str_starts_with($path, $baseDir . '/')) {
            return $baseNamespace;
        }

        $relative    = substr($path, strlen($baseDir) + 1);
        $relativeDir = rtrim(dirname($relative), '/');
        if ($relativeDir === '' || $relativeDir === '.') {
            return $baseNamespace;
        }

        return $baseNamespace . '\\' . str_replace('/', '\\', $relativeDir);
    }

    private function normalizePath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        $path = rtrim($path, '/');
        if ($path === '') {
            return null;
        }

        if (!str_starts_with($path, '/')) {
            $cwd = getcwd();
            if ($cwd !== false) {
                $path = rtrim($cwd, '/') . '/' . $path;
            }
        }

        return $path;
    }
}
