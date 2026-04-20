<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ReactPreviewRewindApiRequestHandler;
use App\Domain\Auth\AppUser;
use App\Domain\Auth\AppUserId;
use App\Infrastructure\CQRS\Query\Bus\QueryBus;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\Twig\UrlTwigExtension;
use App\Infrastructure\User\CurrentAppUser;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Infrastructure\ValueObject\Measurement\UnitSystem;
use App\Tests\ContainerTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ReactPreviewRewindApiRequestHandlerTest extends ContainerTestCase
{
    private ReactPreviewRewindApiRequestHandler $requestHandler;

    public function testHandleReturnsSelectedOptionAndCards(): void
    {
        $response = $this->requestHandler->handle(new Request(query: ['option' => 'all-time']));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('requestedAt', $payload);
        self::assertArrayHasKey('summary', $payload);
        self::assertArrayHasKey('options', $payload);
        self::assertArrayHasKey('selectedOption', $payload);
        self::assertArrayHasKey('items', $payload);
        self::assertArrayHasKey('optionCount', $payload['summary']);
        self::assertArrayHasKey('comparisonAvailable', $payload['summary']);
        self::assertArrayHasKey('value', $payload['selectedOption']);
        self::assertArrayHasKey('totalActivities', $payload['selectedOption']);

        if ([] === $payload['items']) {
            self::assertSame(0, $payload['selectedOption']['totalActivities']);

            return;
        }

        self::assertArrayHasKey('id', $payload['items'][0]);
        self::assertArrayHasKey('kind', $payload['items'][0]);
        self::assertArrayHasKey('title', $payload['items'][0]);
        self::assertArrayHasKey('icon', $payload['items'][0]);
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->requestHandler = new ReactPreviewRewindApiRequestHandler(
            currentAppUser: $this->buildCurrentAppUser(),
            clock: $this->getContainer()->get(Clock::class),
            queryBus: $this->getContainer()->get(QueryBus::class),
            gearRepository: $this->getContainer()->get(\App\Domain\Gear\GearRepository::class),
            imageRepository: $this->getContainer()->get(\App\Domain\Activity\Image\ImageRepository::class),
            enrichedActivities: $this->getContainer()->get(\App\Domain\Activity\EnrichedActivities::class),
            unitSystem: $this->getContainer()->get(UnitSystem::class),
            urlTwigExtension: $this->getContainer()->get(UrlTwigExtension::class),
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