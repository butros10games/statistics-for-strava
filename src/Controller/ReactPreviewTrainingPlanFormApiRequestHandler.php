<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Performance\PerformanceAnchor\PerformanceAnchorHistory;
use App\Domain\Performance\PerformanceAnchor\PerformanceAnchorType;
use App\Domain\TrainingPlanner\RaceEvent;
use App\Domain\TrainingPlanner\RaceEventFamily;
use App\Domain\TrainingPlanner\RaceEventId;
use App\Domain\TrainingPlanner\RaceEventProfile;
use App\Domain\TrainingPlanner\RaceEventRepository;
use App\Domain\TrainingPlanner\RunningWorkoutTargetMode;
use App\Domain\TrainingPlanner\TrainingBlockStyle;
use App\Domain\TrainingPlanner\TrainingFocus;
use App\Domain\TrainingPlanner\TrainingPlan;
use App\Domain\TrainingPlanner\TrainingPlanDiscipline;
use App\Domain\TrainingPlanner\TrainingPlanId;
use App\Domain\TrainingPlanner\TrainingPlanRepository;
use App\Domain\TrainingPlanner\TrainingPlanType;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class ReactPreviewTrainingPlanFormApiRequestHandler
{
    public function __construct(
        private CurrentAppUser $currentAppUser,
        private TrainingPlanRepository $trainingPlanRepository,
        private RaceEventRepository $raceEventRepository,
        private PerformanceAnchorHistory $performanceAnchorHistory,
        private Connection $connection,
        private Clock $clock,
    ) {
    }

    #[Route(path: '/react-preview/api/training-plan-form', methods: ['GET'], priority: 7)]
    public function handle(Request $request): JsonResponse
    {
        $this->currentAppUser->require();

        $today = $this->clock->getCurrentDateTimeImmutable()->setTime(0, 0);
        $trainingPlanId = $request->query->getString('trainingPlanId');
        $trainingPlan = '' === $trainingPlanId ? null : $this->trainingPlanRepository->findById(TrainingPlanId::fromString($trainingPlanId));
        $afterTrainingPlanId = $request->query->getString('afterTrainingPlanId');
        $afterTrainingPlan = '' === $afterTrainingPlanId ? null : $this->trainingPlanRepository->findById(TrainingPlanId::fromString($afterTrainingPlanId));
        $raceEventOptions = $this->loadRaceEventOptions();
        $selectedRaceEvent = $this->findRaceEventById(
            $raceEventOptions,
            $this->nullableRaceEventId($request->query->getString('targetRaceEventId')),
        );
        $defaultStartDay = $this->resolveDefaultStartDay($trainingPlan, $afterTrainingPlan, $selectedRaceEvent, $today);
        $linkedRaceEventIds = $this->buildLinkedRaceEventIds($raceEventOptions, $trainingPlan?->getId());
        $suggestedRaceEvent = $trainingPlan?->getTargetRaceEventId()
            ? $this->findRaceEventById($raceEventOptions, $trainingPlan->getTargetRaceEventId())
            : $selectedRaceEvent;

        if (null === $suggestedRaceEvent && null === $trainingPlan) {
            $suggestedRaceEvent = $this->findSuggestedRaceEvent(
                $raceEventOptions,
                $linkedRaceEventIds,
                $defaultStartDay,
            );
        }

        $defaultEndDay = $this->resolveDefaultEndDay($trainingPlan, $defaultStartDay, $suggestedRaceEvent);
        $defaultType = $trainingPlan?->getType() ?? (null === $suggestedRaceEvent ? TrainingPlanType::TRAINING : TrainingPlanType::RACE);

        return new JsonResponse([
            'mode' => $trainingPlan instanceof TrainingPlan ? 'edit' : 'create',
            'context' => [
                'trainingPlan' => $trainingPlan instanceof TrainingPlan ? [
                    'id' => (string) $trainingPlan->getId(),
                    'title' => $trainingPlan->getTitle(),
                    'type' => $trainingPlan->getType()->value,
                    'startDay' => $trainingPlan->getStartDay()->format('Y-m-d'),
                    'endDay' => $trainingPlan->getEndDay()->format('Y-m-d'),
                ] : null,
                'afterTrainingPlan' => $afterTrainingPlan instanceof TrainingPlan ? [
                    'id' => (string) $afterTrainingPlan->getId(),
                    'title' => $afterTrainingPlan->getTitle(),
                    'type' => $afterTrainingPlan->getType()->value,
                    'endDay' => $afterTrainingPlan->getEndDay()->format('Y-m-d'),
                ] : null,
                'suggestedRaceEvent' => $suggestedRaceEvent instanceof RaceEvent ? $this->serializeRaceEvent($suggestedRaceEvent) : null,
            ],
            'defaults' => [
                'type' => $defaultType->value,
                'title' => $trainingPlan?->getTitle() ?? $suggestedRaceEvent?->getTitle(),
                'startDay' => $trainingPlan?->getStartDay()->format('Y-m-d') ?? $defaultStartDay->format('Y-m-d'),
                'endDay' => $trainingPlan?->getEndDay()->format('Y-m-d') ?? $defaultEndDay->format('Y-m-d'),
                'targetRaceEventId' => $trainingPlan?->getTargetRaceEventId()?->__toString() ?? ($suggestedRaceEvent instanceof RaceEvent ? (string) $suggestedRaceEvent->getId() : null),
                'discipline' => $trainingPlan?->getDiscipline()?->value ?? $afterTrainingPlan?->getDiscipline()?->value,
                'sportSchedule' => $trainingPlan?->getSportSchedule() ?? [],
                'performanceMetrics' => $this->resolveDefaultPerformanceMetrics($trainingPlan, $today),
                'targetRaceProfile' => $trainingPlan?->getTargetRaceProfile()?->value ?? $suggestedRaceEvent?->getProfile()->value,
                'trainingFocus' => $trainingPlan?->getTrainingFocus()?->value,
                'trainingBlockStyle' => $trainingPlan?->getTrainingBlockStyle()?->value ?? TrainingBlockStyle::BALANCED->value,
                'runningWorkoutTargetMode' => $trainingPlan?->getRunningWorkoutTargetMode()?->value ?? RunningWorkoutTargetMode::TIME->value,
                'runHillSessionsEnabled' => $trainingPlan?->isRunHillSessionsEnabled() ?? false,
                'notes' => $trainingPlan?->getNotes(),
            ],
            'options' => [
                'types' => array_map(
                    static fn (TrainingPlanType $type): array => ['value' => $type->value],
                    TrainingPlanType::cases(),
                ),
                'disciplines' => array_map(
                    static fn (TrainingPlanDiscipline $discipline): array => ['value' => $discipline->value],
                    TrainingPlanDiscipline::cases(),
                ),
                'raceEvents' => array_map(
                    fn (RaceEvent $raceEvent): array => $this->serializeRaceEvent($raceEvent),
                    $raceEventOptions,
                ),
                'raceProfileGroups' => $this->buildRaceProfileOptionGroups(),
                'trainingFocuses' => array_map(
                    static fn (TrainingFocus $focus): array => ['value' => $focus->value],
                    TrainingFocus::cases(),
                ),
                'trainingBlockStyles' => array_map(
                    static fn (TrainingBlockStyle $style): array => ['value' => $style->value],
                    TrainingBlockStyle::cases(),
                ),
                'runningWorkoutTargetModes' => array_map(
                    static fn (RunningWorkoutTargetMode $mode): array => ['value' => $mode->value],
                    RunningWorkoutTargetMode::cases(),
                ),
            ],
        ]);
    }

    /**
     * @return list<RaceEvent>
     */
    private function loadRaceEventOptions(): array
    {
        $earliestRaceEvent = $this->raceEventRepository->findEarliest();
        $latestRaceEvent = $this->raceEventRepository->findLatest();

        if (null === $earliestRaceEvent || null === $latestRaceEvent) {
            return [];
        }

        return $this->raceEventRepository->findByDateRange(DateRange::fromDates(
            $earliestRaceEvent->getDay()->setTime(0, 0),
            $latestRaceEvent->getDay()->setTime(23, 59, 59),
        ));
    }

    /**
     * @param list<RaceEvent> $raceEvents
     */
    private function findRaceEventById(array $raceEvents, ?RaceEventId $raceEventId): ?RaceEvent
    {
        if (null === $raceEventId) {
            return null;
        }

        foreach ($raceEvents as $raceEvent) {
            if ((string) $raceEvent->getId() === (string) $raceEventId) {
                return $raceEvent;
            }
        }

        return null;
    }

    /**
     * @param list<RaceEvent> $raceEvents
     *
     * @return array<string, true>
     */
    private function buildLinkedRaceEventIds(array $raceEvents, ?TrainingPlanId $excludedTrainingPlanId = null): array
    {
        $linkedRaceEventIds = [];

        foreach ($this->trainingPlanRepository->findAll() as $trainingPlan) {
            if (
                null !== $excludedTrainingPlanId
                && (string) $trainingPlan->getId() === (string) $excludedTrainingPlanId
            ) {
                continue;
            }

            if (null !== $trainingPlan->getTargetRaceEventId()) {
                $linkedRaceEventIds[(string) $trainingPlan->getTargetRaceEventId()] = true;
            }

            foreach ($raceEvents as $raceEvent) {
                if (!$trainingPlan->containsDay($raceEvent->getDay())) {
                    continue;
                }

                $linkedRaceEventIds[(string) $raceEvent->getId()] = true;
            }
        }

        return $linkedRaceEventIds;
    }

    /**
     * @param list<RaceEvent> $raceEvents
     * @param array<string, true> $linkedRaceEventIds
     */
    private function findSuggestedRaceEvent(array $raceEvents, array $linkedRaceEventIds, SerializableDateTime $fromDay): ?RaceEvent
    {
        foreach ($raceEvents as $raceEvent) {
            if ($raceEvent->getDay() < $fromDay) {
                continue;
            }

            if (isset($linkedRaceEventIds[(string) $raceEvent->getId()])) {
                continue;
            }

            return $raceEvent;
        }

        return null;
    }

    private function resolveDefaultStartDay(
        ?TrainingPlan $trainingPlan,
        ?TrainingPlan $afterTrainingPlan,
        ?RaceEvent $selectedRaceEvent,
        SerializableDateTime $today,
    ): SerializableDateTime {
        if (null !== $trainingPlan) {
            return $trainingPlan->getStartDay();
        }

        if (null !== $afterTrainingPlan) {
            return $afterTrainingPlan->getEndDay()->modify('+1 day')->setTime(0, 0);
        }

        if (null !== $selectedRaceEvent) {
            $suggestedPlanStartDay = $selectedRaceEvent->getDay()->modify('-12 weeks')->setTime(0, 0);

            return $suggestedPlanStartDay > $today ? $suggestedPlanStartDay : $today;
        }

        return $today;
    }

    private function resolveDefaultEndDay(
        ?TrainingPlan $trainingPlan,
        SerializableDateTime $defaultStartDay,
        ?RaceEvent $suggestedRaceEvent,
    ): SerializableDateTime {
        if (null !== $trainingPlan) {
            return $trainingPlan->getEndDay();
        }

        if (null !== $suggestedRaceEvent && $suggestedRaceEvent->getDay() >= $defaultStartDay) {
            return $suggestedRaceEvent->getDay()->setTime(0, 0);
        }

        return $defaultStartDay->modify('+83 days')->setTime(0, 0);
    }

    private function nullableRaceEventId(?string $value): ?RaceEventId
    {
        $value = null === $value ? null : trim($value);

        return null === $value || '' === $value ? null : RaceEventId::fromString($value);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveDefaultPerformanceMetrics(?TrainingPlan $trainingPlan, SerializableDateTime $today): array
    {
        if (null !== $trainingPlan && null !== $trainingPlan->getPerformanceMetrics()) {
            return $trainingPlan->getPerformanceMetrics();
        }

        $metrics = [];

        try {
            $cyclingAnchor = $this->performanceAnchorHistory->find(
                PerformanceAnchorType::CYCLING_THRESHOLD_POWER,
                $today,
            );
            $metrics['cyclingFtp'] = (int) $cyclingAnchor->getValue();
        } catch (\Throwable) {
        }

        try {
            $swimmingAnchor = $this->performanceAnchorHistory->find(
                PerformanceAnchorType::SWIMMING_CRITICAL_SPEED,
                $today,
            );
            $speedMs = $swimmingAnchor->getValue();
            if ($speedMs > 0) {
                $metrics['swimmingCss'] = (int) round(100.0 / $speedMs);
            }
        } catch (\Throwable) {
        }

        $sixWeeksAgo = $today->modify('-6 weeks');
        $runVolume = $this->connection->executeQuery(
            'SELECT SUM(distance) / 1000.0 as totalKm
             FROM Activity
             WHERE sportType IN (\'Run\', \'TrailRun\', \'VirtualRun\')
               AND startDateTime >= :from',
            ['from' => $sixWeeksAgo->format('Y-m-d')],
        )->fetchOne();
        if (is_numeric($runVolume) && (float) $runVolume > 0) {
            $metrics['weeklyRunningVolume'] = round((float) $runVolume / 6, 1);
        }

        $bikeVolume = $this->connection->executeQuery(
            'SELECT SUM(movingTimeInSeconds) / 3600.0 as totalHours
             FROM Activity
             WHERE sportType IN (\'Ride\', \'MountainBikeRide\', \'GravelRide\', \'EBikeRide\', \'EMountainBikeRide\', \'VirtualRide\')
               AND startDateTime >= :from',
            ['from' => $sixWeeksAgo->format('Y-m-d')],
        )->fetchOne();
        if (is_numeric($bikeVolume) && (float) $bikeVolume > 0) {
            $metrics['weeklyBikingVolume'] = round((float) $bikeVolume / 6, 1);
        }

        return $metrics;
    }

    /**
     * @return list<array{family: string, options: list<array{value: string, disciplineValues: list<string>}>}>
     */
    private function buildRaceProfileOptionGroups(): array
    {
        $grouped = [];

        foreach (RaceEventProfile::cases() as $profile) {
            $family = $profile->getFamily();
            $grouped[$family->value] ??= [
                'family' => $family->value,
                'options' => [],
            ];
            $grouped[$family->value]['options'][] = [
                'value' => $profile->value,
                'disciplineValues' => array_map(
                    static fn (TrainingPlanDiscipline $discipline): string => $discipline->value,
                    $this->resolveCompatibleDisciplinesForRaceProfile($profile),
                ),
            ];
        }

        return array_values($grouped);
    }

    /**
     * @return list<TrainingPlanDiscipline>
     */
    private function resolveCompatibleDisciplinesForRaceProfile(RaceEventProfile $profile): array
    {
        return match ($profile->getFamily()) {
            RaceEventFamily::TRIATHLON,
            RaceEventFamily::MULTISPORT,
            RaceEventFamily::SWIM => [TrainingPlanDiscipline::TRIATHLON],
            RaceEventFamily::RIDE => [TrainingPlanDiscipline::CYCLING],
            RaceEventFamily::RUN,
            RaceEventFamily::OTHER => [TrainingPlanDiscipline::RUNNING],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRaceEvent(RaceEvent $raceEvent): array
    {
        return [
            'id' => (string) $raceEvent->getId(),
            'day' => $raceEvent->getDay()->format('Y-m-d'),
            'title' => $raceEvent->getTitle() ?? $raceEvent->getProfile()->value,
            'priority' => $raceEvent->getPriority()->value,
            'profile' => $raceEvent->getProfile()->value,
            'type' => $raceEvent->getType()->value,
            'family' => $raceEvent->getFamily()->value,
        ];
    }
}
