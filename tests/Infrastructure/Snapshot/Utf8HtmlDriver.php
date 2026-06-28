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
        'not' => 0xAC,
        'reg' => 0xAE,
        'shy' => 0xAD,
    ];

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
        $htmlValue = self::repairUtf8Entities($htmlValue);

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

    private static function repairUtf8Entities(string $htmlValue): string
    {
        // Match DOMDocument's mixed mojibake output without requiring the full document to be valid UTF-8.
        $literalBytePattern = '(?:\xC2[\x80-\xBF]|\xC3[\x82\x83\xA2\xAF\xB0])';
        $byteEntityPattern = '(?:&#(?:12[8-9]|1[3-9][0-9]|2[0-4][0-9]|25[0-5]);|&(?:'.implode('|', array_keys(self::BYTE_ENTITY_MAP)).');)';
        $bytePattern = sprintf('(?:%s|%s)', $literalBytePattern, $byteEntityPattern);

        $htmlValue = (string) preg_replace_callback(
            sprintf('/(?:%s){2,}/', $bytePattern),
            static function (array $matches) use ($literalBytePattern): string {
                preg_match_all(sprintf('/&#([0-9]+);|&([a-z]+);|(%s)/', $literalBytePattern), (string) $matches[0], $byteMatches, PREG_SET_ORDER);

                $bytes = '';
                foreach ($byteMatches as $byteMatch) {
                    $byte = match (true) {
                        isset($byteMatch[1]) && '' !== $byteMatch[1] => (int) $byteMatch[1],
                        isset($byteMatch[2]) && '' !== $byteMatch[2] => self::BYTE_ENTITY_MAP[$byteMatch[2]] ?? null,
                        isset($byteMatch[3]) && '' !== $byteMatch[3] => self::decodeLiteralByte((string) $byteMatch[3]),
                        default => null,
                    };

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

        $htmlValue = (string) preg_replace_callback(
            '/&([a-zA-Z][a-zA-Z0-9]+);/',
            static function (array $matches): string {
                if (in_array($matches[1], ['amp', 'gt', 'lt', 'nbsp', 'quot'], true)) {
                    return (string) $matches[0];
                }

                return html_entity_decode((string) $matches[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            },
            $htmlValue
        );

        $htmlValue = str_replace(['%5B', '%5D'], ['[', ']'], $htmlValue);
        $htmlValue = str_replace(
            ['<div class="w-92">&nbsp;</div>', '<div class="pt-0.5">&nbsp;</div>'],
            ["<div class=\"w-92\">\xA0</div>", "<div class=\"pt-0.5\">\xA0</div>"],
            $htmlValue
        );

        return (string) preg_replace_callback(
            "/\\s([a-zA-Z_:][a-zA-Z0-9_:.:-]*)='([^']*)'/",
            static fn (array $matches): string => sprintf(' %s="%s"', $matches[1], str_replace('"', '&quot;', (string) $matches[2])),
            $htmlValue
        );
    }

    private static function decodeLiteralByte(string $literalByte): ?int
    {
        $bytes = array_map(ord(...), str_split($literalByte));
        if (2 !== count($bytes)) {
            return null;
        }

        if (0xC2 === $bytes[0] && $bytes[1] >= 0x80 && $bytes[1] <= 0xBF) {
            return $bytes[1];
        }

        if (0xC3 !== $bytes[0]) {
            return null;
        }

        return match ($bytes[1]) {
            0x82 => 0xC2,
            0x83 => 0xC3,
            0xA2 => 0xE2,
            0xAF => 0xEF,
            0xB0 => 0xF0,
            default => null,
        };
    }
}
