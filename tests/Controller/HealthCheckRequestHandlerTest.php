<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Infrastructure\Serialization\Json;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class HealthCheckRequestHandlerTest extends WebTestCase
{
    public function testHandleReturnsOkWithoutAuthentication(): void
    {
        $client = self::createClient();

        $client->request('GET', '/healthz');

        self::assertResponseIsSuccessful();
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        self::assertSame(['status' => 'ok'], Json::decode((string) $client->getResponse()->getContent()));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }
}
