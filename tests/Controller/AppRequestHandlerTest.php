<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\Auth\AppUser;
use App\Domain\Auth\AppUserId;
use App\Domain\Auth\AppUserRepository;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\Time\Clock\Clock;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AppRequestHandlerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        self::ensureKernelShutdown();
        $this->client = self::createClient();

        $entityManager = $this->client->getContainer()->get(EntityManagerInterface::class);
        $connection = $entityManager->getConnection();
        if ($connection->isConnected()) {
            $connection->close();
        }
        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropDatabase();
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
    }

    #[\Override]
    protected function tearDown(): void
    {
        self::ensureKernelShutdown();

        parent::tearDown();
    }

    public function testHandleRendersIndexForAuthenticatedUsersWithAthlete(): void
    {
        $container = $this->client->getContainer();
        $clock = $container->get(Clock::class);
        $userRepository = $container->get(AppUserRepository::class);
        $connection = $container->get(Connection::class);
        assert($userRepository instanceof AppUserRepository);

        $user = AppUser::register(
            appUserId: AppUserId::random(),
            email: 'owner@example.test',
            passwordHash: '$2y$13$9vAAdgrJ7ikvHq0wX1AXmO9w9cbQ3VIAtF5NCz7L3A5kZZgwyU0EO',
            createdAt: $clock->getCurrentDateTimeImmutable(),
        );
        $userRepository->save($user);

        $connection->insert('AthleteProfile', [
            'appUserId' => (string) $user->getId(),
            'payload' => Json::encode([
                'id' => 'athlete-42',
                'firstname' => 'Ada',
                'lastname' => 'Lovelace',
                'birthDate' => '1990-01-01',
            ]),
        ]);

        $this->client->loginUser($user);
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Tempo | Ada Lovelace', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('id="js-loaded-content"', (string) $this->client->getResponse()->getContent());
    }
}
