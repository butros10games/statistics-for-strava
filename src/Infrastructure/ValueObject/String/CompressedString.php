<?php

declare(strict_types=1);

namespace App\Infrastructure\ValueObject\String;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final readonly class CompressedString implements \Stringable
{
    private const int DEFAULT_ZSTD_LEVEL = 3;
    private const string ZSTD_BINARY_NAME = 'zstd';

    private function __construct(
        private string $compressedValue,
    ) {
    }

    public static function fromUncompressed(string $value): self
    {
        $compressed = self::compressWithExtension($value) ?? self::compressWithBinary($value);
        if (false === $compressed) {
            throw new \RuntimeException('ZSTD compression failed'); // @codeCoverageIgnore
        }

        return new self($compressed);
    }

    public static function fromCompressed(string $value): self
    {
        return new self($value);
    }

    public function uncompress(): string
    {
        $uncompressed = self::uncompressWithExtension($this->compressedValue) ?? self::uncompressWithBinary($this->compressedValue);
        if (false === $uncompressed) {
            throw new \RuntimeException('ZSTD decompression failed');
        }

        return $uncompressed;
    }

    public function __toString(): string
    {
        return $this->compressedValue;
    }

    private static function compressWithExtension(string $value): string|false|null
    {
        if (!function_exists('zstd_compress')) {
            return null;
        }

        return @zstd_compress($value, self::DEFAULT_ZSTD_LEVEL);
    }

    private static function uncompressWithExtension(string $value): string|false|null
    {
        if (!function_exists('zstd_uncompress')) {
            return null;
        }

        return @zstd_uncompress($value);
    }

    private static function compressWithBinary(string $value): string|false|null
    {
        $binary = self::findZstdBinary();
        if (null === $binary) {
            return null;
        }

        return self::runZstdProcess(
            [$binary, '--quiet', '--stdout', '-'.self::DEFAULT_ZSTD_LEVEL],
            $value,
        );
    }

    private static function uncompressWithBinary(string $value): string|false|null
    {
        $binary = self::findZstdBinary();
        if (null === $binary) {
            return null;
        }

        return self::runZstdProcess(
            [$binary, '--quiet', '--decompress', '--stdout'],
            $value,
        );
    }

    private static function runZstdProcess(array $command, string $input): string|false
    {
        $process = new Process($command);
        $process->setInput($input);

        try {
            $process->mustRun();
        } catch (\Throwable) {
            return false;
        }

        return $process->getOutput();
    }

    private static function findZstdBinary(): ?string
    {
        static $binary = false;

        if (false === $binary) {
            $binary = (new ExecutableFinder())->find(self::ZSTD_BINARY_NAME) ?: null;
        }

        return $binary;
    }
}
