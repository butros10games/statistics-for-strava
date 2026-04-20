<?php

declare(strict_types=1);

namespace App\Tests\Console;

use App\Application\AppUrl;
use App\Application\RunBuild\RunBuild;
use App\Application\RunImport\RunImport;
use App\Application\UpdateData\GarminBridgeUpdater;
use App\Console\UpdateDataConsoleCommand;
use App\Domain\Integration\Notification\SendNotification\SendNotification;
use App\Domain\Wellness\WellnessImportConfig;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\CQRS\Command\DomainCommand;
use App\Infrastructure\Doctrine\Migrations\MigrationRunner;
use App\Infrastructure\Mutex\LockName;
use App\Infrastructure\Mutex\Mutex;
use App\Infrastructure\Process\ProcessFactory;
use App\Infrastructure\Time\ResourceUsage\ResourceUsage;
use App\Infrastructure\ValueObject\String\KernelProjectDir;
use App\Tests\Infrastructure\Time\Clock\PausedClock;
use App\Tests\Infrastructure\Time\ResourceUsage\FixedResourceUsage;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

class UpdateDataConsoleCommandTest extends ConsoleCommandTestCase
{
    private UpdateDataConsoleCommand $updateDataConsoleCommand;
    private MockObject $commandBus;
    private ResourceUsage $resourceUsage;
    private MockObject $logger;
    private MockObject $migrationRunner;
    private MockObject $processFactory;

    public function testExecute(): void
    {
        $process = $this->createConfiguredMock(Process::class, [
            'getOutput' => 'Garmin bridge updated.',
            'getErrorOutput' => '',
            'isSuccessful' => true,
        ]);
        $process->expects(self::once())->method('setWorkingDirectory')->with('/var/www')->willReturnSelf();
        $process->expects(self::once())->method('setTimeout')->with(null)->willReturnSelf();
        $process->expects(self::once())->method('run');

        $this->processFactory
            ->expects(self::once())
            ->method('create')
            ->with(['uv', 'run', 'tools/garmin_givemydata_bridge.py'])
            ->willReturn($process);

        $dispatchedCommands = [];
        $this->commandBus
            ->expects(self::exactly(3))
            ->method('dispatch')
            ->willReturnCallback(function (DomainCommand $command) use (&$dispatchedCommands): void {
                $dispatchedCommands[] = $command;
            });

        $this->logger
            ->expects(self::atLeastOnce())
            ->method('info');

        $this->migrationRunner
            ->expects(self::once())
            ->method('run');

        $command = $this->getCommandInApplication('app:update-data');
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'command' => $command->getName(),
        ]);

        $display = $commandTester->getDisplay();
        self::assertStringContainsString('Refreshing Garmin wellness bridge (givemydata)', $display);
        self::assertStringContainsString('Garmin bridge updated.', $display);
        self::assertStringContainsString('Tempo', $display);
        self::assertCount(3, $dispatchedCommands);
        self::assertInstanceOf(RunImport::class, $dispatchedCommands[0]);
        self::assertInstanceOf(RunBuild::class, $dispatchedCommands[1]);
        self::assertInstanceOf(SendNotification::class, $dispatchedCommands[2]);
    }

    public function testExecuteWithSkipOptions(): void
    {
        $this->processFactory
            ->expects(self::never())
            ->method('create');

        $dispatchedCommands = [];
        $this->commandBus
            ->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(function (DomainCommand $command) use (&$dispatchedCommands): void {
                $dispatchedCommands[] = $command;
            });

        $this->logger
            ->expects(self::atLeastOnce())
            ->method('info');

        $this->migrationRunner
            ->expects(self::once())
            ->method('run');

        $command = $this->getCommandInApplication('app:update-data');
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'command' => $command->getName(),
            '--skip-garmin' => true,
            '--skip-build' => true,
        ]);

        $display = $commandTester->getDisplay();
        self::assertStringContainsString('Skipping Garmin wellness sync.', $display);
        self::assertStringContainsString('Skipping static file build.', $display);
        self::assertCount(1, $dispatchedCommands);
        self::assertInstanceOf(RunImport::class, $dispatchedCommands[0]);
    }

    public function testExecuteWithGivemydataBridgeAndDays(): void
    {
        $process = $this->createConfiguredMock(Process::class, [
            'getOutput' => '',
            'getErrorOutput' => '',
            'isSuccessful' => true,
        ]);
        $process->method('setWorkingDirectory')->willReturnSelf();
        $process->method('setTimeout')->willReturnSelf();
        $process->expects(self::once())->method('run');

        $this->processFactory
            ->expects(self::once())
            ->method('create')
            ->with(['uv', 'run', 'tools/garmin_givemydata_bridge.py', '--days', '14'])
            ->willReturn($process);

        $this->commandBus
            ->expects(self::exactly(3))
            ->method('dispatch');

        $this->logger
            ->expects(self::atLeastOnce())
            ->method('info');

        $this->migrationRunner
            ->expects(self::once())
            ->method('run');

        $command = $this->getCommandInApplication('app:update-data');
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'command' => $command->getName(),
            '--garmin-source' => GarminBridgeUpdater::SOURCE_GIVEMYDATA,
            '--garmin-days' => '14',
        ]);
    }

    public function testExecuteWhenGarminBridgeFails(): void
    {
        $process = $this->createConfiguredMock(Process::class, [
            'getOutput' => 'Bridge output',
            'getErrorOutput' => 'Bridge failed',
            'isSuccessful' => false,
        ]);
        $process->method('setWorkingDirectory')->willReturnSelf();
        $process->method('setTimeout')->willReturnSelf();
        $process->expects(self::once())->method('run');

        $this->processFactory
            ->expects(self::once())
            ->method('create')
            ->with(['uv', 'run', 'tools/garmin_givemydata_bridge.py'])
            ->willReturn($process);

        $this->commandBus
            ->expects(self::never())
            ->method('dispatch');

        $this->logger
            ->expects(self::once())
            ->method('error')
            ->with('Bridge failed');

        $this->migrationRunner
            ->expects(self::once())
            ->method('run');

        $this->expectExceptionObject(new \RuntimeException('Bridge failed'));

        $command = $this->getCommandInApplication('app:update-data');
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'command' => $command->getName(),
        ]);
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->updateDataConsoleCommand = new UpdateDataConsoleCommand(
            $this->commandBus = $this->createMock(CommandBus::class),
            $this->resourceUsage = new FixedResourceUsage(),
            $this->logger = $this->createMock(LoggerInterface::class),
            new Mutex(
                connection: $this->getConnection(),
                clock: PausedClock::fromString('2025-12-04'),
                lockName: LockName::IMPORT_DATA_OR_BUILD_APP,
            ),
            $this->migrationRunner = $this->createMock(MigrationRunner::class),
            new GarminBridgeUpdater(
                WellnessImportConfig::create(true, 'storage/imports/wellness/garmin-bridge.json'),
                $this->processFactory = $this->createMock(ProcessFactory::class),
                KernelProjectDir::fromString('/var/www'),
            ),
            AppUrl::fromString('https://localhost'),
        );
    }

    protected function getConsoleCommand(): Command
    {
        return $this->updateDataConsoleCommand;
    }
}