<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Build\BuildMonthlyStatsHtml\BuildMonthlyStatsHtml;
use App\Domain\TrainingPlanner\DbalRaceEventRepository;
use App\Domain\TrainingPlanner\RaceEvent;
use App\Domain\TrainingPlanner\RaceEventFamily;
use App\Domain\TrainingPlanner\RaceEventId;
use App\Domain\TrainingPlanner\RaceEventPriority;
use App\Domain\TrainingPlanner\RaceEventProfile;
use App\Domain\TrainingPlanner\TrainingPlan;
use App\Domain\TrainingPlanner\TrainingPlanRepository;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsController]
final readonly class ReactPreviewRaceEventsApiRequestHandler
{
    public function __construct(
        private CurrentAppUser $currentAppUser,
        private DbalRaceEventRepository $repository,
        private TrainingPlanRepository $trainingPlanRepository,
        private CommandBus $commandBus,
        private Clock $clock,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/react-preview/api/race-events', methods: ['GET'], priority: 6)]
    public function handle(): JsonResponse
    {
        $this->currentAppUser->require();

        return new JsonResponse($this->buildPayload());
    }

    #[Route(path: '/react-preview/api/race-events', methods: ['POST'], priority: 6)]
    public function save(Request $request): JsonResponse
    {
        $this->currentAppUser->require();

        $payload = $this->readPayload($request);
        $now = $this->clock->getCurrentDateTimeImmutable();
        $raceEventId = trim((string) ($payload['raceEventId'] ?? ''));
        $existing = '' === $raceEventId ? null : $this->repository->findById(RaceEventId::fromString($raceEventId));
        $profile = RaceEventProfile::from((string) ($payload['profile'] ?? RaceEventProfile::SPRINT_TRIATHLON->value));
        $family = RaceEventFamily::tryFrom((string) ($payload['family'] ?? $profile->getFamily()->value)) ?? $profile->getFamily();

        $raceEvent = RaceEvent::createWithClassification(
            raceEventId: $existing?->getId() ?? RaceEventId::random(),
            ownerUserId: $existing?->getOwnerUserId() ?? $this->currentAppUser->getId(),
            day: SerializableDateTime::fromString((string) ($payload['day'] ?? $now->format('Y-m-d'))),
            family: $family,
            profile: $profile,
            title: $this->nullableString($payload['title'] ?? null),
            location: $this->nullableString($payload['location'] ?? null),
            notes: $this->nullableString($payload['notes'] ?? null),
            priority: RaceEventPriority::from((string) ($payload['priority'] ?? RaceEventPriority::B->value)),
            targetFinishTimeInSeconds: $this->parseTargetFinishTimeInSeconds(
                (string) ($payload['targetFinishTimeHours'] ?? ''),
                (string) ($payload['targetFinishTimeMinutes'] ?? ''),
            ),
            createdAt: $existing?->getCreatedAt() ?? $now,
            updatedAt: $now,
        );

        $this->repository->upsert($raceEvent);
        $this->rebuildPlannerViews($now);

        return new JsonResponse($this->buildPayload(savedRaceEventId: (string) $raceEvent->getId()));
    }

    #[Route(path: '/react-preview/api/race-events/{raceEventId}', methods: ['DELETE'], priority: 6)]
    public function delete(string $raceEventId): JsonResponse
    {
        $this->currentAppUser->require();

        $this->repository->delete(RaceEventId::fromString($raceEventId));
        $this->rebuildPlannerViews($this->clock->getCurrentDateTimeImmutable());

        return new JsonResponse($this->buildPayload(deletedRaceEventId: $raceEventId));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(?string $savedRaceEventId = null, ?string $deletedRaceEventId = null): array
    {
        $requestedAt = $this->clock->getCurrentDateTimeImmutable();
        $today = $requestedAt->setTime(0, 0);
        $races = $this->loadRaceEvents();
        $coverageLookup = $this->buildCoverageLookup($races);
        $serializedRaces = array_map(
            fn (RaceEvent $raceEvent): array => $this->serializeRaceEvent($raceEvent, $coverageLookup[(string) $raceEvent->getId()] ?? null, $today),
            $races,
        );
        $upcomingRaces = array_filter($races, static fn (RaceEvent $raceEvent): bool => $raceEvent->getDay() >= $today);
        $aRaces = array_filter($races, static fn (RaceEvent $raceEvent): bool => RaceEventPriority::A === $raceEvent->getPriority());
        $coveredRaces = array_filter($coverageLookup, static fn (array $coverage): bool => 'unplanned' !== $coverage['state']);
        $directlyLinkedRaces = array_filter($coverageLookup, static fn (array $coverage): bool => 'linked' === $coverage['state']);
        $initialSelectionId = $savedRaceEventId
            ?? $this->resolveInitialSelectionId($races, $today);

        return [
            'requestedAt' => $requestedAt->format(DATE_ATOM),
            'savedRaceEventId' => $savedRaceEventId,
            'deletedRaceEventId' => $deletedRaceEventId,
            'initialSelectionId' => $initialSelectionId,
            'legacyCreatePath' => 'race-event?redirectTo=/race-planner',
            'summary' => [
                'totalRaces' => count($races),
                'upcomingRaces' => count($upcomingRaces),
                'aRaces' => count($aRaces),
                'coveredRaces' => count($coveredRaces),
                'directlyLinkedRaces' => count($directlyLinkedRaces),
                'unplannedRaces' => count($races) - count($coveredRaces),
            ],
            'formDefaults' => [
                'day' => $today->format('Y-m-d'),
                'family' => RaceEventFamily::TRIATHLON->value,
                'profile' => RaceEventProfile::SPRINT_TRIATHLON->value,
                'priority' => RaceEventPriority::B->value,
                'title' => '',
                'location' => '',
                'notes' => '',
                'targetFinishTimeHours' => '',
                'targetFinishTimeMinutes' => '',
            ],
            'options' => [
                'families' => array_map(
                    fn (RaceEventFamily $family): array => [
                        'value' => $family->value,
                        'label' => $family->trans($this->translator),
                    ],
                    RaceEventFamily::cases(),
                ),
                'priorities' => array_map(
                    fn (RaceEventPriority $priority): array => [
                        'value' => $priority->value,
                        'label' => $priority->trans($this->translator),
                    ],
                    RaceEventPriority::cases(),
                ),
                'profileGroups' => $this->buildProfileGroups(),
            ],
            'races' => $serializedRaces,
        ];
    }

    /**
     * @return list<RaceEvent>
     */
    private function loadRaceEvents(): array
    {
        $earliestRaceEvent = $this->repository->findEarliest();
        $latestRaceEvent = $this->repository->findLatest();

        if (!$earliestRaceEvent instanceof RaceEvent || !$latestRaceEvent instanceof RaceEvent) {
            return [];
        }

        return $this->repository->findByDateRange(DateRange::fromDates(
            $earliestRaceEvent->getDay()->setTime(0, 0),
            $latestRaceEvent->getDay()->setTime(23, 59, 59),
        ));
    }

    /**
     * @param list<RaceEvent> $races
     *
     * @return array<string, array{state: string, linkedTrainingPlan: null|array{id: string, title: string, type: string, racePlannerPath: string}}>
     */
    private function buildCoverageLookup(array $races): array
    {
        $lookup = [];
        $plans = $this->trainingPlanRepository->findAll();

        foreach ($races as $raceEvent) {
            $coverageState = 'unplanned';
            $linkedTrainingPlan = null;

            foreach ($plans as $plan) {
                if (!$plan instanceof TrainingPlan) {
                    continue;
                }

                if (null !== $plan->getTargetRaceEventId() && (string) $plan->getTargetRaceEventId() === (string) $raceEvent->getId()) {
                    $coverageState = 'linked';
                    $linkedTrainingPlan = $this->serializeTrainingPlanReference($plan);

                    break;
                }

                if ('unplanned' === $coverageState && $plan->containsDay($raceEvent->getDay())) {
                    $coverageState = 'covered';
                    $linkedTrainingPlan = $this->serializeTrainingPlanReference($plan);
                }
            }

            $lookup[(string) $raceEvent->getId()] = [
                'state' => $coverageState,
                'linkedTrainingPlan' => $linkedTrainingPlan,
            ];
        }

        return $lookup;
    }

    /**
     * @param array{state: string, linkedTrainingPlan: null|array{id: string, title: string, type: string, racePlannerPath: string}}|null $coverage
     *
     * @return array<string, mixed>
     */
    private function serializeRaceEvent(RaceEvent $raceEvent, ?array $coverage, SerializableDateTime $today): array
    {
        $targetFinishTimeInSeconds = $raceEvent->getTargetFinishTimeInSeconds();

        return [
            'id' => (string) $raceEvent->getId(),
            'day' => $raceEvent->getDay()->format('Y-m-d'),
            'title' => $raceEvent->getTitle() ?? $raceEvent->getProfile()->trans($this->translator),
            'rawTitle' => $raceEvent->getTitle(),
            'location' => $raceEvent->getLocation(),
            'notes' => $raceEvent->getNotes(),
            'priority' => $raceEvent->getPriority()->value,
            'priorityLabel' => $raceEvent->getPriority()->trans($this->translator),
            'family' => $raceEvent->getFamily()->value,
            'familyLabel' => $raceEvent->getFamily()->trans($this->translator),
            'profile' => $raceEvent->getProfile()->value,
            'profileLabel' => $raceEvent->getProfile()->trans($this->translator),
            'type' => $raceEvent->getType()->value,
            'targetFinishTimeInSeconds' => $targetFinishTimeInSeconds,
            'targetFinishTimeHours' => $this->extractTargetFinishHours($targetFinishTimeInSeconds),
            'targetFinishTimeMinutes' => $this->extractTargetFinishMinutes($targetFinishTimeInSeconds),
            'targetFinishTimeLabel' => null === $targetFinishTimeInSeconds ? null : $this->formatTargetFinishTime($targetFinishTimeInSeconds),
            'countdownDays' => $raceEvent->getDay() >= $today ? (int) $today->diff($raceEvent->getDay())->format('%a') : null,
            'coverage' => $coverage ?? [
                'state' => 'unplanned',
                'linkedTrainingPlan' => null,
            ],
            'legacyModalPath' => sprintf('race-event?raceEventId=%s&redirectTo=/race-planner', $raceEvent->getId()),
        ];
    }

    /**
     * @return array{id: string, title: string, type: string, racePlannerPath: string}
     */
    private function serializeTrainingPlanReference(TrainingPlan $trainingPlan): array
    {
        return [
            'id' => (string) $trainingPlan->getId(),
            'title' => $trainingPlan->getTitle() ?? ('race' === $trainingPlan->getType()->value ? 'Race plan' : 'Training plan'),
            'type' => $trainingPlan->getType()->value,
            'racePlannerPath' => sprintf('race-planner/plan-%s', $trainingPlan->getId()),
        ];
    }

    /**
     * @return list<array{family: string, familyLabel: string, options: list<array{value: string, label: string, family: string}>}>
     */
    private function buildProfileGroups(): array
    {
        $groupedOptions = [];

        foreach (RaceEventProfile::cases() as $profile) {
            $family = $profile->getFamily();
            $groupedOptions[$family->value] ??= [
                'family' => $family->value,
                'familyLabel' => $family->trans($this->translator),
                'options' => [],
            ];
            $groupedOptions[$family->value]['options'][] = [
                'value' => $profile->value,
                'label' => $profile->trans($this->translator),
                'family' => $family->value,
            ];
        }

        return array_values($groupedOptions);
    }

    private function resolveInitialSelectionId(array $races, SerializableDateTime $today): ?string
    {
        foreach ($races as $raceEvent) {
            if ($raceEvent->getDay() >= $today) {
                return (string) $raceEvent->getId();
            }
        }

        if ([] === $races) {
            return null;
        }

        return $races[0] instanceof RaceEvent ? (string) $races[0]->getId() : null;
    }

    private function parseTargetFinishTimeInSeconds(string $hours, string $minutes): ?int
    {
        $hours = trim($hours);
        $minutes = trim($minutes);

        if ('' === $hours && '' === $minutes) {
            return null;
        }

        $parsedHours = '' === $hours ? 0 : max(0, (int) $hours);
        $parsedMinutes = '' === $minutes ? 0 : max(0, min(59, (int) $minutes));

        return ($parsedHours * 3600) + ($parsedMinutes * 60);
    }

    private function extractTargetFinishHours(?int $seconds): ?int
    {
        if (null === $seconds || $seconds <= 0) {
            return null;
        }

        return intdiv($seconds, 3600);
    }

    private function extractTargetFinishMinutes(?int $seconds): ?int
    {
        if (null === $seconds || $seconds <= 0) {
            return null;
        }

        return intdiv($seconds % 3600, 60);
    }

    private function formatTargetFinishTime(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return $hours > 0
            ? sprintf('%d:%02d', $hours, $minutes)
            : sprintf('%d min', $minutes);
    }

    private function rebuildPlannerViews(?SerializableDateTime $now = null): void
    {
        $now ??= $this->clock->getCurrentDateTimeImmutable();

        $this->commandBus->dispatch(new BuildMonthlyStatsHtml($now));
    }

    /**
     * @return array<string, mixed>
     */
    private function readPayload(Request $request): array
    {
        if ([] !== $request->request->all()) {
            return $request->request->all();
        }

        $content = trim((string) $request->getContent());
        if ('' === $content) {
            return [];
        }

        try {
            $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($payload) ? $payload : [];
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
