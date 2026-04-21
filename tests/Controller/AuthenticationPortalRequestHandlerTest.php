<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AuthenticationPortalRequestHandlerTest extends WebTestCase
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

    public function testLoginPageRendersReactPortal(): void
    {
        $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('window.statisticsForStravaAuth', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('"kind":"login"', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('react/dist/auth.js', (string) $this->client->getResponse()->getContent());
    }

    public function testRegisterValidationErrorsRenderThroughReactPortal(): void
    {
        $this->client->request('POST', '/register', [
            'email' => '',
            'password' => '',
            'passwordConfirmation' => '',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('"kind":"register"', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('Email and password are required.', (string) $this->client->getResponse()->getContent());
    }
}