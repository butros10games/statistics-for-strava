<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Application\Import\ImportWellness\ImportWellness;
use App\Application\RunBuild\RunBuild;
use App\Application\RunImport\RunImport;
use App\Application\UpdateData\GarminBridgeUpdater;
use App\Domain\Strava\Connection\AppUserStravaConnectionRepository;
use App\Domain\Wellness\DbalDailyWellnessRepository;
use App\Domain\Wellness\WellnessImportConfig;
use App\Domain\Wellness\WellnessSource;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\Console\ConsoleApplication;
use App\Infrastructure\Doctrine\Migrations\MigrationRunner;
use App\Infrastructure\Mutex\LockIsAlreadyAcquired;
use App\Infrastructure\Mutex\LockName;
use App\Infrastructure\Mutex\Mutex;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class ManualSyncRequestHandler
{
    public function __construct(
        private CurrentAppUser $currentAppUser,
        private AppUserStravaConnectionRepository $stravaConnectionRepository,
        private DbalDailyWellnessRepository $dailyWellnessRepository,
        private WellnessImportConfig $wellnessImportConfig,
        private MigrationRunner $migrationRunner,
        private Connection $connection,
        private Clock $clock,
        private KernelInterface $kernel,
        private CommandBus $commandBus,
        private GarminBridgeUpdater $garminBridgeUpdater,
    ) {
    }

    #[Route(path: '/account/sync/strava', name: 'app_account_sync_strava', methods: ['POST'])]
    public function syncStrava(): JsonResponse
    {
        $appUser = $this->currentAppUser->require();
        if (null === $this->stravaConnectionRepository->findByUserId($appUser->getId())) {
            return new JsonResponse([
                'message' => 'Connect Strava before starting a manual Strava sync.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->runSync(
            lockAcquiredBy: sprintf('manual-strava-sync-%s', $appUser->getId()),
            successMessage: 'Strava sync finished successfully.',
            action: function (SymfonyStyle $output): void {
                $this->commandBus->dispatch(new RunImport(
                    output: $output,
                    restrictToActivityIds: null,
                ));
                $this->commandBus->dispatch(new RunBuild(output: $output));
            },
        );
    }

    #[Route(path: '/account/sync/garmin', name: 'app_account_sync_garmin', methods: ['POST'])]
    public function syncGarmin(): JsonResponse
    {
        $appUser = $this->currentAppUser->require();

        if (!$this->wellnessImportConfig->isEnabled()) {
            return new JsonResponse([
                'message' => 'Garmin wellness sync is disabled in your app configuration.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$this->isGarminConfigured()) {
            return new JsonResponse([
                'message' => 'Configure Garmin credentials or tokens before starting a manual Garmin sync.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $response = $this->runSync(
            lockAcquiredBy: sprintf('manual-garmin-sync-%s', $appUser->getId()),
            successMessage: 'Garmin wellness sync finished successfully.',
            action: function (SymfonyStyle $output): void {
                $this->garminBridgeUpdater->update($output);
                $this->commandBus->dispatch(new ImportWellness($output));
                $this->commandBus->dispatch(new RunBuild(output: $output));
            },
        );

        if (!$response->isSuccessful()) {
            return $response;
        }

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $payload['lastImportedDay'] = $this->dailyWellnessRepository
            ->findMostRecentDayForSource(WellnessSource::GARMIN)
            ?->format('Y-m-d');

        return new JsonResponse($payload, $response->getStatusCode());
    }

    /**
     * @param \Closure(SymfonyStyle): void $action
     */
    private function runSync(string $lockAcquiredBy, string $successMessage, \Closure $action): JsonResponse
    {
        set_time_limit(0);

        $bufferedOutput = new BufferedOutput();
        $output = new SymfonyStyle(new ArrayInput([]), $bufferedOutput);
        $lockAcquired = false;
        $startedAt = microtime(true);
        $mutex = new Mutex($this->connection, $this->clock, LockName::IMPORT_DATA_OR_BUILD_APP);

        try {
            $this->initializeConsoleApplication();
            $this->migrationRunner->run($output);
            $mutex->acquireLock($lockAcquiredBy);
            $lockAcquired = true;

            $action($output);

            return new JsonResponse([
                'message' => $successMessage,
                'durationInSeconds' => round(microtime(true) - $startedAt, 1),
                'output' => $this->normalizeOutput($bufferedOutput->fetch()),
            ], Response::HTTP_OK);
        } catch (LockIsAlreadyAcquired) {
            return new JsonResponse([
                'message' => 'Another import or build is already running. Please wait for it to finish and try again.',
                'output' => $this->normalizeOutput($bufferedOutput->fetch()),
            ], Response::HTTP_CONFLICT);
        } catch (\Throwable $exception) {
            return new JsonResponse([
                'message' => $exception->getMessage(),
                'output' => $this->normalizeOutput($bufferedOutput->fetch()),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        } finally {
            if ($lockAcquired) {
                $mutex->releaseLock();
            }
        }
    }

    private function isGarminConfigured(): bool
    {
        $email = trim((string) getenv('GARMIN_EMAIL'));
        $password = trim((string) getenv('GARMIN_PASSWORD'));
        if ('' !== $email && '' !== $password) {
            return true;
        }

        if ('' !== trim((string) getenv('GARMIN_JWT_WEB'))) {
            return true;
        }

        return '' !== trim((string) getenv('GARMIN_DI_TOKEN'));
    }

    private function initializeConsoleApplication(): void
    {
        ConsoleApplication::setApplication(new Application($this->kernel));
    }

    private function normalizeOutput(string $output): string
    {
        $output = preg_replace('/\e\[[\d;]*m/', '', trim($output)) ?? trim($output);
        if ('' === $output) {
            return '';
        }

        if (mb_strlen($output) <= 12000) {
            return $output;
        }

        return "…\n".mb_substr($output, -12000);
    }
}
