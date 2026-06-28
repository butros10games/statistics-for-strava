<?php

declare(strict_types=1);

namespace App\Domain\Strava\Webhook\ProcessWebhookEvent;

use App\Domain\Strava\Connection\AppUserStravaConnectionRepository;
use App\Domain\Strava\Webhook\WebhookAspectType;
use App\Domain\Strava\Webhook\WebhookEvent;
use App\Domain\Strava\Webhook\WebhookEventRepository;
use App\Infrastructure\CQRS\Command\Command;
use App\Infrastructure\CQRS\Command\CommandHandler;

final readonly class ProcessWebhookEventCommandHandler implements CommandHandler
{
    public function __construct(
        private WebhookEventRepository $webhookEventRepository,
        private AppUserStravaConnectionRepository $stravaConnectionRepository,
    ) {
    }

    public function handle(Command $command): void
    {
        assert($command instanceof ProcessWebhookEvent);

        $payload = $command->getEventPayload();

        if ('activity' !== $payload['object_type']) {
            return;
        }
        if (!$aspectType = WebhookAspectType::tryFrom($payload['aspect_type'])) {
            throw new \RuntimeException(sprintf('Aspect type "%s" not supported', $payload['aspect_type']));
        }

        $ownerAthleteId = $this->extractOwnerAthleteId($payload);
        $connection = null !== $ownerAthleteId
            ? $this->stravaConnectionRepository->findByAthleteId($ownerAthleteId)
            : null;
        $appUserId = null;
        if ($connection instanceof \App\Domain\Strava\Connection\AppUserStravaConnection) {
            $appUserId = (string) $connection->getAppUserId();
            $payload['app_user_id'] = $appUserId;
        }

        $this->webhookEventRepository->add(WebhookEvent::create(
            objectId: (string) $payload['object_id'],
            objectType: $payload['object_type'],
            aspectType: $aspectType,
            payload: $payload,
            ownerAthleteId: $ownerAthleteId,
            appUserId: $appUserId,
        ));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractOwnerAthleteId(array $payload): ?string
    {
        $ownerAthleteId = $payload['owner_id'] ?? $payload['object_owner_id'] ?? null;
        if (null === $ownerAthleteId || '' === trim((string) $ownerAthleteId)) {
            return null;
        }

        return trim((string) $ownerAthleteId);
    }
}
