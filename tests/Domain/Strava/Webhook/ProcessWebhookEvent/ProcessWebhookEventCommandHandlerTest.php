<?php

namespace App\Tests\Domain\Strava\Webhook\ProcessWebhookEvent;

use App\Domain\Auth\AppUserId;
use App\Domain\Strava\Connection\AppUserStravaConnection;
use App\Domain\Strava\Connection\AppUserStravaConnectionRepository;
use App\Domain\Strava\Webhook\ProcessWebhookEvent\ProcessWebhookEvent;
use App\Domain\Strava\Webhook\WebhookAspectType;
use App\Domain\Strava\Webhook\WebhookEvent;
use App\Domain\Strava\Webhook\WebhookEventRepository;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use Spatie\Snapshots\MatchesSnapshots;

class ProcessWebhookEventCommandHandlerTest extends ContainerTestCase
{
    use MatchesSnapshots;

    private CommandBus $commandBus;

    public function testHandle(): void
    {
        $this->commandBus->dispatch(new ProcessWebhookEvent([
            'object_id' => 1,
            'object_type' => 'activity',
            'aspect_type' => 'create',
        ]));

        $this->assertEquals(
            [
                WebhookEvent::create(
                    objectId: '1',
                    objectType: 'activity',
                    aspectType: WebhookAspectType::CREATE,
                    payload: [
                        'object_id' => 1,
                        'object_type' => 'activity',
                        'aspect_type' => 'create',
                    ],
                ),
            ],
            $this->getContainer()->get(WebhookEventRepository::class)->grab()
        );
    }

    public function testHandleWhenNotActivityEvent(): void
    {
        $this->commandBus->dispatch(new ProcessWebhookEvent([
            'object_id' => 1,
            'object_type' => 'athlete',
            'aspect_type' => 'create',
        ]));

        $this->assertEmpty(
            $this->getContainer()->get(WebhookEventRepository::class)->grab()
        );
    }

    public function testHandleStoresAppUserMetadataForKnownOwner(): void
    {
        $appUserId = AppUserId::fromString('user-known-owner');
        $this->getContainer()->get(AppUserStravaConnectionRepository::class)->save(AppUserStravaConnection::connect(
            appUserId: $appUserId,
            stravaAthleteId: '42',
            refreshToken: 'refresh-token',
            scopes: ['read', 'activity:read_all'],
            accessTokenExpiresAt: null,
            updatedAt: SerializableDateTime::fromString('2026-06-28 12:00:00'),
        ));

        $this->commandBus->dispatch(new ProcessWebhookEvent([
            'object_id' => 1,
            'object_type' => 'activity',
            'aspect_type' => 'update',
            'owner_id' => 42,
        ]));

        $this->assertEquals(
            [
                WebhookEvent::create(
                    objectId: '1',
                    objectType: 'activity',
                    aspectType: WebhookAspectType::UPDATE,
                    payload: [
                        'object_id' => 1,
                        'object_type' => 'activity',
                        'aspect_type' => 'update',
                        'owner_id' => 42,
                        'app_user_id' => (string) $appUserId,
                    ],
                    ownerAthleteId: '42',
                    appUserId: (string) $appUserId,
                ),
            ],
            $this->getContainer()->get(WebhookEventRepository::class)->grab()
        );
    }

    public function testHandleWhenInvalidAspectType(): void
    {
        $this->expectExceptionObject(new \RuntimeException('Aspect type "invalid" not supported'));
        $this->commandBus->dispatch(new ProcessWebhookEvent([
            'object_id' => 1,
            'object_type' => 'activity',
            'aspect_type' => 'invalid',
        ]));
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->commandBus = $this->getContainer()->get(CommandBus::class);
    }
}
