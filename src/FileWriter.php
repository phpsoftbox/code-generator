<?php

declare(strict_types=1);

namespace PhpSoftBox\CodeGenerator;

use RuntimeException;

use function chmod;
use function dirname;
use function file_put_contents;
use function is_dir;
use function is_writable;
use function mkdir;
use function realpath;
use function rename;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function stripos;
use function tempnam;
use function umask;
use function unlink;

use const PHP_OS;

final class FileWriter
{
    public static function writeGeneratedFile(GeneratedFile $file): void
    {
        self::writeFile($file->path, $file->contents, $file->chmod);
    }

    public static function writeGeneratedFiles(GeneratedFileSet $files): void
    {
        foreach ($files as $file) {
            self::writeGeneratedFile($file);
        }
    }

    public static function writeFile(string $file, string $content, int $chmod = 0666): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Could not create directory "%s"', $dir));
        }

        set_error_handler(static function (): bool {
            return true;
        });

        try {
            $tmp = tempnam($dir, 'psb');

            if ($tmp === false) {
                throw new RuntimeException(sprintf('Could not create temporary file in directory "%s"', $dir));
            }

            if (dirname($tmp) !== realpath($dir)) {
                unlink($tmp);

                throw new RuntimeException(sprintf('Could not create temporary file in directory "%s"', $dir));
            }

            if (file_put_contents($tmp, $content) === false) {
                unlink($tmp);

                throw new RuntimeException(sprintf('Could not write content to the file "%s"', $file));
            }

            if (chmod($tmp, $chmod & ~umask()) === false) {
                unlink($tmp);

                throw new RuntimeException(sprintf('Could not change chmod of the file "%s"', $file));
            }

            while (rename($tmp, $file) === false) {
                if (is_writable($file) && stripos(PHP_OS, 'WIN') === 0) {
                    continue;
                }

                unlink($tmp);

                throw new RuntimeException(sprintf(
                    'Could not move file "%s" to location "%s": either the source file is not readable, or the destination is not writable',
                    $tmp,
                    $file,
                ));
            }
        } finally {
            restore_error_handler();
        }
    }
}
