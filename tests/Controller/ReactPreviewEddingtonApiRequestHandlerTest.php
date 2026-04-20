<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ReactPreviewEddingtonApiRequestHandler;
use App\Domain\Auth\AppUser;
use App\Domain\Auth\AppUserId;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use App\Infrastructure\ValueObject\Measurement\UnitSystem;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ReactPreviewEddingtonApiRequestHandlerTest extends ContainerTestCase
{
    private ReactPreviewEddingtonApiRequestHandler $requestHandler;
    private UnitSystem $activeUnitSystem;

    public function testHandleReturnsUnitSystemsOrderedByCurrentPreference(): void
    {
        $response = $this->requestHandler->handle();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($this->activeUnitSystem->value, $payload['activeUnitSystem']);
        self::assertSame($this->activeUnitSystem->value, $payload['unitSystems'][0]['value']);
        self::assertSame(2, count($payload['unitSystems']));
        self::assertArrayHasKey('requestedAt', $payload);
    }

    public function testHandleReturnsChartPayloadsForCalculatedEddingtons(): void
    {
        $response = $this->requestHandler->handle();
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $eddingtons = array_merge(
            ...array_map(
                static fn (array $unitSystem): array => $unitSystem['eddingtons'],
                $payload['unitSystems'],
            ),
        );

        foreach ($payload['unitSystems'] as $unitSystem) {
            self::assertArrayHasKey('distanceSymbol', $unitSystem);
            self::assertArrayHasKey('eddingtons', $unitSystem);
        }

        if ([] === $eddingtons) {
            self::assertSame([], $eddingtons);

            return;
        }

        self::assertArrayHasKey('series', $eddingtons[0]['chartOptions']);
        self::assertArrayHasKey('series', $eddingtons[0]['historyChartOptions']);
        self::assertArrayHasKey('daysToNextNumber', $eddingtons[0]);
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->activeUnitSystem = $this->getContainer()->get(UnitSystem::class);

        $this->requestHandler = new ReactPreviewEddingtonApiRequestHandler(
            currentAppUser: $this->buildCurrentAppUser(),
            clock: $this->getContainer()->get(Clock::class),
            activeUnitSystem: $this->activeUnitSystem,
            eddingtonCalculator: $this->getContainer()->get(\App\Domain\Activity\Eddington\EddingtonCalculator::class),
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