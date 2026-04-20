<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Build\BuildHeatmapHtml\HeatmapConfig;
use App\Application\Countries;
use App\Controller\ReactPreviewHeatmapApiRequestHandler;
use App\Domain\Auth\AppUser;
use App\Domain\Auth\AppUserId;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\Time\Format\DateAndTimeFormat;
use App\Infrastructure\Twig\UrlTwigExtension;
use App\Infrastructure\User\CurrentAppUser;
use App\Infrastructure\ValueObject\Measurement\UnitSystem;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ReactPreviewHeatmapApiRequestHandlerTest extends ContainerTestCase
{
    private ReactPreviewHeatmapApiRequestHandler $requestHandler;

    public function testHandleReturnsRoutesFiltersAndConfig(): void
    {
        $response = $this->requestHandler->handle();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('requestedAt', $payload);
        self::assertArrayHasKey('summary', $payload);
        self::assertArrayHasKey('config', $payload);
        self::assertArrayHasKey('filters', $payload);
        self::assertArrayHasKey('places', $payload);
        self::assertArrayHasKey('routes', $payload);
        self::assertArrayHasKey('sportTypes', $payload['filters']);
        self::assertArrayHasKey('workoutTypes', $payload['filters']);
        self::assertArrayHasKey('polylineColor', $payload['config']);
        self::assertArrayHasKey('tileLayerUrls', $payload['config']);

        if ([] === $payload['routes']) {
            self::assertSame(0, $payload['summary']['totalRoutes']);

            return;
        }

        self::assertArrayHasKey('name', $payload['routes'][0]);
        self::assertArrayHasKey('distance', $payload['routes'][0]);
        self::assertArrayHasKey('coordinates', $payload['routes'][0]);
        self::assertArrayHasKey('sportType', $payload['routes'][0]);
        self::assertArrayHasKey('startLocation', $payload['routes'][0]);
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->requestHandler = new ReactPreviewHeatmapApiRequestHandler(
            currentAppUser: $this->buildCurrentAppUser(),
            clock: $this->getContainer()->get(Clock::class),
            routeRepository: $this->getContainer()->get(\App\Domain\Activity\Route\RouteRepository::class),
            sportTypeRepository: $this->getContainer()->get(\App\Domain\Activity\SportType\SportTypeRepository::class),
            countries: $this->getContainer()->get(Countries::class),
            heatmapConfig: $this->getContainer()->get(HeatmapConfig::class),
            unitSystem: $this->getContainer()->get(UnitSystem::class),
            dateAndTimeFormat: $this->getContainer()->get(DateAndTimeFormat::class),
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