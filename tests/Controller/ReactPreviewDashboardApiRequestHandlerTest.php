<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ReactPreviewDashboardApiRequestHandler;
use App\Domain\Auth\AppUser;
use App\Domain\Auth\AppUserId;
use App\Domain\Dashboard\Widget\Widgets;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use App\Tests\ProvideTestData;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;

final class ReactPreviewDashboardApiRequestHandlerTest extends ContainerTestCase
{
    use ProvideTestData;

    private ReactPreviewDashboardApiRequestHandler $requestHandler;

    public function testHandleReturnsDashboardSections(): void
    {
        $response = $this->requestHandler->handle();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('requestedAt', $payload);
        self::assertArrayHasKey('summary', $payload);
        self::assertArrayHasKey('sections', $payload);
        self::assertArrayHasKey('totalWidgets', $payload['summary']);
        self::assertArrayHasKey('sectionCount', $payload['summary']);
        self::assertNotEmpty($payload['sections']);
        self::assertArrayHasKey('id', $payload['sections'][0]);
        self::assertArrayHasKey('label', $payload['sections'][0]);
        self::assertArrayHasKey('widgets', $payload['sections'][0]);
        self::assertArrayHasKey('id', $payload['sections'][0]['widgets'][0]);
        self::assertArrayHasKey('width', $payload['sections'][0]['widgets'][0]);
        self::assertArrayHasKey('html', $payload['sections'][0]['widgets'][0]);
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->provideFullTestSet();

        $this->requestHandler = new ReactPreviewDashboardApiRequestHandler(
            currentAppUser: $this->buildCurrentAppUser(),
            clock: $this->getContainer()->get(Clock::class),
            widgets: $this->getContainer()->get(Widgets::class),
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
