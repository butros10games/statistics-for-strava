<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Build\BuildMonthlyStatsHtml\BuildMonthlyStatsHtml;
use App\Domain\TrainingPlanner\DbalRaceEventRepository;
use App\Domain\TrainingPlanner\DbalTrainingBlockRepository;
use App\Domain\TrainingPlanner\RaceEvent;
use App\Domain\TrainingPlanner\RaceEventId;
use App\Domain\TrainingPlanner\TrainingBlock;
use App\Domain\TrainingPlanner\TrainingBlockId;
use App\Domain\TrainingPlanner\TrainingBlockPhase;
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
final readonly class ReactPreviewTrainingBlocksApiRequestHandler
{
    public function __construct(
        private CurrentAppUser $currentAppUser,
        private DbalTrainingBlockRepository $repository,
        private DbalRaceEventRepository $raceEventRepository,
        private CommandBus $commandBus,
        private Clock $clock,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/react-preview/api/training-blocks', methods: ['GET'], priority: 6)]
    public function handle(): JsonResponse
    {
        $this->currentAppUser->require();

        return new JsonResponse($this->buildPayload());
    }

    #[Route(path: '/react-preview/api/training-blocks', methods: ['POST'], priority: 6)]
    public function save(Request $request): JsonResponse
    {
        $this->currentAppUser->require();

        $payload = $this->readPayload($request);
        $now = $this->clock->getCurrentDateTimeImmutable();
        $trainingBlockId = trim((string) ($payload['trainingBlockId'] ?? ''));
        $existing = '' === $trainingBlockId ? null : $this->repository->findById(TrainingBlockId::fromString($trainingBlockId));
        $startDay = SerializableDateTime::fromString((string) ($payload['startDay'] ?? $now->format('Y-m-d')));
        $endDay = SerializableDateTime::fromString((string) ($payload['endDay'] ?? $startDay->format('Y-m-d')));

        $trainingBlock = TrainingBlock::create(
            trainingBlockId: $existing?->getId() ?? TrainingBlockId::random(),
            startDay: $startDay,
            endDay: $endDay,
            targetRaceEventId: $this->nullableRaceEventId($payload['targetRaceEventId'] ?? null),
            phase: TrainingBlockPhase::from((string) ($payload['phase'] ?? TrainingBlockPhase::BASE->value)),
            title: $this->nullableString($payload['title'] ?? null),
            focus: $this->nullableString($payload['focus'] ?? null),
            notes: $this->nullableString($payload['notes'] ?? null),
            createdAt: $existing?->getCreatedAt() ?? $now,
            updatedAt: $now,
            ownerUserId: $existing?->getOwnerUserId() ?? $this->currentAppUser->getId(),
        );

        $this->repository->upsert($trainingBlock);
        $this->rebuildPlannerViews($now);

        return new JsonResponse($this->buildPayload(savedTrainingBlockId: (string) $trainingBlock->getId()));
    }

    #[Route(path: '/react-preview/api/training-blocks/{trainingBlockId}', methods: ['DELETE'], priority: 6)]
    public function delete(string $trainingBlockId): JsonResponse
    {
        $this->currentAppUser->require();

        $this->repository->delete(TrainingBlockId::fromString($trainingBlockId));
        $this->rebuildPlannerViews($this->clock->getCurrentDateTimeImmutable());

        return new JsonResponse($this->buildPayload(deletedTrainingBlockId: $trainingBlockId));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(?string $savedTrainingBlockId = null, ?string $deletedTrainingBlockId = null): array
    {
        $requestedAt = $this->clock->getCurrentDateTimeImmutable();
        $today = $requestedAt->setTime(0, 0);
        $blocks = $this->loadTrainingBlocks();
        $raceEventsById = $this->loadRaceEventsById();
        $serializedBlocks = array_map(
            fn (TrainingBlock $trainingBlock): array => $this->serializeTrainingBlock($trainingBlock, $raceEventsById[(string) $trainingBlock->getTargetRaceEventId()] ?? null, $today),
            $blocks,
        );
        $currentBlocks = array_filter($blocks, static fn (TrainingBlock $trainingBlock): bool => $trainingBlock->containsDay($today));
        $upcomingBlocks = array_filter($blocks, static fn (TrainingBlock $trainingBlock): bool => $trainingBlock->getStartDay() > $today);
        $completedBlocks = array_filter($blocks, static fn (TrainingBlock $trainingBlock): bool => $trainingBlock->getEndDay() < $today);
        $linkedRaceBlocks = array_filter($blocks, static fn (TrainingBlock $trainingBlock): bool => null !== $trainingBlock->getTargetRaceEventId());
        $totalPlannedDays = array_reduce(
            $blocks,
            static fn (int $sum, TrainingBlock $trainingBlock): int => $sum + $trainingBlock->getDurationInDays(),
            0,
        );
        $initialSelectionId = $savedTrainingBlockId ?? $this->resolveInitialSelectionId($blocks, $today);

        return [
            'requestedAt' => $requestedAt->format(DATE_ATOM),
            'savedTrainingBlockId' => $savedTrainingBlockId,
            'deletedTrainingBlockId' => $deletedTrainingBlockId,
            'initialSelectionId' => $initialSelectionId,
            'legacyCreatePath' => 'training-block?redirectTo=/monthly-stats',
            'summary' => [
                'totalBlocks' => count($blocks),
                'currentBlocks' => count($currentBlocks),
                'upcomingBlocks' => count($upcomingBlocks),
                'completedBlocks' => count($completedBlocks),
                'linkedRaceBlocks' => count($linkedRaceBlocks),
                'totalPlannedDays' => $totalPlannedDays,
            ],
            'formDefaults' => [
                'startDay' => $today->format('Y-m-d'),
                'endDay' => $today->format('Y-m-d'),
                'phase' => TrainingBlockPhase::BASE->value,
                'title' => '',
                'focus' => '',
                'notes' => '',
                'targetRaceEventId' => '',
            ],
            'options' => [
                'phases' => array_map(
                    fn (TrainingBlockPhase $phase): array => [
                        'value' => $phase->value,
                        'label' => $phase->trans($this->translator),
                    ],
                    TrainingBlockPhase::cases(),
                ),
                'raceEvents' => array_map(
                    fn (RaceEvent $raceEvent): array => $this->serializeRaceEventOption($raceEvent, $today),
                    array_values($raceEventsById),
                ),
            ],
            'blocks' => $serializedBlocks,
        ];
    }

    /**
     * @return list<TrainingBlock>
     */
    private function loadTrainingBlocks(): array
    {
        $earliestTrainingBlock = $this->repository->findEarliest();
        $latestTrainingBlock = $this->repository->findLatest();

        if (!$earliestTrainingBlock instanceof TrainingBlock || !$latestTrainingBlock instanceof TrainingBlock) {
            return [];
        }

        return $this->repository->findByDateRange(DateRange::fromDates(
            $earliestTrainingBlock->getStartDay()->setTime(0, 0),
            $latestTrainingBlock->getEndDay()->setTime(23, 59, 59),
        ));
    }

    /**
     * @return array<string, RaceEvent>
     */
    private function loadRaceEventsById(): array
    {
        $earliestRaceEvent = $this->raceEventRepository->findEarliest();
        $latestRaceEvent = $this->raceEventRepository->findLatest();

        if (!$earliestRaceEvent instanceof RaceEvent || !$latestRaceEvent instanceof RaceEvent) {
            return [];
        }

        $raceEvents = $this->raceEventRepository->findByDateRange(DateRange::fromDates(
            $earliestRaceEvent->getDay()->setTime(0, 0),
            $latestRaceEvent->getDay()->setTime(23, 59, 59),
        ));

        $raceEventsById = [];

        foreach ($raceEvents as $raceEvent) {
            $raceEventsById[(string) $raceEvent->getId()] = $raceEvent;
        }

        return $raceEventsById;
    }

    /**
     * @param array<string, RaceEvent> $raceEventsById
     *
     * @return array<string, mixed>
     */
    private function serializeTrainingBlock(TrainingBlock $trainingBlock, ?RaceEvent $linkedRace, SerializableDateTime $today): array
    {
        return [
            'id' => (string) $trainingBlock->getId(),
            'title' => $trainingBlock->getTitle() ?? sprintf('%s block', $trainingBlock->getPhase()->trans($this->translator)),
            'rawTitle' => $trainingBlock->getTitle(),
            'startDay' => $trainingBlock->getStartDay()->format('Y-m-d'),
            'endDay' => $trainingBlock->getEndDay()->format('Y-m-d'),
            'durationInDays' => $trainingBlock->getDurationInDays(),
            'phase' => $trainingBlock->getPhase()->value,
            'phaseLabel' => $trainingBlock->getPhase()->trans($this->translator),
            'focus' => $trainingBlock->getFocus(),
            'notes' => $trainingBlock->getNotes(),
            'state' => $this->resolveBlockState($trainingBlock, $today),
            'linkedRace' => $linkedRace instanceof RaceEvent ? $this->serializeRaceEventOption($linkedRace, $today) : null,
            'legacyModalPath' => sprintf('training-block?trainingBlockId=%s&redirectTo=/monthly-stats', $trainingBlock->getId()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRaceEventOption(RaceEvent $raceEvent, SerializableDateTime $today): array
    {
        return [
            'id' => (string) $raceEvent->getId(),
            'title' => $raceEvent->getTitle() ?? $raceEvent->getProfile()->trans($this->translator),
            'day' => $raceEvent->getDay()->format('Y-m-d'),
            'profile' => $raceEvent->getProfile()->value,
            'profileLabel' => $raceEvent->getProfile()->trans($this->translator),
            'priority' => $raceEvent->getPriority()->value,
            'priorityLabel' => $raceEvent->getPriority()->trans($this->translator),
            'location' => $raceEvent->getLocation(),
            'countdownDays' => $raceEvent->getDay() >= $today ? (int) $today->diff($raceEvent->getDay())->format('%a') : null,
        ];
    }

    private function resolveInitialSelectionId(array $blocks, SerializableDateTime $today): ?string
    {
        foreach ($blocks as $trainingBlock) {
            if ($trainingBlock->containsDay($today)) {
                return (string) $trainingBlock->getId();
            }
        }

        foreach ($blocks as $trainingBlock) {
            if ($trainingBlock->getStartDay() > $today) {
                return (string) $trainingBlock->getId();
            }
        }

        if ([] === $blocks) {
            return null;
        }

        return $blocks[0] instanceof TrainingBlock ? (string) $blocks[0]->getId() : null;
    }

    private function resolveBlockState(TrainingBlock $trainingBlock, SerializableDateTime $today): string
    {
        if ($trainingBlock->containsDay($today)) {
            return 'current';
        }

        if ($trainingBlock->getStartDay() > $today) {
            return 'upcoming';
        }

        return 'completed';
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

    private function nullableRaceEventId(mixed $value): ?RaceEventId
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : RaceEventId::fromString($value);
    }
}
