<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\Auth\AppUser;
use App\Domain\Auth\AppUserId;
use App\Domain\Auth\AppUserRepository;
use App\Infrastructure\Time\Clock\Clock;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LegacyHtmlRedirectRequestHandlerTest extends WebTestCase
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

        $this->createAndLoginUser();
    }

    #[\Override]
    protected function tearDown(): void
    {
        self::ensureKernelShutdown();

        parent::tearDown();
    }

    public function testRacePlannerHtmlRedirectsToReactRoute(): void
    {
        $this->client->request('GET', '/race-planner.html');

        self::assertResponseRedirects('/race-planner');
    }

    public function testRacePlannerPlanHtmlRedirectsToReactRoute(): void
    {
        $this->client->request('GET', '/race-planner/plan-plan-123.html');

        self::assertResponseRedirects('/race-planner/plan/plan-123');
    }

    public function testTrainingPlansHtmlRedirectsToReactRoute(): void
    {
        $this->client->request('GET', '/training-plans.html');

        self::assertResponseRedirects('/training-plans');
    }

    private function createAndLoginUser(): void
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
    }
}