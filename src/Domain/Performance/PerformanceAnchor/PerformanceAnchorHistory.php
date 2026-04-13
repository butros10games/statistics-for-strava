<?php

declare(strict_types=1);

namespace App\Domain\Performance\PerformanceAnchor;

use App\Domain\Activity\ActivityType;
use App\Domain\Ftp\FtpHistory;
use App\Domain\Integration\AI\SupportsAITooling;
use App\Infrastructure\Exception\EntityNotFound;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final class PerformanceAnchorHistory implements SupportsAITooling
{
    /** @var array<string, array<int, PerformanceAnchor>> */
    private array $anchors = [];

    /**
     * @param array<string, mixed> $anchors
     */
    private function __construct(array $anchors)
    {
        foreach (PerformanceAnchorType::cases() as $type) {
            $entries = $anchors[$type->value] ?? [];
            if ([] === $entries) {
                $entries = $anchors[$type->getLegacyKey()] ?? [];
            }

            $defaultSource = 'cycling' === $type->getLegacyKey() || 'running' === $type->getLegacyKey()
                ? PerformanceAnchorSource::FTP_HISTORY
                : PerformanceAnchorSource::MANUAL;

            $this->anchors[$type->value] = $this->mapAnchorHistory($entries, $type, $defaultSource);
            krsort($this->anchors[$type->value]);
        }
    }

    public static function fromFtpHistory(FtpHistory $ftpHistory): self
    {
        $values = [];

        foreach ([ActivityType::RIDE, ActivityType::RUN] as $activityType) {
            $type = PerformanceAnchorType::fromActivityType($activityType);
            $values[$type->value] = [];

            foreach ($ftpHistory->findAll($activityType) as $ftp) {
                $values[$type->value][$ftp->getSetOn()->format('Y-m-d')] = [
                    'value' => $ftp->getFtp()->getValue(),
                    'source' => PerformanceAnchorSource::FTP_HISTORY->value,
                    'confidence' => PerformanceAnchorConfidence::HIGH->value,
                    'sampleSize' => 1,
                ];
            }
        }

        return self::fromArray($values);
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function fromArray(array $values): self
    {
        if (!self::containsExplicitTypeKeys($values)) {
            // Backwards compatibility with old FTP-style config arrays.
            if (!array_key_exists('cycling', $values) && !array_key_exists('running', $values)) {
                $values = [PerformanceAnchorType::CYCLING_THRESHOLD_POWER->value => $values];
            }
        }

        return new self($values);
    }

    public function findAll(PerformanceAnchorType $type): PerformanceAnchors
    {
        $anchors = $this->anchors[$type->value] ?? [];
        ksort($anchors);

        return PerformanceAnchors::fromArray(array_values($anchors));
    }

    public function find(PerformanceAnchorType $type, SerializableDateTime $on): PerformanceAnchor
    {
        $on = SerializableDateTime::fromString($on->format('Y-m-d'));

        foreach ($this->anchors[$type->value] ?? [] as $anchor) {
            if ($on->isAfterOrOn($anchor->getSetOn())) {
                return $anchor;
            }
        }

        throw new EntityNotFound(sprintf('Performance anchor "%s" for date "%s" not found', $type->value, $on));
    }

    /**
     * @return array<string, list<array{setOn: string, value: float, unit: string, source: string, confidence: string, sampleSize: int}>>
     */
    public function exportForAITooling(): array
    {
        $history = [];

        foreach (PerformanceAnchorType::cases() as $type) {
            $history[$type->value] = [];

            foreach ($this->findAll($type) as $anchor) {
                $history[$type->value][] = [
                    'setOn' => $anchor->getSetOn()->format('Y-m-d'),
                    'value' => $anchor->getValue(),
                    'unit' => $type->getUnit(),
                    'source' => $anchor->getSource()->value,
                    'confidence' => $anchor->getConfidence()->value,
                    'sampleSize' => $anchor->getSampleSize(),
                ];
            }
        }

        return $history;
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function containsExplicitTypeKeys(array $values): bool
    {
        foreach (PerformanceAnchorType::cases() as $type) {
            if (array_key_exists($type->value, $values) || array_key_exists($type->getLegacyKey(), $values)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $entries
     *
     * @return array<int, PerformanceAnchor>
     */
    private function mapAnchorHistory(array $entries, PerformanceAnchorType $type, PerformanceAnchorSource $defaultSource): array
    {
        $result = [];

        foreach ($entries as $setOn => $entry) {
            try {
                $date = SerializableDateTime::fromString($setOn);
            } catch (\DateMalformedStringException) {
                throw new \InvalidArgumentException(sprintf('Invalid date "%s" set for performance anchor history "%s"', $setOn, $type->value));
            }

            $value = is_array($entry) ? (float) ($entry['value'] ?? 0.0) : (float) $entry;
            $sampleSize = is_array($entry) ? (int) ($entry['sampleSize'] ?? 1) : 1;
            $source = is_array($entry) && isset($entry['source'])
                ? PerformanceAnchorSource::from((string) $entry['source'])
                : $defaultSource;
            $confidence = is_array($entry) && isset($entry['confidence'])
                ? PerformanceAnchorConfidence::from((string) $entry['confidence'])
                : (PerformanceAnchorSource::FTP_HISTORY === $source ? PerformanceAnchorConfidence::HIGH : PerformanceAnchorConfidence::fromSampleSize($sampleSize));

            $result[$date->getTimestamp()] = PerformanceAnchor::fromState(
                setOn: $date,
                type: $type,
                value: $value,
                source: $source,
                confidence: $confidence,
                sampleSize: $sampleSize,
            );
        }

        return $result;
    }
}