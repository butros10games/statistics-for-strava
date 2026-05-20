<?php

declare(strict_types=1);

namespace App\Application\RunBuild;

use App\Application\Build\BuildActivitiesHtml\BuildActivitiesHtml;
use App\Application\Build\BuildBadgeSvg\BuildBadgeSvg;
use App\Application\Build\BuildBestEffortsHtml\BuildBestEffortsHtml;
use App\Application\Build\BuildChallengesHtml\BuildChallengesHtml;
use App\Application\Build\BuildDashboardHtml\BuildDashboardHtml;
use App\Application\Build\BuildEddingtonHtml\BuildEddingtonHtml;
use App\Application\Build\BuildGearMaintenanceHtml\BuildGearMaintenanceHtml;
use App\Application\Build\BuildGearStatsHtml\BuildGearStatsHtml;
use App\Application\Build\BuildGpxFiles\BuildGpxFiles;
use App\Application\Build\BuildHeatmapHtml\BuildHeatmapHtml;
use App\Application\Build\BuildIndexHtml\BuildIndexHtml;
use App\Application\Build\BuildManifest\BuildManifest;
use App\Application\Build\BuildMilestonesHtml\BuildMilestonesHtml;
use App\Application\Build\BuildMonthlyStatsHtml\BuildMonthlyStatsHtml;
use App\Application\Build\BuildPhotosHtml\BuildPhotosHtml;
use App\Application\Build\BuildRacePlannerHtml\BuildRacePlannerHtml;
use App\Application\Build\BuildRecordingDevices\BuildRecordingDevices;
use App\Application\Build\BuildRewindHtml\BuildRewindHtml;
use App\Application\Build\BuildSegmentsHtml\BuildSegmentsHtml;
use App\Application\Build\BuildTrainingAdvisorExport\BuildTrainingAdvisorExport;
use App\Application\Build\BuildTrainingPlansHtml\BuildTrainingPlansHtml;
use App\Application\Build\ConfigureAppColors\ConfigureAppColors;
use App\Application\Build\ConfigureAppLocale\ConfigureAppLocale;
use App\Application\Import\ImportGear\GearImportStatus;
use App\Domain\Activity\ActivityIdRepository;
use App\Domain\TrainingPlanner\PlannedSessionActivityLinker;
use App\Infrastructure\Console\ProgressBar;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\CQRS\Command\Command;
use App\Infrastructure\CQRS\Command\CommandHandler;
use App\Infrastructure\Doctrine\Migrations\MigrationRunner;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final readonly class RunBuildCommandHandler implements CommandHandler
{
    public function __construct(
        private CommandBus $commandBus,
        private ActivityIdRepository $activityIdRepository,
        private GearImportStatus $gearImportStatus,
        private PlannedSessionActivityLinker $plannedSessionActivityLinker,
        private MigrationRunner $migrationRunner,
        private Clock $clock,
    ) {
    }

    public function handle(Command $command): void
    {
        assert($command instanceof RunBuild);

        $output = $command->getOutput();
        if (!$this->migrationRunner->isAtLatestVersion()) {
            $output->writeln('<error>Your database is not up to date with the migration schema. Run the import command before building the HTML files</error>');

            return;
        }
        if ($this->activityIdRepository->count() <= 0) {
            $output->writeln('<error>Wait until at least one Strava activity has been imported before building the app</error>');

            return;
        }

        if (!$this->gearImportStatus->isComplete()) {
            $output->block('[WARNING] Some of your gear hasn’t been imported yet. This is most likely due to Strava API rate limits being reached. As a result, your gear statistics may currently be incomplete.

This is not a bug, once all your activities have been imported, your gear statistics will update automatically and be complete.', null, 'fg=black;bg=yellow', ' ', true);
        }

        $now = $this->clock->getCurrentDateTimeImmutable();

        $this->plannedSessionActivityLinker->syncUpTo($now);

        $output->writeln('Building app...');
        $output->newLine();

        $buildStages = $this->buildStages($now);

        $progressBar = new ProgressBar($output, $this->countBuildSteps($buildStages));
        $progressBar->start();

        foreach ($buildStages as $buildStage) {
            foreach ($buildStage['steps'] as $buildStep) {
                $progressBar->updateMessage($buildStep['message']);
                $progressBar->advance();
                $this->dispatchBuildStep(
                    stageName: $buildStage['name'],
                    message: $buildStep['message'],
                    command: $buildStep['command'],
                );
            }
        }

        $progressBar->finish();
        $output->writeln('');
    }

    /**
     * @return list<array{name: string, steps: list<array{message: string, command: Command}>}>
     */
    private function buildStages(
        SerializableDateTime $now,
    ): array {
        return [
            [
                'name' => 'Application setup',
                'steps' => [
                    ['message' => 'Configuring locale', 'command' => new ConfigureAppLocale()],
                    ['message' => 'Configuring theme colors', 'command' => new ConfigureAppColors()],
                    ['message' => 'Building Manifest', 'command' => new BuildManifest()],
                ],
            ],
            [
                'name' => 'Core pages',
                'steps' => [
                    ['message' => 'Building index', 'command' => new BuildIndexHtml($now)],
                    ['message' => 'Building dashboard', 'command' => new BuildDashboardHtml()],
                    ['message' => 'Building activities', 'command' => new BuildActivitiesHtml($now)],
                    ['message' => 'Building gpx files', 'command' => new BuildGpxFiles()],
                    ['message' => 'Building monthly stats', 'command' => new BuildMonthlyStatsHtml($now)],
                    ['message' => 'Building gear stats', 'command' => new BuildGearStatsHtml($now)],
                    ['message' => 'Building gear maintenance', 'command' => new BuildGearMaintenanceHtml()],
                    ['message' => 'Building recording devices', 'command' => new BuildRecordingDevices()],
                    ['message' => 'Building eddington', 'command' => new BuildEddingtonHtml($now)],
                    ['message' => 'Building milestones', 'command' => new BuildMilestonesHtml()],
                    ['message' => 'Building segments', 'command' => new BuildSegmentsHtml()],
                    ['message' => 'Building heatmap', 'command' => new BuildHeatmapHtml($now)],
                    ['message' => 'Building best efforts', 'command' => new BuildBestEffortsHtml()],
                    ['message' => 'Building rewind', 'command' => new BuildRewindHtml($now)],
                    ['message' => 'Building challenges', 'command' => new BuildChallengesHtml($now)],
                    ['message' => 'Building photos', 'command' => new BuildPhotosHtml()],
                ],
            ],
            [
                'name' => 'Planning and exports',
                'steps' => [
                    ['message' => 'Building training advisor export', 'command' => new BuildTrainingAdvisorExport($now)],
                    ['message' => 'Building race planner', 'command' => new BuildRacePlannerHtml($now)],
                    ['message' => 'Building plan manager', 'command' => new BuildTrainingPlansHtml($now)],
                    ['message' => 'Building badges', 'command' => new BuildBadgeSvg($now)],
                ],
            ],
        ];
    }

    /**
     * @param list<array{name: string, steps: list<array{message: string, command: Command}>}> $buildStages
     */
    private function countBuildSteps(array $buildStages): int
    {
        $count = 0;

        foreach ($buildStages as $buildStage) {
            $count += count($buildStage['steps']);
        }

        return $count;
    }

    private function dispatchBuildStep(string $stageName, string $message, Command $command): void
    {
        try {
            $this->commandBus->dispatch($command);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                sprintf('Build failed during stage "%s" while "%s" (%s).', $stageName, $message, $command::class),
                previous: $e,
            );
        }
    }
}
