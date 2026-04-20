<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Build\BuildPhotosHtml\DefaultEnabledPhotoFilters;
use App\Application\Countries;
use App\Controller\ReactPreviewPhotosApiRequestHandler;
use App\Domain\Auth\AppUser;
use App\Domain\Auth\AppUserId;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ReactPreviewPhotosApiRequestHandlerTest extends ContainerTestCase
{
    private ReactPreviewPhotosApiRequestHandler $requestHandler;

    public function testHandleReturnsPhotoRowsAndFilterMetadata(): void
    {
        $response = $this->requestHandler->handle();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('requestedAt', $payload);
        self::assertArrayHasKey('summary', $payload);
        self::assertArrayHasKey('filters', $payload);
        self::assertArrayHasKey('defaultEnabledFilters', $payload);
        self::assertArrayHasKey('images', $payload);
        self::assertArrayHasKey('sportTypes', $payload['filters']);
        self::assertArrayHasKey('countries', $payload['filters']);
        self::assertArrayHasKey('sportTypes', $payload['defaultEnabledFilters']);
        self::assertArrayHasKey('countryCode', $payload['defaultEnabledFilters']);

        if ([] === $payload['images']) {
            self::assertSame(0, $payload['summary']['totalImages']);

            return;
        }

        self::assertArrayHasKey('imageUrl', $payload['images'][0]);
        self::assertArrayHasKey('activityName', $payload['images'][0]);
        self::assertArrayHasKey('sportType', $payload['images'][0]);
        self::assertArrayHasKey('orientation', $payload['images'][0]);
        self::assertArrayHasKey('countries', $payload['images'][0]);
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->requestHandler = new ReactPreviewPhotosApiRequestHandler(
            currentAppUser: $this->buildCurrentAppUser(),
            clock: $this->getContainer()->get(Clock::class),
            imageRepository: $this->getContainer()->get(\App\Domain\Activity\Image\ImageRepository::class),
            sportTypeRepository: $this->getContainer()->get(\App\Domain\Activity\SportType\SportTypeRepository::class),
            countries: $this->getContainer()->get(Countries::class),
            defaultEnabledPhotoFilters: $this->getContainer()->get(DefaultEnabledPhotoFilters::class),
            enrichedActivities: $this->getContainer()->get(\App\Domain\Activity\EnrichedActivities::class),
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