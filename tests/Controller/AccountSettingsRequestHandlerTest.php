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

final class AccountSettingsRequestHandlerTest extends WebTestCase
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

    public function testDirectAccountSettingsRouteRendersStyledPage(): void
    {
        $this->createAndLoginUser();

        $this->client->request('GET', '/account/settings');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('css/dist/tailwind.min.css', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('id="js-loaded-content"', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('js/dist/app.min.js', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('<!DOCTYPE html>', (string) $this->client->getResponse()->getContent());
    }

    public function testFragmentHeaderRendersContentOnly(): void
    {
        $user = $this->createAndLoginUser();

        $this->client->request('GET', '/account/settings', server: [
            'HTTP_X_FRAGMENT_REQUEST' => '1',
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString((string) $user->getEmail(), (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('<!DOCTYPE html>', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('css/dist/tailwind.min.css', (string) $this->client->getResponse()->getContent());
    }

    private function createAndLoginUser(): AppUser
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

        return $user;
    }
}