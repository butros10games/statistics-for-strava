<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\TrainingPlanner\RaceEvent;
use App\Domain\TrainingPlanner\RaceEventRepository;
use App\Domain\TrainingPlanner\TrainingPlan;
use App\Domain\TrainingPlanner\TrainingPlanRepository;
use App\Domain\TrainingPlanner\TrainingPlanType;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class ReactPreviewTrainingPlansApiRequestHandler
{
    public function __construct(
        private CurrentAppUser $currentAppUser,
        private TrainingPlanRepository $trainingPlanRepository,
        private RaceEventRepository $raceEventRepository,
        private Clock $clock,
        private TrainingPlanRequestHandler $trainingPlanRequestHandler,
    ) {
    }

    #[Route(path: '/react-preview/api/training-plans', methods: ['GET'], priority: 6)]
    public function handle(): JsonResponse
    {
        $this->currentAppUser->require();

        return new JsonResponse($this->buildPayload());
    }

    #[Route(path: '/react-preview/api/training-plans', methods: ['POST'], priority: 6)]
    public function create(Request $request): JsonResponse
    {
        $this->currentAppUser->require();

        $payload = $request->toArray();
        $submitRequest = new Request(
            request: [
                ...$payload,
                'redirectTo' => '/react-preview/training-plans',
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        );

        $this->trainingPlanRequestHandler->handle($submitRequest);

        return new JsonResponse($this->buildPayload());
    }

    #[Route(path: '/react-preview/api/training-plans/{trainingPlanId}', methods: ['DELETE'], priority: 6)]
    public function delete(string $trainingPlanId): JsonResponse
    {
        $this->currentAppUser->require();

        $deleteRequest = new Request(
            request: [
                'trainingPlanId' => $trainingPlanId,
                'redirectTo' => '/react-preview/training-plans',
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        );

        $this->trainingPlanRequestHandler->delete($deleteRequest);

        return new JsonResponse($this->buildPayload());
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(): array
    {
        $now = $this->clock->getCurrentDateTimeImmutable()->setTime(0, 0);
        $plans = $this->trainingPlanRepository->findAll();
        $raceEvents = $this->loadRaceEvents();
        $raceEventsById = $this->buildRaceEventsById($raceEvents);
        [$planRecords, $continuitySummary] = $this->buildPlanRecords($plans, $raceEvents, $raceEventsById, $now);
        $linkedRaceEventIds = $this->buildLinkedRaceEventIds($plans, $raceEvents);
        $unassignedUpcomingRaces = array_values(array_filter(
            $raceEvents,
            static fn (RaceEvent $raceEvent): bool => $raceEvent->getDay() >= $now && !isset($linkedRaceEventIds[(string) $raceEvent->getId()]),
        ));
        $latestPlan = $this->findLatestPlan($plans);
        $nextSuggestedStartDay = null === $latestPlan
            ? $now
            : $latestPlan->getEndDay()->modify('+1 day')->setTime(0, 0);
        $activePlanRecord = $this->findActiveOrNextPlanRecord($planRecords);

        return [
            'requestedAt' => $now->format(DATE_ATOM),
            'activePlanId' => $activePlanRecord['id'] ?? null,
            'stats' => [
                'totalPlans' => count($plans),
                'racePlans' => count(array_filter($plans, static fn (TrainingPlan $plan): bool => TrainingPlanType::RACE === $plan->getType())),
                'trainingPlans' => count(array_filter($plans, static fn (TrainingPlan $plan): bool => TrainingPlanType::TRAINING === $plan->getType())),
                'gapCount' => $continuitySummary['gapCount'],
                'overlapCount' => $continuitySummary['overlapCount'],
                'handoffCount' => $continuitySummary['handoffCount'],
                'unassignedUpcomingRaces' => count($unassignedUpcomingRaces),
                'nextSuggestedStartDay' => $nextSuggestedStartDay->format('Y-m-d'),
            ],
            'plans' => $planRecords,
            'unassignedUpcomingRaces' => array_map(
                fn (RaceEvent $raceEvent): array => $this->serializeRaceEvent($raceEvent),
                $unassignedUpcomingRaces,
            ),
        ];
    }

    /**
     * @return list<RaceEvent>
     */
    private function loadRaceEvents(): array
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
     *
     * @return array<string, RaceEvent>
     */
    private function buildRaceEventsById(array $raceEvents): array
    {
        $indexed = [];

        foreach ($raceEvents as $raceEvent) {
            $indexed[(string) $raceEvent->getId()] = $raceEvent;
        }

        return $indexed;
    }

    /**
     * @param list<TrainingPlan> $plans
     * @param list<RaceEvent> $raceEvents
     *
     * @return array<string, true>
     */
    private function buildLinkedRaceEventIds(array $plans, array $raceEvents): array
    {
        $linkedRaceEventIds = [];

        foreach ($plans as $plan) {
            if (null !== $plan->getTargetRaceEventId()) {
                $linkedRaceEventIds[(string) $plan->getTargetRaceEventId()] = true;
            }

            foreach ($this->findRacesInPlanWindow($plan, $raceEvents) as $raceEvent) {
                $linkedRaceEventIds[(string) $raceEvent->getId()] = true;
            }
        }

        return $linkedRaceEventIds;
    }

    /**
     * @param list<TrainingPlan> $plans
     * @param list<RaceEvent> $raceEvents
     * @param array<string, RaceEvent> $raceEventsById
     *
     * @return array{0: list<array<string, mixed>>, 1: array{gapCount: int, overlapCount: int, handoffCount: int}}
     */
    private function buildPlanRecords(array $plans, array $raceEvents, array $raceEventsById, SerializableDateTime $now): array
    {
        $planRecords = [];
        $gapCount = 0;
        $overlapCount = 0;
        $handoffCount = 0;

        foreach ($plans as $index => $plan) {
            $nextPlan = $plans[$index + 1] ?? null;
            $linkedRace = null === $plan->getTargetRaceEventId()
                ? null
                : ($raceEventsById[(string) $plan->getTargetRaceEventId()] ?? null);
            $linkedRaceState = null === $plan->getTargetRaceEventId()
                ? 'none'
                : (null === $linkedRace
                    ? 'missing'
                    : ($linkedRace->getDay() < $plan->getStartDay() || $linkedRace->getDay() > $plan->getEndDay()
                        ? 'outside-window'
                        : 'linked'));
            $windowRaces = $this->findRacesInPlanWindow($plan, $raceEvents);
            $continuity = null;

            if ($nextPlan instanceof TrainingPlan) {
                $daysBetweenPlans = (int) $plan->getEndDay()
                    ->modify('+1 day')
                    ->diff($nextPlan->getStartDay())
                    ->format('%r%a');

                if ($daysBetweenPlans > 0) {
                    ++$gapCount;
                    $continuity = [
                        'kind' => 'gap',
                        'days' => $daysBetweenPlans,
                        'nextPlanId' => (string) $nextPlan->getId(),
                        'nextPlanTitle' => $this->buildPlanTitle($nextPlan),
                    ];
                } elseif ($daysBetweenPlans < 0) {
                    ++$overlapCount;
                    $continuity = [
                        'kind' => 'overlap',
                        'days' => abs($daysBetweenPlans),
                        'nextPlanId' => (string) $nextPlan->getId(),
                        'nextPlanTitle' => $this->buildPlanTitle($nextPlan),
                    ];
                } else {
                    ++$handoffCount;
                    $continuity = [
                        'kind' => 'handoff',
                        'days' => 0,
                        'nextPlanId' => (string) $nextPlan->getId(),
                        'nextPlanTitle' => $this->buildPlanTitle($nextPlan),
                    ];
                }
            }

            $planRecords[] = [
                'id' => (string) $plan->getId(),
                'title' => $this->buildPlanTitle($plan, $linkedRace),
                'status' => $this->resolveStatus($plan, $now),
                'type' => $plan->getType()->value,
                'startDay' => $plan->getStartDay()->format('Y-m-d'),
                'endDay' => $plan->getEndDay()->format('Y-m-d'),
                'durationDays' => $plan->getDurationInDays(),
                'durationWeeks' => $plan->getDurationInWeeks(),
                'notes' => $plan->getNotes(),
                'racePlannerPath' => sprintf('race-planner/plan-%s', $plan->getId()),
                'visibility' => $plan->getVisibility()->value,
                'linkedRace' => $linkedRace instanceof RaceEvent ? $this->serializeRaceEvent($linkedRace) : null,
                'linkedRaceState' => $linkedRaceState,
                'windowRaces' => array_map(
                    fn (RaceEvent $raceEvent): array => $this->serializeRaceEvent($raceEvent),
                    $windowRaces,
                ),
                'continuity' => $continuity,
                'discipline' => $plan->getDiscipline()?->value,
                'objective' => TrainingPlanType::RACE === $plan->getType()
                    ? ($plan->getTargetRaceProfile()?->value ?? $linkedRace?->getProfile()->value)
                    : ($plan->getTrainingBlockStyle()?->value ?? $plan->getTrainingFocus()?->value),
                'scheduleHighlights' => $this->buildScheduleHighlights($plan),
                'performanceHighlights' => $this->buildPerformanceHighlights($plan),
            ];
        }

        return [$planRecords, [
            'gapCount' => $gapCount,
            'overlapCount' => $overlapCount,
            'handoffCount' => $handoffCount,
        ]];
    }

    private function resolveStatus(TrainingPlan $plan, SerializableDateTime $now): string
    {
        if ($plan->containsDay($now)) {
            return 'current';
        }

        return $plan->getStartDay() > $now ? 'upcoming' : 'completed';
    }

    /**
     * @return list<string>
     */
    private function buildScheduleHighlights(TrainingPlan $plan): array
    {
        $sportSchedule = $plan->getSportSchedule();
        if (!is_array($sportSchedule) || [] === $sportSchedule) {
            return [];
        }

        $highlights = [];
        foreach ([
            'swimDays' => 'Swim',
            'bikeDays' => 'Bike',
            'runDays' => 'Run',
            'longRideDays' => 'Long ride',
            'longRunDays' => 'Long run',
        ] as $key => $label) {
            $days = $sportSchedule[$key] ?? null;
            if (!is_array($days) || [] === $days) {
                continue;
            }

            $highlights[] = sprintf('%s %s', $label, implode('/', array_map($this->formatIsoDayNumber(...), $days)));
        }

        return $highlights;
    }

    /**
     * @return list<string>
     */
    private function buildPerformanceHighlights(TrainingPlan $plan): array
    {
        $metrics = $plan->getPerformanceMetrics();
        if (!is_array($metrics) || [] === $metrics) {
            return [];
        }

        $highlights = [];

        if (isset($metrics['cyclingFtp']) && is_numeric($metrics['cyclingFtp'])) {
            $highlights[] = sprintf('FTP %dW', (int) $metrics['cyclingFtp']);
        }

        if (isset($metrics['runningThresholdPace']) && is_numeric($metrics['runningThresholdPace'])) {
            $highlights[] = sprintf('Threshold %s/km', $this->formatPace((int) $metrics['runningThresholdPace']));
        }

        if (isset($metrics['swimmingCss']) && is_numeric($metrics['swimmingCss'])) {
            $highlights[] = sprintf('CSS %s/100m', $this->formatPace((int) $metrics['swimmingCss']));
        }

        if (isset($metrics['weeklyRunningVolume']) && is_numeric($metrics['weeklyRunningVolume'])) {
            $highlights[] = sprintf('Run vol %s km/wk', $this->formatOneDecimal((float) $metrics['weeklyRunningVolume']));
        }

        if (isset($metrics['weeklyBikingVolume']) && is_numeric($metrics['weeklyBikingVolume'])) {
            $highlights[] = sprintf('Bike vol %s h/wk', $this->formatOneDecimal((float) $metrics['weeklyBikingVolume']));
        }

        return $highlights;
    }

    private function formatIsoDayNumber(mixed $value): string
    {
        return match ((int) $value) {
            1 => 'Mon',
            2 => 'Tue',
            3 => 'Wed',
            4 => 'Thu',
            5 => 'Fri',
            6 => 'Sat',
            7 => 'Sun',
            default => '?',
        };
    }

    private function formatPace(int $seconds): string
    {
        $minutes = intdiv(max(0, $seconds), 60);
        $remainingSeconds = max(0, $seconds % 60);

        return sprintf('%d:%02d', $minutes, $remainingSeconds);
    }

    private function formatOneDecimal(float $value): string
    {
        $formatted = number_format($value, 1, '.', '');

        return str_ends_with($formatted, '.0') ? substr($formatted, 0, -2) : $formatted;
    }

    /**
     * @param list<array<string, mixed>> $planRecords
     *
     * @return null|array<string, mixed>
     */
    private function findActiveOrNextPlanRecord(array $planRecords): ?array
    {
        foreach ($planRecords as $planRecord) {
            if ('current' === $planRecord['status']) {
                return $planRecord;
            }
        }

        foreach ($planRecords as $planRecord) {
            if ('upcoming' === $planRecord['status']) {
                return $planRecord;
            }
        }

        return $planRecords[0] ?? null;
    }

    /**
     * @param list<TrainingPlan> $plans
     */
    private function findLatestPlan(array $plans): ?TrainingPlan
    {
        $latestPlan = null;

        foreach ($plans as $plan) {
            if (!$latestPlan instanceof TrainingPlan) {
                $latestPlan = $plan;

                continue;
            }

            if ($plan->getEndDay() > $latestPlan->getEndDay()) {
                $latestPlan = $plan;

                continue;
            }

            if ($plan->getEndDay() == $latestPlan->getEndDay() && $plan->getUpdatedAt() > $latestPlan->getUpdatedAt()) {
                $latestPlan = $plan;
            }
        }

        return $latestPlan;
    }

    /**
     * @param list<RaceEvent> $raceEvents
     *
     * @return list<RaceEvent>
     */
    private function findRacesInPlanWindow(TrainingPlan $plan, array $raceEvents): array
    {
        return array_values(array_filter(
            $raceEvents,
            static fn (RaceEvent $raceEvent): bool => $plan->containsDay($raceEvent->getDay()),
        ));
    }

    private function buildPlanTitle(TrainingPlan $plan, ?RaceEvent $linkedRace = null): string
    {
        if (null !== $plan->getTitle()) {
            return $plan->getTitle();
        }

        if ($linkedRace instanceof RaceEvent) {
            return $linkedRace->getTitle() ?? $linkedRace->getProfile()->value;
        }

        return TrainingPlanType::RACE === $plan->getType() ? 'Race plan' : 'Training plan';
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