<?php

declare(strict_types=1);

namespace App\Tests\Domain\Dashboard\Widget;

use App\Domain\Dashboard\Widget\WellnessWidget;
use App\Domain\Dashboard\Widget\WidgetConfiguration;
use App\Domain\Wellness\DailyWellness;
use App\Domain\Wellness\DailyWellnessRepository;
use App\Domain\Wellness\WellnessSource;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use Spatie\Snapshots\MatchesSnapshots;

final class WellnessWidgetTest extends ContainerTestCase
{
    use MatchesSnapshots;

    private WellnessWidget $widget;
    private DailyWellnessRepository $dailyWellnessRepository;

    public function testItShouldRenderNullWithoutWellnessData(): void
    {
        $this->assertNull($this->widget->render(
            now: SerializableDateTime::fromString('2026-04-07 00:00:00'),
            configuration: WidgetConfiguration::empty(),
        ));
    }

    public function testItShouldRenderWellnessData(): void
    {
        $this->dailyWellnessRepository->upsert(DailyWellness::create(
            day: SerializableDateTime::fromString('2026-04-01 00:00:00'),
            source: WellnessSource::GARMIN,
            stepsCount: 12345,
            sleepDurationInSeconds: 27900,
            sleepScore: 81,
            hrv: 54.7,
            payload: ['source' => 'fixture'],
            importedAt: SerializableDateTime::fromString('2026-04-07 09:00:00'),
        ));
        $this->dailyWellnessRepository->upsert(DailyWellness::create(
            day: SerializableDateTime::fromString('2026-04-02 00:00:00'),
            source: WellnessSource::GARMIN,
            stepsCount: 9876,
            sleepDurationInSeconds: 25200,
            sleepScore: 75,
            hrv: 49.2,
            payload: ['source' => 'fixture'],
            importedAt: SerializableDateTime::fromString('2026-04-07 09:00:00'),
        ));

        $this->assertMatchesTextSnapshot($this->widget->render(
            now: SerializableDateTime::fromString('2026-04-07 00:00:00'),
            configuration: WidgetConfiguration::empty(),
        ));
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->widget = $this->getContainer()->get(WellnessWidget::class);
        $this->dailyWellnessRepository = $this->getContainer()->get(DailyWellnessRepository::class);
    }
}