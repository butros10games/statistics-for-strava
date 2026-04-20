<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ReactPreviewChallengesApiRequestHandler;
use App\Domain\Auth\AppUser;
use App\Domain\Auth\AppUserId;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;

final class ReactPreviewChallengesApiRequestHandlerTest extends ContainerTestCase
{
    private ReactPreviewChallengesApiRequestHandler $requestHandler;

    public function testHandleReturnsGroupedChallenges(): void
    {
        $response = $this->requestHandler->handle();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('requestedAt', $payload);
        self::assertArrayHasKey('summary', $payload);
        self::assertArrayHasKey('filters', $payload);
        self::assertArrayHasKey('groups', $payload);
        self::assertArrayHasKey('years', $payload['filters']);

        if ([] === $payload['groups']) {
            self::assertSame(0, $payload['summary']['totalChallenges']);

            return;
        }

        self::assertArrayHasKey('monthId', $payload['groups'][0]);
        self::assertArrayHasKey('monthLabel', $payload['groups'][0]);
        self::assertArrayHasKey('count', $payload['groups'][0]);
        self::assertArrayHasKey('challenges', $payload['groups'][0]);
        self::assertArrayHasKey('name', $payload['groups'][0]['challenges'][0]);
        self::assertArrayHasKey('logoUrl', $payload['groups'][0]['challenges'][0]);
        self::assertArrayHasKey('externalUrl', $payload['groups'][0]['challenges'][0]);
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->requestHandler = new ReactPreviewChallengesApiRequestHandler(
            currentAppUser: $this->buildCurrentAppUser(),
            clock: $this->getContainer()->get(Clock::class),
            challengeRepository: $this->getContainer()->get(\App\Domain\Challenge\ChallengeRepository::class),
        );
    }

    private function buildCurrentAppUser(): CurrentAppUser
    {
        $security = $this->createStub(Security::class);
        $security
            ->method('getUser')
            ->willReturn(AppUser::register(
                AppUserId::random(),
                'preview@example.com',
                'hash',
                SerializableDateTime::fromString('2026-01-01 08:00:00'),
            ));

        return new CurrentAppUser($security);
    }
}