<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Domain\Activity\ActivityType;
use App\Domain\TrainingPlanner\PlanGenerator\ProposedSession;
use App\Domain\TrainingPlanner\PlanGenerator\RaceProfileTrainingRules;
use App\Domain\TrainingPlanner\PlanGenerator\TrainingPlanGenerator;
use App\Domain\TrainingPlanner\PlanGenerator\TrainingPlanProposal;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final readonly class RacePlannerUpcomingSessionRegenerator
{
    public function __construct(
        private RaceEventRepository $raceEventRepository,
        private TrainingBlockRepository $trainingBlockRepository,
        private PlannedSessionRepository $plannedSessionRepository,
        private RacePlannerExistingBlockSelector $existingBlockSelector,
        private RacePlannerConfiguration $racePlannerConfiguration,
        private TrainingPlanGenerator $trainingPlanGenerator,
        private AdaptivePlanningContextBuilder $adaptivePlanningContextBuilder,
        private TrainingPlanRepository $trainingPlanRepository,
    ) {
    }

    public function regenerate(RaceEvent $targetRace, SerializableDateTime $now): RacePlannerUpcomingSessionRegenerationSummary
    {
        $planningContext = $this->buildPlanningContext($targetRace, $now);
        $linkedTrainingPlan = $planningContext['linkedTrainingPlan'];

        if (!$linkedTrainingPlan instanceof TrainingPlan) {
            return new RacePlannerUpcomingSessionRegenerationSummary(0, 0);
        }

        $regenerationStartDay = $now->setTime(0, 0);
        $regenerationEndDay = $linkedTrainingPlan->getEndDay()->setTime(23, 59, 59);
        $existingSessions = $planningContext['existingSessions'];

        $preservedSessions = array_values(array_filter(
            $existingSessions,
            fn (PlannedSession $plannedSession): bool => $this->shouldPreserveSession($plannedSession, $regenerationStartDay),
        ));

        $sessionsToReplace = array_values(array_filter(
            $existingSessions,
            fn (PlannedSession $plannedSession): bool => $plannedSession->getDay() >= $regenerationStartDay
                && $plannedSession->getDay() <= $regenerationEndDay
                && !$this->shouldPreserveSession($plannedSession, $regenerationStartDay),
        ));

        foreach ($sessionsToReplace as $plannedSession) {
            $this->plannedSessionRepository->delete($plannedSession->getId());
        }

        $createdSessionCount = 0;
        foreach ($this->collectRegeneratedSessions(
            proposal: $planningContext['proposal'],
            regenerationStartDay: $regenerationStartDay,
            regenerationEndDay: $regenerationEndDay,
            preservedSessions: $preservedSessions,
        ) as $proposedSession) {
            $this->plannedSessionRepository->upsert(PlannedSession::create(
                plannedSessionId: PlannedSessionId::random(),
                day: $proposedSession->getDay(),
                activityType: $proposedSession->getActivityType(),
                title: $proposedSession->getTitle(),
                notes: $proposedSession->getNotes(),
                targetLoad: null,
                targetDurationInSeconds: $proposedSession->getTargetDurationInSeconds(),
                targetIntensity: $proposedSession->getTargetIntensity(),
                templateActivityId: null,
                estimationSource: $this->determineEstimationSource($proposedSession),
                linkedActivityId: null,
                linkStatus: PlannedSessionLinkStatus::UNLINKED,
                createdAt: $now,
                updatedAt: $now,
                workoutSteps: $this->mapWorkoutStepsForPlannedSession($proposedSession->getWorkoutSteps()),
            ));
            ++$createdSessionCount;
        }

        return new RacePlannerUpcomingSessionRegenerationSummary(
            removedSessionCount: count($sessionsToReplace),
            createdSessionCount: $createdSessionCount,
        );
    }

    private function shouldPreserveSession(PlannedSession $plannedSession, SerializableDateTime $regenerationStartDay): bool
    {
        if ($plannedSession->getDay() < $regenerationStartDay) {
            return true;
        }

        return null !== $plannedSession->getLinkedActivityId();
    }

    /**
     * @param list<PlannedSession> $preservedSessions
     *
     * @return list<ProposedSession>
     */
    private function collectRegeneratedSessions(
        TrainingPlanProposal $proposal,
        SerializableDateTime $regenerationStartDay,
        SerializableDateTime $regenerationEndDay,
        array $preservedSessions,
    ): array {
        $regeneratedSessions = [];

        foreach ($proposal->getProposedBlocks() as $proposedTrainingBlock) {
            foreach ($proposedTrainingBlock->getWeekSkeletons() as $weekSkeleton) {
                foreach ($weekSkeleton->getSessions() as $proposedSession) {
                    if ($proposedSession->getDay() < $regenerationStartDay || $proposedSession->getDay() > $regenerationEndDay) {
                        continue;
                    }

                    if ($this->hasConflictingPreservedSession($preservedSessions, $proposedSession)) {
                        continue;
                    }

                    if ($this->hasEquivalentGeneratedSession($regeneratedSessions, $proposedSession)) {
                        continue;
                    }

                    $regeneratedSessions[] = $proposedSession;
                }
            }
        }

        return $regeneratedSessions;
    }

    /**
     * @param list<PlannedSession> $preservedSessions
     */
    private function hasConflictingPreservedSession(array $preservedSessions, ProposedSession $proposedSession): bool
    {
        foreach ($preservedSessions as $preservedSession) {
            if ($preservedSession->getDay()->format('Y-m-d') !== $proposedSession->getDay()->format('Y-m-d')) {
                continue;
            }

            if ($preservedSession->getActivityType() === $proposedSession->getActivityType()) {
                if ($this->canCoexistAsDoubleRun($preservedSessions, $preservedSession, $proposedSession)) {
                    continue;
                }

                return true;
            }

            if ($this->normalizeNullableString($preservedSession->getTitle()) === $this->normalizeNullableString($proposedSession->getTitle())) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<ProposedSession> $generatedSessions
     */
    private function hasEquivalentGeneratedSession(array $generatedSessions, ProposedSession $proposedSession): bool
    {
        foreach ($generatedSessions as $generatedSession) {
            if ($generatedSession->getDay()->format('Y-m-d') !== $proposedSession->getDay()->format('Y-m-d')) {
                continue;
            }

            if ($generatedSession->getActivityType() !== $proposedSession->getActivityType()) {
                continue;
            }

            if ($this->canCoexistAsDoubleRun($generatedSessions, $generatedSession, $proposedSession)) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function determineEstimationSource(ProposedSession $proposedSession): PlannedSessionEstimationSource
    {
        if ($proposedSession->hasWorkoutSteps()) {
            return PlannedSessionEstimationSource::WORKOUT_TARGETS;
        }

        return null !== $proposedSession->getTargetDurationInSeconds() || null !== $proposedSession->getTargetIntensity()
            ? PlannedSessionEstimationSource::DURATION_INTENSITY
            : PlannedSessionEstimationSource::UNKNOWN;
    }

    /**
     * @param list<array<string, mixed>> $workoutSteps
     *
     * @return list<array<string, mixed>>
     */
    private function mapWorkoutStepsForPlannedSession(array $workoutSteps): array
    {
        $mappedSteps = [];
        $sequence = 1;

        $appendMappedSteps = function (array $steps, ?string $parentBlockId) use (&$appendMappedSteps, &$mappedSteps, &$sequence): void {
            foreach ($steps as $step) {
                $itemId = sprintf('generated-step-%d', $sequence++);
                $type = (string) ($step['type'] ?? 'steady');
                $mappedStep = [
                    'itemId' => $itemId,
                    'type' => $type,
                    'label' => $this->normalizeNullableString(isset($step['label']) ? (string) $step['label'] : null),
                    'targetType' => $this->normalizeNullableString(isset($step['targetType']) ? (string) $step['targetType'] : null),
                    'durationInSeconds' => isset($step['durationInSeconds']) ? (int) $step['durationInSeconds'] : null,
                    'distanceInMeters' => isset($step['distanceInMeters']) ? (int) $step['distanceInMeters'] : null,
                    'targetPower' => isset($step['targetPower']) ? (int) $step['targetPower'] : null,
                    'targetPace' => $this->normalizeNullableString(isset($step['targetPace']) ? (string) $step['targetPace'] : null),
                    'targetHeartRate' => isset($step['targetHeartRate']) ? (int) $step['targetHeartRate'] : null,
                    'recoveryAfterInSeconds' => isset($step['recoveryAfterInSeconds']) ? (int) $step['recoveryAfterInSeconds'] : null,
                    'parentBlockId' => $parentBlockId,
                ];

                if ('repeatBlock' === $type) {
                    $mappedStep['repetitions'] = max(1, (int) ($step['repetitions'] ?? 1));
                }

                $mappedSteps[] = array_filter($mappedStep, static fn (mixed $value): bool => null !== $value);

                if ('repeatBlock' === $type && is_array($step['steps'] ?? null)) {
                    /** @var list<array<string, mixed>> $childSteps */
                    $childSteps = $step['steps'];
                    $appendMappedSteps($childSteps, $itemId);
                }
            }
        };

        $appendMappedSteps($workoutSteps, null);

        return $mappedSteps;
    }

    private function normalizeNullableString(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }

    /**
     * @param list<PlannedSession|ProposedSession> $sessions
     */
    private function canCoexistAsDoubleRun(array $sessions, PlannedSession|ProposedSession $existingSession, ProposedSession $proposedSession): bool
    {
        if (ActivityType::RUN !== $existingSession->getActivityType() || ActivityType::RUN !== $proposedSession->getActivityType()) {
            return false;
        }

        if ($this->isBrickLikeRun($existingSession, $sessions) || $proposedSession->isBrickSession()) {
            return false;
        }

        if (!$this->isSecondaryRunTitle($existingSession->getTitle()) && !$this->isSecondaryRunTitle($proposedSession->getTitle())) {
            return false;
        }

        return $this->countRunSessionsOnDay($sessions, $proposedSession->getDay()) < 2;
    }

    /**
     * @param list<PlannedSession|ProposedSession> $sessions
     */
    private function isBrickLikeRun(PlannedSession|ProposedSession $session, array $sessions): bool
    {
        if (ActivityType::RUN !== $session->getActivityType()) {
            return false;
        }

        if ($session instanceof ProposedSession && $session->isBrickSession()) {
            return true;
        }

        $title = strtolower(trim((string) $session->getTitle()));
        if ('brick run' === $title) {
            return true;
        }

        foreach ($sessions as $candidateSession) {
            if ($candidateSession->getDay()->format('Y-m-d') !== $session->getDay()->format('Y-m-d')) {
                continue;
            }

            if (ActivityType::RIDE === $candidateSession->getActivityType()) {
                return true;
            }
        }

        return false;
    }

    private function isSecondaryRunTitle(?string $title): bool
    {
        return null !== $title && str_starts_with(strtolower(trim($title)), 'secondary ');
    }

    /**
     * @param list<PlannedSession|ProposedSession> $sessions
     */
    private function countRunSessionsOnDay(array $sessions, SerializableDateTime $day): int
    {
        return count(array_filter($sessions, static function (PlannedSession|ProposedSession $session) use ($day): bool {
            return $session->getDay()->format('Y-m-d') === $day->format('Y-m-d')
                && ActivityType::RUN === $session->getActivityType();
        }));
    }

    /**
     * @return array{proposal: TrainingPlanProposal, existingBlocks: list<TrainingBlock>, existingSessions: list<PlannedSession>, linkedTrainingPlan: ?TrainingPlan}
     */
    private function buildPlanningContext(RaceEvent $targetRace, SerializableDateTime $now): array
    {
        $upcomingRaces = $this->raceEventRepository->findUpcoming($now, 20);
        if ([] === $upcomingRaces) {
            $upcomingRaces = [$targetRace];
        }

        $linkedTrainingPlan = $this->trainingPlanRepository->findByTargetRaceEventId($targetRace->getId());
        $rules = RaceProfileTrainingRules::forProfile($linkedTrainingPlan?->getTargetRaceProfile() ?? $targetRace->getProfile());
        $configuredPlanStartDay = $this->racePlannerConfiguration->findPlanStartDay();
        $planningEndDay = $this->resolvePlanningEndDay($targetRace, $rules);
        $searchRange = DateRange::fromDates(
            $targetRace->getDay()->modify(sprintf('-%d weeks', max(32, $rules->getMaximumPlanWeeks() + 6)))->setTime(0, 0),
            $planningEndDay,
        );
        $blocksInPlanningWindow = $this->trainingBlockRepository->findByDateRange($searchRange);
        $reusableExistingBlocks = $this->existingBlockSelector->selectReusableBlocks($targetRace, $blocksInPlanningWindow, $planningEndDay);
        $effectivePlanStartDay = $this->resolveEffectivePlanStartDay(
            $configuredPlanStartDay,
            $targetRace,
            $now,
            ($reusableExistingBlocks[0] ?? null)?->getStartDay(),
        );

        $dateRange = DateRange::fromDates($effectivePlanStartDay, $planningEndDay);
        $existingBlocks = [] !== $reusableExistingBlocks
            ? $reusableExistingBlocks
            : $this->trainingBlockRepository->findByDateRange($dateRange);
        $existingSessions = $this->plannedSessionRepository->findByDateRange($dateRange);
        $regenerationStartDay = $now->setTime(0, 0);
        $currentWeekStartDay = SerializableDateTime::fromDateTimeImmutable($regenerationStartDay->modify('monday this week'))->setTime(0, 0);
        $preservedSessions = array_values(array_filter(
            $existingSessions,
            fn (PlannedSession $plannedSession): bool => $this->shouldPreserveSession($plannedSession, $regenerationStartDay),
        ));
        $planningSeedSessions = array_values(array_filter(
            $preservedSessions,
            static fn (PlannedSession $plannedSession): bool => $plannedSession->getDay() < $currentWeekStartDay,
        ));
        $adaptivePlanningContext = $this->adaptivePlanningContextBuilder->build(
            referenceDate: $now,
            plannedSessions: $preservedSessions,
            raceEvents: $upcomingRaces,
            trainingBlocks: $existingBlocks,
        );

        return [
            'proposal' => $this->trainingPlanGenerator->generate(
                targetRace: $targetRace,
                planStartDay: $effectivePlanStartDay,
                allRaceEvents: $upcomingRaces,
                existingBlocks: $existingBlocks,
                existingSessions: $planningSeedSessions,
                referenceDate: $now,
                linkedTrainingPlan: $linkedTrainingPlan,
                adaptivePlanningContext: $adaptivePlanningContext,
            ),
            'existingBlocks' => $existingBlocks,
            'existingSessions' => $existingSessions,
            'linkedTrainingPlan' => $linkedTrainingPlan,
        ];
    }

    private function resolveEffectivePlanStartDay(
        ?SerializableDateTime $configuredPlanStartDay,
        RaceEvent $targetRace,
        SerializableDateTime $now,
        ?SerializableDateTime $existingBlockStartDay = null,
    ): SerializableDateTime {
        $planStartDay = ($existingBlockStartDay ?? $configuredPlanStartDay ?? $now)->setTime(0, 0);
        $targetRaceDay = $targetRace->getDay()->setTime(0, 0);

        return $planStartDay > $targetRaceDay ? $targetRaceDay : $planStartDay;
    }

    private function resolvePlanningEndDay(RaceEvent $targetRace, RaceProfileTrainingRules $rules): SerializableDateTime
    {
        $recoveryWeeks = $rules->getPostRaceRecoveryWeeks();
        if (0 === $recoveryWeeks) {
            return $targetRace->getDay()->setTime(23, 59, 59);
        }

        return $targetRace->getDay()->modify(sprintf('+%d weeks', $recoveryWeeks))->setTime(23, 59, 59);
    }
}