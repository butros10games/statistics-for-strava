<?php

declare(strict_types=1);

namespace App\Tests\Console;

use App\Console\BacktestTrainingModelsConsoleCommand;
use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityWithRawData;
use App\Domain\Activity\SportType\SportType;
use App\Domain\Athlete\Athlete;
use App\Domain\Athlete\AthleteRepository;
use App\Domain\TrainingPlanner\PlannedSession;
use App\Domain\TrainingPlanner\PlannedSessionEstimationSource;
use App\Domain\TrainingPlanner\PlannedSessionId;
use App\Domain\TrainingPlanner\PlannedSessionIntensity;
use App\Domain\TrainingPlanner\PlannedSessionLinkStatus;
use App\Domain\TrainingPlanner\PlannedSessionRepository;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\Domain\Activity\ActivityBuilder;
use App\Tests\Infrastructure\Time\Clock\PausedClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class BacktestTrainingModelsConsoleCommandTest extends ConsoleCommandTestCase
{
    private BacktestTrainingModelsConsoleCommand $command;

    public function testExecuteIncludesPlannerForecastBenchmarkWhenHistoricalWindowsExist(): void
    {
        $this->seedAthlete();
        $this->seedActivities();
        $this->seedPlannedSessions();

        $command = $this->getCommandInApplication('app:training:backtest');
        $commandTester = new CommandTester($command);

        $statusCode = $commandTester->execute([
            'command' => $command->getName(),
        ]);

        $display = $commandTester->getDisplay();

        self::assertSame(Command::SUCCESS, $statusCode);
        self::assertStringContainsString('Planner forecast backtest', $display);
        self::assertStringContainsString('Historical forecast cutoffs evaluated', $display);
        self::assertStringContainsString('Reconstructable forecast windows', $display);
        self::assertStringContainsString('2023-07-22', $display);
        self::assertStringNotContainsString('No reconstructable 7-day planner forecast windows could be built from the current data yet.', $display);
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->command = new BacktestTrainingModelsConsoleCommand(
            connection: $this->getConnection(),
            activityRepository: $this->getContainer()->get(ActivityRepository::class),
            athleteRepository: $this->getContainer()->get(AthleteRepository::class),
            performanceAnchorHistory: $this->getContainer()->get(\App\Domain\Performance\PerformanceAnchor\PerformanceAnchorHistory::class),
            plannedSessionRepository: $this->getContainer()->get(PlannedSessionRepository::class),
            dailyTrainingLoad: $this->getContainer()->get(\App\Domain\Activity\DailyTrainingLoad::class),
            integratedDailyLoadCalculator: $this->getContainer()->get(\App\Domain\Dashboard\Widget\TrainingLoad\IntegratedDailyLoadCalculator::class),
            dailyWellnessRepository: $this->getContainer()->get(\App\Domain\Wellness\DailyWellnessRepository::class),
            dailyRecoveryCheckInRepository: $this->getContainer()->get(\App\Domain\Wellness\DailyRecoveryCheckInRepository::class),
            wellnessReadinessCalculator: $this->getContainer()->get(\App\Domain\Dashboard\Widget\TrainingLoad\WellnessReadinessCalculator::class),
            clock: PausedClock::fromString('2023-08-10 09:00:00'),
        );
    }

    protected function getConsoleCommand(): Command
    {
        return $this->command;
    }

    private function seedAthlete(): void
    {
        $this->getContainer()->get(AthleteRepository::class)->save(Athlete::create([
            'id' => 100,
            'birthDate' => '1989-08-14',
            'firstname' => 'Test',
            'lastname' => 'Athlete',
            'sex' => 'M',
        ]));
    }

    private function seedActivities(): void
    {
        /** @var ActivityRepository $activityRepository */
        $activityRepository = $this->getContainer()->get(ActivityRepository::class);

        $activities = [
            ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed('910001'))
                ->withName('Historical ride 1')
                ->withSportType(SportType::RIDE)
                ->withStartDateTime(SerializableDateTime::fromString('2023-07-18 08:00:00'))
                ->withMovingTimeInSeconds(3600)
                ->withAverageHeartRate(138)
                ->build(),
            ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed('910002'))
                ->withName('Historical ride 2')
                ->withSportType(SportType::RIDE)
                ->withStartDateTime(SerializableDateTime::fromString('2023-07-20 08:00:00'))
                ->withMovingTimeInSeconds(5400)
                ->withAverageHeartRate(146)
                ->build(),
            ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed('910003'))
                ->withName('Actual ride 1')
                ->withSportType(SportType::RIDE)
                ->withStartDateTime(SerializableDateTime::fromString('2023-07-28 08:00:00'))
                ->withMovingTimeInSeconds(4500)
                ->withAverageHeartRate(144)
                ->build(),
            ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed('910004'))
                ->withName('Actual ride 2')
                ->withSportType(SportType::RIDE)
                ->withStartDateTime(SerializableDateTime::fromString('2023-07-29 08:00:00'))
                ->withMovingTimeInSeconds(7200)
                ->withAverageHeartRate(151)
                ->build(),
        ];

        foreach ($activities as $activity) {
            $activityRepository->add(ActivityWithRawData::fromState(
                activity: $activity,
                rawData: [],
            ));
        }
    }

    private function seedPlannedSessions(): void
    {
        /** @var PlannedSessionRepository $plannedSessionRepository */
        $plannedSessionRepository = $this->getContainer()->get(PlannedSessionRepository::class);

        $plannedSessionRepository->upsert($this->createPlannedSession(
            day: '2023-07-28',
            durationInSeconds: 3600,
            intensity: PlannedSessionIntensity::MODERATE,
            createdAt: '2023-07-20 07:30:00',
            updatedAt: '2023-07-20 07:30:00',
        ));
        $plannedSessionRepository->upsert($this->createPlannedSession(
            day: '2023-07-29',
            durationInSeconds: 5400,
            intensity: PlannedSessionIntensity::HARD,
            createdAt: '2023-07-20 07:45:00',
            updatedAt: '2023-07-20 07:45:00',
        ));
        $plannedSessionRepository->upsert($this->createPlannedSession(
            day: '2023-07-30',
            durationInSeconds: 3600,
            intensity: PlannedSessionIntensity::EASY,
            createdAt: '2023-07-20 08:00:00',
            updatedAt: '2023-07-25 08:00:00',
        ));
    }

    private function createPlannedSession(
        string $day,
        int $durationInSeconds,
        PlannedSessionIntensity $intensity,
        string $createdAt,
        string $updatedAt,
    ): PlannedSession {
        return PlannedSession::create(
            plannedSessionId: PlannedSessionId::random(),
            day: SerializableDateTime::fromString($day),
            activityType: \App\Domain\Activity\ActivityType::RIDE,
            title: 'Benchmark session',
            notes: null,
            targetLoad: null,
            targetDurationInSeconds: $durationInSeconds,
            targetIntensity: $intensity,
            templateActivityId: null,
            estimationSource: PlannedSessionEstimationSource::UNKNOWN,
            linkedActivityId: null,
            linkStatus: PlannedSessionLinkStatus::UNLINKED,
            createdAt: SerializableDateTime::fromString($createdAt),
            updatedAt: SerializableDateTime::fromString($updatedAt),
        );
    }
}
