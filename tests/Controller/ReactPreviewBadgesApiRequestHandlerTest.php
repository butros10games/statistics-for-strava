<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\AppUrl;
use App\Controller\ReactPreviewBadgesApiRequestHandler;
use App\Domain\Activity\BestEffort\BestEffortsCalculator;
use App\Domain\Auth\AppUser;
use App\Domain\Auth\AppUserId;
use App\Domain\Zwift\ZwiftLevel;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ReactPreviewBadgesApiRequestHandlerTest extends ContainerTestCase
{
    private ReactPreviewBadgesApiRequestHandler $requestHandler;

    public function testHandleReturnsBadgeSections(): void
    {
        $response = $this->requestHandler->handle();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('requestedAt', $payload);
        self::assertArrayHasKey('summary', $payload);
        self::assertArrayHasKey('sections', $payload);
        self::assertArrayHasKey('totalBadges', $payload['summary']);
        self::assertArrayHasKey('categoryCount', $payload['summary']);

        if ([] === $payload['sections']) {
            self::assertSame(0, $payload['summary']['totalBadges']);

            return;
        }

        self::assertArrayHasKey('id', $payload['sections'][0]);
        self::assertArrayHasKey('label', $payload['sections'][0]);
        self::assertArrayHasKey('badges', $payload['sections'][0]);
        self::assertArrayHasKey('name', $payload['sections'][0]['badges'][0]);
        self::assertArrayHasKey('imageUrl', $payload['sections'][0]['badges'][0]);
        self::assertArrayHasKey('embedCode', $payload['sections'][0]['badges'][0]);
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $zwiftLevel = $this->getContainer()->has(ZwiftLevel::class)
            ? $this->getContainer()->get(ZwiftLevel::class)
            : null;

        $this->requestHandler = new ReactPreviewBadgesApiRequestHandler(
            currentAppUser: $this->buildCurrentAppUser(),
            clock: $this->getContainer()->get(Clock::class),
            bestEffortsCalculator: $this->getContainer()->get(BestEffortsCalculator::class),
            appUrl: $this->getContainer()->get(AppUrl::class),
            zwiftLevel: $zwiftLevel,
            translator: $this->getContainer()->get(TranslatorInterface::class),
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