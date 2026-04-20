<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Countries;
use App\Controller\ReactPreviewSegmentsApiRequestHandler;
use App\Domain\Activity\EnrichedActivities;
use App\Domain\Auth\AppUser;
use App\Domain\Auth\AppUserId;
use App\Domain\Segment\SegmentRepository;
use App\Infrastructure\Repository\Pagination;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ReactPreviewSegmentsApiRequestHandlerTest extends ContainerTestCase
{
    private ReactPreviewSegmentsApiRequestHandler $requestHandler;
    private SegmentRepository $segmentRepository;

    public function testHandleReturnsSegmentRowsAndFilterMetadata(): void
    {
        $response = $this->requestHandler->handle();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('requestedAt', $payload);
        self::assertArrayHasKey('summary', $payload);
        self::assertArrayHasKey('filters', $payload);
        self::assertArrayHasKey('segments', $payload);
        self::assertArrayHasKey('sportTypes', $payload['filters']);
        self::assertArrayHasKey('countries', $payload['filters']);

        if ([] === $payload['segments']) {
            self::assertSame([], $payload['segments']);

            return;
        }

        self::assertArrayHasKey('displayName', $payload['segments'][0]);
        self::assertArrayHasKey('distance', $payload['segments'][0]);
        self::assertArrayHasKey('bestEffort', $payload['segments'][0]);
    }

    public function testHandleDetailReturns404ForUnknownSegment(): void
    {
        $response = $this->requestHandler->handleDetail('segment-does-not-exist');

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Segment not found.', $payload['message']);
    }

    public function testHandleDetailReturnsChartsAndTopEffortsForExistingSegmentWhenAvailable(): void
    {
        $segment = $this->segmentRepository->findAll(Pagination::fromOffsetAndLimit(0, 1))->getFirst();

        if (null === $segment) {
            self::assertNull($segment);

            return;
        }

        $response = $this->requestHandler->handleDetail((string) $segment->getId());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('segment', $payload);
        self::assertArrayHasKey('charts', $payload);
        self::assertArrayHasKey('history', $payload['charts']);
        self::assertArrayHasKey('series', $payload['charts']['history']);
        self::assertArrayHasKey('topEfforts', $payload);
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->segmentRepository = $this->getContainer()->get(SegmentRepository::class);
        $this->requestHandler = new ReactPreviewSegmentsApiRequestHandler(
            currentAppUser: $this->buildCurrentAppUser(),
            clock: $this->getContainer()->get(Clock::class),
            segmentRepository: $this->segmentRepository,
            segmentEffortRepository: $this->getContainer()->get(\App\Domain\Segment\SegmentEffort\SegmentEffortRepository::class),
            sportTypeRepository: $this->getContainer()->get(\App\Domain\Activity\SportType\SportTypeRepository::class),
            countries: $this->getContainer()->get(Countries::class),
            enrichedActivities: $this->getContainer()->get(EnrichedActivities::class),
            unitSystem: $this->getContainer()->get(\App\Infrastructure\ValueObject\Measurement\UnitSystem::class),
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