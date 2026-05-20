<?php

namespace App\Tests\Application\RunImport;

use App\Application\Import\ImportSegments\ImportSegments;
use App\Application\RunImport\RunImport;
use App\Application\RunImport\RunImportCommandHandler;
use App\Domain\Strava\Strava;
use App\Domain\Wellness\WellnessImportConfig;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\CQRS\Command\Command;
use App\Infrastructure\Serialization\Json;
use App\Tests\ContainerTestCase;
use App\Tests\Infrastructure\CQRS\Command\Bus\SpyCommandBus;
use App\Tests\Infrastructure\FileSystem\SuccessfulPermissionChecker;
use App\Tests\Infrastructure\FileSystem\UnwritablePermissionChecker;
use App\Tests\SpyOutput;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use Spatie\Snapshots\MatchesSnapshots;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Style\SymfonyStyle;

class RunImportCommandHandlerTest extends ContainerTestCase
{
    use MatchesSnapshots;

    private RunImportCommandHandler $importStravaDataCommandHandler;
    private CommandBus $commandBus;
    private MockObject $connection;

    public function testHandle(): void
    {
        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with('VACUUM');

        $output = new SpyOutput();
        $this->importStravaDataCommandHandler->handle(new RunImport(
            output: new SymfonyStyle(new StringInput('input'), $output),
            restrictToActivityIds: null,
        ));
        $this->assertMatchesTextSnapshot(str_replace(' ', '', $output));
        $this->assertMatchesJsonSnapshot(Json::encode($this->commandBus->getDispatchedCommands()));
    }

    public function testHandleWithWellnessImportEnabled(): void
    {
        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with('VACUUM');

        $this->importStravaDataCommandHandler = new RunImportCommandHandler(
            $this->getContainer()->get(Strava::class),
            $this->commandBus = new SpyCommandBus(),
            WellnessImportConfig::create(true, 'tests/fixtures/wellness/garmin-bridge.json'),
            new SuccessfulPermissionChecker(),
            $this->connection,
        );

        $output = new SpyOutput();
        $this->importStravaDataCommandHandler->handle(new RunImport(
            output: new SymfonyStyle(new StringInput('input'), $output),
            restrictToActivityIds: null,
        ));

        $this->assertMatchesJsonSnapshot(Json::encode($this->commandBus->getDispatchedCommands()));
    }

    public function testHandleWithInsufficientPermissions(): void
    {
        $this->connection
            ->expects($this->never())
            ->method('executeStatement');

        $this->importStravaDataCommandHandler = new RunImportCommandHandler(
            $this->getContainer()->get(Strava::class),
            $this->commandBus = new SpyCommandBus(),
            WellnessImportConfig::create(false, 'storage/imports/wellness/garmin-bridge.json'),
            new UnwritablePermissionChecker(),
            $this->connection,
        );

        $output = new SpyOutput();
        $this->importStravaDataCommandHandler->handle(new RunImport(
            output: new SymfonyStyle(new StringInput('input'), $output),
            restrictToActivityIds: null,
        ));
        $this->assertMatchesTextSnapshot(str_replace(' ', '', $output));
    }

    public function testHandleWrapsImportStepFailuresWithStageContext(): void
    {
        $this->connection
            ->expects($this->never())
            ->method('executeStatement');

        $handler = new RunImportCommandHandler(
            $this->getContainer()->get(Strava::class),
            new class() implements CommandBus {
                public function dispatch(Command $command): void
                {
                    if ($command instanceof ImportSegments) {
                        throw new \RuntimeException('OH NO ERROR');
                    }
                }
            },
            WellnessImportConfig::create(false, 'storage/imports/wellness/garmin-bridge.json'),
            new SuccessfulPermissionChecker(),
            $this->connection,
        );

        $output = new SpyOutput();

        try {
            $handler->handle(new RunImport(
                output: new SymfonyStyle(new StringInput('input'), $output),
                restrictToActivityIds: null,
            ));
            $this->fail('Expected import step failure to be wrapped with stage context.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                sprintf('Import failed during stage "Enrichment" while "Importing segments" (%s).', ImportSegments::class),
                $exception->getMessage(),
            );
            $this->assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
            $this->assertSame('OH NO ERROR', $exception->getPrevious()?->getMessage());
        }
    }

    #[\Override]
    protected function setUp(): void
    {
        $this->importStravaDataCommandHandler = new RunImportCommandHandler(
            $this->getContainer()->get(Strava::class),
            $this->commandBus = new SpyCommandBus(),
            WellnessImportConfig::create(false, 'storage/imports/wellness/garmin-bridge.json'),
            new SuccessfulPermissionChecker(),
            $this->connection = $this->createMock(Connection::class),
        );
    }
}
