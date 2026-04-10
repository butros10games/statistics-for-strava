<?php

declare(strict_types=1);

namespace App\Console;

use App\Application\AppUrl;
use App\Application\RunBuild\RunBuild;
use App\Application\RunImport\RunImport;
use App\Application\UpdateData\GarminBridgeUpdater;
use App\Domain\Integration\Notification\SendNotification\SendNotification;
use App\Infrastructure\Console\ProvideConsoleIntro;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\DependencyInjection\Mutex\WithMutex;
use App\Infrastructure\Doctrine\Migrations\MigrationRunner;
use App\Infrastructure\Logging\LoggableConsoleOutput;
use App\Infrastructure\Mutex\LockName;
use App\Infrastructure\Mutex\Mutex;
use App\Infrastructure\Time\ResourceUsage\ResourceUsage;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[WithMonologChannel('console-output')]
#[WithMutex(lockName: LockName::IMPORT_DATA_OR_BUILD_APP)]
#[AsCommand(
    name: 'app:update-data',
    description: 'Refresh Garmin wellness data, import Strava data, and optionally rebuild the app',
    aliases: ['app:update']
)]
final class UpdateDataConsoleCommand extends Command
{
    use ProvideConsoleIntro;

    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly ResourceUsage $resourceUsage,
        private readonly LoggerInterface $logger,
        private readonly Mutex $mutex,
        private readonly MigrationRunner $migrationRunner,
        private readonly GarminBridgeUpdater $garminBridgeUpdater,
        private readonly AppUrl $appUrl,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('skip-garmin', null, InputOption::VALUE_NONE, 'Skip the Garmin wellness bridge refresh step')
            ->addOption('skip-build', null, InputOption::VALUE_NONE, 'Skip rebuilding the static app files after importing')
            ->addOption(
                'garmin-source',
                null,
                InputOption::VALUE_REQUIRED,
                'Which Garmin bridge integration to use (givemydata or garminconnect)',
                GarminBridgeUpdater::SOURCE_GIVEMYDATA,
            )
            ->addOption('garmin-days', null, InputOption::VALUE_REQUIRED, 'Fetch only the last N Garmin days before importing')
            ->addOption('garmin-all', null, InputOption::VALUE_NONE, 'Fetch all Garmin history before importing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output = new SymfonyStyle($input, new LoggableConsoleOutput($output, $this->logger));
        $this->resourceUsage->startTimer();
        $this->outputConsoleIntro($output);

        $garminDays = $this->parseGarminDays($input->getOption('garmin-days'));
        $garminAll = (bool) $input->getOption('garmin-all');
        if ($garminAll && null !== $garminDays) {
            throw new \InvalidArgumentException('Use either --garmin-days or --garmin-all, not both.');
        }

        try {
            $this->migrationRunner->run($output);
            $this->mutex->acquireLock('UpdateDataConsoleCommand');

            if ((bool) $input->getOption('skip-garmin')) {
                $output->comment('Skipping Garmin wellness sync.');
            } else {
                $this->garminBridgeUpdater->update(
                    $output,
                    (string) $input->getOption('garmin-source'),
                    $garminDays,
                    $garminAll,
                );
            }

            $this->commandBus->dispatch(new RunImport(
                output: $output,
                restrictToActivityIds: null,
            ));

            if ((bool) $input->getOption('skip-build')) {
                $output->comment('Skipping static file build.');
            } else {
                $this->commandBus->dispatch(new RunBuild(output: $output));
            }
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage());
            throw $e;
        }

        $this->resourceUsage->stopTimer();

        if (!(bool) $input->getOption('skip-build')) {
            $this->commandBus->dispatch(new SendNotification(
                title: 'Update successful',
                message: sprintf('New data update was successful in %ss', $this->resourceUsage->getRunTimeInSeconds()),
                tags: ['+1'],
                actionUrl: $this->appUrl,
            ));
        }

        $output->writeln(sprintf(
            '<info>%s</info>',
            $this->resourceUsage->format(),
        ));

        return Command::SUCCESS;
    }

    private function parseGarminDays(mixed $garminDays): ?int
    {
        if (null === $garminDays || '' === $garminDays) {
            return null;
        }

        if (!is_scalar($garminDays) || !is_numeric((string) $garminDays)) {
            throw new \InvalidArgumentException('The --garmin-days option must be a positive integer.');
        }

        $parsed = (int) $garminDays;
        if ($parsed < 1) {
            throw new \InvalidArgumentException('The --garmin-days option must be a positive integer.');
        }

        return $parsed;
    }
}