<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ReactPreviewGearApiRequestHandler;
use App\Domain\Auth\AppUser;
use App\Domain\Auth\AppUserId;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ReactPreviewGearApiRequestHandlerTest extends ContainerTestCase
{
    private ReactPreviewGearApiRequestHandler $requestHandler;

    public function testHandleReturnsGearPreviewPayload(): void
    {
        $response = $this->requestHandler->handle();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('requestedAt', $payload);
        self::assertArrayHasKey('unitSystem', $payload);
        self::assertArrayHasKey('summary', $payload);
        self::assertArrayHasKey('activeGear', $payload);
        self::assertArrayHasKey('retiredGear', $payload);
        self::assertArrayHasKey('charts', $payload);
        self::assertArrayHasKey('series', $payload['charts']['distancePerMonthPerGear']);
        self::assertArrayHasKey('series', $payload['charts']['distanceOverTimePerGear']);
    }

    public function testHandleReturnsSerializableGearRowsWhenAvailable(): void
    {
        $response = $this->requestHandler->handle();
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $rows = [...$payload['activeGear'], ...$payload['retiredGear']];

        if ([] === $rows) {
            self::assertSame([], $rows);

            return;
        }

        self::assertArrayHasKey('name', $rows[0]);
        self::assertArrayHasKey('distance', $rows[0]);
        self::assertArrayHasKey('movingTime', $rows[0]);
        self::assertArrayHasKey('averageSpeed', $rows[0]);
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->requestHandler = new ReactPreviewGearApiRequestHandler(
            currentAppUser: $this->buildCurrentAppUser(),
            clock: $this->getContainer()->get(Clock::class),
            gearRepository: $this->getContainer()->get(\App\Domain\Gear\GearRepository::class),
            customGearConfig: $this->getContainer()->get(\App\Domain\Gear\CustomGear\CustomGearConfig::class),
            maintenanceTaskProgressCalculator: $this->getContainer()->get(\App\Domain\Gear\Maintenance\Task\Progress\MaintenanceTaskProgressCalculator::class),
            enrichedActivities: $this->getContainer()->get(\App\Domain\Activity\EnrichedActivities::class),
            unitSystem: $this->getContainer()->get(\App\Infrastructure\ValueObject\Measurement\UnitSystem::class),
            queryBus: $this->getContainer()->get(\App\Infrastructure\CQRS\Query\Bus\QueryBus::class),
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