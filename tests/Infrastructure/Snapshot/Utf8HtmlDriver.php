<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Snapshot;

use PHPUnit\Framework\Assert;
use Spatie\Snapshots\Driver;
use Spatie\Snapshots\Exceptions\CantBeSerialized;

final class Utf8HtmlDriver implements Driver
{
    /** @var array<string, int> */
    private const array BYTE_ENTITY_MAP = [
        'acirc' => 0xE2,
        'cedil' => 0xB8,
        'copy' => 0xA9,
        'eth' => 0xF0,
        'iuml' => 0xEF,
        'nbsp' => 0xA0,
        'reg' => 0xAE,
    ];

    private static ?bool $domDocumentPreservesUtf8 = null;

    #[\Override]
    public function serialize($data): string
    {
        if (!is_string($data)) {
            throw new CantBeSerialized('Only strings can be serialized to html');
        }

        if ('' === $data) {
            return "\n";
        }

        if (!str_contains($data, '<')) {
            $data = sprintf('<html><body>%s</body></html>', $data);
        }

        $domDocument = new \DOMDocument('1.0');
        $domDocument->preserveWhiteSpace = false;
        $domDocument->formatOutput = true;

        @$domDocument->loadHTML($data, LIBXML_HTML_NODEFDTD);

        $htmlValue = (string) $domDocument->saveHTML();
        if (!self::domDocumentPreservesUtf8()) {
            $htmlValue = self::repairUtf8Entities($htmlValue);
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $htmlValue = implode("\n", explode("\r\n", $htmlValue));
        }

        return $htmlValue;
    }

    #[\Override]
    public function extension(): string
    {
        return 'html';
    }

    #[\Override]
    public function match($expected, $actual): void
    {
        Assert::assertEquals($expected, $this->serialize($actual));
    }

    private static function domDocumentPreservesUtf8(): bool
    {
        if (null !== self::$domDocumentPreservesUtf8) {
            return self::$domDocumentPreservesUtf8;
        }

        $domDocument = new \DOMDocument('1.0');
        $domDocument->preserveWhiteSpace = false;
        $domDocument->formatOutput = true;

        @$domDocument->loadHTML('<html><body>👑</body></html>', LIBXML_HTML_NODEFDTD);

        self::$domDocumentPreservesUtf8 = str_contains((string) $domDocument->saveHTML(), '👑');

        return self::$domDocumentPreservesUtf8;
    }

    private static function repairUtf8Entities(string $htmlValue): string
    {
        $byteEntityPattern = '(?:&#(?:12[8-9]|1[3-9][0-9]|2[0-4][0-9]|25[0-5]);|&(?:'.implode('|', array_keys(self::BYTE_ENTITY_MAP)).');)';

        $htmlValue = (string) preg_replace_callback(
            sprintf('/(?:%s){2,}/', $byteEntityPattern),
            static function (array $matches): string {
                preg_match_all('/&#([0-9]+);|&([a-z]+);/', (string) $matches[0], $entityMatches, PREG_SET_ORDER);

                $bytes = '';
                foreach ($entityMatches as $entityMatch) {
                    $byte = isset($entityMatch[1]) && '' !== $entityMatch[1]
                        ? (int) $entityMatch[1]
                        : (self::BYTE_ENTITY_MAP[$entityMatch[2]] ?? null);

                    if (!is_int($byte) || $byte < 128 || $byte > 255) {
                        return (string) $matches[0];
                    }

                    $bytes .= chr($byte);
                }

                return mb_check_encoding($bytes, 'UTF-8') ? $bytes : (string) $matches[0];
            },
            $htmlValue
        );

        $htmlValue = (string) preg_replace_callback(
            '/&#([0-9]+);/',
            static function (array $matches): string {
                $codePoint = (int) $matches[1];

                if ($codePoint <= 255) {
                    return (string) $matches[0];
                }

                return mb_chr($codePoint, 'UTF-8');
            },
            $htmlValue
        );

        return (string) preg_replace_callback(
            "/\\s([a-zA-Z_:][a-zA-Z0-9_:.:-]*)='([^']*)'/",
            static fn (array $matches): string => sprintf(' %s="%s"', $matches[1], str_replace('"', '&quot;', (string) $matches[2])),
            $htmlValue
        );
    }
}
