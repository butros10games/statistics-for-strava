<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Domain\Auth\AppUser;
use App\Domain\Auth\AppUserId;
use App\Domain\Auth\AppUserRepository;
use App\Domain\Strava\StravaRefreshToken;
use App\Infrastructure\Time\Clock\Clock;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AuthFlowTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $container = $this->client->getContainer();

        $entityManager = $container->get(EntityManagerInterface::class);
        $connection = $entityManager->getConnection();
        if ($connection->isConnected()) {
            $connection->close();
        }
        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropDatabase();
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $connection->executeStatement('CREATE TABLE IF NOT EXISTS KeyValue (`key` VARCHAR(255) NOT NULL, `value` CLOB NOT NULL, PRIMARY KEY(`key`))');
    }

    #[\Override]
    protected function tearDown(): void
    {
        self::ensureKernelShutdown();

        parent::tearDown();
    }

    public function testAnonymousUsersAreRedirectedToLogin(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/login');
    }

    public function testAuthenticatedUserWithoutAthleteSeesSetupPage(): void
    {
        $container = $this->client->getContainer();
        $clock = $container->get(Clock::class);
        $userRepository = $container->get(AppUserRepository::class);
        assert($userRepository instanceof AppUserRepository);

        $user = AppUser::register(
            appUserId: AppUserId::random(),
            email: 'owner@example.test',
            passwordHash: '$2y$13$9vAAdgrJ7ikvHq0wX1AXmO9w9cbQ3VIAtF5NCz7L3A5kZZgwyU0EO',
            createdAt: $clock->getCurrentDateTimeImmutable(),
        );
        $userRepository->save($user);

        $this->client->loginUser($user);
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Finish account setup', (string) $this->client->getResponse()->getContent());
    }

    public function testFirstRegisteredUserClaimsLegacySingleUserData(): void
    {
        $container = $this->client->getContainer();
        $connection = $container->get(Connection::class);
        $refreshToken = $container->get(StravaRefreshToken::class);
        assert($connection instanceof Connection);
        assert($refreshToken instanceof StravaRefreshToken);

        $legacyAthletePayload = json_encode([
            'id' => 'legacy-athlete-1',
            'firstname' => 'Butros',
            'lastname' => 'Groot',
            'sex' => 'M',
            'birthDate' => '1990-01-01 00:00:00',
        ], JSON_THROW_ON_ERROR);

        $connection->insert('KeyValue', [
            'key' => 'athlete',
            'value' => $legacyAthletePayload,
        ]);

        $now = '2026-04-19 12:00:00';
        $connection->insert('TrainingPlan', [
            'trainingPlanId' => 'legacy-plan',
            'ownerUserId' => null,
            'type' => 'race',
            'startDay' => $now,
            'endDay' => $now,
            'targetRaceEventId' => null,
            'title' => 'Legacy plan',
            'notes' => null,
            'discipline' => null,
            'sportSchedule' => null,
            'performanceMetrics' => null,
            'targetRaceProfile' => null,
            'trainingFocus' => null,
            'trainingBlockStyle' => null,
            'runningWorkoutTargetMode' => null,
            'runHillSessionsEnabled' => 0,
            'visibility' => 'friends',
            'createdAt' => $now,
            'updatedAt' => $now,
        ]);
        $connection->insert('TrainingBlock', [
            'trainingBlockId' => 'legacy-block',
            'ownerUserId' => null,
            'startDay' => $now,
            'endDay' => $now,
            'targetRaceEventId' => null,
            'phase' => 'base',
            'title' => 'Legacy block',
            'focus' => null,
            'notes' => null,
            'createdAt' => $now,
            'updatedAt' => $now,
        ]);
        $connection->insert('PlannedSession', [
            'plannedSessionId' => 'legacy-session',
            'ownerUserId' => null,
            'day' => $now,
            'activityType' => 'Run',
            'title' => 'Legacy session',
            'notes' => null,
            'targetLoad' => null,
            'targetDurationInSeconds' => null,
            'targetIntensity' => null,
            'templateActivityId' => null,
            'workoutSteps' => null,
            'estimationSource' => 'unknown',
            'linkedActivityId' => null,
            'linkStatus' => 'unlinked',
            'createdAt' => $now,
            'updatedAt' => $now,
        ]);
        $connection->insert('RaceEvent', [
            'raceEventId' => 'legacy-race',
            'ownerUserId' => null,
            'day' => $now,
            'type' => 'run10k',
            'family' => 'run',
            'profile' => 'run10k',
            'title' => 'Legacy race',
            'location' => null,
            'notes' => null,
            'priority' => 'a',
            'targetFinishTimeInSeconds' => null,
            'createdAt' => $now,
            'updatedAt' => $now,
        ]);

        $this->client->request('POST', '/register', [
            'email' => 'butrosgroot@gmail.com',
            'password' => 'secret123',
            'passwordConfirmation' => 'secret123',
        ]);

        self::assertResponseRedirects('/login?registered=1');

        $userRepository = $container->get(AppUserRepository::class);
        assert($userRepository instanceof AppUserRepository);
        $user = $userRepository->findByEmail('butrosgroot@gmail.com');

        self::assertInstanceOf(AppUser::class, $user);

        $appUserId = (string) $user->getId();

        self::assertSame($appUserId, $connection->fetchOne('SELECT ownerUserId FROM TrainingPlan WHERE trainingPlanId = ?', ['legacy-plan']));
        self::assertSame($appUserId, $connection->fetchOne('SELECT ownerUserId FROM TrainingBlock WHERE trainingBlockId = ?', ['legacy-block']));
        self::assertSame($appUserId, $connection->fetchOne('SELECT ownerUserId FROM PlannedSession WHERE plannedSessionId = ?', ['legacy-session']));
        self::assertSame($appUserId, $connection->fetchOne('SELECT ownerUserId FROM RaceEvent WHERE raceEventId = ?', ['legacy-race']));
        self::assertSame($legacyAthletePayload, $connection->fetchOne('SELECT payload FROM AthleteProfile WHERE appUserId = ?', [$appUserId]));
        self::assertSame('legacy-athlete-1', $connection->fetchOne('SELECT stravaAthleteId FROM AppUserStravaConnection WHERE appUserId = ?', [$appUserId]));
        self::assertSame((string) $refreshToken, $connection->fetchOne('SELECT refreshToken FROM AppUserStravaConnection WHERE appUserId = ?', [$appUserId]));
    }
}
