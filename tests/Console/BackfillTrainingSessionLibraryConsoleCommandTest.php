<?php

declare(strict_types=1);

namespace App\Tests\Console;

use App\Console\BackfillTrainingSessionLibraryConsoleCommand;
use App\Domain\Activity\ActivityType;
use App\Domain\TrainingPlanner\DbalPlannedSessionRepository;
use App\Domain\TrainingPlanner\DbalTrainingSessionRepository;
use App\Domain\TrainingPlanner\PlannedSession;
use App\Domain\TrainingPlanner\PlannedSessionEstimationSource;
use App\Domain\TrainingPlanner\PlannedSessionId;
use App\Domain\TrainingPlanner\PlannedSessionIntensity;
use App\Domain\TrainingPlanner\PlannedSessionLinkStatus;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class BackfillTrainingSessionLibraryConsoleCommandTest extends ConsoleCommandTestCase
{
    private BackfillTrainingSessionLibraryConsoleCommand $command;
    private DbalPlannedSessionRepository $plannedSessionRepository;
    private DbalTrainingSessionRepository $trainingSessionRepository;

    public function testExecuteBackfillsExistingPlannedSessionsIntoTrainingSessionLibrary(): void
    {
        $this->plannedSessionRepository->upsert($this->createPlannedSession(
            plannedSessionId: PlannedSessionId::random(),
            day: '2026-04-10 00:00:00',
            title: 'Friday tempo',
            activityType: ActivityType::RUN,
            targetLoad: 63.5,
        ));
        $this->plannedSessionRepository->upsert($this->createPlannedSession(
            plannedSessionId: PlannedSessionId::random(),
            day: '2026-04-14 00:00:00',
            title: 'Sunday long run',
            activityType: ActivityType::RUN,
            targetLoad: 81.2,
        ));

        $command = $this->getCommandInApplication('app:training-session:backfill');
        $commandTester = new CommandTester($command);
        $statusCode = $commandTester->execute([
            'command' => $command->getName(),
        ]);

        $display = $commandTester->getDisplay();
        $recommendedTrainingSessions = $this->trainingSessionRepository->findRecommended(ActivityType::RUN, 10);

        self::assertSame(Command::SUCCESS, $statusCode);
        self::assertStringContainsString('Training-session library backfill completed.', $display);
        self::assertStringContainsString('Planned sessions processed', $display);
        self::assertCount(2, $recommendedTrainingSessions);
        self::assertSame('Sunday long run', $recommendedTrainingSessions[0]->getTitle());
        self::assertSame('Friday tempo', $recommendedTrainingSessions[1]->getTitle());
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->command = $this->getContainer()->get(BackfillTrainingSessionLibraryConsoleCommand::class);
        $this->plannedSessionRepository = new DbalPlannedSessionRepository($this->getConnection());
        $this->trainingSessionRepository = new DbalTrainingSessionRepository($this->getConnection());
    }

    protected function getConsoleCommand(): Command
    {
        return $this->command;
    }

    private function createPlannedSession(
        PlannedSessionId $plannedSessionId,
        string $day,
        string $title,
        ActivityType $activityType,
        float $targetLoad,
    ): PlannedSession {
        $date = SerializableDateTime::fromString($day);

        return PlannedSession::create(
            plannedSessionId: $plannedSessionId,
            day: $date,
            activityType: $activityType,
            title: $title,
            notes: 'Created before the library existed',
            targetLoad: $targetLoad,
            targetDurationInSeconds: 3600,
            targetIntensity: PlannedSessionIntensity::MODERATE,
            templateActivityId: null,
            workoutSteps: [],
            estimationSource: PlannedSessionEstimationSource::MANUAL_TARGET_LOAD,
            linkedActivityId: null,
            linkStatus: PlannedSessionLinkStatus::UNLINKED,
            createdAt: $date,
            updatedAt: $date,
        );
    }
}