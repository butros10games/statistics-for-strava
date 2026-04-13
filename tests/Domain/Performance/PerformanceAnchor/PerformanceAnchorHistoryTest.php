<?php

declare(strict_types=1);

namespace App\Tests\Domain\Performance\PerformanceAnchor;

use App\Domain\Activity\ActivityType;
use App\Domain\Ftp\FtpHistory;
use App\Domain\Performance\PerformanceAnchor\PerformanceAnchorConfidence;
use App\Domain\Performance\PerformanceAnchor\PerformanceAnchorHistory;
use App\Domain\Performance\PerformanceAnchor\PerformanceAnchorSource;
use App\Domain\Performance\PerformanceAnchor\PerformanceAnchorType;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use PHPUnit\Framework\TestCase;

final class PerformanceAnchorHistoryTest extends TestCase
{
    public function testItIsBackwardsCompatibleWithLegacyCyclingHistoryArrays(): void
    {
        $history = PerformanceAnchorHistory::fromArray(['2025-11-28' => 220]);

        self::assertSame(220.0, $history->find(PerformanceAnchorType::CYCLING_THRESHOLD_POWER, SerializableDateTime::fromString('2025-11-28'))->getValue());
        self::assertSame(PerformanceAnchorSource::FTP_HISTORY, $history->find(PerformanceAnchorType::CYCLING_THRESHOLD_POWER, SerializableDateTime::fromString('2025-11-28'))->getSource());
    }

    public function testItCanMapTypedEntriesWithMetadata(): void
    {
        $history = PerformanceAnchorHistory::fromArray([
            PerformanceAnchorType::RUNNING_THRESHOLD_POWER->value => [
                '2026-01-01' => [
                    'value' => 285,
                    'source' => PerformanceAnchorSource::BEST_EFFORTS->value,
                    'confidence' => PerformanceAnchorConfidence::MEDIUM->value,
                    'sampleSize' => 5,
                ],
            ],
        ]);

        $anchor = $history->find(PerformanceAnchorType::RUNNING_THRESHOLD_POWER, SerializableDateTime::fromString('2026-01-02'));

        self::assertSame(285.0, $anchor->getValue());
        self::assertSame(PerformanceAnchorSource::BEST_EFFORTS, $anchor->getSource());
        self::assertSame(PerformanceAnchorConfidence::MEDIUM, $anchor->getConfidence());
        self::assertSame(5, $anchor->getSampleSize());
    }

    public function testItCanBeCreatedFromFtpHistory(): void
    {
        $ftpHistory = FtpHistory::fromArray([
            'cycling' => ['2025-11-28' => 220],
            'running' => ['2025-12-01' => 260],
        ]);
        $history = PerformanceAnchorHistory::fromFtpHistory($ftpHistory);

        self::assertSame(220.0, $history->find(PerformanceAnchorType::CYCLING_THRESHOLD_POWER, SerializableDateTime::fromString('2025-11-29'))->getValue());
        self::assertSame(260.0, $history->find(PerformanceAnchorType::RUNNING_THRESHOLD_POWER, SerializableDateTime::fromString('2025-12-02'))->getValue());
    }

    public function testItExportsAnchorHistoryForAiTooling(): void
    {
        $history = PerformanceAnchorHistory::fromFtpHistory(FtpHistory::fromArray([
            'cycling' => ['2025-11-28' => 220],
        ]));

        self::assertSame([
            [
                'setOn' => '2025-11-28',
                'value' => 220.0,
                'unit' => 'W',
                'source' => 'ftp_history',
                'confidence' => 'high',
                'sampleSize' => 1,
            ],
        ], $history->exportForAITooling()[PerformanceAnchorType::CYCLING_THRESHOLD_POWER->value]);
    }

    public function testItThrowsOnInvalidDate(): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('Invalid date "YYYY-MM-DD" set for performance anchor history "cycling_threshold_power"'));

        PerformanceAnchorHistory::fromArray([
            PerformanceAnchorType::CYCLING_THRESHOLD_POWER->value => ['YYYY-MM-DD' => 220],
        ]);
    }

    public function testItThrowsWhenNoAnchorExistsForRequestedDate(): void
    {
        $this->expectExceptionMessage('Performance anchor "cycling_threshold_power" for date "2025-01-01 00:00:00" not found');

        PerformanceAnchorHistory::fromArray([
            PerformanceAnchorType::CYCLING_THRESHOLD_POWER->value => ['2025-11-28' => 220],
        ])->find(PerformanceAnchorType::CYCLING_THRESHOLD_POWER, SerializableDateTime::fromString('2025-01-01'));
    }
}