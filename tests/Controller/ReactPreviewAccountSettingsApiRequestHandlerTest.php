<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ReactPreviewAccountSettingsApiRequestHandler;
use App\Domain\Auth\AppUser;
use App\Domain\Auth\AppUserId;
use App\Domain\Strava\Connection\AppUserStravaConnection;
use App\Domain\Strava\Connection\DbalAppUserStravaConnectionRepository;
use App\Domain\Wellness\DailyWellness;
use App\Domain\Wellness\DbalDailyWellnessRepository;
use App\Domain\Wellness\WellnessImportConfig;
use App\Domain\Wellness\WellnessSource;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;

final class ReactPreviewAccountSettingsApiRequestHandlerTest extends ContainerTestCase
{
    private ReactPreviewAccountSettingsApiRequestHandler $requestHandler;
    private DbalAppUserStravaConnectionRepository $stravaConnectionRepository;
    private DbalDailyWellnessRepository $dailyWellnessRepository;
    private AppUser $appUser;

    public function testHandleReturnsAccountBootstrap(): void
    {
        putenv('GARMIN_EMAIL=athlete@example.test');
        putenv('GARMIN_PASSWORD=secret');

        $this->stravaConnectionRepository->save(AppUserStravaConnection::connect(
            appUserId: $this->appUser->getId(),
            stravaAthleteId: '123456',
            refreshToken: 'refresh-token',
            scopes: ['read', 'activity:read_all'],
            accessTokenExpiresAt: SerializableDateTime::fromString('2026-04-21 10:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-04-20 09:15:00'),
        ));
        $this->dailyWellnessRepository->upsert(DailyWellness::create(
            day: SerializableDateTime::fromString('2026-04-19 00:00:00'),
            source: WellnessSource::GARMIN,
            stepsCount: 10234,
            sleepDurationInSeconds: 28200,
            sleepScore: 81,
            hrv: 62.4,
            payload: ['source' => 'garmin-connect-bridge'],
            importedAt: SerializableDateTime::fromString('2026-04-19 08:00:00'),
        ));

        $response = $this->requestHandler->handle();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('owner@example.test', $payload['account']['email']);
        self::assertFalse($payload['account']['emailVerified']);
        self::assertTrue($payload['strava']['connected']);
        self::assertSame('123456', $payload['strava']['athleteId']);
        self::assertSame(['read', 'activity:read_all'], $payload['strava']['scopes']);
        self::assertTrue($payload['garmin']['enabled']);
        self::assertTrue($payload['garmin']['configured']);
        self::assertSame('credentials', $payload['garmin']['connectionMode']);
        self::assertSame('Email + password', $payload['garmin']['connectionModeLabel']);
        self::assertSame('2026-04-19', $payload['garmin']['lastImportedDay']);
        self::assertSame('account/sync/strava', $payload['actions']['syncStravaPath']);
        self::assertSame('react-preview/api/account-settings/strava-disconnect', $payload['actions']['disconnectStravaPath']);
    }

    public function testDisconnectStravaRemovesConnectionAndReturnsUpdatedPayload(): void
    {
        $this->stravaConnectionRepository->save(AppUserStravaConnection::connect(
            appUserId: $this->appUser->getId(),
            stravaAthleteId: '123456',
            refreshToken: 'refresh-token',
            scopes: ['read'],
            accessTokenExpiresAt: null,
            updatedAt: SerializableDateTime::fromString('2026-04-20 09:15:00'),
        ));

        $response = $this->requestHandler->disconnectStrava();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($payload['disconnectedStrava']);
        self::assertFalse($payload['strava']['connected']);
        self::assertNull($payload['strava']['athleteId']);
        self::assertNull($this->stravaConnectionRepository->findByUserId($this->appUser->getId()));
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        putenv('GARMIN_EMAIL');
        putenv('GARMIN_PASSWORD');
        putenv('GARMIN_JWT_WEB');
        putenv('GARMIN_DI_TOKEN');

        $this->appUser = AppUser::register(
            AppUserId::random(),
            'owner@example.test',
            'hash',
            SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );
        $this->stravaConnectionRepository = new DbalAppUserStravaConnectionRepository($this->getConnection());
        $this->dailyWellnessRepository = new DbalDailyWellnessRepository($this->getConnection());
        $this->requestHandler = new ReactPreviewAccountSettingsApiRequestHandler(
            currentAppUser: $this->buildCurrentAppUser(),
            stravaConnectionRepository: $this->stravaConnectionRepository,
            dailyWellnessRepository: $this->dailyWellnessRepository,
            wellnessImportConfig: WellnessImportConfig::create(true, 'storage/imports/wellness/garmin-bridge.json'),
            clock: $this->getContainer()->get(Clock::class),
        );
    }

    #[\Override]
    protected function tearDown(): void
    {
        putenv('GARMIN_EMAIL');
        putenv('GARMIN_PASSWORD');
        putenv('GARMIN_JWT_WEB');
        putenv('GARMIN_DI_TOKEN');

        parent::tearDown();
    }

    private function buildCurrentAppUser(): CurrentAppUser
    {
        $security = $this->createStub(Security::class);
        $security
            ->method('getUser')
            ->willReturn($this->appUser);

        return new CurrentAppUser($security);
    }
}
