<?php

declare(strict_types=1);

namespace App\Console;

use App\Domain\TrainingPlanner\DbalPlannedSessionRepository;
use App\Domain\TrainingPlanner\TrainingSessionLibrarySynchronizer;
use App\Infrastructure\Console\ProvideConsoleIntro;
use App\Infrastructure\ValueObject\Time\DateRange;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:training-session:backfill', description: 'Backfill the reusable training-session library from existing planned sessions')]
final class BackfillTrainingSessionLibraryConsoleCommand extends Command
{
    use ProvideConsoleIntro;

    public function __construct(
        private readonly DbalPlannedSessionRepository $plannedSessionRepository,
        private readonly TrainingSessionLibrarySynchronizer $trainingSessionLibrarySynchronizer,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->outputConsoleIntro($io);

        $earliestPlannedSession = $this->plannedSessionRepository->findEarliest();
        $latestPlannedSession = $this->plannedSessionRepository->findLatest();

        if (null === $earliestPlannedSession || null === $latestPlannedSession) {
            $io->success('No planned sessions found, so there is nothing to backfill.');

            return Command::SUCCESS;
        }

        $plannedSessions = $this->plannedSessionRepository->findByDateRange(DateRange::fromDates(
            $earliestPlannedSession->getDay()->setTime(0, 0),
            $latestPlannedSession->getDay()->setTime(23, 59, 59),
        ));

        $trainingSessionCountBefore = $this->countTrainingSessions();
        $linkedTrainingSessionCountBefore = $this->countLinkedTrainingSessions();

        $io->section('Backfilling training-session library');
        $io->progressStart(count($plannedSessions));

        foreach ($plannedSessions as $plannedSession) {
            $this->trainingSessionLibrarySynchronizer->sync($plannedSession);
            $io->progressAdvance();
        }

        $io->progressFinish();
        $io->newLine(2);

        $trainingSessionCountAfter = $this->countTrainingSessions();
        $linkedTrainingSessionCountAfter = $this->countLinkedTrainingSessions();

        $io->table(
            ['Metric', 'Value'],
            [
                ['Planned sessions processed', (string) count($plannedSessions)],
                ['Training sessions before', (string) $trainingSessionCountBefore],
                ['Training sessions after', (string) $trainingSessionCountAfter],
                ['Net change', sprintf('%+d', $trainingSessionCountAfter - $trainingSessionCountBefore)],
                ['Linked library sessions before', (string) $linkedTrainingSessionCountBefore],
                ['Linked library sessions after', (string) $linkedTrainingSessionCountAfter],
            ],
        );

        $io->success('Training-session library backfill completed.');

        return Command::SUCCESS;
    }

    private function countTrainingSessions(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM TrainingSession');
    }

    private function countLinkedTrainingSessions(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM TrainingSession WHERE sourcePlannedSessionId IS NOT NULL');
    }
}