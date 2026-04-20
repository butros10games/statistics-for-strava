<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ReactPreviewMilestonesApiRequestHandler;
use App\Domain\Auth\AppUser;
use App\Domain\Auth\AppUserId;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ReactPreviewMilestonesApiRequestHandlerTest extends ContainerTestCase
{
    private ReactPreviewMilestonesApiRequestHandler $requestHandler;

    public function testHandleReturnsTimelineDataAndFilters(): void
    {
        $response = $this->requestHandler->handle();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('requestedAt', $payload);
        self::assertArrayHasKey('summary', $payload);
        self::assertArrayHasKey('filters', $payload);
        self::assertArrayHasKey('milestones', $payload);
        self::assertArrayHasKey('groups', $payload['filters']);
        self::assertArrayHasKey('sportTypes', $payload['filters']);
        self::assertArrayHasKey('years', $payload['filters']);

        if ([] === $payload['milestones']) {
            self::assertSame(0, $payload['summary']['totalMilestones']);

            return;
        }

        self::assertArrayHasKey('title', $payload['milestones'][0]);
        self::assertArrayHasKey('filterGroup', $payload['milestones'][0]);
        self::assertArrayHasKey('details', $payload['milestones'][0]);
        self::assertArrayHasKey('year', $payload['milestones'][0]);
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->requestHandler = new ReactPreviewMilestonesApiRequestHandler(
            currentAppUser: $this->buildCurrentAppUser(),
            clock: $this->getContainer()->get(Clock::class),
            milestoneCollector: $this->getContainer()->get(\App\Domain\Milestone\MilestoneCollector::class),
            enrichedActivities: $this->getContainer()->get(\App\Domain\Activity\EnrichedActivities::class),
            measurementTwigExtension: $this->getContainer()->get(\App\Infrastructure\Twig\MeasurementTwigExtension::class),
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