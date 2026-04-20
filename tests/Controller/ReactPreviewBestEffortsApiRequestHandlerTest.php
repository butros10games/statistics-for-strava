<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ReactPreviewBestEffortsApiRequestHandler;
use App\Domain\Activity\BestEffort\BestEffortsCalculator;
use App\Domain\Auth\AppUser;
use App\Domain\Auth\AppUserId;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ReactPreviewBestEffortsApiRequestHandlerTest extends ContainerTestCase
{
    private ReactPreviewBestEffortsApiRequestHandler $requestHandler;
    private BestEffortsCalculator $bestEffortsCalculator;

    public function testHandleReturnsActivityTypesPeriodsAndRows(): void
    {
        $response = $this->requestHandler->handle();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('requestedAt', $payload);
        self::assertArrayHasKey('activityTypes', $payload);

        if ([] === $payload['activityTypes']) {
            self::assertSame([], $payload['activityTypes']);

            return;
        }

        self::assertArrayHasKey('value', $payload['activityTypes'][0]);
        self::assertArrayHasKey('periods', $payload['activityTypes'][0]);
        self::assertArrayHasKey('chartOptions', $payload['activityTypes'][0]['periods'][0]);
        self::assertArrayHasKey('rows', $payload['activityTypes'][0]['periods'][0]);
    }

    public function testHandleHistoryReturns404ForUnknownTarget(): void
    {
        $response = $this->requestHandler->handleHistory(new Request([
            'activityType' => 'Ride',
            'distanceValue' => '999',
            'distanceSymbol' => 'km',
        ]));

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Best effort history target not found.', $payload['message']);
    }

    public function testHandleHistoryReturnsRankingsForExistingDistanceWhenAvailable(): void
    {
        $activityType = $this->bestEffortsCalculator->getActivityTypes()->getFirst();

        if (null === $activityType) {
            self::assertNull($activityType);

            return;
        }

        $distance = $activityType->getDistancesForBestEffortCalculation()[0] ?? null;

        if (null === $distance) {
            self::assertNull($distance);

            return;
        }

        $response = $this->requestHandler->handleHistory(new Request([
            'activityType' => $activityType->value,
            'distanceValue' => (string) $distance->toFloat(),
            'distanceSymbol' => $distance->getSymbol(),
        ]));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('distance', $payload);
        self::assertArrayHasKey('rankings', $payload);
        self::assertArrayHasKey('sportTypes', $payload);
        self::assertCount(10, $payload['rankings']);
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->bestEffortsCalculator = $this->getContainer()->get(BestEffortsCalculator::class);
        $this->requestHandler = new ReactPreviewBestEffortsApiRequestHandler(
            currentAppUser: $this->buildCurrentAppUser(),
            clock: $this->getContainer()->get(Clock::class),
            bestEffortsCalculator: $this->bestEffortsCalculator,
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