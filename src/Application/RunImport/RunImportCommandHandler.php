<?php

declare(strict_types=1);

namespace App\Application\RunImport;

use App\Application\Import\CalculateActivityMetrics\CalculateActivityMetrics;
use App\Application\Import\DeleteActivitiesMarkedForDeletion\DeleteActivitiesMarkedForDeletion;
use App\Application\Import\ImportActivities\ImportActivities;
use App\Application\Import\ImportAthlete\ImportAthlete;
use App\Application\Import\ImportChallenges\ImportChallenges;
use App\Application\Import\ImportGear\ImportGear;
use App\Application\Import\ImportSegments\ImportSegments;
use App\Application\Import\ImportWellness\ImportWellness;
use App\Application\Import\LinkCustomGearToActivities\LinkCustomGearToActivities;
use App\Application\Import\ProcessRawActivityData\ProcessRawActivityData;
use App\Domain\Activity\ActivityIds;
use App\Domain\Strava\RateLimit\StravaRateLimits;
use App\Domain\Strava\Strava;
use App\Domain\Wellness\WellnessImportConfig;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\CQRS\Command\Command;
use App\Infrastructure\CQRS\Command\CommandHandler;
use App\Infrastructure\FileSystem\PermissionChecker;
use Doctrine\DBAL\Connection;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToWriteFile;
use Symfony\Component\Console\Style\SymfonyStyle;

final readonly class RunImportCommandHandler implements CommandHandler
{
    public function __construct(
        private Strava $strava,
        private CommandBus $commandBus,
        private WellnessImportConfig $wellnessImportConfig,
        private PermissionChecker $fileSystemPermissionChecker,
        private Connection $connection,
    ) {
    }

    public function handle(Command $command): void
    {
        assert($command instanceof RunImport);

        $output = $command->getOutput();
        try {
            $this->fileSystemPermissionChecker->ensureWriteAccess();
        } catch (UnableToWriteFile|UnableToCreateDirectory) {
            $output->writeln('<error>Make sure the container has write permissions to "storage/database" and "storage/files" on the host system</error>');

            return;
        }

        foreach ($this->importStages($output, $command->getRestrictToActivityIds()) as $importStage) {
            foreach ($importStage['steps'] as $importStep) {
                $this->dispatchImportStep(
                    stageName: $importStage['name'],
                    message: $importStep['message'],
                    command: $importStep['command'],
                );
            }
        }

        if (($rateLimits = $this->strava->getRateLimit()) instanceof StravaRateLimits) {
            $output->title('STRAVA API RATE LIMITS');
            $output->listing([
                sprintf('15 min rate: %s/%s', $rateLimits->getFifteenMinRateUsage(), $rateLimits->getFifteenMinRateLimit()),
                sprintf('15 min read rate: %s/%s', $rateLimits->getFifteenMinReadRateUsage(), $rateLimits->getFifteenMinReadRateLimit()),
                sprintf('daily rate: %s/%s', $rateLimits->getDailyRateUsage(), $rateLimits->getDailyRateLimit()),
                sprintf('daily read rate: %s/%s', $rateLimits->getDailyReadRateUsage(), $rateLimits->getDailyReadRateLimit()),
            ]);
        }

        $this->vacuumDatabase();
        $output->writeln('Database got vacuumed 🧹');
    }

    /**
     * @return list<array{name: string, steps: list<array{message: string, command: Command}>}>
     */
    private function importStages(SymfonyStyle $output, ?ActivityIds $restrictToActivityIds): array
    {
        $enrichmentSteps = [
            ['message' => 'Importing segments', 'command' => new ImportSegments($output)],
            ['message' => 'Importing challenges', 'command' => new ImportChallenges($output)],
        ];

        if ($this->wellnessImportConfig->isEnabled()) {
            $enrichmentSteps[] = ['message' => 'Importing wellness', 'command' => new ImportWellness($output)];
        }

        return [
            [
                'name' => 'Core import',
                'steps' => [
                    ['message' => 'Importing athlete', 'command' => new ImportAthlete($output)],
                    [
                        'message' => 'Importing activities',
                        'command' => new ImportActivities(
                            output: $output,
                            restrictToActivityIds: $restrictToActivityIds,
                        ),
                    ],
                    [
                        'message' => 'Importing gear',
                        'command' => new ImportGear(
                            output: $output,
                            restrictToActivityIds: $restrictToActivityIds,
                        ),
                    ],
                    ['message' => 'Processing raw activity data', 'command' => new ProcessRawActivityData($output)],
                    ['message' => 'Linking custom gear', 'command' => new LinkCustomGearToActivities($output)],
                ],
            ],
            [
                'name' => 'Enrichment',
                'steps' => $enrichmentSteps,
            ],
            [
                'name' => 'Post-processing',
                'steps' => [
                    ['message' => 'Calculating activity metrics', 'command' => new CalculateActivityMetrics($output)],
                    ['message' => 'Deleting activities marked for deletion', 'command' => new DeleteActivitiesMarkedForDeletion($output)],
                ],
            ],
        ];
    }

    private function dispatchImportStep(string $stageName, string $message, Command $command): void
    {
        try {
            $this->commandBus->dispatch($command);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                sprintf('Import failed during stage "%s" while "%s" (%s).', $stageName, $message, $command::class),
                previous: $e,
            );
        }
    }

    private function vacuumDatabase(): void
    {
        try {
            $this->connection->executeStatement('VACUUM');
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Import failed during stage "Database maintenance" while "Vacuuming database".',
                previous: $e,
            );
        }
    }
}
