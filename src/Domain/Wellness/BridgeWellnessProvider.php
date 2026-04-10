<?php

declare(strict_types=1);

namespace App\Domain\Wellness;

use App\Infrastructure\Serialization\Json;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\ValueObject\String\KernelProjectDir;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final readonly class BridgeWellnessProvider implements WellnessProvider
{
    public function __construct(
        private WellnessImportConfig $config,
        private KernelProjectDir $kernelProjectDir,
        private Clock $clock,
    ) {
    }

    public function fetch(): array
    {
        $path = $this->resolvePath($this->config->getBridgeSourcePath());
        if (!file_exists($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if (false === $contents) {
            throw new \RuntimeException(sprintf('Could not read wellness bridge file at "%s"', $path));
        }

        $decoded = Json::decode($contents);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('The wellness bridge file must decode to an array of records.');
        }

        $records = $decoded;
        if (!array_is_list($decoded)) {
            $records = $decoded['records'] ?? null;
        }

        if (!is_array($records) || !array_is_list($records)) {
            throw new \InvalidArgumentException('The wellness bridge file must contain a list of records or a top-level "records" list.');
        }

        return array_map($this->hydrateRecord(...), $records);
    }

    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return rtrim((string) $this->kernelProjectDir, '/').'/'.ltrim($path, '/');
    }

    /**
     * @param array<string, mixed> $record
     */
    private function hydrateRecord(array $record): DailyWellness
    {
        if (!array_key_exists('date', $record) || !is_string($record['date'])) {
            throw new \InvalidArgumentException('Each wellness bridge record must contain a string "date" field.');
        }

        if (isset($record['source']) && WellnessSource::GARMIN->value !== $record['source']) {
            throw new \InvalidArgumentException(sprintf('Unsupported wellness source "%s" in bridge file.', $record['source']));
        }

        $payload = $record;
        if (isset($record['payload']) && is_array($record['payload'])) {
            $payload = $record['payload'];
        }

        return DailyWellness::create(
            day: SerializableDateTime::fromString($record['date'])->setTime(0, 0),
            source: WellnessSource::GARMIN,
            stepsCount: $this->normalizeInt($record['stepsCount'] ?? $record['steps'] ?? null),
            sleepDurationInSeconds: $this->normalizeInt($record['sleepDurationInSeconds'] ?? $record['sleepDuration'] ?? null),
            sleepScore: $this->normalizeInt($record['sleepScore'] ?? null),
            hrv: $this->normalizeFloat($record['hrv'] ?? null),
            payload: $payload,
            importedAt: $this->clock->getCurrentDateTimeImmutable(),
        );
    }

    private function normalizeInt(mixed $value): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }

        if (!is_numeric($value)) {
            throw new \InvalidArgumentException(sprintf('Expected an integer-compatible value, received "%s".', get_debug_type($value)));
        }

        return (int) round((float) $value);
    }

    private function normalizeFloat(mixed $value): ?float
    {
        if (null === $value || '' === $value) {
            return null;
        }

        if (!is_numeric($value)) {
            throw new \InvalidArgumentException(sprintf('Expected a float-compatible value, received "%s".', get_debug_type($value)));
        }

        return (float) $value;
    }
}