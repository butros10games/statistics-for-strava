<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ReactPreviewActivitiesApiRequestHandler;
use App\Domain\Auth\AppUser;
use App\Domain\Auth\AppUserId;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ReactPreviewActivitiesApiRequestHandlerTest extends ContainerTestCase
{
    private ReactPreviewActivitiesApiRequestHandler $requestHandler;
    private FilesystemOperator $apiStorage;

    public function testHandleReturnsDecodedActivityRowsAndFilterMetadata(): void
    {
        $this->apiStorage->write(
            'activity/data-table.json',
            (string) Json::encodeAndCompress([
                [
                    'active' => true,
                    'searchables' => 'morning run',
                    'filterables' => [
                        'sportType' => 'Run',
                        'countryCode' => ['be'],
                        'distance' => 105,
                    ],
                    'summables' => [
                        'distance' => 10.5,
                        'moving-time' => 0.8,
                    ],
                    'sort' => [
                        'start-date' => 1_717_113_600,
                        'distance' => 10_500,
                    ],
                    'markup' => '<tr><td>Morning run</td></tr>',
                ],
            ]),
        );

        $response = $this->requestHandler->handle();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(1, $payload['rows']);
        self::assertSame('morning run', $payload['rows'][0]['searchables']);
        self::assertSame('<tr><td>Morning run</td></tr>', $payload['rows'][0]['markup']);
        self::assertSame('race', $payload['filters']['workoutTypes'][0]['value']);
        self::assertArrayHasKey('requestedAt', $payload);
    }

    public function testHandleReturnsEmptyRowsWhenActivityTableHasNotBeenBuiltYet(): void
    {
        $response = $this->requestHandler->handle();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([], $payload['rows']);
        self::assertNotEmpty($payload['filters']['workoutTypes']);
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->apiStorage = $this->getContainer()->get('api.storage');
        if ($this->apiStorage->fileExists('activity/data-table.json')) {
            $this->apiStorage->delete('activity/data-table.json');
        }

        $this->requestHandler = new ReactPreviewActivitiesApiRequestHandler(
            currentAppUser: $this->buildCurrentAppUser(),
            clock: $this->getContainer()->get(Clock::class),
            apiStorage: $this->apiStorage,
            sportTypeRepository: $this->getContainer()->get(\App\Domain\Activity\SportType\SportTypeRepository::class),
            deviceRepository: $this->getContainer()->get(\App\Domain\Activity\Device\DeviceRepository::class),
            gearRepository: $this->getContainer()->get(\App\Domain\Gear\GearRepository::class),
            countries: $this->getContainer()->get(\App\Application\Countries::class),
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