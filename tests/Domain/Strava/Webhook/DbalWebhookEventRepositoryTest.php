<?php

namespace App\Tests\Domain\Strava\Webhook;

use App\Domain\Strava\Webhook\DbalWebhookEventRepository;
use App\Domain\Strava\Webhook\WebhookAspectType;
use App\Domain\Strava\Webhook\WebhookEvent;
use App\Domain\Strava\Webhook\WebhookEventRepository;
use App\Tests\ContainerTestCase;
use Spatie\Snapshots\MatchesSnapshots;

class DbalWebhookEventRepositoryTest extends ContainerTestCase
{
    use MatchesSnapshots;

    private WebhookEventRepository $webhookEventRepository;

    public function testAddAndGrab(): void
    {
        $createEvent = WebhookEvent::create(
            objectId: '1',
            objectType: 'activity',
            aspectType: WebhookAspectType::CREATE,
            payload: ['original' => true],
            ownerAthleteId: '123',
            appUserId: 'user-one',
        );

        $this->webhookEventRepository->add($createEvent);
        $this->webhookEventRepository->add(WebhookEvent::create(
            objectId: '1',
            objectType: 'activity',
            aspectType: WebhookAspectType::CREATE,
            payload: ['latest' => true],
            ownerAthleteId: '123',
            appUserId: 'user-one',
        ));

        $deleteEvent = WebhookEvent::create(
            objectId: '1',
            objectType: 'activity',
            aspectType: WebhookAspectType::DELETE,
            payload: ['deleted' => true],
            ownerAthleteId: '123',
            appUserId: 'user-one',
        );

        $this->webhookEventRepository->add($deleteEvent);

        $this->assertEquals([
            WebhookEvent::create(
                objectId: '1',
                objectType: 'activity',
                aspectType: WebhookAspectType::CREATE,
                payload: ['latest' => true],
                ownerAthleteId: '123',
                appUserId: 'user-one',
            ),
            $deleteEvent,
        ], $this->webhookEventRepository->grab());
        $this->assertEmpty($this->webhookEventRepository->grab());
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->webhookEventRepository = new DbalWebhookEventRepository($this->getConnection());
    }
}
