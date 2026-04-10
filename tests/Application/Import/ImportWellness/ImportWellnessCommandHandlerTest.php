<?php

declare(strict_types=1);

namespace App\Tests\Application\Import\ImportWellness;

use App\Application\Import\ImportWellness\ImportWellness;
use App\Application\Import\ImportWellness\ImportWellnessCommandHandler;
use App\Domain\Wellness\BridgeWellnessProvider;
use App\Domain\Wellness\DailyWellnessRepository;
use App\Domain\Wellness\DbalDailyWellnessRepository;
use App\Domain\Wellness\WellnessImportConfig;
use App\Domain\Wellness\WellnessSource;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use App\Tests\Infrastructure\Time\Clock\PausedClock;
use App\Tests\SpyOutput;
use Spatie\Snapshots\MatchesSnapshots;

final class ImportWellnessCommandHandlerTest extends ContainerTestCase
{
    use MatchesSnapshots;

    private ImportWellnessCommandHandler $handler;
    private DailyWellnessRepository $repository;

    public function testHandleImportsBridgeDataIdempotently(): void
    {
        $output = new SpyOutput();

        $this->handler->handle(new ImportWellness($output));
        $this->handler->handle(new ImportWellness($output));

        $this->assertMatchesTextSnapshot($output);
        $this->assertMatchesJsonSnapshot(Json::encode($this->repository->findByDateRange(
            DateRange::fromDates(
                from: SerializableDateTime::fromString('2026-04-01 00:00:00'),
                till: SerializableDateTime::fromString('2026-04-03 00:00:00'),
            ),
            WellnessSource::GARMIN,
        )));
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new DbalDailyWellnessRepository($this->getConnection());
        $this->handler = new ImportWellnessCommandHandler(
            new BridgeWellnessProvider(
                WellnessImportConfig::create(
                    enabled: true,
                    bridgeSourcePath: 'tests/fixtures/wellness/garmin-bridge.json',
                ),
                $this->getContainer()->get(\App\Infrastructure\ValueObject\String\KernelProjectDir::class),
                PausedClock::fromString('2026-04-07 11:00:00'),
            ),
            $this->repository,
        );
    }
}