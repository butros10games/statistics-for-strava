<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Domain\Activity\ActivityType;
use App\Domain\TrainingPlanner\PlanGenerator\ProposedSession;
use App\Domain\TrainingPlanner\PlanGenerator\ProposedTrainingBlock;
use App\Domain\TrainingPlanner\PlanGenerator\RaceProfileTrainingRules;
use App\Domain\TrainingPlanner\PlanGenerator\TrainingPlanGenerator;
use App\Domain\TrainingPlanner\PlanGenerator\TrainingPlanProposal;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final readonly class RacePlannerRecoveryManager
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

    public function summarizeFromProposal(
        TrainingPlanProposal $proposal,
        array $existingBlocks,
        array $existingSessions,
    ): RacePlannerRecoverySaveSummary {
        [$missingRecoveryBlocks, $missingRecoverySessions] = $this->extractMissingRecoveryItems(
            $proposal,
            $existingBlocks,
            $existingSessions,
        );

        return new RacePlannerRecoverySaveSummary(
            missingRecoveryBlockCount: count($missingRecoveryBlocks),
            missingRecoverySessionCount: count($missingRecoverySessions),
        );
    }

    public function save(RaceEvent $targetRace, SerializableDateTime $now): RacePlannerRecoverySaveSummary
    {
        $planningContext = $this->buildPlanningContext($targetRace, $now);
        [$missingRecoveryBlocks, $missingRecoverySessions] = $this->extractMissingRecoveryItems(
            $planningContext['proposal'],
            $planningContext['existingBlocks'],
            $planningContext['existingSessions'],
        );

        foreach ($missingRecoveryBlocks as $proposedTrainingBlock) {
            $this->trainingBlockRepository->upsert(TrainingBlock::create(
                trainingBlockId: TrainingBlockId::random(),
                startDay: $proposedTrainingBlock->getStartDay(),
                endDay: $proposedTrainingBlock->getEndDay(),
                targetRaceEventId: $proposedTrainingBlock->getTargetRaceEventId(),
                phase: $proposedTrainingBlock->getPhase(),
                title: $proposedTrainingBlock->getTitle(),
                focus: $proposedTrainingBlock->getFocus(),
                notes: null,
                createdAt: $now,
                updatedAt: $now,
            ));
        }

        foreach ($missingRecoverySessions as $missingRecoverySession) {
            $proposedSession = $missingRecoverySession['session'];

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
                workoutSteps: $this->mapWorkoutStepsForPlannedSession($proposedSession->getWorkoutSteps()),
                estimationSource: $this->determineEstimationSource($proposedSession),
                linkedActivityId: null,
                linkStatus: PlannedSessionLinkStatus::UNLINKED,
                createdAt: $now,
                updatedAt: $now,
            ));
        }

        return new RacePlannerRecoverySaveSummary(
            missingRecoveryBlockCount: count($missingRecoveryBlocks),
            missingRecoverySessionCount: count($missingRecoverySessions),
        );
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

    /**
     * @param list<TrainingBlock> $existingBlocks
     * @param list<PlannedSession> $existingSessions
     *
     * @return array{0: list<ProposedTrainingBlock>, 1: list<array{block: ProposedTrainingBlock, session: ProposedSession}>}
     */
    private function extractMissingRecoveryItems(
        TrainingPlanProposal $proposal,
        array $existingBlocks,
        array $existingSessions,
    ): array {
        $missingRecoveryBlocks = [];
        $missingRecoverySessions = [];

        foreach ($proposal->getProposedBlocks() as $proposedTrainingBlock) {
            if (TrainingBlockPhase::RECOVERY !== $proposedTrainingBlock->getPhase()) {
                continue;
            }

            if (!$this->hasEquivalentTrainingBlock($existingBlocks, $proposedTrainingBlock)) {
                $missingRecoveryBlocks[] = $proposedTrainingBlock;
            }

            foreach ($proposedTrainingBlock->getWeekSkeletons() as $weekSkeleton) {
                foreach ($weekSkeleton->getSessions() as $proposedSession) {
                    if ($this->hasEquivalentPlannedSession($existingSessions, $proposedSession)) {
                        continue;
                    }

                    $missingRecoverySessions[] = [
                        'block' => $proposedTrainingBlock,
                        'session' => $proposedSession,
                    ];
                }
            }
        }

        return [$missingRecoveryBlocks, $missingRecoverySessions];
    }

    /**
     * @param list<TrainingBlock> $existingBlocks
     */
    private function hasEquivalentTrainingBlock(array $existingBlocks, ProposedTrainingBlock $proposedTrainingBlock): bool
    {
        foreach ($existingBlocks as $existingBlock) {
            if (TrainingBlockPhase::RECOVERY !== $existingBlock->getPhase()) {
                continue;
            }

            if ($existingBlock->getStartDay()->format('Y-m-d') !== $proposedTrainingBlock->getStartDay()->format('Y-m-d')) {
                continue;
            }

            if ($existingBlock->getEndDay()->format('Y-m-d') !== $proposedTrainingBlock->getEndDay()->format('Y-m-d')) {
                continue;
            }

            if ((string) $existingBlock->getTargetRaceEventId() !== (string) $proposedTrainingBlock->getTargetRaceEventId()) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @param list<PlannedSession> $existingSessions
     */
    private function hasEquivalentPlannedSession(array $existingSessions, ProposedSession $proposedSession): bool
    {
        foreach ($existingSessions as $existingSession) {
            if ($existingSession->getDay()->format('Y-m-d') !== $proposedSession->getDay()->format('Y-m-d')) {
                continue;
            }

            if ($existingSession->getActivityType() !== $proposedSession->getActivityType()) {
                continue;
            }

            if ($this->canCoexistAsDoubleRun($existingSessions, $existingSession, $proposedSession)) {
                continue;
            }

            if ($this->normalizeNullableString($existingSession->getTitle()) !== $this->normalizeNullableString($proposedSession->getTitle())) {
                continue;
            }

            if ($existingSession->getTargetIntensity() !== $proposedSession->getTargetIntensity()) {
                continue;
            }

            if ($existingSession->getTargetDurationInSeconds() !== $proposedSession->getTargetDurationInSeconds()) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @param list<PlannedSession> $sessions
     */
    private function canCoexistAsDoubleRun(array $sessions, PlannedSession $existingSession, ProposedSession $proposedSession): bool
    {
        if (ActivityType::RUN !== $existingSession->getActivityType() || ActivityType::RUN !== $proposedSession->getActivityType()) {
            return false;
        }

        if ('brick run' === strtolower(trim((string) $existingSession->getTitle())) || $proposedSession->isBrickSession()) {
            return false;
        }

        if (!$this->isSecondaryRunTitle($existingSession->getTitle()) && !$this->isSecondaryRunTitle($proposedSession->getTitle())) {
            return false;
        }

        return $this->countRunSessionsOnDay($sessions, $proposedSession->getDay()) < 2;
    }

    private function isSecondaryRunTitle(?string $title): bool
    {
        return null !== $title && str_starts_with(strtolower(trim($title)), 'secondary ');
    }

    /**
     * @param list<PlannedSession> $sessions
     */
    private function countRunSessionsOnDay(array $sessions, SerializableDateTime $day): int
    {
        return count(array_filter($sessions, static function (PlannedSession $session) use ($day): bool {
            return $session->getDay()->format('Y-m-d') === $day->format('Y-m-d')
                && ActivityType::RUN === $session->getActivityType();
        }));
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
     * @return array{proposal: TrainingPlanProposal, existingBlocks: list<TrainingBlock>, existingSessions: list<PlannedSession>}
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
        $adaptivePlanningContext = $this->adaptivePlanningContextBuilder->build(
            referenceDate: $now,
            plannedSessions: $existingSessions,
            raceEvents: $upcomingRaces,
            trainingBlocks: $existingBlocks,
        );

        return [
            'proposal' => $this->trainingPlanGenerator->generate(
                targetRace: $targetRace,
                planStartDay: $effectivePlanStartDay,
                allRaceEvents: $upcomingRaces,
                existingBlocks: $existingBlocks,
                existingSessions: $existingSessions,
                referenceDate: $now,
                linkedTrainingPlan: $linkedTrainingPlan,
                adaptivePlanningContext: $adaptivePlanningContext,
            ),
            'existingBlocks' => $existingBlocks,
            'existingSessions' => $existingSessions,
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