<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner\PlanGenerator;

use App\Domain\Activity\ActivityType;
use App\Domain\Dashboard\Widget\TrainingLoad\ReadinessStatus;
use App\Domain\TrainingPlanner\AdaptivePlanningContext;
use App\Domain\TrainingPlanner\PlannedSession;
use App\Domain\TrainingPlanner\PlannedSessionIntensity;
use App\Domain\TrainingPlanner\RaceEvent;
use App\Domain\TrainingPlanner\RaceEventFamily;
use App\Domain\TrainingPlanner\RaceEventProfile;
use App\Domain\TrainingPlanner\RaceEventPriority;
use App\Domain\TrainingPlanner\RunningWorkoutTargetMode;
use App\Domain\TrainingPlanner\TrainingBlock;
use App\Domain\TrainingPlanner\TrainingBlockPhase;
use App\Domain\TrainingPlanner\TrainingBlockStyle;
use App\Domain\TrainingPlanner\TrainingFocus;
use App\Domain\TrainingPlanner\TrainingPlan;
use App\Domain\TrainingPlanner\TrainingPlanDiscipline;
use App\Domain\TrainingPlanner\TrainingPlanType;
use App\Domain\TrainingPlanner\TrainingSession;
use App\Domain\TrainingPlanner\TrainingSessionObjective;
use App\Domain\TrainingPlanner\TrainingSessionRecommendationCriteria;
use App\Domain\TrainingPlanner\TrainingSessionRepository;
use App\Domain\TrainingPlanner\Prediction\RunningPlanPerformancePredictor;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final readonly class TrainingPlanGenerator
{
    public function __construct(
        private ?TrainingSessionRepository $trainingSessionRepository = null,
        private ?RunningPlanPerformancePredictor $runningPlanPerformancePredictor = null,
    ) {
    }

    /**
     * @param list<RaceEvent> $allRaceEvents all upcoming race events, sorted by day ASC
     */
    public function generate(
        RaceEvent $targetRace,
        SerializableDateTime $planStartDay,
        array $allRaceEvents = [],
        array $existingBlocks = [],
        array $existingSessions = [],
        ?SerializableDateTime $referenceDate = null,
        ?TrainingPlan $linkedTrainingPlan = null,
        ?AdaptivePlanningContext $adaptivePlanningContext = null,
    ): TrainingPlanProposal {
        $rules = RaceProfileTrainingRules::forProfile($linkedTrainingPlan?->getTargetRaceProfile() ?? $targetRace->getProfile());
        $raceDay = $targetRace->getDay();
        $planStart = ([] !== $existingBlocks ? $existingBlocks[0]->getStartDay() : $planStartDay)->setTime(0, 0);

        $preRaceTotalDays = max(1, (int) $planStart->diff($raceDay->setTime(23, 59, 59))->days);
        $preRaceTotalWeeks = max(1, (int) ceil($preRaceTotalDays / 7));

        $warnings = $this->detectWarnings($targetRace, $rules, $preRaceTotalWeeks, $allRaceEvents);
        $proposedBlocks = [] === $existingBlocks
            ? $this->populateWeekSkeletons(
                $this->buildBlockPeriodization($planStart, $raceDay, $rules, $targetRace, $preRaceTotalWeeks, $linkedTrainingPlan),
                $rules,
                $targetRace,
                $allRaceEvents,
                $linkedTrainingPlan,
                $adaptivePlanningContext,
            )
            : $this->buildProposalFromExistingBlocks(
                blocks: $existingBlocks,
                existingSessions: $existingSessions,
                rules: $rules,
                targetRace: $targetRace,
                allRaceEvents: $allRaceEvents,
                referenceDate: ($referenceDate ?? $planStart)->setTime(0, 0),
                linkedTrainingPlan: $linkedTrainingPlan,
                adaptivePlanningContext: $adaptivePlanningContext,
            );

        $planEnd = $this->resolvePlanEndDay($targetRace, $proposedBlocks);
        $totalDays = max(1, (int) $planStart->diff($planEnd)->days);
        $totalWeeks = max(1, (int) ceil($totalDays / 7));
        $proposal = TrainingPlanProposal::create(
            targetRace: $targetRace,
            rules: $rules,
            planStartDay: $planStart,
            planEndDay: $planEnd,
            totalWeeks: $totalWeeks,
            proposedBlocks: $proposedBlocks,
            warnings: $warnings,
        );
        $proposedBlocks = $this->applyRunningProgressionToProposedBlocks(
            proposedBlocks: $proposedBlocks,
            linkedTrainingPlan: $linkedTrainingPlan,
            proposal: $proposal,
            existingSessions: $existingSessions,
            referenceDate: $referenceDate,
        );

        return TrainingPlanProposal::create(
            targetRace: $targetRace,
            rules: $rules,
            planStartDay: $planStart,
            planEndDay: $planEnd,
            totalWeeks: $totalWeeks,
            proposedBlocks: $proposedBlocks,
            warnings: $warnings,
        );
    }

    /**
     * @param list<ProposedTrainingBlock> $proposedBlocks
     *
     * @return list<ProposedTrainingBlock>
     */
    private function applyRunningProgressionToProposedBlocks(
        array $proposedBlocks,
        ?TrainingPlan $linkedTrainingPlan,
        TrainingPlanProposal $proposal,
        array $existingSessions = [],
        ?SerializableDateTime $referenceDate = null,
    ): array {
        if (!$linkedTrainingPlan instanceof TrainingPlan) {
            return $proposedBlocks;
        }

        $predictor = $this->runningPlanPerformancePredictor ?? new RunningPlanPerformancePredictor();
        $prediction = $predictor->predict($linkedTrainingPlan, $proposal, $existingSessions, $referenceDate);
        if (null === $prediction) {
            return $proposedBlocks;
        }

        $projectedThresholdPacesByWeekStartDate = $prediction->getProjectedThresholdPaceByWeekStartDate();

        return array_map(function (ProposedTrainingBlock $block) use ($projectedThresholdPacesByWeekStartDate): ProposedTrainingBlock {
            $updatedWeekSkeletons = array_map(function (ProposedWeekSkeleton $week) use ($projectedThresholdPacesByWeekStartDate): ProposedWeekSkeleton {
                $projectedThresholdPaceInSeconds = $projectedThresholdPacesByWeekStartDate[$week->getStartDay()->format('Y-m-d')] ?? null;
                if (null === $projectedThresholdPaceInSeconds || $week->isManuallyPlanned()) {
                    return $week;
                }

                $updatedSessions = array_map(
                    fn (ProposedSession $session): ProposedSession => $this->applyProjectedRunningThresholdToSession($session, $projectedThresholdPaceInSeconds),
                    $week->getSessions(),
                );

                return ProposedWeekSkeleton::create(
                    weekNumber: $week->getWeekNumber(),
                    startDay: $week->getStartDay(),
                    endDay: $week->getEndDay(),
                    sessions: $updatedSessions,
                    targetLoadMultiplier: $week->getTargetLoadMultiplier(),
                    isManuallyPlanned: $week->isManuallyPlanned(),
                    isRecoveryWeek: $week->isRecoveryWeek(),
                );
            }, $block->getWeekSkeletons());

            return ProposedTrainingBlock::create(
                startDay: $block->getStartDay(),
                endDay: $block->getEndDay(),
                phase: $block->getPhase(),
                targetRaceEventId: $block->getTargetRaceEventId(),
                title: $block->getTitle(),
                focus: $block->getFocus(),
                weekSkeletons: $updatedWeekSkeletons,
            );
        }, $proposedBlocks);
    }

    private function applyProjectedRunningThresholdToSession(ProposedSession $session, int $projectedThresholdPaceInSeconds): ProposedSession
    {
        if (ActivityType::RUN !== $session->getActivityType()) {
            return $session;
        }

        $updatedWorkoutSteps = $session->hasWorkoutSteps()
            ? $this->applyProjectedRunningThresholdToWorkoutSteps(
                workoutSteps: $session->getWorkoutSteps(),
                intensity: $session->getTargetIntensity(),
                isLongSession: $this->isLongSessionTitle($session->getTitle()),
                projectedThresholdPaceInSeconds: $projectedThresholdPaceInSeconds,
            )
            : $session->getWorkoutSteps();
        $updatedNotes = $this->applyProjectedRunningGuidanceToNotes(
            notes: $session->getNotes(),
            projectedThresholdPaceInSeconds: $projectedThresholdPaceInSeconds,
            intensity: $session->getTargetIntensity(),
        );

        if ($updatedWorkoutSteps === $session->getWorkoutSteps() && $updatedNotes === $session->getNotes()) {
            return $session;
        }

        return ProposedSession::create(
            day: $session->getDay(),
            activityType: $session->getActivityType(),
            targetIntensity: $session->getTargetIntensity(),
            title: $session->getTitle(),
            notes: $updatedNotes,
            targetDurationInSeconds: $session->getTargetDurationInSeconds(),
            isKeySession: $session->isKeySession(),
            isBrickSession: $session->isBrickSession(),
            workoutSteps: $updatedWorkoutSteps,
        );
    }

    /**
     * @param list<array<string, mixed>> $workoutSteps
     *
     * @return list<array<string, mixed>>
     */
    private function applyProjectedRunningThresholdToWorkoutSteps(
        array $workoutSteps,
        PlannedSessionIntensity $intensity,
        bool $isLongSession,
        int $projectedThresholdPaceInSeconds,
    ): array {
        return array_map(function (array $workoutStep) use ($intensity, $isLongSession, $projectedThresholdPaceInSeconds): array {
            if ('repeatBlock' === ($workoutStep['type'] ?? null) && is_array($workoutStep['steps'] ?? null)) {
                $workoutStep['steps'] = $this->applyProjectedRunningThresholdToWorkoutSteps(
                    workoutSteps: $workoutStep['steps'],
                    intensity: $intensity,
                    isLongSession: $isLongSession,
                    projectedThresholdPaceInSeconds: $projectedThresholdPaceInSeconds,
                );

                return $workoutStep;
            }

            $workoutStep['targetPace'] = $this->resolveRunPaceTarget(
                thresholdPaceInSeconds: $projectedThresholdPaceInSeconds,
                stepType: (string) ($workoutStep['type'] ?? 'steady'),
                intensity: $intensity,
                isLongSession: $isLongSession,
            );

            return $workoutStep;
        }, $workoutSteps);
    }

    private function applyProjectedRunningGuidanceToNotes(
        ?string $notes,
        int $projectedThresholdPaceInSeconds,
        PlannedSessionIntensity $intensity,
    ): ?string {
        $guidance = $this->buildRunGuidance($projectedThresholdPaceInSeconds, $intensity);
        if (null === $notes || '' === trim($notes)) {
            return $guidance;
        }

        $guidancePattern = '/(Use threshold pace around \d+:\d{2}\/km on the main work\.|Keep the steady work a touch easier than threshold pace \(\d+:\d{2}\/km\)\.|Keep easy running comfortably slower than threshold pace \(\d+:\d{2}\/km\)\.)/';

        if (1 === preg_match($guidancePattern, $notes)) {
            return (string) preg_replace($guidancePattern, $guidance, $notes, 1);
        }

        return trim(sprintf('%s %s', $notes, $guidance));
    }

    /**
     * @param list<RaceEvent> $allRaceEvents
     *
     * @return list<PlanAdaptationWarning>
     */
    private function detectWarnings(
        RaceEvent $targetRace,
        RaceProfileTrainingRules $rules,
        int $totalWeeks,
        array $allRaceEvents,
    ): array {
        $warnings = [];

        if ($totalWeeks < $rules->getMinimumPlanWeeks()) {
            $warnings[] = PlanAdaptationWarning::create(
                type: PlanAdaptationWarningType::PLAN_TOO_SHORT,
                title: 'Plan is shorter than ideal',
                body: sprintf(
                    'The available time is %d weeks, but this race profile typically needs at least %d weeks. The plan will compress some phases.',
                    $totalWeeks,
                    $rules->getMinimumPlanWeeks(),
                ),
                severity: PlanAdaptationWarningSeverity::WARNING,
            );
        }

        if ($totalWeeks > $rules->getMaximumPlanWeeks()) {
            $warnings[] = PlanAdaptationWarning::create(
                type: PlanAdaptationWarningType::PLAN_TOO_LONG,
                title: 'Plan is longer than typical',
                body: sprintf(
                    'The available time is %d weeks. Plans for this race profile rarely exceed %d weeks. The base phase will be extended.',
                    $totalWeeks,
                    $rules->getMaximumPlanWeeks(),
                ),
                severity: PlanAdaptationWarningSeverity::INFO,
            );
        }

        $aRacesInWindow = array_filter(
            $allRaceEvents,
            static fn (RaceEvent $event): bool => RaceEventPriority::A === $event->getPriority()
                && (string) $event->getId() !== (string) $targetRace->getId(),
        );

        if ([] !== $aRacesInWindow) {
            $warnings[] = PlanAdaptationWarning::create(
                type: PlanAdaptationWarningType::MULTIPLE_A_RACES,
                title: 'Multiple A-races detected',
                body: 'There are other A-priority races in the plan window. Each A-race needs its own periodization cycle, which may create conflicts.',
                severity: PlanAdaptationWarningSeverity::CRITICAL,
            );
        }

        $raceDay = $targetRace->getDay();
        $taperStart = $raceDay->modify(sprintf('-%d weeks', $rules->getTaperWeeks()));
        $bRacesInTaper = array_filter(
            $allRaceEvents,
            static fn (RaceEvent $event): bool => RaceEventPriority::B === $event->getPriority()
                && $event->getDay() >= $taperStart
                && $event->getDay() < $raceDay,
        );

        foreach ($bRacesInTaper as $bRace) {
            $daysBeforeARace = (int) $bRace->getDay()->diff($raceDay)->days;
            if ($daysBeforeARace <= 14) {
                $warnings[] = PlanAdaptationWarning::create(
                    type: PlanAdaptationWarningType::B_RACE_NEAR_A_RACE,
                    title: sprintf('B-race close to A-race (%d days out)', $daysBeforeARace),
                    body: sprintf(
                        '%s is only %d days before the target race. This may compromise freshness for race day.',
                        $bRace->getTitle() ?? 'B-race',
                        $daysBeforeARace,
                    ),
                    severity: PlanAdaptationWarningSeverity::WARNING,
                );
            }
        }

        $baseWeeksAvailable = $totalWeeks - $rules->getTaperWeeks() - $rules->getPeakWeeks() - max(1, (int) ceil($totalWeeks * 0.35));
        if ($baseWeeksAvailable < $rules->getBaseWeeksMinimum()) {
            $warnings[] = PlanAdaptationWarning::create(
                type: PlanAdaptationWarningType::INSUFFICIENT_BASE,
                title: 'Limited base-building time',
                body: 'There may not be enough weeks to build a proper aerobic foundation before the build phase starts.',
                severity: PlanAdaptationWarningSeverity::INFO,
            );
        }

        return $warnings;
    }

    /**
     * @return list<ProposedTrainingBlock>
     */
    private function buildBlockPeriodization(
        SerializableDateTime $planStart,
        SerializableDateTime $raceDay,
        RaceProfileTrainingRules $rules,
        RaceEvent $targetRace,
        int $totalWeeks,
        ?TrainingPlan $linkedTrainingPlan = null,
    ): array {
        if ($this->isDevelopmentTrainingPlan($linkedTrainingPlan)) {
            return $this->buildDevelopmentBlockPeriodization(
                planStart: $planStart,
                totalWeeks: $totalWeeks,
                linkedTrainingPlan: $linkedTrainingPlan,
            );
        }

        $blocks = [];
        $targetRaceEventId = $targetRace->getId();

        $taperWeeks = $rules->getTaperWeeks();
        $peakWeeks = $rules->getPeakWeeks();
        $buildWeeks = max(1, (int) ceil(($totalWeeks - $taperWeeks - $peakWeeks) * 0.45));
        $baseWeeks = max(1, $totalWeeks - $buildWeeks - $peakWeeks - $taperWeeks);

        if ($totalWeeks <= 4) {
            $baseWeeks = 0;
            $buildWeeks = max(1, $totalWeeks - $taperWeeks);
            $peakWeeks = 0;
        } elseif ($totalWeeks <= $rules->getMinimumPlanWeeks()) {
            $baseWeeks = max(1, $totalWeeks - $buildWeeks - $peakWeeks - $taperWeeks);
        }

        $currentDay = $planStart;

        if ($baseWeeks > 0) {
            $endDay = $currentDay->modify(sprintf('+%d weeks -1 day', $baseWeeks));
            $blocks[] = ProposedTrainingBlock::create(
                startDay: $currentDay,
                endDay: $endDay,
                phase: TrainingBlockPhase::BASE,
                targetRaceEventId: $targetRaceEventId,
                title: 'Base',
                focus: $this->resolveBlockFocusDescription(
                    TrainingBlockPhase::BASE,
                    'Build aerobic foundation and movement quality',
                    $linkedTrainingPlan,
                ),
            );
            $currentDay = $endDay->modify('+1 day');
        }

        $endDay = $currentDay->modify(sprintf('+%d weeks -1 day', $buildWeeks));
        $blocks[] = ProposedTrainingBlock::create(
            startDay: $currentDay,
            endDay: $endDay,
            phase: TrainingBlockPhase::BUILD,
            targetRaceEventId: $targetRaceEventId,
            title: 'Build',
            focus: $this->resolveBlockFocusDescription(
                TrainingBlockPhase::BUILD,
                'Increase race-specific fitness and workload',
                $linkedTrainingPlan,
            ),
        );
        $currentDay = $endDay->modify('+1 day');

        if ($peakWeeks > 0) {
            $endDay = $currentDay->modify(sprintf('+%d weeks -1 day', $peakWeeks));
            $blocks[] = ProposedTrainingBlock::create(
                startDay: $currentDay,
                endDay: $endDay,
                phase: TrainingBlockPhase::PEAK,
                targetRaceEventId: $targetRaceEventId,
                title: 'Peak',
                focus: $this->resolveBlockFocusDescription(
                    TrainingBlockPhase::PEAK,
                    'Maximize race-specific sharpness',
                    $linkedTrainingPlan,
                ),
            );
            $currentDay = $endDay->modify('+1 day');
        }

        if ($taperWeeks > 0) {
            $blocks[] = ProposedTrainingBlock::create(
                startDay: $currentDay,
                endDay: $raceDay,
                phase: TrainingBlockPhase::TAPER,
                targetRaceEventId: $targetRaceEventId,
                title: 'Taper',
                focus: $this->resolveBlockFocusDescription(
                    TrainingBlockPhase::TAPER,
                    'Protect freshness while maintaining sharpness',
                    $linkedTrainingPlan,
                ),
            );
        }

        $recoveryWeeks = $rules->getPostRaceRecoveryWeeks();
        if ($recoveryWeeks > 0) {
            $recoveryStartDay = $raceDay->modify('+1 day')->setTime(0, 0);
            $recoveryEndDay = $recoveryStartDay->modify(sprintf('+%d weeks -1 day', $recoveryWeeks));
            $blocks[] = ProposedTrainingBlock::create(
                startDay: $recoveryStartDay,
                endDay: $recoveryEndDay,
                phase: TrainingBlockPhase::RECOVERY,
                targetRaceEventId: $targetRaceEventId,
                title: 'Recovery',
                focus: $this->resolveBlockFocusDescription(
                    TrainingBlockPhase::RECOVERY,
                    'Absorb race stress and return to training gradually',
                    $linkedTrainingPlan,
                ),
            );
        }

        return $blocks;
    }

    /**
     * @return list<ProposedTrainingBlock>
     */
    private function buildDevelopmentBlockPeriodization(
        SerializableDateTime $planStart,
        int $totalWeeks,
        ?TrainingPlan $linkedTrainingPlan = null,
    ): array {
        $blocks = [];
        $currentDay = $planStart;

        $baseWeeks = $this->isSpeedEnduranceTrainingPlan($linkedTrainingPlan)
            ? match (true) {
                $totalWeeks <= 3 => 1,
                $totalWeeks <= 6 => max(1, $totalWeeks - 3),
                default => min(max(2, (int) round($totalWeeks * 0.3)), max(2, $totalWeeks - 5)),
            }
            : match (true) {
                $totalWeeks <= 3 => max(1, $totalWeeks - 1),
                $totalWeeks <= 6 => max(2, $totalWeeks - 2),
                default => min(max(4, (int) round($totalWeeks * 0.4)), max(4, $totalWeeks - 4)),
            };
        $baseWeeks = max(0, min($baseWeeks, max(0, $totalWeeks - 1)));
        $buildWeeks = max(1, $totalWeeks - $baseWeeks);

        if ($baseWeeks > 0) {
            $endDay = $currentDay->modify(sprintf('+%d weeks -1 day', $baseWeeks));
            $blocks[] = ProposedTrainingBlock::create(
                startDay: $currentDay,
                endDay: $endDay,
                phase: TrainingBlockPhase::BASE,
                targetRaceEventId: null,
                title: 'Base',
                focus: $this->resolveBlockFocusDescription(
                    TrainingBlockPhase::BASE,
                    'Build durable aerobic support and movement quality for the target distance.',
                    $linkedTrainingPlan,
                ),
            );
            $currentDay = $endDay->modify('+1 day');
        }

        $endDay = $currentDay->modify(sprintf('+%d weeks -1 day', $buildWeeks));
        $blocks[] = ProposedTrainingBlock::create(
            startDay: $currentDay,
            endDay: $endDay,
            phase: TrainingBlockPhase::BUILD,
            targetRaceEventId: null,
            title: 'Build',
            focus: $this->resolveBlockFocusDescription(
                TrainingBlockPhase::BUILD,
                'Build event-distance fitness with progressive volume, threshold support, and regular recovery weeks.',
                $linkedTrainingPlan,
            ),
        );

        return $blocks;
    }

    /**
     * @param list<TrainingBlock> $blocks
     * @param list<PlannedSession> $existingSessions
     * @param list<RaceEvent> $allRaceEvents
     *
     * @return list<ProposedTrainingBlock>
     */
    private function buildProposalFromExistingBlocks(
        array $blocks,
        array $existingSessions,
        RaceProfileTrainingRules $rules,
        RaceEvent $targetRace,
        array $allRaceEvents,
        SerializableDateTime $referenceDate,
        ?TrainingPlan $linkedTrainingPlan = null,
        ?AdaptivePlanningContext $adaptivePlanningContext = null,
    ): array {
        $bAndCRaces = array_filter(
            $allRaceEvents,
            static fn (RaceEvent $event): bool => RaceEventPriority::A !== $event->getPriority()
                && (string) $event->getId() !== (string) $targetRace->getId(),
        );
        $bAndCRacesByDay = [];
        foreach ($bAndCRaces as $race) {
            $bAndCRacesByDay[$race->getDay()->format('Y-m-d')] = $race;
        }

        $proposedBlocks = [];
        $planWeekNumber = 1;

        foreach ($blocks as $block) {
            $weekSkeletons = [];
            $currentWeekStart = $block->getStartDay();
            $weekNumber = 1;
            $blockDurationWeeks = max(1, (int) ceil($block->getDurationInDays() / 7));

            while ($currentWeekStart <= $block->getEndDay()) {
                $weekEnd = min($currentWeekStart->modify('+6 days'), $block->getEndDay());
                $loadMultiplier = $this->resolveLoadMultiplier($block->getPhase(), $weekNumber, $blockDurationWeeks, $planWeekNumber, $linkedTrainingPlan, $adaptivePlanningContext);
                $isCycleRecoveryWeek = $this->isCycleRecoveryWeek($block->getPhase(), $planWeekNumber);
                $weekRace = $this->findRaceInWeek($bAndCRacesByDay, $currentWeekStart, $weekEnd);
                $sessionsForWeek = array_values(array_filter(
                    $existingSessions,
                    static fn (PlannedSession $session): bool => $session->getDay() >= $currentWeekStart && $session->getDay() <= $weekEnd,
                ));
                $mappedExistingSessions = $this->mapExistingPlannedSessionsToProposedSessions($sessionsForWeek);
                $isManuallyPlannedWeek = [] !== $mappedExistingSessions;
                $weekSessions = $weekEnd < $referenceDate
                    ? $mappedExistingSessions
                    : ($isManuallyPlannedWeek
                        ? $mappedExistingSessions
                        : $this->buildWeekSessions(
                            rules: $rules,
                            phase: $block->getPhase(),
                            weekStart: $currentWeekStart,
                            loadMultiplier: $loadMultiplier,
                            targetRace: $targetRace,
                            weekInBlock: $weekNumber,
                            blockDurationWeeks: $blockDurationWeeks,
                            weekRace: $weekRace,
                            isCycleRecoveryWeek: $isCycleRecoveryWeek,
                            linkedTrainingPlan: $linkedTrainingPlan,
                            adaptivePlanningContext: $adaptivePlanningContext,
                        ));

                $this->sortProposedSessions($weekSessions);

                $weekSkeletons[] = ProposedWeekSkeleton::create(
                    weekNumber: $weekNumber,
                    startDay: $currentWeekStart,
                    endDay: $weekEnd,
                    sessions: $weekSessions,
                    targetLoadMultiplier: $loadMultiplier,
                    isManuallyPlanned: $isManuallyPlannedWeek,
                    isRecoveryWeek: TrainingBlockPhase::RECOVERY === $block->getPhase() || $isCycleRecoveryWeek,
                );

                $currentWeekStart = $weekEnd->modify('+1 day');
                ++$weekNumber;
                ++$planWeekNumber;
            }

            $proposedBlocks[] = ProposedTrainingBlock::create(
                startDay: $block->getStartDay(),
                endDay: $block->getEndDay(),
                phase: $block->getPhase(),
                targetRaceEventId: $block->getTargetRaceEventId(),
                title: $block->getTitle() ?? $block->getPhase()->value,
                focus: $block->getFocus(),
                weekSkeletons: $weekSkeletons,
            );
        }

        $recoveryTail = $this->buildGeneratedRecoveryTail(
            existingBlocks: $blocks,
            existingSessions: $existingSessions,
            rules: $rules,
            targetRace: $targetRace,
            allRaceEvents: $allRaceEvents,
            referenceDate: $referenceDate,
            linkedTrainingPlan: $linkedTrainingPlan,
            adaptivePlanningContext: $adaptivePlanningContext,
        );

        if (null !== $recoveryTail) {
            $proposedBlocks[] = $recoveryTail;
        }

        return $proposedBlocks;
    }

    /**
     * @param list<TrainingBlock> $existingBlocks
     * @param list<PlannedSession> $existingSessions
     * @param list<RaceEvent> $allRaceEvents
     */
    private function buildGeneratedRecoveryTail(
        array $existingBlocks,
        array $existingSessions,
        RaceProfileTrainingRules $rules,
        RaceEvent $targetRace,
        array $allRaceEvents,
        SerializableDateTime $referenceDate,
        ?TrainingPlan $linkedTrainingPlan = null,
        ?AdaptivePlanningContext $adaptivePlanningContext = null,
    ): ?ProposedTrainingBlock {
        if ($this->isDevelopmentTrainingPlan($linkedTrainingPlan)) {
            return null;
        }

        $raceDay = $targetRace->getDay()->setTime(0, 0);
        $recoveryWeeks = $rules->getPostRaceRecoveryWeeks();
        if (0 === $recoveryWeeks || [] === $existingBlocks) {
            return null;
        }

        $latestExistingBlockEndDay = $existingBlocks[array_key_last($existingBlocks)]->getEndDay()->setTime(0, 0);
        if ($latestExistingBlockEndDay < $raceDay->modify('-7 days')) {
            return null;
        }

        $targetRecoveryStartDay = $raceDay->modify('+1 day')->setTime(0, 0);
        $targetRecoveryEndDay = $targetRecoveryStartDay->modify(sprintf('+%d weeks -1 day', $recoveryWeeks));
        if ($latestExistingBlockEndDay >= $targetRecoveryEndDay) {
            return null;
        }

        $recoveryStartDay = $latestExistingBlockEndDay >= $targetRecoveryStartDay
            ? $latestExistingBlockEndDay->modify('+1 day')->setTime(0, 0)
            : $targetRecoveryStartDay;

        if ($recoveryStartDay > $targetRecoveryEndDay) {
            return null;
        }

        $bAndCRaces = array_filter(
            $allRaceEvents,
            static fn (RaceEvent $event): bool => RaceEventPriority::A !== $event->getPriority()
                && (string) $event->getId() !== (string) $targetRace->getId(),
        );
        $bAndCRacesByDay = [];
        foreach ($bAndCRaces as $race) {
            $bAndCRacesByDay[$race->getDay()->format('Y-m-d')] = $race;
        }

        $weekSkeletons = [];
        $currentWeekStart = $recoveryStartDay;
        $weekNumber = 1;
        $blockDurationWeeks = max(1, (int) ceil($recoveryStartDay->diff($targetRecoveryEndDay)->days / 7));

        while ($currentWeekStart <= $targetRecoveryEndDay) {
            $weekEnd = min($currentWeekStart->modify('+6 days'), $targetRecoveryEndDay);
            $loadMultiplier = $this->resolveLoadMultiplier(TrainingBlockPhase::RECOVERY, $weekNumber, $blockDurationWeeks, $weekNumber, $linkedTrainingPlan, $adaptivePlanningContext);
            $weekRace = $this->findRaceInWeek($bAndCRacesByDay, $currentWeekStart, $weekEnd);
            $sessionsForWeek = array_values(array_filter(
                $existingSessions,
                static fn (PlannedSession $session): bool => $session->getDay() >= $currentWeekStart && $session->getDay() <= $weekEnd,
            ));
            $mappedExistingSessions = $this->mapExistingPlannedSessionsToProposedSessions($sessionsForWeek);
            $isManuallyPlannedWeek = [] !== $mappedExistingSessions;
            $weekSessions = $weekEnd < $referenceDate
                ? $mappedExistingSessions
                : ($isManuallyPlannedWeek
                    ? $mappedExistingSessions
                    : $this->buildWeekSessions(
                        rules: $rules,
                        phase: TrainingBlockPhase::RECOVERY,
                        weekStart: $currentWeekStart,
                        loadMultiplier: $loadMultiplier,
                        targetRace: $targetRace,
                        weekInBlock: $weekNumber,
                        blockDurationWeeks: $blockDurationWeeks,
                        weekRace: $weekRace,
                        linkedTrainingPlan: $linkedTrainingPlan,
                        adaptivePlanningContext: $adaptivePlanningContext,
                    ));

            $this->sortProposedSessions($weekSessions);

            $weekSkeletons[] = ProposedWeekSkeleton::create(
                weekNumber: $weekNumber,
                startDay: $currentWeekStart,
                endDay: $weekEnd,
                sessions: $weekSessions,
                targetLoadMultiplier: $loadMultiplier,
                isManuallyPlanned: $isManuallyPlannedWeek,
                isRecoveryWeek: true,
            );

            $currentWeekStart = $weekEnd->modify('+1 day');
            ++$weekNumber;
        }

        return ProposedTrainingBlock::create(
            startDay: $recoveryStartDay,
            endDay: $targetRecoveryEndDay,
            phase: TrainingBlockPhase::RECOVERY,
            targetRaceEventId: $targetRace->getId(),
            title: 'Recovery',
            focus: $this->resolveBlockFocusDescription(
                TrainingBlockPhase::RECOVERY,
                'Absorb race stress and return to training gradually',
                $linkedTrainingPlan,
            ),
            weekSkeletons: $weekSkeletons,
        );
    }

    /**
     * @param list<ProposedTrainingBlock> $blocks
     * @param list<RaceEvent> $allRaceEvents
     *
     * @return list<ProposedTrainingBlock>
     */
    private function populateWeekSkeletons(
        array $blocks,
        RaceProfileTrainingRules $rules,
        RaceEvent $targetRace,
        array $allRaceEvents,
        ?TrainingPlan $linkedTrainingPlan = null,
        ?AdaptivePlanningContext $adaptivePlanningContext = null,
    ): array {
        $bAndCRaces = array_filter(
            $allRaceEvents,
            static fn (RaceEvent $event): bool => RaceEventPriority::A !== $event->getPriority()
                && (string) $event->getId() !== (string) $targetRace->getId(),
        );
        $bAndCRacesByDay = [];
        foreach ($bAndCRaces as $race) {
            $bAndCRacesByDay[$race->getDay()->format('Y-m-d')] = $race;
        }

        $populatedBlocks = [];
        $planWeekNumber = 1;

        foreach ($blocks as $block) {
            $weekSkeletons = [];
            $currentWeekStart = $block->getStartDay();
            $weekNumber = 1;

            while ($currentWeekStart <= $block->getEndDay()) {
                $weekEnd = min(
                    $currentWeekStart->modify('+6 days'),
                    $block->getEndDay(),
                );

                $loadMultiplier = $this->resolveLoadMultiplier($block->getPhase(), $weekNumber, $block->getDurationInWeeks(), $planWeekNumber, $linkedTrainingPlan, $adaptivePlanningContext);
                $isCycleRecoveryWeek = $this->isCycleRecoveryWeek($block->getPhase(), $planWeekNumber);
                $weekRace = $this->findRaceInWeek($bAndCRacesByDay, $currentWeekStart, $weekEnd);
                $sessions = $this->buildWeekSessions(
                    rules: $rules,
                    phase: $block->getPhase(),
                    weekStart: $currentWeekStart,
                    loadMultiplier: $loadMultiplier,
                    targetRace: $targetRace,
                    weekInBlock: $weekNumber,
                    blockDurationWeeks: $block->getDurationInWeeks(),
                    weekRace: $weekRace,
                    isCycleRecoveryWeek: $isCycleRecoveryWeek,
                    linkedTrainingPlan: $linkedTrainingPlan,
                    adaptivePlanningContext: $adaptivePlanningContext,
                );

                $weekSkeletons[] = ProposedWeekSkeleton::create(
                    weekNumber: $weekNumber,
                    startDay: $currentWeekStart,
                    endDay: $weekEnd,
                    sessions: $sessions,
                    targetLoadMultiplier: $loadMultiplier,
                    isRecoveryWeek: TrainingBlockPhase::RECOVERY === $block->getPhase() || $isCycleRecoveryWeek,
                );

                $currentWeekStart = $weekEnd->modify('+1 day');
                ++$weekNumber;
                ++$planWeekNumber;
            }

            $populatedBlocks[] = ProposedTrainingBlock::create(
                startDay: $block->getStartDay(),
                endDay: $block->getEndDay(),
                phase: $block->getPhase(),
                targetRaceEventId: $block->getTargetRaceEventId(),
                title: $block->getTitle(),
                focus: $block->getFocus(),
                weekSkeletons: $weekSkeletons,
            );
        }

        return $populatedBlocks;
    }

    private function resolveLoadMultiplier(
        TrainingBlockPhase $phase,
        int $weekInBlock,
        int $blockDurationWeeks,
        int $planWeekNumber,
        ?TrainingPlan $linkedTrainingPlan = null,
        ?AdaptivePlanningContext $adaptivePlanningContext = null,
    ): float
    {
        if (TrainingBlockPhase::RECOVERY === $phase) {
            return 0.4;
        }

        $loadMultiplier = match ($phase) {
            TrainingBlockPhase::BASE => 0.6 + (0.15 * min($weekInBlock / max(1, $blockDurationWeeks), 1.0)),
            TrainingBlockPhase::BUILD => 0.8 + (0.15 * min($weekInBlock / max(1, $blockDurationWeeks), 1.0)),
            TrainingBlockPhase::PEAK => 1.0,
            TrainingBlockPhase::TAPER => $this->resolveTaperLoadMultiplier($weekInBlock, $blockDurationWeeks),
            TrainingBlockPhase::RECOVERY => 0.4,
        };

        if (!$this->isCycleRecoveryWeek($phase, $planWeekNumber)) {
            return $this->applyPerformanceLoadAdjustments($loadMultiplier, $phase, $weekInBlock, $blockDurationWeeks, $planWeekNumber, $linkedTrainingPlan, $adaptivePlanningContext);
        }

        $recoveryAdjustedLoadMultiplier = match ($phase) {
            TrainingBlockPhase::BASE => max(0.55, $loadMultiplier - 0.10),
            TrainingBlockPhase::BUILD => max(0.70, $loadMultiplier - 0.18),
            default => $loadMultiplier,
        };

        return $this->applyPerformanceLoadAdjustments($recoveryAdjustedLoadMultiplier, $phase, $weekInBlock, $blockDurationWeeks, $planWeekNumber, $linkedTrainingPlan, $adaptivePlanningContext, true);
    }

    private function isCycleRecoveryWeek(TrainingBlockPhase $phase, int $planWeekNumber): bool
    {
        if (!in_array($phase, [TrainingBlockPhase::BASE, TrainingBlockPhase::BUILD], true)) {
            return false;
        }

        return $planWeekNumber >= 4 && 0 === $planWeekNumber % 4;
    }

    /**
     * @param array<string, RaceEvent> $bAndCRacesByDay
     */
    private function findRaceInWeek(
        array $bAndCRacesByDay,
        SerializableDateTime $weekStart,
        SerializableDateTime $weekEnd,
    ): ?RaceEvent {
        $currentDay = $weekStart;

        while ($currentDay <= $weekEnd) {
            $key = $currentDay->format('Y-m-d');

            if (isset($bAndCRacesByDay[$key])) {
                return $bAndCRacesByDay[$key];
            }

            $currentDay = SerializableDateTime::fromDateTimeImmutable($currentDay->modify('+1 day'));
        }

        return null;
    }

    /**
     * @return list<ProposedSession>
     */
    private function buildWeekSessions(
        RaceProfileTrainingRules $rules,
        TrainingBlockPhase $phase,
        SerializableDateTime $weekStart,
        float $loadMultiplier,
        RaceEvent $targetRace,
        int $weekInBlock,
        int $blockDurationWeeks,
        ?RaceEvent $weekRace,
        bool $isCycleRecoveryWeek = false,
        ?TrainingPlan $linkedTrainingPlan = null,
        ?AdaptivePlanningContext $adaptivePlanningContext = null,
    ): array {
        if (TrainingBlockPhase::TAPER === $phase) {
            return $this->buildTaperWeekSessions(
                rules: $rules,
                targetRace: $targetRace,
                weekStart: $weekStart,
                loadMultiplier: $loadMultiplier,
                weekInBlock: $weekInBlock,
                blockDurationWeeks: $blockDurationWeeks,
                weekRace: $weekRace,
                linkedTrainingPlan: $linkedTrainingPlan,
            );
        }

        $sessionsPerWeek = $this->resolveSessionCount($rules, $phase, $loadMultiplier, $isCycleRecoveryWeek, $linkedTrainingPlan, $adaptivePlanningContext);
        $disciplines = $this->resolveDisciplineDistribution($rules, $sessionsPerWeek, $linkedTrainingPlan);
        $sessions = [];
        $preferredSlotIndex = 0;

        if (null !== $weekRace) {
            $raceDay = $weekRace->getDay();
            $sessions[] = ProposedSession::create(
                day: $raceDay,
                activityType: $this->resolvePrimaryActivityType($weekRace->getProfile()->getFamily()),
                targetIntensity: PlannedSessionIntensity::RACE,
                title: $this->resolveRaceEventTitle($weekRace),
                notes: sprintf('%s race', RaceEventPriority::B === $weekRace->getPriority() ? 'B' : 'C'),
                isKeySession: true,
            );

            if (RaceEventPriority::B === $weekRace->getPriority()) {
                $sessionsPerWeek = max(2, $sessionsPerWeek - 2);
            } else {
                $sessionsPerWeek = max(2, $sessionsPerWeek - 1);
            }
        }

        $hardSessionsPlaced = 0;
        $maxHardSessions = $this->resolveHardSessionCount($rules, $phase, $isCycleRecoveryWeek, $linkedTrainingPlan);
        $longSessionDisciplines = $this->resolveLongSessionDisciplines($rules, $linkedTrainingPlan);
        $remainingDisciplines = $disciplines;
        $plannedSessions = [];

        if (!in_array($phase, [TrainingBlockPhase::TAPER, TrainingBlockPhase::RECOVERY], true)) {
            foreach ($longSessionDisciplines as $longSessionDiscipline) {
                if (!$this->removeFirstDisciplineOccurrence($remainingDisciplines, $longSessionDiscipline)) {
                    continue;
                }

                $plannedSessions[] = [
                    'activityType' => $longSessionDiscipline,
                    'intensity' => PlannedSessionIntensity::MODERATE,
                    'isKey' => true,
                    'isLongSession' => true,
                    'title' => $this->buildLongSessionTitle($longSessionDiscipline),
                    'notes' => null,
                    'targetDuration' => $this->resolveLongSessionDuration($longSessionDiscipline, $targetRace->getProfile(), $phase, $loadMultiplier, $linkedTrainingPlan, $adaptivePlanningContext),
                ];
            }
        }

        foreach ($this->resolveHardSessionActivityTypes($remainingDisciplines, $maxHardSessions, $linkedTrainingPlan) as $hardSessionActivityType) {
            if (!$this->removeFirstDisciplineOccurrence($remainingDisciplines, $hardSessionActivityType)) {
                continue;
            }

            $plannedSessions[] = [
                'activityType' => $hardSessionActivityType,
                'intensity' => TrainingBlockPhase::TAPER === $phase
                    ? PlannedSessionIntensity::MODERATE
                    : PlannedSessionIntensity::HARD,
                'isKey' => true,
                'isLongSession' => false,
                'title' => $this->buildKeySessionTitle($hardSessionActivityType, $phase),
                'notes' => null,
                'targetDuration' => $this->resolveKeySessionDuration($hardSessionActivityType, $targetRace->getProfile(), $phase, $loadMultiplier, $linkedTrainingPlan, $adaptivePlanningContext),
            ];
            ++$hardSessionsPlaced;
        }

        foreach ($remainingDisciplines as $activityType) {
            $intensity = PlannedSessionIntensity::EASY;
            $isKey = false;
            $isLongSession = false;
            $title = null;
            $notes = null;
            $targetDuration = null;

            if (TrainingBlockPhase::RECOVERY === $phase) {
                $title = $this->buildRecoverySessionTitle($activityType);
                $notes = 'Keep this fully conversational and cut it short if race fatigue is still hanging around.';
                $targetDuration = $this->resolveRecoverySessionDuration($activityType, $targetRace->getProfile(), $loadMultiplier, $linkedTrainingPlan, $adaptivePlanningContext);
            } else {
                $title = $this->buildEasySessionTitle($activityType);
                $targetDuration = $this->resolveEasySessionDuration($activityType, $targetRace->getProfile(), $loadMultiplier, $linkedTrainingPlan, $adaptivePlanningContext);
            }

            $plannedSessions[] = [
                'activityType' => $activityType,
                'intensity' => $intensity,
                'isKey' => $isKey,
                'isLongSession' => $isLongSession,
                'title' => $title,
                'notes' => $notes,
                'targetDuration' => $targetDuration,
            ];
        }

        foreach ($plannedSessions as $plannedSession) {
            $activityType = $plannedSession['activityType'];
            $intensity = $plannedSession['intensity'];
            $isKey = $plannedSession['isKey'];
            $isLongSession = $plannedSession['isLongSession'];
            $title = $plannedSession['title'];
            $notes = $plannedSession['notes'];
            $targetDuration = $plannedSession['targetDuration'];
            $workoutSteps = [];

            $sessionDay = $this->resolveSessionDay($weekStart, $preferredSlotIndex, $sessions, $activityType, $isLongSession, $linkedTrainingPlan, $isKey);
            if (null === $sessionDay) {
                break;
            }

            ++$preferredSlotIndex;

            [$title, $notes, $targetDuration, $workoutSteps] = $this->applyRecommendedTrainingSession(
                activityType: $activityType,
                phase: $phase,
                intensity: $intensity,
                isLongSession: $isLongSession,
                title: $title,
                notes: $notes,
                targetDurationInSeconds: $targetDuration,
                existingSessions: $sessions,
            );

            $workoutSteps = $this->resolveStructuredWorkoutSteps(
                activityType: $activityType,
                phase: $phase,
                intensity: $intensity,
                isLongSession: $isLongSession,
                targetDurationInSeconds: $targetDuration,
                workoutSteps: $workoutSteps,
                sessionTitle: $title,
                weekInBlock: $weekInBlock,
                blockDurationWeeks: $blockDurationWeeks,
                linkedTrainingPlan: $linkedTrainingPlan,
            );

            $notes = $this->appendPerformanceGuidance($activityType, $intensity, $notes, $linkedTrainingPlan);

            $sessions[] = ProposedSession::create(
                day: $sessionDay,
                activityType: $activityType,
                targetIntensity: $intensity,
                title: $title,
                notes: $notes,
                targetDurationInSeconds: $targetDuration,
                isKeySession: $isKey,
                workoutSteps: $workoutSteps,
            );
        }

        $sessions = $this->addSecondaryRunIfEligible(
            sessions: $sessions,
            rules: $rules,
            phase: $phase,
            targetRace: $targetRace,
            loadMultiplier: $loadMultiplier,
            linkedTrainingPlan: $linkedTrainingPlan,
            adaptivePlanningContext: $adaptivePlanningContext,
        );

        if ($rules->needsBrickSessions() && !$isCycleRecoveryWeek && !in_array($phase, [TrainingBlockPhase::BASE, TrainingBlockPhase::TAPER, TrainingBlockPhase::RECOVERY], true)) {
            $sessions = $this->addBrickSessionIfMissing($sessions, $weekStart);
        }

        $this->sortProposedSessions($sessions);

        return $sessions;
    }

    /**
     * @param list<ProposedSession> $sessions
     *
     * @return list<ProposedSession>
     */
    private function addSecondaryRunIfEligible(
        array $sessions,
        RaceProfileTrainingRules $rules,
        TrainingBlockPhase $phase,
        RaceEvent $targetRace,
        float $loadMultiplier,
        ?TrainingPlan $linkedTrainingPlan = null,
        ?AdaptivePlanningContext $adaptivePlanningContext = null,
    ): array {
        $doubleRunSpecification = $this->resolveDoubleRunSpecification(
            sessions: $sessions,
            rules: $rules,
            phase: $phase,
            linkedTrainingPlan: $linkedTrainingPlan,
            adaptivePlanningContext: $adaptivePlanningContext,
        );

        if (!is_array($doubleRunSpecification)) {
            return $sessions;
        }

        $anchorSession = $this->resolveDoubleRunAnchorSession($sessions, $doubleRunSpecification['anchorIntensity']);
        if (!$anchorSession instanceof ProposedSession) {
            return $sessions;
        }

        if ($this->countRunSessionsOnDay($sessions, $anchorSession->getDay()) >= 2) {
            return $sessions;
        }

        $targetDurationInSeconds = $this->resolveSecondaryRunDuration(
            pairing: $doubleRunSpecification['pairing'],
            targetRace: $targetRace,
            phase: $phase,
            loadMultiplier: $loadMultiplier,
            linkedTrainingPlan: $linkedTrainingPlan,
            adaptivePlanningContext: $adaptivePlanningContext,
        );
        $workoutSteps = $this->buildSecondaryRunWorkoutSteps($doubleRunSpecification['pairing'], $targetDurationInSeconds);
        $workoutSteps = $this->resolveStructuredWorkoutSteps(
            activityType: ActivityType::RUN,
            phase: $phase,
            intensity: $doubleRunSpecification['secondaryIntensity'],
            isLongSession: false,
            targetDurationInSeconds: $targetDurationInSeconds,
            workoutSteps: $workoutSteps,
            linkedTrainingPlan: $linkedTrainingPlan,
        );
        $notes = $this->appendPerformanceGuidance(
            ActivityType::RUN,
            $doubleRunSpecification['secondaryIntensity'],
            $doubleRunSpecification['notes'],
            $linkedTrainingPlan,
        );

        $sessions[] = ProposedSession::create(
            day: $anchorSession->getDay(),
            activityType: ActivityType::RUN,
            targetIntensity: $doubleRunSpecification['secondaryIntensity'],
            title: $doubleRunSpecification['title'],
            notes: $notes,
            targetDurationInSeconds: $targetDurationInSeconds,
            workoutSteps: $workoutSteps,
        );

        return $sessions;
    }

    /**
     * @param list<ProposedSession> $sessions
     *
     * @return array{pairing: string, anchorIntensity: PlannedSessionIntensity, secondaryIntensity: PlannedSessionIntensity, title: string, notes: string}|null
     */
    private function resolveDoubleRunSpecification(
        array $sessions,
        RaceProfileTrainingRules $rules,
        TrainingBlockPhase $phase,
        ?TrainingPlan $linkedTrainingPlan = null,
        ?AdaptivePlanningContext $adaptivePlanningContext = null,
    ): ?array {
        if (!$this->supportsDoubleRunScheduling($rules, $linkedTrainingPlan)) {
            return null;
        }

        if (!in_array($phase, [TrainingBlockPhase::BASE, TrainingBlockPhase::BUILD, TrainingBlockPhase::PEAK], true)) {
            return null;
        }

        $runningVolume = $this->resolveEffectiveWeeklyVolume(ActivityType::RUN, $linkedTrainingPlan, $adaptivePlanningContext);
        if (null === $runningVolume || $runningVolume < 40.0) {
            return null;
        }

        $availableTrainingDays = $this->countAvailableTrainingDays($linkedTrainingPlan?->getSportSchedule());
        if ($availableTrainingDays < 5) {
            return null;
        }

        $readinessStatus = $adaptivePlanningContext?->getCurrentWeekReadinessContext()->getReadinessScore()?->getStatus();
        if (ReadinessStatus::NEEDS_RECOVERY === $readinessStatus) {
            return null;
        }

        $hardRunCount = $this->countRunSessionsByIntensity($sessions, PlannedSessionIntensity::HARD);
        $easyRunCount = $this->countRunSessionsByIntensity($sessions, PlannedSessionIntensity::EASY);

        if ($this->isSpeedEnduranceTrainingPlan($linkedTrainingPlan)
            && in_array($phase, [TrainingBlockPhase::BUILD, TrainingBlockPhase::PEAK], true)
            && $runningVolume >= 70.0
            && $availableTrainingDays >= 6
            && $hardRunCount >= 1
            && ReadinessStatus::CAUTION !== $readinessStatus) {
            return [
                'pairing' => 'threshold_threshold',
                'anchorIntensity' => PlannedSessionIntensity::HARD,
                'secondaryIntensity' => PlannedSessionIntensity::HARD,
                'title' => 'Secondary threshold run',
                'notes' => 'Expert-only double-run day. Keep the second run compact and controlled, and downgrade it to easy if mechanics are off.',
            ];
        }

        if (($this->isSpeedEnduranceTrainingPlan($linkedTrainingPlan) || TrainingFocus::RUN === $linkedTrainingPlan?->getTrainingFocus())
            && in_array($phase, [TrainingBlockPhase::BUILD, TrainingBlockPhase::PEAK], true)
            && $runningVolume >= 50.0
            && $hardRunCount >= 1) {
            return [
                'pairing' => 'easy_threshold',
                'anchorIntensity' => PlannedSessionIntensity::HARD,
                'secondaryIntensity' => PlannedSessionIntensity::EASY,
                'title' => 'Secondary easy run',
                'notes' => 'Second run of the day. Keep it truly conversational so the quality session remains the only real stressor.',
            ];
        }

        if ($easyRunCount >= 1) {
            return [
                'pairing' => 'easy_easy',
                'anchorIntensity' => PlannedSessionIntensity::EASY,
                'secondaryIntensity' => PlannedSessionIntensity::EASY,
                'title' => 'Secondary easy run',
                'notes' => 'Second run of the day. Keep this short, relaxed, and finish before the first run starts talking back.',
            ];
        }

        return null;
    }

    private function supportsDoubleRunScheduling(RaceProfileTrainingRules $rules, ?TrainingPlan $linkedTrainingPlan = null): bool
    {
        if (!$rules->needsRunSessions() || !$this->isDevelopmentTrainingPlan($linkedTrainingPlan)) {
            return false;
        }

        return match ($linkedTrainingPlan?->getDiscipline()) {
            TrainingPlanDiscipline::RUNNING => true,
            TrainingPlanDiscipline::TRIATHLON => TrainingFocus::RUN === $linkedTrainingPlan?->getTrainingFocus(),
            default => false,
        };
    }

    /**
     * @param list<ProposedSession> $sessions
     */
    private function countRunSessionsByIntensity(array $sessions, PlannedSessionIntensity $intensity): int
    {
        return count(array_filter($sessions, function (ProposedSession $session) use ($intensity): bool {
            return ActivityType::RUN === $session->getActivityType()
                && $intensity === $session->getTargetIntensity()
                && !$session->isBrickSession()
                && !$this->isLongSessionTitle($session->getTitle());
        }));
    }

    /**
     * @param list<ProposedSession> $sessions
     */
    private function resolveDoubleRunAnchorSession(array $sessions, PlannedSessionIntensity $anchorIntensity): ?ProposedSession
    {
        $candidates = array_values(array_filter($sessions, function (ProposedSession $session) use ($sessions, $anchorIntensity): bool {
            return ActivityType::RUN === $session->getActivityType()
                && $anchorIntensity === $session->getTargetIntensity()
                && !$session->isBrickSession()
                && !$this->isLongSessionTitle($session->getTitle())
                && !$this->isSecondaryRunTitle($session->getTitle())
                && 1 === $this->countSessionsOnDay($sessions, $session->getDay())
                && 1 === $this->countRunSessionsOnDay($sessions, $session->getDay());
        }));

        if ([] === $candidates) {
            return null;
        }

        usort($candidates, function (ProposedSession $left, ProposedSession $right): int {
            $keyComparison = ($right->isKeySession() ? 1 : 0) <=> ($left->isKeySession() ? 1 : 0);
            if (0 !== $keyComparison) {
                return $keyComparison;
            }

            $dayRankComparison = $this->resolveDoubleRunDayRank($left->getDay()) <=> $this->resolveDoubleRunDayRank($right->getDay());
            if (0 !== $dayRankComparison) {
                return $dayRankComparison;
            }

            return ($left->getTargetDurationInSeconds() ?? 0) <=> ($right->getTargetDurationInSeconds() ?? 0);
        });

        return $candidates[0] ?? null;
    }

    private function resolveDoubleRunDayRank(SerializableDateTime $day): int
    {
        $ranking = [2 => 0, 4 => 1, 5 => 2, 3 => 3, 6 => 4, 1 => 5, 7 => 6];

        return $ranking[(int) $day->format('N')] ?? 99;
    }

    private function resolveSecondaryRunDuration(
        string $pairing,
        RaceEvent $targetRace,
        TrainingBlockPhase $phase,
        float $loadMultiplier,
        ?TrainingPlan $linkedTrainingPlan = null,
        ?AdaptivePlanningContext $adaptivePlanningContext = null,
    ): int {
        $durationInSeconds = match ($pairing) {
            'easy_easy' => (int) round($this->resolveEasySessionDuration(
                activityType: ActivityType::RUN,
                profile: $targetRace->getProfile(),
                loadMultiplier: $loadMultiplier,
                linkedTrainingPlan: $linkedTrainingPlan,
                adaptivePlanningContext: $adaptivePlanningContext,
            ) * 0.55),
            'easy_threshold' => (int) round($this->resolveEasySessionDuration(
                activityType: ActivityType::RUN,
                profile: $targetRace->getProfile(),
                loadMultiplier: $loadMultiplier,
                linkedTrainingPlan: $linkedTrainingPlan,
                adaptivePlanningContext: $adaptivePlanningContext,
            ) * 0.45),
            'threshold_threshold' => (int) round($this->resolveKeySessionDuration(
                activityType: ActivityType::RUN,
                profile: $targetRace->getProfile(),
                phase: $phase,
                loadMultiplier: $loadMultiplier,
                linkedTrainingPlan: $linkedTrainingPlan,
                adaptivePlanningContext: $adaptivePlanningContext,
            ) * 0.55),
            default => 1_500,
        };

        return match ($pairing) {
            'easy_easy' => max(1_200, min(2_100, $durationInSeconds)),
            'easy_threshold' => max(900, min(1_800, $durationInSeconds)),
            'threshold_threshold' => max(1_500, min(2_400, $durationInSeconds)),
            default => max(900, $durationInSeconds),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildSecondaryRunWorkoutSteps(string $pairing, int $targetDurationInSeconds): array
    {
        return match ($pairing) {
            'threshold_threshold' => $this->buildSecondaryThresholdRunWorkoutSteps($targetDurationInSeconds),
            'easy_threshold' => $this->buildEasyEnduranceWorkoutSteps(ActivityType::RUN, $targetDurationInSeconds, 'Short aerobic support'),
            default => $this->buildEasyEnduranceWorkoutSteps(ActivityType::RUN, $targetDurationInSeconds, 'Secondary aerobic run'),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildSecondaryThresholdRunWorkoutSteps(int $targetDurationInSeconds): array
    {
        $warmup = 420;
        $cooldown = 360;
        $mainDurationInSeconds = max(600, $targetDurationInSeconds - $warmup - $cooldown);
        $repeatCount = $mainDurationInSeconds >= 1_020 ? 3 : 2;
        $workDurationInSeconds = max(240, (int) floor(($mainDurationInSeconds * 0.68) / $repeatCount));
        $recoveryDurationInSeconds = max(90, (int) floor(($mainDurationInSeconds * 0.32) / $repeatCount));
        $allocatedDurationInSeconds = $warmup + $cooldown + ($repeatCount * ($workDurationInSeconds + $recoveryDurationInSeconds));
        $cooldown += max(0, $targetDurationInSeconds - $allocatedDurationInSeconds);

        return [
            $this->buildTimedWorkoutStep('warmup', $warmup, 'Easy jog + reset'),
            $this->buildRepeatBlockWorkoutStep(
                $repeatCount,
                [
                    $this->buildTimedWorkoutStep('interval', $workDurationInSeconds, 'Controlled threshold'),
                    $this->buildTimedWorkoutStep('recovery', $recoveryDurationInSeconds, 'Easy jog'),
                ],
            ),
            $this->buildTimedWorkoutStep('cooldown', $cooldown, 'Easy finish'),
        ];
    }

    /**
     * @param list<ActivityType> $disciplines
     *
     * @return list<ActivityType>
     */
    private function resolveHardSessionActivityTypes(array $disciplines, int $maxHardSessions, ?TrainingPlan $linkedTrainingPlan = null): array
    {
        if ($maxHardSessions <= 0 || [] === $disciplines) {
            return [];
        }

        $countsByDiscipline = [];
        foreach ($disciplines as $discipline) {
            $countsByDiscipline[$discipline->value] = ($countsByDiscipline[$discipline->value] ?? 0) + 1;
        }

        $prioritizedDisciplines = array_values(array_reduce($disciplines, static function (array $carry, ActivityType $discipline): array {
            $carry[$discipline->value] = $discipline;

            return $carry;
        }, []));

        usort($prioritizedDisciplines, function (ActivityType $left, ActivityType $right) use ($linkedTrainingPlan): int {
            return $this->resolveHardDisciplineWeight($right, $linkedTrainingPlan) <=> $this->resolveHardDisciplineWeight($left, $linkedTrainingPlan);
        });

        $hardSessionActivities = [];
        while (count($hardSessionActivities) < $maxHardSessions) {
            $selectedInPass = false;

            foreach ($prioritizedDisciplines as $discipline) {
                if (($countsByDiscipline[$discipline->value] ?? 0) <= 0) {
                    continue;
                }

                $hardSessionActivities[] = $discipline;
                --$countsByDiscipline[$discipline->value];
                $selectedInPass = true;

                if (count($hardSessionActivities) >= $maxHardSessions) {
                    break;
                }
            }

            if (!$selectedInPass) {
                break;
            }
        }

        return $hardSessionActivities;
    }

    private function resolveHardDisciplineWeight(ActivityType $activityType, ?TrainingPlan $linkedTrainingPlan = null): int
    {
        $weight = $this->resolveDisciplineWeight($activityType, $linkedTrainingPlan);
        $weight += match ($activityType) {
            ActivityType::RUN => 6,
            ActivityType::RIDE => 4,
            ActivityType::WATER_SPORTS => 0,
            default => 0,
        };

        if ($this->resolveFocusActivityType($linkedTrainingPlan?->getTrainingFocus()) === $activityType) {
            $weight += 4;
        }

        return $weight;
    }

    /**
     * @param list<ActivityType> $disciplines
     */
    private function removeFirstDisciplineOccurrence(array &$disciplines, ActivityType $activityType): bool
    {
        foreach ($disciplines as $index => $discipline) {
            if ($discipline !== $activityType) {
                continue;
            }

            unset($disciplines[$index]);
            $disciplines = array_values($disciplines);

            return true;
        }

        return false;
    }

    /**
     * @param list<ProposedSession> $existingSessions
     *
     * @return array{0: ?string, 1: ?string, 2: ?int, 3: list<array<string, mixed>>}
     */
    private function applyRecommendedTrainingSession(
        ActivityType $activityType,
        TrainingBlockPhase $phase,
        PlannedSessionIntensity $intensity,
        bool $isLongSession,
        ?string $title,
        ?string $notes,
        ?int $targetDurationInSeconds,
        array $existingSessions,
    ): array {
        $recommendedTrainingSession = $this->findRecommendedTrainingSession(
            activityType: $activityType,
            phase: $phase,
            intensity: $intensity,
            isLongSession: $isLongSession,
            targetDurationInSeconds: $targetDurationInSeconds,
            existingSessions: $existingSessions,
        );

        if (!$recommendedTrainingSession instanceof TrainingSession) {
            return [$title, $notes, $targetDurationInSeconds, []];
        }

        return [
            $recommendedTrainingSession->getTitle() ?? $title,
            $recommendedTrainingSession->getNotes() ?? $notes,
            $recommendedTrainingSession->getTargetDurationInSeconds() ?? $targetDurationInSeconds,
            $recommendedTrainingSession->getWorkoutSteps(),
        ];
    }

    /**
     * @param list<ProposedSession> $existingSessions
     */
    private function findRecommendedTrainingSession(
        ActivityType $activityType,
        TrainingBlockPhase $phase,
        PlannedSessionIntensity $intensity,
        bool $isLongSession,
        ?int $targetDurationInSeconds,
        array $existingSessions,
    ): ?TrainingSession {
        if (!$this->trainingSessionRepository instanceof TrainingSessionRepository || null === $targetDurationInSeconds) {
            return null;
        }

        $objective = $this->resolveTrainingSessionObjective($phase, $intensity, $isLongSession);
        $durationTolerance = max(600, (int) round($targetDurationInSeconds * 0.2));
        $requiresWorkoutSteps = !$isLongSession && in_array($intensity, [PlannedSessionIntensity::HARD, PlannedSessionIntensity::MODERATE], true);
        $criteriaVariants = [
            new TrainingSessionRecommendationCriteria(
                sessionPhase: $phase,
                sessionObjective: $objective,
                targetIntensity: $intensity,
                minimumTargetDurationInSeconds: max(300, $targetDurationInSeconds - $durationTolerance),
                maximumTargetDurationInSeconds: $targetDurationInSeconds + $durationTolerance,
                requiresWorkoutSteps: $requiresWorkoutSteps ? true : null,
            ),
            new TrainingSessionRecommendationCriteria(
                sessionObjective: $objective,
                targetIntensity: $intensity,
                minimumTargetDurationInSeconds: max(300, $targetDurationInSeconds - $durationTolerance),
                maximumTargetDurationInSeconds: $targetDurationInSeconds + $durationTolerance,
                requiresWorkoutSteps: $requiresWorkoutSteps ? true : null,
            ),
            new TrainingSessionRecommendationCriteria(
                sessionObjective: $objective,
                minimumTargetDurationInSeconds: max(300, $targetDurationInSeconds - $durationTolerance),
                maximumTargetDurationInSeconds: $targetDurationInSeconds + $durationTolerance,
                requiresWorkoutSteps: $requiresWorkoutSteps ? true : null,
            ),
        ];

        foreach ($criteriaVariants as $criteria) {
            foreach ($this->trainingSessionRepository->findRecommended($activityType, 8, $criteria) as $trainingSession) {
                if ($this->matchesExistingWeekSession($trainingSession, $existingSessions)) {
                    continue;
                }

                return $trainingSession;
            }
        }

        return null;
    }

    private function resolveTrainingSessionObjective(
        TrainingBlockPhase $phase,
        PlannedSessionIntensity $intensity,
        bool $isLongSession,
    ): TrainingSessionObjective {
        if (TrainingBlockPhase::RECOVERY === $phase) {
            return TrainingSessionObjective::RECOVERY;
        }

        if ($isLongSession) {
            return TrainingSessionObjective::ENDURANCE;
        }

        return match (true) {
            TrainingBlockPhase::PEAK === $phase => TrainingSessionObjective::RACE_SPECIFIC,
            TrainingBlockPhase::BUILD === $phase && PlannedSessionIntensity::HARD === $intensity => TrainingSessionObjective::HIGH_INTENSITY,
            TrainingBlockPhase::BASE === $phase && PlannedSessionIntensity::HARD === $intensity => TrainingSessionObjective::THRESHOLD,
            PlannedSessionIntensity::MODERATE === $intensity => TrainingSessionObjective::THRESHOLD,
            PlannedSessionIntensity::EASY === $intensity => TrainingSessionObjective::ENDURANCE,
            default => TrainingSessionObjective::ENDURANCE,
        };
    }

    /**
     * @param list<ProposedSession> $existingSessions
     */
    private function matchesExistingWeekSession(TrainingSession $trainingSession, array $existingSessions): bool
    {
        foreach ($existingSessions as $existingSession) {
            if ($trainingSession->getTitle() !== null && $trainingSession->getTitle() === $existingSession->getTitle()) {
                return true;
            }

            if ([] !== $trainingSession->getWorkoutSteps() && $trainingSession->getWorkoutSteps() === $existingSession->getWorkoutSteps()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<ActivityType>
     */
    private function resolveLongSessionDisciplines(RaceProfileTrainingRules $rules, ?TrainingPlan $linkedTrainingPlan = null): array
    {
        if (TrainingPlanDiscipline::RUNNING === $linkedTrainingPlan?->getDiscipline()) {
            return [ActivityType::RUN];
        }

        if (TrainingPlanDiscipline::CYCLING === $linkedTrainingPlan?->getDiscipline()) {
            return [ActivityType::RIDE];
        }

        if ($this->isSpeedEnduranceTrainingPlan($linkedTrainingPlan) && TrainingPlanDiscipline::TRIATHLON === $linkedTrainingPlan?->getDiscipline()) {
            return [ActivityType::RIDE, ActivityType::RUN];
        }

        $disciplines = [];
        $focusActivityType = $this->resolveFocusActivityType($linkedTrainingPlan?->getTrainingFocus());

        if ($focusActivityType instanceof ActivityType && match ($focusActivityType) {
            ActivityType::RUN => $rules->needsRunSessions(),
            ActivityType::RIDE => $rules->needsBikeSessions(),
            ActivityType::WATER_SPORTS => $rules->needsSwimSessions(),
            default => false,
        }) {
            $disciplines[] = $focusActivityType;
        }

        if ($rules->needsBikeSessions() && !in_array(ActivityType::RIDE, $disciplines, true)) {
            $disciplines[] = ActivityType::RIDE;
        }

        if ($rules->needsRunSessions() && !in_array(ActivityType::RUN, $disciplines, true)) {
            $disciplines[] = ActivityType::RUN;
        }

        if ([] === $disciplines && $rules->needsSwimSessions()) {
            $disciplines[] = ActivityType::WATER_SPORTS;
        }

        $longSessionCount = $this->isSpeedEnduranceTrainingPlan($linkedTrainingPlan)
            && TrainingPlanDiscipline::TRIATHLON === $linkedTrainingPlan?->getDiscipline()
            ? 2
            : max(1, $rules->getLongSessionsPerWeek());

        return array_slice($disciplines, 0, $longSessionCount);
    }

    /**
     * @param list<ActivityType> $longSessionDisciplines
     * @param array<string, true> $placedLongSessionDisciplines
     */
    private function shouldPlaceLongSession(
        ActivityType $activityType,
        array $longSessionDisciplines,
        array $placedLongSessionDisciplines,
    ): bool {
        foreach ($longSessionDisciplines as $discipline) {
            if (isset($placedLongSessionDisciplines[$discipline->value])) {
                continue;
            }

            return $discipline === $activityType;
        }

        return false;
    }

    private function resolveTaperLoadMultiplier(int $weekInBlock, int $blockDurationWeeks): float
    {
        $taperLength = max(1, min(3, $blockDurationWeeks));

        return match ($taperLength) {
            1 => 0.52,
            2 => 1 === $weekInBlock ? 0.66 : 0.42,
            default => match ($weekInBlock) {
                1 => 0.76,
                2 => 0.60,
                default => 0.42,
            },
        };
    }

    /**
     * @return list<ProposedSession>
     */
    private function buildTaperWeekSessions(
        RaceProfileTrainingRules $rules,
        RaceEvent $targetRace,
        SerializableDateTime $weekStart,
        float $loadMultiplier,
        int $weekInBlock,
        int $blockDurationWeeks,
        ?RaceEvent $weekRace,
        ?TrainingPlan $linkedTrainingPlan = null,
    ): array {
        $weekEnd = SerializableDateTime::fromDateTimeImmutable($weekStart->modify('+6 days'));
        $raceDay = $targetRace->getDay()->setTime(0, 0);
        $isRaceWeek = $raceDay >= $weekStart && $raceDay <= $weekEnd;

        $sessions = match ($targetRace->getFamily()) {
            RaceEventFamily::TRIATHLON,
            RaceEventFamily::MULTISPORT => $this->buildMultisportTaperSessions($rules, $targetRace, $weekStart, $weekEnd, $weekInBlock, $blockDurationWeeks, $isRaceWeek, $loadMultiplier),
            RaceEventFamily::RIDE => $this->buildRideTaperSessions($targetRace, $weekStart, $weekEnd, $weekInBlock, $blockDurationWeeks, $isRaceWeek),
            RaceEventFamily::SWIM => $this->buildSwimTaperSessions($targetRace, $weekStart, $weekEnd, $isRaceWeek),
            RaceEventFamily::RUN,
            RaceEventFamily::OTHER => $this->buildRunTaperSessions($targetRace, $weekStart, $weekEnd, $weekInBlock, $blockDurationWeeks, $isRaceWeek),
        };

        if (null !== $weekRace && !$isRaceWeek) {
            if ([] !== $sessions) {
                array_pop($sessions);
            }

            $sessions[] = $this->buildRaceEventSession($weekRace);
        }

        if ($isRaceWeek) {
            $sessions[] = $this->buildRaceEventSession($targetRace, 'A race');
        }

        $sessions = $this->applyPerformanceTargetsToSessions($sessions, $linkedTrainingPlan);

        $this->sortProposedSessions($sessions);

        return $sessions;
    }

    /**
     * @return list<ProposedSession>
     */
    private function buildMultisportTaperSessions(
        RaceProfileTrainingRules $rules,
        RaceEvent $targetRace,
        SerializableDateTime $weekStart,
        SerializableDateTime $weekEnd,
        int $weekInBlock,
        int $blockDurationWeeks,
        bool $isRaceWeek,
        float $loadMultiplier,
    ): array {
        $sessions = [];
        $raceDay = $targetRace->getDay()->setTime(0, 0);
        $isLongCourse = in_array($targetRace->getProfile(), [RaceEventProfile::HALF_DISTANCE_TRIATHLON, RaceEventProfile::FULL_DISTANCE_TRIATHLON], true);

        if ($isRaceWeek) {
            if ($rules->needsSwimSessions()) {
                $this->appendTaperSession(
                    sessions: $sessions,
                    weekStart: $weekStart,
                    weekEnd: $weekEnd,
                    day: SerializableDateTime::fromDateTimeImmutable($raceDay->modify('-6 days')),
                    activityType: ActivityType::WATER_SPORTS,
                    intensity: PlannedSessionIntensity::EASY,
                    title: 'Swim rhythm touch',
                    notes: 'A short swim with a few crisp efforts. Get out while you still feel hungry for more.',
                    workoutSteps: $this->buildSwimRhythmWorkoutSteps(true),
                );
            }

            if ($rules->needsBikeSessions()) {
                $this->appendTaperSession(
                    sessions: $sessions,
                    weekStart: $weekStart,
                    weekEnd: $weekEnd,
                    day: SerializableDateTime::fromDateTimeImmutable($raceDay->modify('-4 days')),
                    activityType: ActivityType::RIDE,
                    intensity: PlannedSessionIntensity::MODERATE,
                    title: 'Bike openers',
                    notes: 'Short race-specific touches only. The goal is snap, not fatigue.',
                    workoutSteps: $this->buildBikeSharpenerWorkoutSteps(true, $isLongCourse),
                    isKeySession: true,
                );
            }

            if ($rules->needsRunSessions()) {
                $this->appendTaperSession(
                    sessions: $sessions,
                    weekStart: $weekStart,
                    weekEnd: $weekEnd,
                    day: SerializableDateTime::fromDateTimeImmutable($raceDay->modify('-2 days')),
                    activityType: ActivityType::RUN,
                    intensity: PlannedSessionIntensity::EASY,
                    title: 'Run opener',
                    notes: 'Keep it short and relaxed with a few quick strides so race pace feels familiar.',
                    workoutSteps: $this->buildRunOpenerWorkoutSteps(),
                );
            }

            return $sessions;
        }

        if ($rules->needsSwimSessions()) {
            $this->appendTaperSession(
                sessions: $sessions,
                weekStart: $weekStart,
                weekEnd: $weekEnd,
                day: SerializableDateTime::fromDateTimeImmutable($weekStart->modify('+1 day')),
                activityType: ActivityType::WATER_SPORTS,
                intensity: PlannedSessionIntensity::EASY,
                title: 'Swim rhythm tune-up',
                notes: 'Hold relaxed race rhythm, keep the stroke long, and finish feeling smooth.',
                workoutSteps: $this->buildSwimRhythmWorkoutSteps(false),
            );
        }

        if ($isLongCourse && $rules->needsBikeSessions()) {
            $this->appendTaperSession(
                sessions: $sessions,
                weekStart: $weekStart,
                weekEnd: $weekEnd,
                day: $weekStart,
                activityType: ActivityType::RIDE,
                intensity: PlannedSessionIntensity::EASY,
                title: 'Easy taper flush',
                notes: 'A short steady spin to absorb the peak block and keep the legs moving without sneaking in extra fatigue.',
                workoutSteps: $this->buildEasyEnduranceWorkoutSteps(
                    ActivityType::RIDE,
                    max(2_400, (int) round(3_600 * $loadMultiplier)),
                    'Easy aerobic flush',
                ),
            );
        }

        if ($rules->needsBikeSessions()) {
            $this->appendTaperSession(
                sessions: $sessions,
                weekStart: $weekStart,
                weekEnd: $weekEnd,
                day: SerializableDateTime::fromDateTimeImmutable($weekStart->modify('+3 days')),
                activityType: ActivityType::RIDE,
                intensity: PlannedSessionIntensity::MODERATE,
                title: 'Bike race-pace cutdown',
                notes: 'Keep the race-specific work compact. Stop before the legs get heavy.',
                workoutSteps: $this->buildBikeSharpenerWorkoutSteps(false, $isLongCourse),
                isKeySession: true,
            );
        }

        if ($rules->needsRunSessions()) {
            $this->appendTaperSession(
                sessions: $sessions,
                weekStart: $weekStart,
                weekEnd: $weekEnd,
                day: SerializableDateTime::fromDateTimeImmutable($weekStart->modify(sprintf('+%d days', $isLongCourse ? 4 : 5))),
                activityType: ActivityType::RUN,
                intensity: PlannedSessionIntensity::MODERATE,
                title: 'Run race-pace cutdown',
                notes: 'Dial in cadence and race feel, then back off before the session bites back.',
                workoutSteps: $this->buildRunSharpenerWorkoutSteps(false, $targetRace->getProfile()),
                isKeySession: true,
            );
        }

        if ($isLongCourse && $rules->needsBikeSessions()) {
            $this->appendTaperSession(
                sessions: $sessions,
                weekStart: $weekStart,
                weekEnd: $weekEnd,
                day: SerializableDateTime::fromDateTimeImmutable($weekStart->modify('+5 days')),
                activityType: ActivityType::RIDE,
                intensity: PlannedSessionIntensity::MODERATE,
                title: 'Reduced endurance ride',
                notes: 'Keep the long-course endurance feel, but stop while it still feels comfortably contained.',
                workoutSteps: $this->buildReducedEnduranceRideWorkoutSteps($targetRace->getProfile(), $weekInBlock, $blockDurationWeeks),
                isKeySession: true,
            );
        }

        if ($isLongCourse && $rules->needsRunSessions()) {
            $this->appendTaperSession(
                sessions: $sessions,
                weekStart: $weekStart,
                weekEnd: $weekEnd,
                day: SerializableDateTime::fromDateTimeImmutable($weekStart->modify('+6 days')),
                activityType: ActivityType::RUN,
                intensity: PlannedSessionIntensity::MODERATE,
                title: 'Controlled long run',
                notes: 'Shorter than peak, but still long enough to keep long-course rhythm without any late-week residue.',
                workoutSteps: $this->buildTaperLongRunWorkoutSteps($targetRace->getProfile(), $weekInBlock, $blockDurationWeeks),
                isKeySession: true,
            );

            return $sessions;
        }

        $this->appendTaperSession(
            sessions: $sessions,
            weekStart: $weekStart,
            weekEnd: $weekEnd,
            day: SerializableDateTime::fromDateTimeImmutable($weekStart->modify('+6 days')),
            activityType: $rules->needsBikeSessions() ? ActivityType::RIDE : ActivityType::RUN,
            intensity: PlannedSessionIntensity::EASY,
            title: $rules->needsBikeSessions() ? 'Easy taper flush' : 'Easy taper support',
            notes: 'This is just enough movement to keep the legs awake. If you feel flat, do a little less.',
            workoutSteps: $this->buildEasyEnduranceWorkoutSteps(
                $rules->needsBikeSessions() ? ActivityType::RIDE : ActivityType::RUN,
                max(1_800, (int) round(2_700 * $loadMultiplier)),
                'Easy aerobic flush',
            ),
        );

        return $sessions;
    }

    /**
     * @return list<ProposedSession>
     */
    private function buildRunTaperSessions(
        RaceEvent $targetRace,
        SerializableDateTime $weekStart,
        SerializableDateTime $weekEnd,
        int $weekInBlock,
        int $blockDurationWeeks,
        bool $isRaceWeek,
    ): array {
        $sessions = [];
        $raceDay = $targetRace->getDay()->setTime(0, 0);

        if ($isRaceWeek) {
            $this->appendTaperSession(
                sessions: $sessions,
                weekStart: $weekStart,
                weekEnd: $weekEnd,
                day: SerializableDateTime::fromDateTimeImmutable($raceDay->modify('-5 days')),
                activityType: ActivityType::RUN,
                intensity: PlannedSessionIntensity::MODERATE,
                title: 'Race-pace touch',
                notes: 'A short reminder session to keep race rhythm familiar without carrying any residue.',
                workoutSteps: $this->buildRunSharpenerWorkoutSteps(true, $targetRace->getProfile()),
                isKeySession: true,
            );
            $this->appendTaperSession(
                sessions: $sessions,
                weekStart: $weekStart,
                weekEnd: $weekEnd,
                day: SerializableDateTime::fromDateTimeImmutable($raceDay->modify('-3 days')),
                activityType: ActivityType::RUN,
                intensity: PlannedSessionIntensity::EASY,
                title: 'Easy run + strides',
                notes: 'Keep the easy running easy. The strides are just for rhythm and relaxation.',
                workoutSteps: $this->buildEasyRunWithStridesWorkoutSteps(),
            );
            $this->appendTaperSession(
                sessions: $sessions,
                weekStart: $weekStart,
                weekEnd: $weekEnd,
                day: SerializableDateTime::fromDateTimeImmutable($raceDay->modify('-1 day')),
                activityType: ActivityType::RUN,
                intensity: PlannedSessionIntensity::EASY,
                title: 'Shakeout run',
                notes: 'This is optional if travel, nerves, or life say otherwise. Finish fresher than you started.',
                workoutSteps: $this->buildShakeoutWorkoutSteps(),
            );

            return $sessions;
        }

        $this->appendTaperSession(
            sessions: $sessions,
            weekStart: $weekStart,
            weekEnd: $weekEnd,
            day: SerializableDateTime::fromDateTimeImmutable($weekStart->modify('+1 day')),
            activityType: ActivityType::RUN,
            intensity: PlannedSessionIntensity::EASY,
            title: 'Easy run + strides',
            notes: 'Keep the easy volume trimmed and let the short strides keep the legs lively.',
            workoutSteps: $this->buildEasyRunWithStridesWorkoutSteps(),
        );
        $this->appendTaperSession(
            sessions: $sessions,
            weekStart: $weekStart,
            weekEnd: $weekEnd,
            day: SerializableDateTime::fromDateTimeImmutable($weekStart->modify('+3 days')),
            activityType: ActivityType::RUN,
            intensity: PlannedSessionIntensity::MODERATE,
            title: 'Race-pace cutdown',
            notes: 'This keeps rhythm and confidence high while overall volume keeps stepping down.',
            workoutSteps: $this->buildRunSharpenerWorkoutSteps(false, $targetRace->getProfile()),
            isKeySession: true,
        );
        $this->appendTaperSession(
            sessions: $sessions,
            weekStart: $weekStart,
            weekEnd: $weekEnd,
            day: SerializableDateTime::fromDateTimeImmutable($weekStart->modify('+5 days')),
            activityType: ActivityType::RUN,
            intensity: PlannedSessionIntensity::MODERATE,
            title: 'Controlled long run',
            notes: 'Shorter than a peak long run and never a grind. Finish feeling contained, not depleted.',
            workoutSteps: $this->buildTaperLongRunWorkoutSteps($targetRace->getProfile(), $weekInBlock, $blockDurationWeeks),
            isKeySession: true,
        );
        $this->appendTaperSession(
            sessions: $sessions,
            weekStart: $weekStart,
            weekEnd: $weekEnd,
            day: SerializableDateTime::fromDateTimeImmutable($weekStart->modify('+6 days')),
            activityType: ActivityType::RUN,
            intensity: PlannedSessionIntensity::EASY,
            title: 'Easy taper support',
            notes: 'A short easy run to stay loose. Skip it if recovery is lagging.',
            workoutSteps: $this->buildEasyEnduranceWorkoutSteps(ActivityType::RUN, 1_800, 'Relaxed aerobic support'),
        );

        return $sessions;
    }

    /**
     * @return list<ProposedSession>
     */
    private function buildRideTaperSessions(
        RaceEvent $targetRace,
        SerializableDateTime $weekStart,
        SerializableDateTime $weekEnd,
        int $weekInBlock,
        int $blockDurationWeeks,
        bool $isRaceWeek,
    ): array {
        $sessions = [];
        $raceDay = $targetRace->getDay()->setTime(0, 0);
        $isLongRideRace = in_array($targetRace->getProfile(), [RaceEventProfile::RIDE, RaceEventProfile::GRAVEL_RACE], true);

        if ($isRaceWeek) {
            $this->appendTaperSession(
                sessions: $sessions,
                weekStart: $weekStart,
                weekEnd: $weekEnd,
                day: SerializableDateTime::fromDateTimeImmutable($raceDay->modify('-5 days')),
                activityType: ActivityType::RIDE,
                intensity: PlannedSessionIntensity::MODERATE,
                title: 'Race-pace touch',
                notes: 'Short work at race feel with generous recovery. The goal is sharpness, not strain.',
                workoutSteps: $this->buildBikeSharpenerWorkoutSteps(true, $isLongRideRace),
                isKeySession: true,
            );
            $this->appendTaperSession(
                sessions: $sessions,
                weekStart: $weekStart,
                weekEnd: $weekEnd,
                day: SerializableDateTime::fromDateTimeImmutable($raceDay->modify('-3 days')),
                activityType: ActivityType::RIDE,
                intensity: PlannedSessionIntensity::EASY,
                title: 'Cadence opener',
                notes: 'A brief spin with a few fast legs. Keep the rest of the ride genuinely easy.',
                workoutSteps: $this->buildRideCadenceWorkoutSteps(),
            );
            $this->appendTaperSession(
                sessions: $sessions,
                weekStart: $weekStart,
                weekEnd: $weekEnd,
                day: SerializableDateTime::fromDateTimeImmutable($raceDay->modify('-1 day')),
                activityType: ActivityType::RIDE,
                intensity: PlannedSessionIntensity::EASY,
                title: 'Leg opener spin',
                notes: 'Optional and short. You should feel better after it than during it.',
                workoutSteps: $this->buildEasyEnduranceWorkoutSteps(ActivityType::RIDE, 1_500, 'Easy leg opener'),
            );

            return $sessions;
        }

        $this->appendTaperSession(
            sessions: $sessions,
            weekStart: $weekStart,
            weekEnd: $weekEnd,
            day: SerializableDateTime::fromDateTimeImmutable($weekStart->modify('+1 day')),
            activityType: ActivityType::RIDE,
            intensity: PlannedSessionIntensity::EASY,
            title: 'Aerobic spin',
            notes: 'Steady aerobic time, no hero pulls, no sneaky tempo detours.',
            workoutSteps: $this->buildEasyEnduranceWorkoutSteps(ActivityType::RIDE, 2_700, 'Controlled endurance'),
        );
        $this->appendTaperSession(
            sessions: $sessions,
            weekStart: $weekStart,
            weekEnd: $weekEnd,
            day: SerializableDateTime::fromDateTimeImmutable($weekStart->modify('+3 days')),
            activityType: ActivityType::RIDE,
            intensity: PlannedSessionIntensity::MODERATE,
            title: 'Race-pace cutdown',
            notes: 'Keep race-specific pressure in the legs, but only in bite-sized pieces.',
            workoutSteps: $this->buildBikeSharpenerWorkoutSteps(false, $isLongRideRace),
            isKeySession: true,
        );
        $this->appendTaperSession(
            sessions: $sessions,
            weekStart: $weekStart,
            weekEnd: $weekEnd,
            day: SerializableDateTime::fromDateTimeImmutable($weekStart->modify('+5 days')),
            activityType: ActivityType::RIDE,
            intensity: PlannedSessionIntensity::MODERATE,
            title: 'Reduced endurance ride',
            notes: 'A shorter endurance ride than peak. Keep it steady and finish with plenty left in the tank.',
            workoutSteps: $this->buildReducedEnduranceRideWorkoutSteps($targetRace->getProfile(), $weekInBlock, $blockDurationWeeks),
            isKeySession: true,
        );
        $this->appendTaperSession(
            sessions: $sessions,
            weekStart: $weekStart,
            weekEnd: $weekEnd,
            day: SerializableDateTime::fromDateTimeImmutable($weekStart->modify('+6 days')),
            activityType: ActivityType::RIDE,
            intensity: PlannedSessionIntensity::EASY,
            title: 'Easy taper support',
            notes: 'Short and light. Leave a little spring in the legs.',
            workoutSteps: $this->buildRideCadenceWorkoutSteps(),
        );

        return $sessions;
    }

    /**
     * @return list<ProposedSession>
     */
    private function buildSwimTaperSessions(
        RaceEvent $targetRace,
        SerializableDateTime $weekStart,
        SerializableDateTime $weekEnd,
        bool $isRaceWeek,
    ): array {
        $sessions = [];
        $raceDay = $targetRace->getDay()->setTime(0, 0);

        if ($isRaceWeek) {
            $this->appendTaperSession(
                sessions: $sessions,
                weekStart: $weekStart,
                weekEnd: $weekEnd,
                day: SerializableDateTime::fromDateTimeImmutable($raceDay->modify('-4 days')),
                activityType: ActivityType::WATER_SPORTS,
                intensity: PlannedSessionIntensity::MODERATE,
                title: 'Race-rhythm set',
                notes: 'A short hit of race rhythm with lots of easy swimming around it.',
                workoutSteps: $this->buildSwimRhythmWorkoutSteps(true),
                isKeySession: true,
            );
            $this->appendTaperSession(
                sessions: $sessions,
                weekStart: $weekStart,
                weekEnd: $weekEnd,
                day: SerializableDateTime::fromDateTimeImmutable($raceDay->modify('-2 days')),
                activityType: ActivityType::WATER_SPORTS,
                intensity: PlannedSessionIntensity::EASY,
                title: 'Loosen swim',
                notes: 'Keep it light, smooth, and confidence-building.',
                workoutSteps: $this->buildEasyEnduranceWorkoutSteps(ActivityType::WATER_SPORTS, 1_500, 'Relaxed loosen swim'),
            );

            return $sessions;
        }

        $this->appendTaperSession(
            sessions: $sessions,
            weekStart: $weekStart,
            weekEnd: $weekEnd,
            day: SerializableDateTime::fromDateTimeImmutable($weekStart->modify('+1 day')),
            activityType: ActivityType::WATER_SPORTS,
            intensity: PlannedSessionIntensity::MODERATE,
            title: 'Race-rhythm set',
            notes: 'Short main work at race feel, then get out before the shoulders feel stale.',
            workoutSteps: $this->buildSwimRhythmWorkoutSteps(false),
            isKeySession: true,
        );
        $this->appendTaperSession(
            sessions: $sessions,
            weekStart: $weekStart,
            weekEnd: $weekEnd,
            day: SerializableDateTime::fromDateTimeImmutable($weekStart->modify('+4 days')),
            activityType: ActivityType::WATER_SPORTS,
            intensity: PlannedSessionIntensity::EASY,
            title: 'Steady feel swim',
            notes: 'Stay technically tidy and keep the session comfortably sub-fatiguing.',
            workoutSteps: $this->buildEasyEnduranceWorkoutSteps(ActivityType::WATER_SPORTS, 2_100, 'Steady technical feel'),
        );
        $this->appendTaperSession(
            sessions: $sessions,
            weekStart: $weekStart,
            weekEnd: $weekEnd,
            day: SerializableDateTime::fromDateTimeImmutable($weekStart->modify('+6 days')),
            activityType: ActivityType::WATER_SPORTS,
            intensity: PlannedSessionIntensity::EASY,
            title: 'Open-water style loosen',
            notes: 'Keep a little rhythm in the water without turning it into a set that chases fatigue.',
            workoutSteps: $this->buildEasyEnduranceWorkoutSteps(ActivityType::WATER_SPORTS, 1_500, 'Short loosen swim'),
        );

        return $sessions;
    }

    private function appendTaperSession(
        array &$sessions,
        SerializableDateTime $weekStart,
        SerializableDateTime $weekEnd,
        SerializableDateTime $day,
        ActivityType $activityType,
        PlannedSessionIntensity $intensity,
        string $title,
        string $notes,
        array $workoutSteps,
        bool $isKeySession = false,
        bool $isBrickSession = false,
    ): void {
        if ($day < $weekStart || $day > $weekEnd) {
            return;
        }

        $candidate = ProposedSession::create(
            day: $day,
            activityType: $activityType,
            targetIntensity: $intensity,
            title: $title,
            notes: $notes,
            targetDurationInSeconds: $this->calculateWorkoutDuration($workoutSteps),
            isKeySession: $isKeySession,
            isBrickSession: $isBrickSession,
            workoutSteps: $workoutSteps,
        );

        if ($this->hasConflictingSession($sessions, $candidate)) {
            return;
        }

        $sessions[] = $candidate;
    }

    private function buildRaceEventSession(RaceEvent $raceEvent, ?string $notes = null): ProposedSession
    {
        return ProposedSession::create(
            day: $raceEvent->getDay(),
            activityType: $this->resolvePrimaryActivityType($raceEvent->getProfile()->getFamily()),
            targetIntensity: PlannedSessionIntensity::RACE,
            title: $this->resolveRaceEventTitle($raceEvent),
            notes: $notes ?? sprintf('%s race', RaceEventPriority::B === $raceEvent->getPriority() ? 'B' : 'C'),
            isKeySession: true,
        );
    }

    private function resolveRaceEventTitle(RaceEvent $raceEvent): string
    {
        $title = trim((string) ($raceEvent->getTitle() ?? ''));
        if ('' !== $title) {
            return $title;
        }

        return match ($raceEvent->getProfile()) {
            RaceEventProfile::RUN_5K => '5K run',
            RaceEventProfile::RUN_10K => '10K run',
            RaceEventProfile::HALF_MARATHON => 'Half marathon',
            RaceEventProfile::MARATHON => 'Marathon',
            RaceEventProfile::HALF_DISTANCE_TRIATHLON => '70.3 triathlon',
            RaceEventProfile::FULL_DISTANCE_TRIATHLON => 'Full-distance triathlon',
            default => $this->humanizeRaceProfileValue($raceEvent->getProfile()->value),
        };
    }

    private function humanizeRaceProfileValue(string $value): string
    {
        $label = preg_replace('/(?<!^)([A-Z])/', ' $1', $value) ?? $value;
        $label = str_replace(['5k', '10k'], ['5K', '10K'], $label);

        return ucfirst($label);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildSwimRhythmWorkoutSteps(bool $raceWeek): array
    {
        return [
            $this->buildTimedWorkoutStep('warmup', $raceWeek ? 480 : 600, 'Easy build + drills'),
            $this->buildRepeatBlockWorkoutStep(
                $raceWeek ? 4 : 3,
                [
                    $this->buildTimedWorkoutStep('steady', $raceWeek ? 120 : 300, $raceWeek ? 'Brisk rhythm' : 'Race rhythm'),
                    $this->buildTimedWorkoutStep('recovery', $raceWeek ? 60 : 90, 'Easy float'),
                ],
            ),
            $this->buildTimedWorkoutStep('cooldown', $raceWeek ? 360 : 480, 'Easy reset'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildBikeSharpenerWorkoutSteps(bool $raceWeek, bool $isLongCourse): array
    {
        if ($raceWeek) {
            return [
                $this->buildTimedWorkoutStep('warmup', 720, 'Easy spin + cadence build'),
                $this->buildRepeatBlockWorkoutStep(
                    3,
                    [
                        $this->buildTimedWorkoutStep('interval', 120, 'Race-effort opener'),
                        $this->buildTimedWorkoutStep('recovery', 180, 'Easy spin'),
                    ],
                ),
                $this->buildTimedWorkoutStep('cooldown', 600, 'Legs down'),
            ];
        }

        return [
            $this->buildTimedWorkoutStep('warmup', 900, 'Easy spin + cadence lift'),
            $this->buildRepeatBlockWorkoutStep(
                $isLongCourse ? 3 : 2,
                [
                    $this->buildTimedWorkoutStep('steady', $isLongCourse ? 480 : 360, $isLongCourse ? '70.3 / long-course effort' : 'Race effort'),
                    $this->buildTimedWorkoutStep('recovery', 240, 'Reset easy'),
                ],
            ),
            $this->buildTimedWorkoutStep('cooldown', 900, 'Easy roll home'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildRunSharpenerWorkoutSteps(bool $raceWeek, RaceEventProfile $profile): array
    {
        if ($raceWeek) {
            return [
                $this->buildTimedWorkoutStep('warmup', 600, 'Easy jog'),
                $this->buildRepeatBlockWorkoutStep(
                    in_array($profile, [RaceEventProfile::RUN_5K, RaceEventProfile::RUN_10K], true) ? 5 : 4,
                    [
                        $this->buildTimedWorkoutStep('interval', 30, 'Stride / opener'),
                        $this->buildTimedWorkoutStep('recovery', 90, 'Easy jog'),
                    ],
                ),
                $this->buildTimedWorkoutStep('cooldown', 600, 'Relaxed finish'),
            ];
        }

        $workDuration = match ($profile) {
            RaceEventProfile::MARATHON => 600,
            RaceEventProfile::HALF_MARATHON, RaceEventProfile::HALF_DISTANCE_TRIATHLON => 480,
            default => 360,
        };

        return [
            $this->buildTimedWorkoutStep('warmup', 720, 'Easy jog + drills'),
            $this->buildRepeatBlockWorkoutStep(
                2,
                [
                    $this->buildTimedWorkoutStep('steady', $workDuration, 'Race-pace feel'),
                    $this->buildTimedWorkoutStep('recovery', 180, 'Easy float'),
                ],
            ),
            $this->buildTimedWorkoutStep('cooldown', 600, 'Relaxed cooldown'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildRunOpenerWorkoutSteps(): array
    {
        return [
            $this->buildTimedWorkoutStep('warmup', 600, 'Easy jog'),
            $this->buildRepeatBlockWorkoutStep(
                4,
                [
                    $this->buildTimedWorkoutStep('interval', 30, 'Fast but relaxed stride'),
                    $this->buildTimedWorkoutStep('recovery', 90, 'Easy jog'),
                ],
            ),
            $this->buildTimedWorkoutStep('cooldown', 300, 'Easy finish'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildEasyRunWithStridesWorkoutSteps(): array
    {
        return [
            $this->buildTimedWorkoutStep('warmup', 300, 'Settle in'),
            $this->buildTimedWorkoutStep('steady', 1_500, 'Easy aerobic'),
            $this->buildRepeatBlockWorkoutStep(
                4,
                [
                    $this->buildTimedWorkoutStep('interval', 20, 'Smooth stride'),
                    $this->buildTimedWorkoutStep('recovery', 100, 'Walk / easy jog'),
                ],
            ),
            $this->buildTimedWorkoutStep('cooldown', 300, 'Easy reset'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildShakeoutWorkoutSteps(): array
    {
        return [
            $this->buildTimedWorkoutStep('warmup', 300, 'Easy start'),
            $this->buildTimedWorkoutStep('steady', 720, 'Very easy loosen'),
            $this->buildRepeatBlockWorkoutStep(
                2,
                [
                    $this->buildTimedWorkoutStep('interval', 20, 'Relaxed stride'),
                    $this->buildTimedWorkoutStep('recovery', 70, 'Walk back'),
                ],
            ),
            $this->buildTimedWorkoutStep('cooldown', 180, 'Done while feeling good'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildTaperLongRunWorkoutSteps(RaceEventProfile $profile, int $weekInBlock, int $blockDurationWeeks): array
    {
        $durationInSeconds = match ($profile) {
            RaceEventProfile::FULL_DISTANCE_TRIATHLON => match (true) {
                $blockDurationWeeks >= 3 && 1 === $weekInBlock => 7_800,
                $blockDurationWeeks >= 3 && 2 === $weekInBlock => 6_600,
                default => 6_000,
            },
            RaceEventProfile::HALF_DISTANCE_TRIATHLON => 1 === $weekInBlock ? 5_700 : 5_100,
            RaceEventProfile::MARATHON => 1 === $weekInBlock && $blockDurationWeeks >= 3 ? 5_400 : 4_800,
            RaceEventProfile::HALF_MARATHON => 4_200,
            default => 3_600,
        };

        return [
            $this->buildTimedWorkoutStep('warmup', 600, 'Easy start'),
            $this->buildTimedWorkoutStep('steady', max(1_800, $durationInSeconds - 1_500), 'Controlled aerobic'),
            $this->buildTimedWorkoutStep('steady', 600, 'Race rhythm touch'),
            $this->buildTimedWorkoutStep('cooldown', 300, 'Easy reset'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildReducedEnduranceRideWorkoutSteps(RaceEventProfile $profile, int $weekInBlock, int $blockDurationWeeks): array
    {
        $durationInSeconds = match ($profile) {
            RaceEventProfile::FULL_DISTANCE_TRIATHLON => match (true) {
                $blockDurationWeeks >= 3 && 1 === $weekInBlock => 18_000,
                $blockDurationWeeks >= 3 && 2 === $weekInBlock => 15_300,
                default => 13_500,
            },
            RaceEventProfile::HALF_DISTANCE_TRIATHLON => 1 === $weekInBlock ? 12_600 : 10_800,
            RaceEventProfile::GRAVEL_RACE => 5_400,
            RaceEventProfile::RIDE => 4_800,
            default => 4_200,
        };

        if (in_array($profile, [RaceEventProfile::HALF_DISTANCE_TRIATHLON, RaceEventProfile::FULL_DISTANCE_TRIATHLON], true)) {
            return [
                $this->buildTimedWorkoutStep('warmup', 600, 'Easy roll-out'),
                $this->buildTimedWorkoutStep('steady', max(2_400, $durationInSeconds - 1_200), 'Controlled endurance'),
                $this->buildTimedWorkoutStep('cooldown', 600, 'Easy spin down'),
            ];
        }

        if ($blockDurationWeeks >= 3 && 1 === $weekInBlock) {
            $durationInSeconds += 900;
        }

        return [
            $this->buildTimedWorkoutStep('warmup', 600, 'Easy roll-out'),
            $this->buildTimedWorkoutStep('steady', max(2_400, $durationInSeconds - 1_200), 'Controlled endurance'),
            $this->buildTimedWorkoutStep('cooldown', 600, 'Easy spin down'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildRideCadenceWorkoutSteps(): array
    {
        return [
            $this->buildTimedWorkoutStep('warmup', 600, 'Easy spin'),
            $this->buildRepeatBlockWorkoutStep(
                4,
                [
                    $this->buildTimedWorkoutStep('interval', 60, 'High-cadence lift'),
                    $this->buildTimedWorkoutStep('recovery', 120, 'Easy roll'),
                ],
            ),
            $this->buildTimedWorkoutStep('cooldown', 600, 'Easy finish'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildEasyEnduranceWorkoutSteps(ActivityType $activityType, int $durationInSeconds, string $mainLabel): array
    {
        $warmup = ActivityType::WATER_SPORTS === $activityType ? 300 : 480;
        $cooldown = ActivityType::WATER_SPORTS === $activityType ? 240 : 300;

        return [
            $this->buildTimedWorkoutStep('warmup', $warmup, 'Easy settle'),
            $this->buildTimedWorkoutStep('steady', max(300, $durationInSeconds - $warmup - $cooldown), $mainLabel),
            $this->buildTimedWorkoutStep('cooldown', $cooldown, 'Easy finish'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTimedWorkoutStep(string $type, int $durationInSeconds, ?string $label = null): array
    {
        $step = [
            'type' => $type,
            'targetType' => 'time',
            'durationInSeconds' => $durationInSeconds,
        ];

        if (null !== $label && '' !== trim($label)) {
            $step['label'] = trim($label);
        }

        return $step;
    }

    /**
     * @param list<array<string, mixed>> $steps
     *
     * @return array<string, mixed>
     */
    private function buildRepeatBlockWorkoutStep(int $repetitions, array $steps): array
    {
        return [
            'type' => 'repeatBlock',
            'repetitions' => max(1, $repetitions),
            'steps' => $steps,
        ];
    }

    /**
     * @param list<array<string, mixed>> $workoutSteps
     */
    private function calculateWorkoutDuration(array $workoutSteps): int
    {
        $durationInSeconds = 0;

        foreach ($workoutSteps as $step) {
            $type = (string) ($step['type'] ?? 'steady');

            if ('repeatBlock' === $type) {
                /** @var list<array<string, mixed>> $childSteps */
                $childSteps = is_array($step['steps'] ?? null) ? $step['steps'] : [];
                $durationInSeconds += max(1, (int) ($step['repetitions'] ?? 1)) * $this->calculateWorkoutDuration($childSteps);
                continue;
            }

            $durationInSeconds += (int) ($step['durationInSeconds'] ?? 0);
        }

        return $durationInSeconds;
    }

    private function resolveSessionCount(
        RaceProfileTrainingRules $rules,
        TrainingBlockPhase $phase,
        float $loadMultiplier,
        bool $isCycleRecoveryWeek = false,
        ?TrainingPlan $linkedTrainingPlan = null,
        ?AdaptivePlanningContext $adaptivePlanningContext = null,
    ): int
    {
        $ideal = $rules->getSessionsPerWeekIdeal();

        if ($this->isDevelopmentTrainingPlan($linkedTrainingPlan)) {
            if ($isCycleRecoveryWeek) {
                $count = max($rules->getSessionsPerWeekMinimum(), (int) round($ideal * 0.75));

                return $this->applySessionCountPreferenceAdjustments($count, $rules, $linkedTrainingPlan, $adaptivePlanningContext);
            }

            $count = match ($phase) {
                TrainingBlockPhase::BASE => min($rules->getSessionsPerWeekMaximum(), $ideal),
                TrainingBlockPhase::BUILD, TrainingBlockPhase::PEAK => min($rules->getSessionsPerWeekMaximum(), $ideal + 1),
                TrainingBlockPhase::TAPER => max($rules->getSessionsPerWeekMinimum(), $ideal),
                TrainingBlockPhase::RECOVERY => max(2, (int) round($ideal * 0.6)),
            };

            return $this->applySessionCountPreferenceAdjustments($count, $rules, $linkedTrainingPlan, $adaptivePlanningContext);
        }

        if ($isCycleRecoveryWeek) {
            $count = max($rules->getSessionsPerWeekMinimum(), (int) round($ideal * 0.65));

            return $this->applySessionCountPreferenceAdjustments($count, $rules, $linkedTrainingPlan, $adaptivePlanningContext);
        }

        $count = match ($phase) {
            TrainingBlockPhase::BASE => max($rules->getSessionsPerWeekMinimum(), (int) round($ideal * 0.8)),
            TrainingBlockPhase::BUILD => $ideal,
            TrainingBlockPhase::PEAK => $ideal,
            TrainingBlockPhase::TAPER => max($rules->getSessionsPerWeekMinimum(), (int) round($ideal * 0.6)),
            TrainingBlockPhase::RECOVERY => max(2, min($rules->getSessionsPerWeekMinimum(), (int) round($ideal * 0.4))),
        };

        return $this->applySessionCountPreferenceAdjustments($count, $rules, $linkedTrainingPlan, $adaptivePlanningContext);
    }

    private function resolveHardSessionCount(
        RaceProfileTrainingRules $rules,
        TrainingBlockPhase $phase,
        bool $isCycleRecoveryWeek = false,
        ?TrainingPlan $linkedTrainingPlan = null,
    ): int
    {
        if ($isCycleRecoveryWeek) {
            return 1;
        }

        if ($this->isSpeedEnduranceTrainingPlan($linkedTrainingPlan)) {
            return match ($phase) {
                TrainingBlockPhase::BASE => max(1, $rules->getHardSessionsPerWeek()),
                TrainingBlockPhase::BUILD, TrainingBlockPhase::PEAK => min(3, $rules->getHardSessionsPerWeek() + 1),
                TrainingBlockPhase::TAPER => 1,
                TrainingBlockPhase::RECOVERY => 0,
            };
        }

        return match ($phase) {
            TrainingBlockPhase::BASE => max(1, $rules->getHardSessionsPerWeek() - 1),
            TrainingBlockPhase::BUILD => $rules->getHardSessionsPerWeek(),
            TrainingBlockPhase::PEAK => $rules->getHardSessionsPerWeek(),
            TrainingBlockPhase::TAPER => 1,
            TrainingBlockPhase::RECOVERY => 0,
        };
    }

    /**
     * @return list<ActivityType>
     */
    private function resolveDisciplineDistribution(RaceProfileTrainingRules $rules, int $sessionCount, ?TrainingPlan $linkedTrainingPlan = null): array
    {
        $needed = $this->resolveNeededDisciplines($rules, $linkedTrainingPlan);

        if ([] === $needed) {
            return array_fill(0, $sessionCount, ActivityType::RUN);
        }

        if (1 === count($needed)) {
            return array_fill(0, $sessionCount, $needed[0]);
        }

        $countsByDiscipline = $this->resolveMinimumDisciplineCounts($needed, $sessionCount, $rules, $linkedTrainingPlan);
        $assignedSessions = array_sum($countsByDiscipline);

        while ($assignedSessions > $sessionCount) {
            usort($needed, function (ActivityType $left, ActivityType $right) use ($countsByDiscipline, $linkedTrainingPlan): int {
                $countComparison = ($countsByDiscipline[$right->value] ?? 0) <=> ($countsByDiscipline[$left->value] ?? 0);
                if (0 !== $countComparison) {
                    return $countComparison;
                }

                return $this->resolveDisciplineWeight($right, $linkedTrainingPlan) <=> $this->resolveDisciplineWeight($left, $linkedTrainingPlan);
            });

            foreach ($needed as $type) {
                if ($assignedSessions <= $sessionCount) {
                    break;
                }

                if (($countsByDiscipline[$type->value] ?? 0) <= 0) {
                    continue;
                }

                --$countsByDiscipline[$type->value];
                --$assignedSessions;
            }
        }

        $remaining = $sessionCount - $assignedSessions;

        while ($remaining > 0) {
            usort($needed, function (ActivityType $left, ActivityType $right) use ($linkedTrainingPlan, $countsByDiscipline): int {
                $leftScore = $this->resolveDisciplineWeight($left, $linkedTrainingPlan) / max(1, ($countsByDiscipline[$left->value] ?? 0) + 1);
                $rightScore = $this->resolveDisciplineWeight($right, $linkedTrainingPlan) / max(1, ($countsByDiscipline[$right->value] ?? 0) + 1);
                $scoreComparison = $rightScore <=> $leftScore;
                if (0 !== $scoreComparison) {
                    return $scoreComparison;
                }

                return ($countsByDiscipline[$left->value] ?? 0) <=> ($countsByDiscipline[$right->value] ?? 0);
            });

            $selectedDiscipline = $needed[0] ?? null;
            if (!$selectedDiscipline instanceof ActivityType) {
                break;
            }

            $countsByDiscipline[$selectedDiscipline->value] = ($countsByDiscipline[$selectedDiscipline->value] ?? 0) + 1;
            --$remaining;
        }

        $disciplines = [];
        $hasRemainingSlots = true;

        while ($hasRemainingSlots) {
            $hasRemainingSlots = false;

            foreach ($needed as $type) {
                $count = $countsByDiscipline[$type->value] ?? 0;
                if ($count <= 0) {
                    continue;
                }

                $disciplines[] = $type;
                $countsByDiscipline[$type->value] = $count - 1;
                $hasRemainingSlots = true;
            }
        }

        return $disciplines;
    }

    /**
     * @param list<ActivityType> $needed
     *
     * @return array<string, int>
     */
    private function resolveMinimumDisciplineCounts(
        array $needed,
        int $sessionCount,
        RaceProfileTrainingRules $rules,
        ?TrainingPlan $linkedTrainingPlan = null,
    ): array {
        $countsByDiscipline = [];

        foreach ($needed as $type) {
            $countsByDiscipline[$type->value] = $sessionCount >= count($needed) ? 1 : 0;
        }

        $isTriathlonDistribution = $rules->needsSwimSessions() && $rules->needsBikeSessions() && $rules->needsRunSessions();

        if ($isTriathlonDistribution && $sessionCount >= 7) {
            $countsByDiscipline[ActivityType::WATER_SPORTS->value] = max($countsByDiscipline[ActivityType::WATER_SPORTS->value] ?? 0, 2);
            $countsByDiscipline[ActivityType::RIDE->value] = max($countsByDiscipline[ActivityType::RIDE->value] ?? 0, 2);
            $countsByDiscipline[ActivityType::RUN->value] = max($countsByDiscipline[ActivityType::RUN->value] ?? 0, 2);
        }

        if ($this->isSpeedEnduranceTrainingPlan($linkedTrainingPlan) && $isTriathlonDistribution) {
            $countsByDiscipline[ActivityType::WATER_SPORTS->value] = max($countsByDiscipline[ActivityType::WATER_SPORTS->value] ?? 0, 1);
            $countsByDiscipline[ActivityType::RIDE->value] = max($countsByDiscipline[ActivityType::RIDE->value] ?? 0, $sessionCount >= 6 ? 2 : 1);
            $countsByDiscipline[ActivityType::RUN->value] = max($countsByDiscipline[ActivityType::RUN->value] ?? 0, $sessionCount >= 7 ? 3 : 2);
        }

        $focusActivityType = $this->resolveFocusActivityType($linkedTrainingPlan?->getTrainingFocus());
        if ($focusActivityType instanceof ActivityType && isset($countsByDiscipline[$focusActivityType->value]) && $sessionCount >= 8) {
            ++$countsByDiscipline[$focusActivityType->value];
        }

        return $countsByDiscipline;
    }

    /**
     * @param list<ProposedSession> $existingSessions
     */
    private function resolveSessionDay(
        SerializableDateTime $weekStart,
        int $preferredSlotIndex,
        array $existingSessions,
        ActivityType $activityType,
        bool $isLongSession = false,
        ?TrainingPlan $linkedTrainingPlan = null,
        bool $isKeySession = false,
    ): ?SerializableDateTime {
        $usedDaysByActivity = [];
        $totalSessionsByDay = [];

        foreach ($existingSessions as $session) {
            $usedDaysByActivity[$session->getDay()->format('Y-m-d')][$session->getActivityType()->value] = true;
            $totalSessionsByDay[$session->getDay()->format('Y-m-d')] = ($totalSessionsByDay[$session->getDay()->format('Y-m-d')] ?? 0) + 1;
        }

        $defaultDayOffsets = $this->resolveDefaultDayOffsets($activityType, $isLongSession, $isKeySession);
        $preferredOffsets = $this->prioritizePreferredOffsets(
            $this->resolvePreferredDayOffsets($weekStart, $activityType, $isLongSession, $linkedTrainingPlan),
            $activityType,
            $isLongSession,
            $isKeySession,
        );
        $candidateOffsets = array_values(array_unique(array_merge(
            $preferredOffsets,
            array_slice($defaultDayOffsets, $preferredSlotIndex),
            array_slice($defaultDayOffsets, 0, $preferredSlotIndex),
        )));
        $preferredOffsetLookup = array_fill_keys($preferredOffsets, true);

        $availableCandidates = [];

        foreach ($candidateOffsets as $priority => $offset) {
            $candidate = SerializableDateTime::fromDateTimeImmutable($weekStart->modify(sprintf('+%d days', $offset)));
            $candidateKey = $candidate->format('Y-m-d');

            if (isset($usedDaysByActivity[$candidateKey][$activityType->value])) {
                continue;
            }

            $availableCandidates[] = [
                'priority' => $priority,
                'isPreferred' => isset($preferredOffsetLookup[$offset]),
                'sessionCount' => $totalSessionsByDay[$candidateKey] ?? 0,
                'candidate' => $candidate,
            ];
        }

        if ([] !== $availableCandidates) {
            usort($availableCandidates, static function (array $left, array $right): int {
                $preferredComparison = ($right['isPreferred'] ? 1 : 0) <=> ($left['isPreferred'] ? 1 : 0);
                if (0 !== $preferredComparison) {
                    return $preferredComparison;
                }

                $sessionCountComparison = $left['sessionCount'] <=> $right['sessionCount'];
                if (0 !== $sessionCountComparison) {
                    return $sessionCountComparison;
                }

                return $left['priority'] <=> $right['priority'];
            });

            return $availableCandidates[0]['candidate'];
        }

        foreach ($candidateOffsets as $offset) {
            return SerializableDateTime::fromDateTimeImmutable($weekStart->modify(sprintf('+%d days', $offset)));
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private function resolveDefaultDayOffsets(ActivityType $activityType, bool $isLongSession, bool $isKeySession): array
    {
        if ($isLongSession) {
            return match ($activityType) {
                ActivityType::RIDE => [5, 6, 4, 3, 1, 2, 0],
                ActivityType::RUN => [6, 5, 4, 3, 1, 2, 0],
                default => [5, 6, 4, 3, 1, 2, 0],
            };
        }

        if ($isKeySession) {
            return [1, 3, 4, 2, 5, 0, 6];
        }

        return [1, 3, 5, 0, 2, 4, 6];
    }

    /**
     * @param list<int> $offsets
     *
     * @return list<int>
     */
    private function prioritizePreferredOffsets(array $offsets, ActivityType $activityType, bool $isLongSession, bool $isKeySession): array
    {
        if ([] === $offsets) {
            return [];
        }

        $ranking = array_flip($this->resolveDefaultDayOffsets($activityType, $isLongSession, $isKeySession));

        usort($offsets, static function (int $left, int $right) use ($ranking): int {
            return ($ranking[$left] ?? PHP_INT_MAX) <=> ($ranking[$right] ?? PHP_INT_MAX);
        });

        return array_values(array_unique($offsets));
    }

    /**
     * @param list<array<string, mixed>> $workoutSteps
     *
     * @return list<array<string, mixed>>
     */
    private function resolveStructuredWorkoutSteps(
        ActivityType $activityType,
        TrainingBlockPhase $phase,
        PlannedSessionIntensity $intensity,
        bool $isLongSession,
        ?int $targetDurationInSeconds,
        array $workoutSteps,
        ?string $sessionTitle = null,
        int $weekInBlock = 1,
        int $blockDurationWeeks = 1,
        ?TrainingPlan $linkedTrainingPlan = null,
    ): array {
        if ([] === $workoutSteps) {
            $workoutSteps = $this->buildDefaultStructuredWorkoutSteps(
                activityType: $activityType,
                phase: $phase,
                intensity: $intensity,
                isLongSession: $isLongSession,
                targetDurationInSeconds: $targetDurationInSeconds,
                sessionTitle: $sessionTitle,
                weekInBlock: $weekInBlock,
                blockDurationWeeks: $blockDurationWeeks,
                linkedTrainingPlan: $linkedTrainingPlan,
            );
        }

        return $this->applyPerformanceTargetsToWorkoutSteps(
            activityType: $activityType,
            intensity: $intensity,
            workoutSteps: $workoutSteps,
            linkedTrainingPlan: $linkedTrainingPlan,
            isLongSession: $isLongSession,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildDefaultStructuredWorkoutSteps(
        ActivityType $activityType,
        TrainingBlockPhase $phase,
        PlannedSessionIntensity $intensity,
        bool $isLongSession,
        ?int $targetDurationInSeconds,
        ?string $sessionTitle = null,
        int $weekInBlock = 1,
        int $blockDurationWeeks = 1,
        ?TrainingPlan $linkedTrainingPlan = null,
    ): array {
        if (null === $targetDurationInSeconds || $targetDurationInSeconds <= 0) {
            return [];
        }

        if (TrainingBlockPhase::RECOVERY === $phase) {
            return $this->buildEasyEnduranceWorkoutSteps($activityType, $targetDurationInSeconds, 'Recovery aerobic');
        }

        if ($isLongSession) {
            return match ($activityType) {
                ActivityType::RIDE => $this->buildVariedLongRideWorkoutSteps(
                    targetDurationInSeconds: $targetDurationInSeconds,
                    phase: $phase,
                    weekInBlock: $weekInBlock,
                    blockDurationWeeks: $blockDurationWeeks,
                    linkedTrainingPlan: $linkedTrainingPlan,
                ),
                ActivityType::RUN => $this->buildVariedLongRunWorkoutSteps(
                    targetDurationInSeconds: $targetDurationInSeconds,
                    phase: $phase,
                    weekInBlock: $weekInBlock,
                    blockDurationWeeks: $blockDurationWeeks,
                    linkedTrainingPlan: $linkedTrainingPlan,
                ),
                default => $this->buildEasyEnduranceWorkoutSteps(
                    $activityType,
                    $targetDurationInSeconds,
                    match ($activityType) {
                        ActivityType::WATER_SPORTS => 'Sustained aerobic swimming',
                        default => 'Long aerobic work',
                    },
                ),
            };
        }

        if (PlannedSessionIntensity::HARD === $intensity) {
            return $this->buildDefaultKeyWorkoutSteps(
                activityType: $activityType,
                targetDurationInSeconds: $targetDurationInSeconds,
                phase: $phase,
                weekInBlock: $weekInBlock,
                blockDurationWeeks: $blockDurationWeeks,
                sessionTitle: $sessionTitle,
                linkedTrainingPlan: $linkedTrainingPlan,
            );
        }

        if (ActivityType::RUN === $activityType) {
            return $this->buildVariedRunSupportWorkoutSteps(
                targetDurationInSeconds: $targetDurationInSeconds,
                intensity: $intensity,
                weekInBlock: $weekInBlock,
                phase: $phase,
            );
        }

        if (ActivityType::RIDE === $activityType) {
            return $this->buildVariedRideSupportWorkoutSteps(
                targetDurationInSeconds: $targetDurationInSeconds,
                intensity: $intensity,
                weekInBlock: $weekInBlock,
                phase: $phase,
            );
        }

        return $this->buildEasyEnduranceWorkoutSteps(
            $activityType,
            $targetDurationInSeconds,
            PlannedSessionIntensity::MODERATE === $intensity ? 'Steady aerobic build' : 'Easy aerobic support',
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildDefaultKeyWorkoutSteps(
        ActivityType $activityType,
        int $targetDurationInSeconds,
        TrainingBlockPhase $phase,
        int $weekInBlock,
        int $blockDurationWeeks,
        ?string $sessionTitle = null,
        ?TrainingPlan $linkedTrainingPlan = null,
    ): array
    {
        return match ($activityType) {
            ActivityType::RUN => $this->buildVariedRunKeyWorkoutSteps(
                targetDurationInSeconds: $targetDurationInSeconds,
                phase: $phase,
                weekInBlock: $weekInBlock,
                blockDurationWeeks: $blockDurationWeeks,
                linkedTrainingPlan: $linkedTrainingPlan,
            ),
            ActivityType::RIDE => $this->buildVariedRideKeyWorkoutSteps(
                targetDurationInSeconds: $targetDurationInSeconds,
                phase: $phase,
                weekInBlock: $weekInBlock,
                blockDurationWeeks: $blockDurationWeeks,
                sessionTitle: $sessionTitle,
                linkedTrainingPlan: $linkedTrainingPlan,
            ),
            default => $this->buildGenericKeyWorkoutSteps($activityType, $targetDurationInSeconds, $linkedTrainingPlan),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildGenericKeyWorkoutSteps(ActivityType $activityType, int $targetDurationInSeconds, ?TrainingPlan $linkedTrainingPlan = null): array
    {
        $isSpeedEndurance = $this->isSpeedEnduranceTrainingPlan($linkedTrainingPlan);
        $warmup = match ($activityType) {
            ActivityType::WATER_SPORTS => 600,
            ActivityType::RIDE => 900,
            ActivityType::RUN => 720,
            default => 600,
        };
        $cooldown = match ($activityType) {
            ActivityType::WATER_SPORTS => 300,
            ActivityType::RIDE => 600,
            ActivityType::RUN => 480,
            default => 300,
        };
        $repeatCount = match ($activityType) {
            ActivityType::WATER_SPORTS => $isSpeedEndurance ? 5 : 4,
            ActivityType::RIDE => $isSpeedEndurance ? 5 : 4,
            ActivityType::RUN => $isSpeedEndurance ? 6 : 5,
            default => 4,
        };
        $minimumWorkDuration = match ($activityType) {
            ActivityType::RIDE => $isSpeedEndurance ? 150 : 180,
            ActivityType::WATER_SPORTS => 90,
            default => $isSpeedEndurance ? 50 : 60,
        };
        $minimumRecoveryDuration = match ($activityType) {
            ActivityType::RIDE => $isSpeedEndurance ? 90 : 120,
            ActivityType::WATER_SPORTS => 60,
            default => $isSpeedEndurance ? 50 : 60,
        };
        $mainDurationInSeconds = max(
            $repeatCount * ($minimumWorkDuration + $minimumRecoveryDuration),
            $targetDurationInSeconds - $warmup - $cooldown,
        );
        $workDurationInSeconds = max(
            $minimumWorkDuration,
            (int) floor(($mainDurationInSeconds * 0.6) / $repeatCount),
        );
        $recoveryDurationInSeconds = max(
            $minimumRecoveryDuration,
            (int) floor(($mainDurationInSeconds * 0.4) / $repeatCount),
        );
        $allocatedDurationInSeconds = $warmup + $cooldown + ($repeatCount * ($workDurationInSeconds + $recoveryDurationInSeconds));
        $cooldown += max(0, $targetDurationInSeconds - $allocatedDurationInSeconds);

        return [
            $this->buildTimedWorkoutStep(
                'warmup',
                $warmup,
                match ($activityType) {
                    ActivityType::RIDE => 'Easy spin + prep',
                    ActivityType::RUN => 'Easy jog + drills',
                    ActivityType::WATER_SPORTS => 'Easy build + drills',
                    default => 'Easy build',
                },
            ),
            $this->buildRepeatBlockWorkoutStep(
                $repeatCount,
                [
                    $this->buildTimedWorkoutStep(
                        'interval',
                        $workDurationInSeconds,
                        match ($activityType) {
                            ActivityType::RIDE => 'Main power interval',
                            ActivityType::RUN => 'Threshold interval',
                            ActivityType::WATER_SPORTS => 'Main pace interval',
                            default => 'Main interval',
                        },
                    ),
                    $this->buildTimedWorkoutStep(
                        'recovery',
                        $recoveryDurationInSeconds,
                        match ($activityType) {
                            ActivityType::RIDE => 'Easy spin',
                            ActivityType::RUN => 'Easy jog',
                            ActivityType::WATER_SPORTS => 'Easy reset',
                            default => 'Easy reset',
                        },
                    ),
                ],
            ),
            $this->buildTimedWorkoutStep(
                'cooldown',
                $cooldown,
                match ($activityType) {
                    ActivityType::RIDE => 'Easy spin down',
                    ActivityType::RUN => 'Easy reset',
                    ActivityType::WATER_SPORTS => 'Easy reset',
                    default => 'Easy finish',
                },
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildVariedRunKeyWorkoutSteps(
        int $targetDurationInSeconds,
        TrainingBlockPhase $phase,
        int $weekInBlock,
        int $blockDurationWeeks,
        ?TrainingPlan $linkedTrainingPlan = null,
    ): array {
        $family = $this->resolveRunKeyWorkoutFamily($phase, $weekInBlock, $linkedTrainingPlan);
        $mode = $this->resolveRunWorkoutTargetModeForFamily($family, $linkedTrainingPlan);

        return match ($family) {
            'hill_reps' => [
                $this->buildTimedWorkoutStep('warmup', 900, 'Easy jog + drills'),
                $this->buildRepeatBlockWorkoutStep(
                    $weekInBlock >= 3 || $blockDurationWeeks <= 2 ? 8 : 6,
                    [
                        $this->buildHillRunWorkoutStep('interval', 75, 'Hill rep', 'Hill effort'),
                        $this->buildHillRunWorkoutStep('recovery', 120, 'Jog back down', 'Easy jog'),
                    ],
                ),
                $this->buildTimedWorkoutStep('cooldown', max(360, $targetDurationInSeconds - (($weekInBlock >= 3 || $blockDurationWeeks <= 2 ? 8 : 6) * 195) - 900), 'Easy reset'),
            ],
            'threshold_blocks' => [
                $this->buildTimedWorkoutStep('warmup', 720, 'Easy jog + drills'),
                $this->buildRepeatBlockWorkoutStep(
                    $weekInBlock >= 3 ? 4 : 3,
                    [
                        $this->buildRunWorkoutStep('interval', 480, 2000, 'Threshold block', $mode),
                        $this->buildRunWorkoutStep('recovery', 120, 200, 'Float jog', $mode),
                    ],
                ),
                $this->buildTimedWorkoutStep('cooldown', 480, 'Easy reset'),
            ],
            'cruise_intervals' => [
                $this->buildTimedWorkoutStep('warmup', 720, 'Easy jog + drills'),
                $this->buildRepeatBlockWorkoutStep(
                    $weekInBlock >= 3 || $blockDurationWeeks <= 2 ? 5 : 4,
                    [
                        $this->buildRunWorkoutStep('interval', 240, 1000, 'Cruise rep', $mode),
                        $this->buildRunWorkoutStep('recovery', 90, 200, 'Float jog', $mode),
                    ],
                ),
                $this->buildTimedWorkoutStep('cooldown', 480, 'Easy reset'),
            ],
            'track_reps' => [
                $this->buildTimedWorkoutStep('warmup', 720, 'Easy jog + drills'),
                $this->buildRepeatBlockWorkoutStep(
                    $weekInBlock >= 3 ? 10 : 8,
                    [
                        $this->buildRunWorkoutStep('interval', 60, 400, 'Track float rep', $mode),
                        $this->buildRunWorkoutStep('recovery', 45, 200, 'Track float', $mode),
                    ],
                ),
                $this->buildTimedWorkoutStep('cooldown', 480, 'Easy reset'),
            ],
            'fartlek' => [
                $this->buildTimedWorkoutStep('warmup', 720, 'Easy jog + drills'),
                $this->buildRepeatBlockWorkoutStep(
                    $weekInBlock >= 3 ? 8 : 6,
                    [
                        $this->buildTimedWorkoutStep('interval', 120, 'On segment'),
                        $this->buildTimedWorkoutStep('recovery', 60, 'Float jog'),
                    ],
                ),
                $this->buildTimedWorkoutStep('steady', max(300, $targetDurationInSeconds - 2_640), 'Steady aerobic settle'),
                $this->buildTimedWorkoutStep('cooldown', 360, 'Easy reset'),
            ],
            'over_unders' => [
                $this->buildTimedWorkoutStep('warmup', 720, 'Easy jog + drills'),
                $this->buildRepeatBlockWorkoutStep(
                    $weekInBlock >= 3 ? 4 : 3,
                    [
                        $this->buildRunWorkoutStep('interval', 180, 800, 'Over segment', $mode),
                        $this->buildRunWorkoutStep('steady', 120, 600, 'Under segment', $mode),
                        $this->buildRunWorkoutStep('recovery', 90, 200, 'Reset jog', $mode),
                    ],
                ),
                $this->buildTimedWorkoutStep('cooldown', 480, 'Easy reset'),
            ],
            default => [
                $this->buildTimedWorkoutStep('warmup', 720, 'Easy jog + drills'),
                $this->buildRunWorkoutStep('interval', 60, 400, 'Pyramid start', $mode),
                $this->buildRunWorkoutStep('recovery', 60, 200, 'Float jog', $mode),
                $this->buildRunWorkoutStep('interval', 120, 800, 'Pyramid build', $mode),
                $this->buildRunWorkoutStep('recovery', 90, 200, 'Float jog', $mode),
                $this->buildRunWorkoutStep('interval', 180, 1200, 'Pyramid peak', $mode),
                $this->buildRunWorkoutStep('recovery', 120, 400, 'Reset jog', $mode),
                $this->buildRunWorkoutStep('interval', 120, 800, 'Pyramid descend', $mode),
                $this->buildRunWorkoutStep('recovery', 90, 200, 'Float jog', $mode),
                $this->buildRunWorkoutStep('interval', 60, 400, 'Pyramid close', $mode),
                $this->buildTimedWorkoutStep('cooldown', 480, 'Easy reset'),
            ],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildVariedRunSupportWorkoutSteps(
        int $targetDurationInSeconds,
        PlannedSessionIntensity $intensity,
        int $weekInBlock,
        TrainingBlockPhase $phase,
    ): array {
        if (TrainingBlockPhase::RECOVERY === $phase) {
            return $this->buildEasyEnduranceWorkoutSteps(ActivityType::RUN, $targetDurationInSeconds, 'Recovery aerobic');
        }

        $familyIndex = ($weekInBlock - 1) % 3;

        if (1 === $familyIndex) {
            $warmup = 420;
            $cooldown = 300;
            $strideBlockDuration = 6 * (20 + 100);

            return [
                $this->buildTimedWorkoutStep('warmup', $warmup, 'Easy settle'),
                $this->buildTimedWorkoutStep('steady', max(600, $targetDurationInSeconds - $warmup - $cooldown - $strideBlockDuration), 'Easy aerobic'),
                $this->buildRepeatBlockWorkoutStep(
                    6,
                    [
                        $this->buildTimedWorkoutStep('interval', 20, 'Smooth stride'),
                        $this->buildTimedWorkoutStep('recovery', 100, 'Walk / easy jog'),
                    ],
                ),
                $this->buildTimedWorkoutStep('cooldown', $cooldown, 'Easy reset'),
            ];
        }

        if (2 === $familyIndex) {
            $warmup = 420;
            $cooldown = 300;
            $cadenceBlockDuration = 6 * (30 + 90);

            return [
                $this->buildTimedWorkoutStep('warmup', $warmup, 'Easy settle'),
                $this->buildTimedWorkoutStep('steady', max(600, $targetDurationInSeconds - $warmup - $cooldown - $cadenceBlockDuration), PlannedSessionIntensity::MODERATE === $intensity ? 'Steady aerobic build' : 'Easy aerobic'),
                $this->buildRepeatBlockWorkoutStep(
                    6,
                    [
                        $this->buildTimedWorkoutStep('interval', 30, 'Cadence lift'),
                        $this->buildTimedWorkoutStep('recovery', 90, 'Easy jog'),
                    ],
                ),
                $this->buildTimedWorkoutStep('cooldown', $cooldown, 'Easy reset'),
            ];
        }

        return $this->buildEasyEnduranceWorkoutSteps(
            ActivityType::RUN,
            $targetDurationInSeconds,
            PlannedSessionIntensity::MODERATE === $intensity ? 'Steady aerobic build' : 'Easy aerobic support',
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildVariedRideSupportWorkoutSteps(
        int $targetDurationInSeconds,
        PlannedSessionIntensity $intensity,
        int $weekInBlock,
        TrainingBlockPhase $phase,
    ): array {
        if (TrainingBlockPhase::RECOVERY === $phase) {
            return $this->buildEasyEnduranceWorkoutSteps(ActivityType::RIDE, $targetDurationInSeconds, 'Recovery aerobic');
        }

        $familyIndex = ($weekInBlock - 1) % 3;

        if (1 === $familyIndex) {
            $warmup = 600;
            $cooldown = 300;
            $spinUpBlockDuration = 6 * (30 + 90);

            return [
                $this->buildTimedWorkoutStep('warmup', $warmup, 'Easy settle'),
                $this->buildTimedWorkoutStep('steady', max(900, $targetDurationInSeconds - $warmup - $cooldown - $spinUpBlockDuration), PlannedSessionIntensity::MODERATE === $intensity ? 'Steady endurance build' : 'Easy aerobic'),
                $this->buildRepeatBlockWorkoutStep(
                    6,
                    [
                        $this->buildTimedWorkoutStep('interval', 30, 'High-cadence spin-up'),
                        $this->buildTimedWorkoutStep('recovery', 90, 'Easy roll'),
                    ],
                ),
                $this->buildTimedWorkoutStep('cooldown', $cooldown, 'Easy reset'),
            ];
        }

        if (2 === $familyIndex) {
            $warmup = 600;
            $cooldown = 300;
            $torqueBlockDuration = 4 * (240 + 120);

            return [
                $this->buildTimedWorkoutStep('warmup', $warmup, 'Easy settle'),
                $this->buildTimedWorkoutStep('steady', max(900, $targetDurationInSeconds - $warmup - $cooldown - $torqueBlockDuration), PlannedSessionIntensity::MODERATE === $intensity ? 'Steady endurance build' : 'Easy aerobic'),
                $this->buildRepeatBlockWorkoutStep(
                    4,
                    [
                        $this->buildTimedWorkoutStep('steady', 240, 'Seated torque block'),
                        $this->buildTimedWorkoutStep('recovery', 120, 'Easy spin'),
                    ],
                ),
                $this->buildTimedWorkoutStep('cooldown', $cooldown, 'Easy reset'),
            ];
        }

        return $this->buildEasyEnduranceWorkoutSteps(
            ActivityType::RIDE,
            $targetDurationInSeconds,
            PlannedSessionIntensity::MODERATE === $intensity ? 'Steady aerobic build' : 'Easy aerobic support',
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildVariedRideKeyWorkoutSteps(
        int $targetDurationInSeconds,
        TrainingBlockPhase $phase,
        int $weekInBlock,
        int $blockDurationWeeks,
        ?string $sessionTitle = null,
        ?TrainingPlan $linkedTrainingPlan = null,
    ): array {
        $family = $this->resolveRideKeyWorkoutFamily($phase, $weekInBlock, $blockDurationWeeks, $linkedTrainingPlan);

        return match ($family) {
            'microbursts' => [
                $this->buildTimedWorkoutStep('warmup', 900, 'Easy spin + cadence lift'),
                $this->buildRepeatBlockWorkoutStep(
                    10,
                    [
                        $this->buildTimedWorkoutStep('interval', 30, '30s on'),
                        $this->buildTimedWorkoutStep('recovery', 30, '30s off'),
                    ],
                ),
                $this->buildTimedWorkoutStep('steady', 300, 'Easy roll between sets'),
                $this->buildRepeatBlockWorkoutStep(
                    10,
                    [
                        $this->buildTimedWorkoutStep('interval', 30, '30s on'),
                        $this->buildTimedWorkoutStep('recovery', 30, '30s off'),
                    ],
                ),
                $this->buildTimedWorkoutStep('steady', max(300, $targetDurationInSeconds - 3_300), 'Settle into endurance'),
                $this->buildTimedWorkoutStep('cooldown', 600, 'Easy spin down'),
            ],
            'threshold_ladder' => [
                $this->buildTimedWorkoutStep('warmup', 900, 'Easy spin + prep'),
                $this->buildTimedWorkoutStep('interval', 480, 'Threshold ladder 1'),
                $this->buildTimedWorkoutStep('recovery', 180, 'Easy spin'),
                $this->buildTimedWorkoutStep('interval', 600, 'Threshold ladder 2'),
                $this->buildTimedWorkoutStep('recovery', 240, 'Easy spin'),
                $this->buildTimedWorkoutStep('interval', 720, 'Threshold ladder 3'),
                $this->buildTimedWorkoutStep('steady', max(300, $targetDurationInSeconds - 4_320), 'Steady endurance settle'),
                $this->buildTimedWorkoutStep('cooldown', 600, 'Easy spin down'),
            ],
            'sweet_spot_blocks' => [
                $this->buildTimedWorkoutStep('warmup', 900, 'Easy spin + prep'),
                $this->buildRepeatBlockWorkoutStep(
                    $weekInBlock >= 3 ? 3 : 2,
                    [
                        $this->buildTimedWorkoutStep('steady', 600, 'Sweet spot block'),
                        $this->buildTimedWorkoutStep('recovery', 180, 'Easy spin'),
                    ],
                ),
                $this->buildTimedWorkoutStep('steady', max(300, $targetDurationInSeconds - (($weekInBlock >= 3 ? 3 : 2) * 780) - 1_500), 'Steady endurance settle'),
                $this->buildTimedWorkoutStep('cooldown', 600, 'Easy spin down'),
            ],
            'vo2_blocks' => [
                $this->buildTimedWorkoutStep('warmup', 900, 'Easy spin + prep'),
                $this->buildRepeatBlockWorkoutStep(
                    $weekInBlock >= 3 ? 6 : 5,
                    [
                        $this->buildTimedWorkoutStep('interval', 180, 'VO₂ rep'),
                        $this->buildTimedWorkoutStep('recovery', 180, 'Easy spin'),
                    ],
                ),
                $this->buildTimedWorkoutStep('steady', max(300, $targetDurationInSeconds - 3_660), 'Steady endurance settle'),
                $this->buildTimedWorkoutStep('cooldown', 600, 'Easy spin down'),
            ],
            'late_session_intervals' => [
                $this->buildTimedWorkoutStep('warmup', 900, 'Easy spin + prep'),
                $this->buildTimedWorkoutStep('steady', max(900, $targetDurationInSeconds - 3_720), 'Endurance settle before the work'),
                $this->buildRepeatBlockWorkoutStep(
                    3,
                    [
                        $this->buildTimedWorkoutStep('interval', 360, 'Late-session interval'),
                        $this->buildTimedWorkoutStep('recovery', 180, 'Easy spin'),
                    ],
                ),
                $this->buildTimedWorkoutStep('cooldown', 600, 'Easy spin down'),
            ],
            default => [
                $this->buildTimedWorkoutStep('warmup', 900, 'Easy spin + prep'),
                $this->buildRepeatBlockWorkoutStep(
                    $weekInBlock >= 3 ? 4 : 3,
                    [
                        $this->buildTimedWorkoutStep('interval', 180, 'Over segment'),
                        $this->buildTimedWorkoutStep('steady', 180, 'Under segment'),
                        $this->buildTimedWorkoutStep('recovery', 180, 'Easy spin'),
                    ],
                ),
                $this->buildTimedWorkoutStep('steady', max(300, $targetDurationInSeconds - 3_120), 'Steady endurance settle'),
                $this->buildTimedWorkoutStep('cooldown', 600, 'Easy spin down'),
            ],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildVariedLongRunWorkoutSteps(
        int $targetDurationInSeconds,
        TrainingBlockPhase $phase,
        int $weekInBlock,
        int $blockDurationWeeks,
        ?TrainingPlan $linkedTrainingPlan = null,
    ): array {
        if (!in_array($phase, [TrainingBlockPhase::BUILD, TrainingBlockPhase::PEAK], true)) {
            return $this->buildEasyEnduranceWorkoutSteps(ActivityType::RUN, $targetDurationInSeconds, 'Long aerobic endurance');
        }

        if (0 === $weekInBlock % 2) {
            return [
                $this->buildTimedWorkoutStep('warmup', 600, 'Easy start'),
                $this->buildTimedWorkoutStep('steady', max(1_800, $targetDurationInSeconds - 2_100), 'Aerobic settle'),
                $this->buildTimedWorkoutStep('steady', 900, 'Progress to steady finish'),
                $this->buildTimedWorkoutStep('cooldown', 600, 'Easy reset'),
            ];
        }

        if ($blockDurationWeeks >= 3 && $weekInBlock === $blockDurationWeeks) {
            $mode = $this->resolvePreferredRunningWorkoutTargetMode($linkedTrainingPlan);

            return [
                $this->buildTimedWorkoutStep('warmup', 600, 'Easy start'),
                $this->buildTimedWorkoutStep('steady', max(1_200, $targetDurationInSeconds - 3_300), 'Aerobic settle'),
                $this->buildRepeatBlockWorkoutStep(
                    2,
                    [
                        $this->buildRunWorkoutStep('steady', 600, 2000, 'Steady block', $mode),
                        $this->buildRunWorkoutStep('recovery', 300, 400, 'Float jog', $mode),
                    ],
                ),
                $this->buildTimedWorkoutStep('cooldown', 300, 'Easy reset'),
            ];
        }

        return $this->buildEasyEnduranceWorkoutSteps(ActivityType::RUN, $targetDurationInSeconds, 'Long aerobic endurance');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildVariedLongRideWorkoutSteps(
        int $targetDurationInSeconds,
        TrainingBlockPhase $phase,
        int $weekInBlock,
        int $blockDurationWeeks,
        ?TrainingPlan $linkedTrainingPlan = null,
    ): array {
        if (!in_array($phase, [TrainingBlockPhase::BUILD, TrainingBlockPhase::PEAK], true)) {
            return $this->buildEasyEnduranceWorkoutSteps(ActivityType::RIDE, $targetDurationInSeconds, 'Long aerobic ride');
        }

        if (0 === $weekInBlock % 2) {
            return [
                $this->buildTimedWorkoutStep('warmup', 900, 'Easy roll-out'),
                $this->buildTimedWorkoutStep('steady', max(2_400, $targetDurationInSeconds - 4_140), 'Endurance cruise'),
                $this->buildRepeatBlockWorkoutStep(
                    3,
                    [
                        $this->buildTimedWorkoutStep('steady', 480, 'Late-session surge'),
                        $this->buildTimedWorkoutStep('recovery', 240, 'Easy reset'),
                    ],
                ),
                $this->buildTimedWorkoutStep('cooldown', 600, 'Easy spin down'),
            ];
        }

        if ($blockDurationWeeks >= 4 && 3 === $weekInBlock) {
            return [
                $this->buildTimedWorkoutStep('warmup', 900, 'Easy roll-out'),
                $this->buildTimedWorkoutStep('steady', max(2_400, $targetDurationInSeconds - 4_740), 'Endurance cruise'),
                $this->buildRepeatBlockWorkoutStep(
                    2,
                    [
                        $this->buildTimedWorkoutStep('steady', 720, 'Sweet-spot insert'),
                        $this->buildTimedWorkoutStep('recovery', 360, 'Easy reset'),
                    ],
                ),
                $this->buildTimedWorkoutStep('cooldown', 600, 'Easy spin down'),
            ];
        }

        if ($blockDurationWeeks >= 3 && $weekInBlock === $blockDurationWeeks) {
            return [
                $this->buildTimedWorkoutStep('warmup', 900, 'Easy roll-out'),
                $this->buildTimedWorkoutStep('steady', max(2_400, $targetDurationInSeconds - 5_040), 'Endurance cruise'),
                $this->buildRepeatBlockWorkoutStep(
                    3,
                    [
                        $this->buildTimedWorkoutStep('steady', 720, 'Long tempo block'),
                        $this->buildTimedWorkoutStep('recovery', 360, 'Easy reset'),
                    ],
                ),
                $this->buildTimedWorkoutStep('cooldown', 600, 'Easy spin down'),
            ];
        }

        return $this->buildEasyEnduranceWorkoutSteps(ActivityType::RIDE, $targetDurationInSeconds, 'Long aerobic ride');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRunWorkoutStep(
        string $type,
        int $durationInSeconds,
        int $distanceInMeters,
        string $label,
        RunningWorkoutTargetMode $mode,
    ): array {
        if (RunningWorkoutTargetMode::DISTANCE === $mode) {
            return [
                'type' => $type,
                'targetType' => 'distance',
                'distanceInMeters' => $distanceInMeters,
                'label' => trim($label),
            ];
        }

        return $this->buildTimedWorkoutStep($type, $durationInSeconds, $label);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildHillRunWorkoutStep(
        string $type,
        int $durationInSeconds,
        string $label,
        string $targetPace,
    ): array {
        return [
            'type' => $type,
            'targetType' => 'time',
            'durationInSeconds' => $durationInSeconds,
            'label' => trim($label),
            'targetPace' => trim($targetPace),
        ];
    }

    private function resolvePreferredRunningWorkoutTargetMode(?TrainingPlan $linkedTrainingPlan = null): RunningWorkoutTargetMode
    {
        return $linkedTrainingPlan?->getRunningWorkoutTargetMode() ?? RunningWorkoutTargetMode::TIME;
    }

    private function resolveRunWorkoutTargetModeForFamily(string $family, ?TrainingPlan $linkedTrainingPlan = null): RunningWorkoutTargetMode
    {
        return match ($family) {
            'cruise_intervals' => RunningWorkoutTargetMode::DISTANCE,
            'track_reps' => RunningWorkoutTargetMode::DISTANCE,
            'fartlek' => RunningWorkoutTargetMode::TIME,
            'hill_reps' => RunningWorkoutTargetMode::TIME,
            default => $this->resolvePreferredRunningWorkoutTargetMode($linkedTrainingPlan),
        };
    }

    private function resolveRunKeyWorkoutFamily(
        TrainingBlockPhase $phase,
        int $weekInBlock,
        ?TrainingPlan $linkedTrainingPlan = null,
    ): string {
        $families = $this->isSpeedEnduranceTrainingPlan($linkedTrainingPlan)
            ? ['over_unders', 'track_reps', 'pyramid', 'fartlek', 'cruise_intervals', 'threshold_blocks']
            : ['pyramid', 'threshold_blocks', 'cruise_intervals', 'track_reps', 'fartlek', 'over_unders'];

        if ($this->supportsRunHillSessions($linkedTrainingPlan)) {
            array_splice($families, 1, 0, ['hill_reps']);
        }

        $phaseOffset = TrainingBlockPhase::PEAK === $phase ? 1 : 0;

        return $families[($weekInBlock - 1 + $phaseOffset) % count($families)];
    }

    private function supportsRunHillSessions(?TrainingPlan $linkedTrainingPlan = null): bool
    {
        return $linkedTrainingPlan?->isRunHillSessionsEnabled() ?? false;
    }

    private function resolveRideKeyWorkoutFamily(
        TrainingBlockPhase $phase,
        int $weekInBlock,
        int $blockDurationWeeks,
        ?TrainingPlan $linkedTrainingPlan = null,
    ): string {
        $families = $this->isSpeedEnduranceTrainingPlan($linkedTrainingPlan)
            ? ['microbursts', 'over_unders', 'vo2_blocks', 'threshold_ladder', 'sweet_spot_blocks', 'late_session_intervals']
            : ['over_unders', 'microbursts', 'threshold_ladder', 'sweet_spot_blocks', 'vo2_blocks', 'late_session_intervals'];
        $phaseOffset = TrainingBlockPhase::PEAK === $phase ? 1 : 0;

        return $families[($weekInBlock - 1 + $phaseOffset + max(0, $blockDurationWeeks - 2)) % count($families)];
    }

    /**
     * @param list<ProposedSession> $sessions
     *
     * @return list<ProposedSession>
     */
    private function applyPerformanceTargetsToSessions(array $sessions, ?TrainingPlan $linkedTrainingPlan = null): array
    {
        if (null === $linkedTrainingPlan) {
            return $sessions;
        }

        return array_map(function (ProposedSession $session) use ($linkedTrainingPlan): ProposedSession {
            if (!$session->hasWorkoutSteps()) {
                return $session;
            }

            $enrichedWorkoutSteps = $this->applyPerformanceTargetsToWorkoutSteps(
                activityType: $session->getActivityType(),
                intensity: $session->getTargetIntensity(),
                workoutSteps: $session->getWorkoutSteps(),
                linkedTrainingPlan: $linkedTrainingPlan,
                isLongSession: $this->isLongSessionTitle($session->getTitle()),
            );

            if ($enrichedWorkoutSteps === $session->getWorkoutSteps()) {
                return $session;
            }

            return ProposedSession::create(
                day: $session->getDay(),
                activityType: $session->getActivityType(),
                targetIntensity: $session->getTargetIntensity(),
                title: $session->getTitle(),
                notes: $session->getNotes(),
                targetDurationInSeconds: $session->getTargetDurationInSeconds(),
                isKeySession: $session->isKeySession(),
                isBrickSession: $session->isBrickSession(),
                workoutSteps: $enrichedWorkoutSteps,
            );
        }, $sessions);
    }

    /**
     * @param list<array<string, mixed>> $workoutSteps
     *
     * @return list<array<string, mixed>>
     */
    private function applyPerformanceTargetsToWorkoutSteps(
        ActivityType $activityType,
        PlannedSessionIntensity $intensity,
        array $workoutSteps,
        ?TrainingPlan $linkedTrainingPlan = null,
        bool $isLongSession = false,
    ): array {
        $performanceMetrics = $linkedTrainingPlan?->getPerformanceMetrics();
        if ([] === $workoutSteps || !is_array($performanceMetrics) || [] === $performanceMetrics) {
            return $workoutSteps;
        }

        return $this->applyPerformanceTargetsToWorkoutStepsFromMetrics(
            activityType: $activityType,
            intensity: $intensity,
            workoutSteps: $workoutSteps,
            performanceMetrics: $performanceMetrics,
            isLongSession: $isLongSession,
            linkedTrainingPlan: $linkedTrainingPlan,
        );
    }

    /**
     * @param list<array<string, mixed>> $workoutSteps
     * @param array<string, mixed> $performanceMetrics
     *
     * @return list<array<string, mixed>>
     */
    private function applyPerformanceTargetsToWorkoutStepsFromMetrics(
        ActivityType $activityType,
        PlannedSessionIntensity $intensity,
        array $workoutSteps,
        array $performanceMetrics,
        bool $isLongSession = false,
        ?TrainingPlan $linkedTrainingPlan = null,
    ): array {
        return array_map(function (array $workoutStep) use ($activityType, $intensity, $performanceMetrics, $isLongSession, $linkedTrainingPlan): array {
            if ('repeatBlock' === ($workoutStep['type'] ?? null) && is_array($workoutStep['steps'] ?? null)) {
                $workoutStep['steps'] = $this->applyPerformanceTargetsToWorkoutStepsFromMetrics(
                    activityType: $activityType,
                    intensity: $intensity,
                    workoutSteps: $workoutStep['steps'],
                    performanceMetrics: $performanceMetrics,
                    isLongSession: $isLongSession,
                    linkedTrainingPlan: $linkedTrainingPlan,
                );

                return $workoutStep;
            }

            $stepType = (string) ($workoutStep['type'] ?? 'steady');

            if (ActivityType::RIDE === $activityType
                && (!isset($workoutStep['targetPower']) || (int) $workoutStep['targetPower'] <= 0)
                && isset($performanceMetrics['cyclingFtp'])
                && is_numeric($performanceMetrics['cyclingFtp'])) {
                $workoutStep['targetPower'] = $this->resolveRidePowerTarget(
                    ftp: (int) $performanceMetrics['cyclingFtp'],
                    stepType: $stepType,
                    intensity: $intensity,
                    isLongSession: $isLongSession,
                    linkedTrainingPlan: $linkedTrainingPlan,
                );
            }

            if (ActivityType::RUN === $activityType
                && (!isset($workoutStep['targetPace']) || '' === trim((string) $workoutStep['targetPace']))
                && isset($performanceMetrics['runningThresholdPace'])
                && is_numeric($performanceMetrics['runningThresholdPace'])) {
                $workoutStep['targetPace'] = $this->resolveRunPaceTarget(
                    thresholdPaceInSeconds: (int) $performanceMetrics['runningThresholdPace'],
                    stepType: $stepType,
                    intensity: $intensity,
                    isLongSession: $isLongSession,
                    linkedTrainingPlan: $linkedTrainingPlan,
                );
            }

            return $workoutStep;
        }, $workoutSteps);
    }

    private function resolveRidePowerTarget(
        int $ftp,
        string $stepType,
        PlannedSessionIntensity $intensity,
        bool $isLongSession,
        ?TrainingPlan $linkedTrainingPlan = null,
    ): int {
        $factor = match ($stepType) {
            'warmup', 'cooldown' => 0.60,
            'recovery' => 0.55,
            'interval' => match ($intensity) {
                PlannedSessionIntensity::HARD => 1.00,
                PlannedSessionIntensity::MODERATE => 0.88,
                default => 0.72,
            },
            default => $isLongSession
                ? 0.78
                : match ($intensity) {
                    PlannedSessionIntensity::HARD => 0.95,
                    PlannedSessionIntensity::MODERATE => 0.82,
                    default => 0.70,
                },
        };

        if ($this->isSpeedEnduranceTrainingPlan($linkedTrainingPlan)) {
            $factor += match ($stepType) {
                'interval' => PlannedSessionIntensity::HARD === $intensity ? 0.04 : 0.0,
                'steady' => PlannedSessionIntensity::MODERATE === $intensity ? 0.02 : 0.0,
                default => 0.0,
            };
        }

        return (int) (round(($ftp * $factor) / 5) * 5);
    }

    private function resolveRunPaceTarget(
        int $thresholdPaceInSeconds,
        string $stepType,
        PlannedSessionIntensity $intensity,
        bool $isLongSession,
        ?TrainingPlan $linkedTrainingPlan = null,
    ): string {
        $paceOffsetInSeconds = match ($stepType) {
            'warmup', 'cooldown' => 60,
            'recovery' => 75,
            'interval' => match ($intensity) {
                PlannedSessionIntensity::HARD => 0,
                PlannedSessionIntensity::MODERATE => 10,
                default => 25,
            },
            default => $isLongSession
                ? 35
                : match ($intensity) {
                    PlannedSessionIntensity::HARD => 5,
                    PlannedSessionIntensity::MODERATE => 20,
                    default => 45,
                },
        };

        if ($this->isSpeedEnduranceTrainingPlan($linkedTrainingPlan)) {
            $paceOffsetInSeconds += match ($stepType) {
                'interval' => PlannedSessionIntensity::HARD === $intensity ? -10 : 0,
                'steady' => PlannedSessionIntensity::MODERATE === $intensity ? -5 : 0,
                default => 0,
            };
        }

        return sprintf('%s/km', $this->formatPaceValue(max(150, $thresholdPaceInSeconds + $paceOffsetInSeconds)));
    }

    private function isLongSessionTitle(?string $title): bool
    {
        if (null === $title) {
            return false;
        }

        $normalizedTitle = strtolower(trim($title));

        return str_starts_with($normalizedTitle, 'long ')
            || str_contains($normalizedTitle, 'controlled long run')
            || str_contains($normalizedTitle, 'reduced endurance ride');
    }

    private function resolvePrimaryActivityType(RaceEventFamily $family): ActivityType
    {
        return match ($family) {
            RaceEventFamily::TRIATHLON,
            RaceEventFamily::MULTISPORT => ActivityType::RUN,
            RaceEventFamily::RUN => ActivityType::RUN,
            RaceEventFamily::RIDE => ActivityType::RIDE,
            RaceEventFamily::SWIM => ActivityType::WATER_SPORTS,
            RaceEventFamily::OTHER => ActivityType::RUN,
        };
    }

    private function buildKeySessionTitle(ActivityType $activityType, TrainingBlockPhase $phase): string
    {
        $discipline = $this->activityTypeLabel($activityType);

        return match ($phase) {
            TrainingBlockPhase::BASE => sprintf('%s tempo', $discipline),
            TrainingBlockPhase::BUILD => sprintf('%s intervals', $discipline),
            TrainingBlockPhase::PEAK => sprintf('%s race-pace', $discipline),
            TrainingBlockPhase::TAPER => sprintf('%s sharpener', $discipline),
            TrainingBlockPhase::RECOVERY => sprintf('Easy %s', strtolower($discipline)),
        };
    }

    private function buildLongSessionTitle(ActivityType $activityType): string
    {
        return sprintf('Long %s', strtolower($this->activityTypeLabel($activityType)));
    }

    private function buildEasySessionTitle(ActivityType $activityType): string
    {
        return sprintf('Easy %s', strtolower($this->activityTypeLabel($activityType)));
    }

    private function buildRecoverySessionTitle(ActivityType $activityType): string
    {
        return sprintf('Recovery %s', strtolower($this->activityTypeLabel($activityType)));
    }

    private function resolveKeySessionDuration(
        ActivityType $activityType,
        RaceEventProfile $profile,
        TrainingBlockPhase $phase,
        float $loadMultiplier,
        ?TrainingPlan $linkedTrainingPlan = null,
        ?AdaptivePlanningContext $adaptivePlanningContext = null,
    ): int
    {
        $baseDuration = match ($activityType) {
            ActivityType::WATER_SPORTS => 3000,
            ActivityType::RIDE => 4500,
            ActivityType::RUN => 3600,
            default => 3600,
        };

        $phaseMultiplier = match ($phase) {
            TrainingBlockPhase::BASE => 0.85,
            TrainingBlockPhase::BUILD => 1.0,
            TrainingBlockPhase::PEAK => 1.05,
            TrainingBlockPhase::TAPER => 0.7,
            TrainingBlockPhase::RECOVERY => 0.6,
        };

        return (int) round($baseDuration * $phaseMultiplier * $loadMultiplier * $this->resolveProfileDurationMultiplier($profile, $activityType, 'key') * $this->resolvePerformanceDurationMultiplier($activityType, 'key', $linkedTrainingPlan, $adaptivePlanningContext) * $this->resolveTrainingBlockStyleDurationMultiplier($activityType, 'key', $linkedTrainingPlan));
    }

    private function resolveLongSessionDuration(
        ActivityType $activityType,
        RaceEventProfile $profile,
        TrainingBlockPhase $phase,
        float $loadMultiplier,
        ?TrainingPlan $linkedTrainingPlan = null,
        ?AdaptivePlanningContext $adaptivePlanningContext = null,
    ): int
    {
        $baseDuration = match ($activityType) {
            ActivityType::WATER_SPORTS => 3600,
            ActivityType::RIDE => 7200,
            ActivityType::RUN => 5400,
            default => 4500,
        };

        $phaseMultiplier = match ($phase) {
            TrainingBlockPhase::BASE => 0.8,
            TrainingBlockPhase::BUILD => 1.0,
            TrainingBlockPhase::PEAK => 1.1,
            default => 0.7,
        };

        return (int) round($baseDuration * $phaseMultiplier * $loadMultiplier * $this->resolveProfileDurationMultiplier($profile, $activityType, 'long') * $this->resolvePerformanceDurationMultiplier($activityType, 'long', $linkedTrainingPlan, $adaptivePlanningContext) * $this->resolveTrainingBlockStyleDurationMultiplier($activityType, 'long', $linkedTrainingPlan));
    }

    private function resolveEasySessionDuration(
        ActivityType $activityType,
        RaceEventProfile $profile,
        float $loadMultiplier,
        ?TrainingPlan $linkedTrainingPlan = null,
        ?AdaptivePlanningContext $adaptivePlanningContext = null,
    ): int
    {
        $baseDuration = match ($activityType) {
            ActivityType::WATER_SPORTS => 2400,
            ActivityType::RIDE => 3600,
            ActivityType::RUN => 2700,
            default => 2700,
        };

        return (int) round($baseDuration * $loadMultiplier * $this->resolveProfileDurationMultiplier($profile, $activityType, 'easy') * $this->resolvePerformanceDurationMultiplier($activityType, 'easy', $linkedTrainingPlan, $adaptivePlanningContext) * $this->resolveTrainingBlockStyleDurationMultiplier($activityType, 'easy', $linkedTrainingPlan));
    }

    private function resolveRecoverySessionDuration(
        ActivityType $activityType,
        RaceEventProfile $profile,
        float $loadMultiplier,
        ?TrainingPlan $linkedTrainingPlan = null,
        ?AdaptivePlanningContext $adaptivePlanningContext = null,
    ): int
    {
        $baseDuration = match ($activityType) {
            ActivityType::WATER_SPORTS => 1800,
            ActivityType::RIDE => 2700,
            ActivityType::RUN => 1800,
            default => 1800,
        };

        return (int) round($baseDuration * max(0.8, $loadMultiplier) * $this->resolveProfileDurationMultiplier($profile, $activityType, 'recovery') * $this->resolvePerformanceDurationMultiplier($activityType, 'recovery', $linkedTrainingPlan, $adaptivePlanningContext) * $this->resolveTrainingBlockStyleDurationMultiplier($activityType, 'recovery', $linkedTrainingPlan));
    }

    private function resolveProfileDurationMultiplier(RaceEventProfile $profile, ActivityType $activityType, string $sessionType): float
    {
        return match ($profile) {
            RaceEventProfile::HALF_DISTANCE_TRIATHLON => match ($sessionType) {
                'key' => match ($activityType) {
                    ActivityType::WATER_SPORTS => 1.2,
                    ActivityType::RIDE => 1.6,
                    ActivityType::RUN => 1.35,
                    default => 1.0,
                },
                'long' => match ($activityType) {
                    ActivityType::WATER_SPORTS => 1.2,
                    ActivityType::RIDE => 1.85,
                    ActivityType::RUN => 1.35,
                    default => 1.0,
                },
                'easy' => match ($activityType) {
                    ActivityType::WATER_SPORTS => 1.15,
                    ActivityType::RIDE => 1.4,
                    ActivityType::RUN => 1.35,
                    default => 1.0,
                },
                'recovery' => match ($activityType) {
                    ActivityType::RIDE => 1.15,
                    ActivityType::RUN => 1.1,
                    default => 1.0,
                },
                default => 1.0,
            },
            RaceEventProfile::FULL_DISTANCE_TRIATHLON => match ($sessionType) {
                'key' => match ($activityType) {
                    ActivityType::WATER_SPORTS => 1.25,
                    ActivityType::RIDE => 1.85,
                    ActivityType::RUN => 1.4,
                    default => 1.0,
                },
                'long' => match ($activityType) {
                    ActivityType::WATER_SPORTS => 1.25,
                    ActivityType::RIDE => 2.3,
                    ActivityType::RUN => 1.55,
                    default => 1.0,
                },
                'easy' => match ($activityType) {
                    ActivityType::WATER_SPORTS => 1.2,
                    ActivityType::RIDE => 1.55,
                    ActivityType::RUN => 1.4,
                    default => 1.0,
                },
                'recovery' => match ($activityType) {
                    ActivityType::RIDE => 1.2,
                    ActivityType::RUN => 1.1,
                    default => 1.0,
                },
                default => 1.0,
            },
            default => 1.0,
        };
    }

    private function resolveBlockFocusDescription(
        TrainingBlockPhase $phase,
        string $defaultFocus,
        ?TrainingPlan $linkedTrainingPlan = null,
    ): string {
        if ($this->isDevelopmentTrainingPlan($linkedTrainingPlan)) {
            $defaultFocus = $this->isSpeedEnduranceTrainingPlan($linkedTrainingPlan)
                ? match ($phase) {
                    TrainingBlockPhase::BASE => 'Keep the endurance floor in place while reintroducing sharper rhythm and economy work.',
                    TrainingBlockPhase::BUILD => 'Bias the week toward faster repeatable work while protecting one long ride and one longer run.',
                    TrainingBlockPhase::PEAK => 'Sharpen top-end repeatability without drifting into full race-block volume.',
                    TrainingBlockPhase::TAPER => 'Keep the legs snappy while preserving just enough endurance continuity.',
                    TrainingBlockPhase::RECOVERY => 'Absorb the sharper work without letting the endurance floor disappear.',
                }
                : match ($phase) {
                    TrainingBlockPhase::BASE => 'Build durable aerobic support and movement quality for the target distance.',
                    TrainingBlockPhase::BUILD => 'Build event-distance fitness with progressive volume, threshold support, and regular recovery weeks.',
                    TrainingBlockPhase::PEAK => 'Consolidate strong training without tapering yet.',
                    TrainingBlockPhase::TAPER => 'Keep continuity high; save tapering for race-specific blocks.',
                    TrainingBlockPhase::RECOVERY => 'Use easier work to absorb the block and keep momentum.',
                };
        }

        $focus = $linkedTrainingPlan?->getTrainingFocus();
        if (null === $focus) {
            return $defaultFocus;
        }

        return match ($focus) {
            TrainingFocus::RUN => match ($phase) {
                TrainingBlockPhase::BASE => 'Build a durable aerobic base with extra run frequency and economy work',
                TrainingBlockPhase::BUILD => 'Push running durability and threshold while keeping support work in place',
                TrainingBlockPhase::PEAK => 'Sharpen race-ready run fitness without piling on residual fatigue',
                TrainingBlockPhase::TAPER => 'Freshen the legs while keeping run rhythm alive',
                TrainingBlockPhase::RECOVERY => 'Absorb the work and bring the run legs back gradually',
            },
            TrainingFocus::BIKE => match ($phase) {
                TrainingBlockPhase::BASE => 'Build aerobic durability with extra saddle time and smooth force application',
                TrainingBlockPhase::BUILD => 'Lift bike-specific power and repeatability while maintaining balance elsewhere',
                TrainingBlockPhase::PEAK => 'Sharpen bike-specific race readiness and pacing confidence',
                TrainingBlockPhase::TAPER => 'Keep the bike legs sharp while trimming fatigue',
                TrainingBlockPhase::RECOVERY => 'Let bike fatigue fade before reintroducing structure',
            },
            TrainingFocus::SWIM => match ($phase) {
                TrainingBlockPhase::BASE => 'Reinforce swim frequency, feel for the water, and clean technical habits',
                TrainingBlockPhase::BUILD => 'Raise swim-specific speed and sustainable rhythm without losing form',
                TrainingBlockPhase::PEAK => 'Sharpen swim pace judgment and confidence for race day',
                TrainingBlockPhase::TAPER => 'Keep the water feel snappy while protecting freshness',
                TrainingBlockPhase::RECOVERY => 'Use easy water time to restore feel without adding stress',
            },
            TrainingFocus::GENERAL => $defaultFocus,
        };
    }

    private function isDevelopmentTrainingPlan(?TrainingPlan $linkedTrainingPlan = null): bool
    {
        return $linkedTrainingPlan instanceof TrainingPlan
            && TrainingPlanType::TRAINING === $linkedTrainingPlan->getType()
            && null === $linkedTrainingPlan->getTargetRaceEventId();
    }

    private function applySessionCountPreferenceAdjustments(
        int $count,
        RaceProfileTrainingRules $rules,
        ?TrainingPlan $linkedTrainingPlan = null,
        ?AdaptivePlanningContext $adaptivePlanningContext = null,
    ): int {
        $signals = [];

        $runningVolume = $this->resolveEffectiveWeeklyVolume(ActivityType::RUN, $linkedTrainingPlan, $adaptivePlanningContext);
        if ($rules->needsRunSessions() && null !== $runningVolume) {
            $signals[] = match (true) {
                $runningVolume >= 75.0 => 2,
                $runningVolume >= 50.0 => 1,
                $runningVolume > 0.0 && $runningVolume <= 20.0 => -2,
                $runningVolume <= 32.0 => -1,
                default => 0,
            };
        }

        $bikingVolume = $this->resolveEffectiveWeeklyVolume(ActivityType::RIDE, $linkedTrainingPlan, $adaptivePlanningContext);
        if ($rules->needsBikeSessions() && null !== $bikingVolume) {
            $signals[] = match (true) {
                $bikingVolume >= 12.0 => 2,
                $bikingVolume >= 8.0 => 1,
                $bikingVolume > 0.0 && $bikingVolume <= 3.0 => -2,
                $bikingVolume <= 5.0 => -1,
                default => 0,
            };
        }

        $availableTrainingDays = $this->countAvailableTrainingDays($linkedTrainingPlan?->getSportSchedule());
        if ($availableTrainingDays >= 6) {
            $signals[] = 1;
        } elseif ($availableTrainingDays > 0 && $availableTrainingDays <= 3) {
            $signals[] = -1;
        }

        if ([] !== $signals) {
            $count += max(-2, min(2, (int) round(array_sum($signals) / max(1, count($signals) / 1.5))));
        }

        return max($rules->getSessionsPerWeekMinimum(), min($rules->getSessionsPerWeekMaximum(), $count));
    }

    /**
     * @return list<ActivityType>
     */
    private function resolveNeededDisciplines(RaceProfileTrainingRules $rules, ?TrainingPlan $linkedTrainingPlan = null): array
    {
        return match ($linkedTrainingPlan?->getDiscipline()) {
            TrainingPlanDiscipline::RUNNING => [ActivityType::RUN],
            TrainingPlanDiscipline::CYCLING => [ActivityType::RIDE],
            TrainingPlanDiscipline::TRIATHLON => [ActivityType::WATER_SPORTS, ActivityType::RIDE, ActivityType::RUN],
            default => $this->resolveRuleDrivenDisciplines($rules),
        };
    }

    /**
     * @return list<ActivityType>
     */
    private function resolveRuleDrivenDisciplines(RaceProfileTrainingRules $rules): array
    {
        $needed = [];

        if ($rules->needsSwimSessions()) {
            $needed[] = ActivityType::WATER_SPORTS;
        }
        if ($rules->needsBikeSessions()) {
            $needed[] = ActivityType::RIDE;
        }
        if ($rules->needsRunSessions()) {
            $needed[] = ActivityType::RUN;
        }

        return $needed;
    }

    private function resolveDisciplineWeight(ActivityType $activityType, ?TrainingPlan $linkedTrainingPlan = null): int
    {
        $sportSchedule = $linkedTrainingPlan?->getSportSchedule();
        $scheduleWeight = 0;

        if (is_array($sportSchedule)) {
            $scheduleKey = match ($activityType) {
                ActivityType::WATER_SPORTS => 'swimDays',
                ActivityType::RIDE => 'bikeDays',
                ActivityType::RUN => 'runDays',
                default => null,
            };

            if (null !== $scheduleKey && is_array($sportSchedule[$scheduleKey] ?? null)) {
                $scheduleWeight += count($sportSchedule[$scheduleKey]);
            }

            if (ActivityType::RIDE === $activityType && is_array($sportSchedule['longRideDays'] ?? null)) {
                $scheduleWeight += count($sportSchedule['longRideDays']);
            }

            if (ActivityType::RUN === $activityType && is_array($sportSchedule['longRunDays'] ?? null)) {
                $scheduleWeight += count($sportSchedule['longRunDays']);
            }
        }

        $weight = max(1, $scheduleWeight);
        if ($this->resolveFocusActivityType($linkedTrainingPlan?->getTrainingFocus()) === $activityType) {
            $weight += 2;
        }

        if ($this->isSpeedEnduranceTrainingPlan($linkedTrainingPlan)) {
            $weight += match ($activityType) {
                ActivityType::RUN => 3,
                ActivityType::RIDE => 1,
                default => 0,
            };
        }

        return $weight;
    }

    private function resolveTrainingBlockStyleDurationMultiplier(
        ActivityType $activityType,
        string $sessionType,
        ?TrainingPlan $linkedTrainingPlan = null,
    ): float {
        if (!$this->isSpeedEnduranceTrainingPlan($linkedTrainingPlan)) {
            return 1.0;
        }

        return match ($sessionType) {
            'key' => match ($activityType) {
                ActivityType::RUN => 0.92,
                ActivityType::RIDE => 0.90,
                default => 0.94,
            },
            'long' => match ($activityType) {
                ActivityType::RUN => 0.82,
                ActivityType::RIDE => 0.84,
                default => 0.90,
            },
            'easy' => match ($activityType) {
                ActivityType::RUN => 0.86,
                ActivityType::RIDE => 0.88,
                default => 0.92,
            },
            'recovery' => 0.90,
            default => 1.0,
        };
    }

    private function isSpeedEnduranceTrainingPlan(?TrainingPlan $linkedTrainingPlan = null): bool
    {
        return $this->isDevelopmentTrainingPlan($linkedTrainingPlan)
            && TrainingBlockStyle::SPEED_ENDURANCE === $linkedTrainingPlan?->getTrainingBlockStyle();
    }

    /**
     * @return list<int>
     */
    private function resolvePreferredDayOffsets(
        SerializableDateTime $weekStart,
        ActivityType $activityType,
        bool $isLongSession = false,
        ?TrainingPlan $linkedTrainingPlan = null,
    ): array {
        $sportSchedule = $linkedTrainingPlan?->getSportSchedule();
        if (!is_array($sportSchedule) || [] === $sportSchedule) {
            return [];
        }

        $keys = match ($activityType) {
            ActivityType::WATER_SPORTS => ['swimDays'],
            ActivityType::RIDE => $isLongSession ? ['longRideDays', 'bikeDays'] : ['bikeDays'],
            ActivityType::RUN => $isLongSession ? ['longRunDays', 'runDays'] : ['runDays'],
            default => [],
        };

        $offsets = [];
        foreach ($keys as $key) {
            $offsets = array_merge($offsets, $this->resolveScheduleDayOffsets($weekStart, $sportSchedule[$key] ?? null));
        }

        return array_values(array_unique($offsets));
    }

    /**
     * @param mixed $dayValues
     *
     * @return list<int>
     */
    private function resolveScheduleDayOffsets(SerializableDateTime $weekStart, mixed $dayValues): array
    {
        if (!is_array($dayValues)) {
            return [];
        }

        $weekStartIsoDay = (int) $weekStart->format('N');
        $offsets = [];

        foreach ($dayValues as $value) {
            $dayNumber = (int) $value;
            if ($dayNumber < 1 || $dayNumber > 7) {
                continue;
            }

            $offsets[] = ($dayNumber - $weekStartIsoDay + 7) % 7;
        }

        return $offsets;
    }

    private function resolveFocusActivityType(?TrainingFocus $focus): ?ActivityType
    {
        return match ($focus) {
            TrainingFocus::RUN => ActivityType::RUN,
            TrainingFocus::BIKE => ActivityType::RIDE,
            TrainingFocus::SWIM => ActivityType::WATER_SPORTS,
            default => null,
        };
    }

    private function appendPerformanceGuidance(
        ActivityType $activityType,
        PlannedSessionIntensity $intensity,
        ?string $notes,
        ?TrainingPlan $linkedTrainingPlan = null,
    ): ?string {
        $metrics = $linkedTrainingPlan?->getPerformanceMetrics();
        if (!is_array($metrics) || [] === $metrics) {
            return $notes;
        }

        $guidance = match ($activityType) {
            ActivityType::RIDE => isset($metrics['cyclingFtp']) && is_numeric($metrics['cyclingFtp'])
                ? $this->buildBikeGuidance((int) $metrics['cyclingFtp'], $intensity)
                : null,
            ActivityType::RUN => isset($metrics['runningThresholdPace']) && is_numeric($metrics['runningThresholdPace'])
                ? $this->buildRunGuidance((int) $metrics['runningThresholdPace'], $intensity)
                : null,
            ActivityType::WATER_SPORTS => isset($metrics['swimmingCss']) && is_numeric($metrics['swimmingCss'])
                ? $this->buildSwimGuidance((int) $metrics['swimmingCss'], $intensity)
                : null,
            default => null,
        };

        return null === $guidance
            ? $notes
            : trim(implode(' ', array_filter([$notes, $guidance])));
    }

    private function buildBikeGuidance(int $ftp, PlannedSessionIntensity $intensity): string
    {
        return match ($intensity) {
            PlannedSessionIntensity::HARD => sprintf('Anchor the main work around 95–102%% FTP (%dW).', $ftp),
            PlannedSessionIntensity::MODERATE => sprintf('Let the steady work settle around 75–85%% FTP (%dW).', $ftp),
            default => sprintf('Keep the effort clearly aerobic relative to FTP (%dW).', $ftp),
        };
    }

    private function buildRunGuidance(int $thresholdPaceInSeconds, PlannedSessionIntensity $intensity): string
    {
        $pace = $this->formatPaceValue($thresholdPaceInSeconds);

        return match ($intensity) {
            PlannedSessionIntensity::HARD => sprintf('Use threshold pace around %s/km on the main work.', $pace),
            PlannedSessionIntensity::MODERATE => sprintf('Keep the steady work a touch easier than threshold pace (%s/km).', $pace),
            default => sprintf('Keep easy running comfortably slower than threshold pace (%s/km).', $pace),
        };
    }

    private function buildSwimGuidance(int $cssInSeconds, PlannedSessionIntensity $intensity): string
    {
        $pace = $this->formatPaceValue($cssInSeconds);

        return match ($intensity) {
            PlannedSessionIntensity::HARD => sprintf('Swim the main reps around CSS (%s/100m).', $pace),
            PlannedSessionIntensity::MODERATE => sprintf('Let the steady swimming hover near CSS feel (%s/100m).', $pace),
            default => sprintf('Keep easy swimming relaxed relative to CSS (%s/100m).', $pace),
        };
    }

    private function resolvePerformanceDurationMultiplier(
        ActivityType $activityType,
        string $sessionType,
        ?TrainingPlan $linkedTrainingPlan = null,
        ?AdaptivePlanningContext $adaptivePlanningContext = null,
    ): float {
        $baseline = null;
        $volume = $this->resolveEffectiveWeeklyVolume($activityType, $linkedTrainingPlan, $adaptivePlanningContext);

        if (ActivityType::RUN === $activityType) {
            $baseline = 'long' === $sessionType ? 45.0 : 35.0;
        }

        if (ActivityType::RIDE === $activityType) {
            $baseline = 'long' === $sessionType ? 8.0 : 6.0;
        }

        if (null === $baseline || null === $volume || $volume <= 0.0) {
            return 1.0;
        }

        $sensitivity = 'long' === $sessionType ? 0.55 : 0.38;
        $multiplier = 1.0 + ((($volume / $baseline) - 1.0) * $sensitivity);

        return max(0.8, min(1.4, $multiplier));
    }

    private function applyPerformanceLoadAdjustments(
        float $loadMultiplier,
        TrainingBlockPhase $phase,
        int $weekInBlock,
        int $blockDurationWeeks,
        int $planWeekNumber,
        ?TrainingPlan $linkedTrainingPlan = null,
        ?AdaptivePlanningContext $adaptivePlanningContext = null,
        bool $isCycleRecoveryWeek = false,
    ): float {
        $athleteBaselineLoad = $this->resolveAthleteBaselineLoad($linkedTrainingPlan, $adaptivePlanningContext);
        if (null === $athleteBaselineLoad) {
            return $loadMultiplier;
        }

        [$baseFloor, $baseCeiling] = match ($phase) {
            TrainingBlockPhase::BASE => [0.82, 1.02],
            TrainingBlockPhase::BUILD => [0.96, 1.16],
            TrainingBlockPhase::PEAK => [0.98, 1.10],
            TrainingBlockPhase::TAPER => [0.42, 0.76],
            TrainingBlockPhase::RECOVERY => [0.4, 0.4],
        };

        if ($isCycleRecoveryWeek && TrainingBlockPhase::BASE === $phase) {
            $baseFloor -= 0.06;
            $baseCeiling -= 0.05;
        }

        if ($isCycleRecoveryWeek && TrainingBlockPhase::BUILD === $phase) {
            $baseFloor -= 0.08;
            $baseCeiling -= 0.07;
        }

        $progress = min($weekInBlock / max(1, $blockDurationWeeks), 1.0);
        $uplift = $athleteBaselineLoad - 1.0;
        $targetFloor = $baseFloor + ($uplift * 0.55);
        $targetCeiling = $baseCeiling + ($uplift * 0.45);
        $progressiveTarget = $targetFloor + (($targetCeiling - $targetFloor) * $progress);
        $progressiveTarget -= $this->resolveAdaptiveLoadPenalty($phase, $planWeekNumber, $adaptivePlanningContext);

        $adjustedTarget = min(1.24, round($progressiveTarget, 3));

        if ($isCycleRecoveryWeek) {
            return min($loadMultiplier, $adjustedTarget);
        }

        return max($loadMultiplier, $adjustedTarget);
    }

    private function resolveAthleteBaselineLoad(
        ?TrainingPlan $linkedTrainingPlan = null,
        ?AdaptivePlanningContext $adaptivePlanningContext = null,
    ): ?float
    {
        $signals = [];

        $runningVolume = $this->resolveEffectiveWeeklyVolume(ActivityType::RUN, $linkedTrainingPlan, $adaptivePlanningContext);
        if (null !== $runningVolume) {
            $signals[] = match (true) {
                $runningVolume >= 75.0 => 1.18,
                $runningVolume >= 55.0 => 1.10,
                $runningVolume >= 35.0 => 1.02,
                $runningVolume > 0.0 && $runningVolume <= 20.0 => 0.84,
                $runningVolume <= 30.0 => 0.92,
                default => 1.0,
            };
        }

        $bikingVolume = $this->resolveEffectiveWeeklyVolume(ActivityType::RIDE, $linkedTrainingPlan, $adaptivePlanningContext);
        if (null !== $bikingVolume) {
            $signals[] = match (true) {
                $bikingVolume >= 12.0 => 1.18,
                $bikingVolume >= 8.0 => 1.10,
                $bikingVolume >= 6.0 => 1.02,
                $bikingVolume > 0.0 && $bikingVolume <= 3.0 => 0.84,
                $bikingVolume <= 5.0 => 0.92,
                default => 1.0,
            };
        }

        $availableTrainingDays = $this->countAvailableTrainingDays($linkedTrainingPlan?->getSportSchedule());
        if ($availableTrainingDays >= 6) {
            $signals[] = 1.05;
        } elseif ($availableTrainingDays >= 5) {
            $signals[] = 1.02;
        } elseif ($availableTrainingDays > 0 && $availableTrainingDays <= 3) {
            $signals[] = 0.94;
        }

        if ([] === $signals) {
            return null;
        }

        return max(0.84, min(1.18, array_sum($signals) / count($signals)));
    }

    private function resolveEffectiveWeeklyVolume(
        ActivityType $activityType,
        ?TrainingPlan $linkedTrainingPlan = null,
        ?AdaptivePlanningContext $adaptivePlanningContext = null,
    ): ?float {
        $metrics = $linkedTrainingPlan?->getPerformanceMetrics();
        $linkedMetric = match ($activityType) {
            ActivityType::RUN => is_array($metrics) && isset($metrics['weeklyRunningVolume']) && is_numeric($metrics['weeklyRunningVolume'])
                ? (float) $metrics['weeklyRunningVolume']
                : null,
            ActivityType::RIDE => is_array($metrics) && isset($metrics['weeklyBikingVolume']) && is_numeric($metrics['weeklyBikingVolume'])
                ? (float) $metrics['weeklyBikingVolume']
                : null,
            default => null,
        };
        $historicalMetric = match ($activityType) {
            ActivityType::RUN => $adaptivePlanningContext?->getHistoricalWeeklyRunningVolume(),
            ActivityType::RIDE => $adaptivePlanningContext?->getHistoricalWeeklyBikingVolume(),
            default => null,
        };

        if (null === $linkedMetric) {
            return $historicalMetric;
        }

        if (null === $historicalMetric) {
            return $linkedMetric;
        }

        return max($linkedMetric, $historicalMetric);
    }

    private function resolveAdaptiveLoadPenalty(
        TrainingBlockPhase $phase,
        int $planWeekNumber,
        ?AdaptivePlanningContext $adaptivePlanningContext = null,
    ): float {
        if (!$adaptivePlanningContext instanceof AdaptivePlanningContext || $planWeekNumber > 2) {
            return 0.0;
        }

        if (!in_array($phase, [TrainingBlockPhase::BASE, TrainingBlockPhase::BUILD, TrainingBlockPhase::PEAK], true)) {
            return 0.0;
        }

        $readinessContext = $adaptivePlanningContext->getCurrentWeekReadinessContext();
        $penalty = match ($readinessContext->getReadinessScore()?->getStatus()) {
            ReadinessStatus::NEEDS_RECOVERY => 0.12,
            ReadinessStatus::CAUTION => 0.06,
            default => 0.0,
        };

        $daysUntilTsbHealthy = $readinessContext->getForecastDaysUntilTsbHealthy() ?? 0;
        if ($daysUntilTsbHealthy >= 4) {
            $penalty += 0.04;
        } elseif ($daysUntilTsbHealthy >= 2) {
            $penalty += 0.02;
        }

        $daysUntilAcRatioHealthy = $readinessContext->getForecastDaysUntilAcRatioHealthy() ?? 0;
        if ($daysUntilAcRatioHealthy >= 4) {
            $penalty += 0.04;
        } elseif ($daysUntilAcRatioHealthy >= 2) {
            $penalty += 0.02;
        }

        return min(0.18, round($penalty, 2));
    }

    /**
     * @param array<string, mixed>|null $sportSchedule
     */
    private function countAvailableTrainingDays(?array $sportSchedule): int
    {
        if (!is_array($sportSchedule) || [] === $sportSchedule) {
            return 0;
        }

        $days = [];
        foreach (['swimDays', 'bikeDays', 'runDays', 'longRideDays', 'longRunDays'] as $scheduleKey) {
            if (!is_array($sportSchedule[$scheduleKey] ?? null)) {
                continue;
            }

            foreach ($sportSchedule[$scheduleKey] as $day) {
                $dayNumber = (int) $day;
                if ($dayNumber >= 1 && $dayNumber <= 7) {
                    $days[$dayNumber] = true;
                }
            }
        }

        return count($days);
    }

    private function formatPaceValue(int $seconds): string
    {
        $minutes = intdiv(max(0, $seconds), 60);
        $remainingSeconds = max(0, $seconds % 60);

        return sprintf('%d:%02d', $minutes, $remainingSeconds);
    }

    /**
     * @param list<ProposedSession> $sessions
     */
    private function sortProposedSessions(array &$sessions): void
    {
        usort($sessions, function (ProposedSession $left, ProposedSession $right): int {
            $dayComparison = $left->getDay() <=> $right->getDay();
            if (0 !== $dayComparison) {
                return $dayComparison;
            }

            $typeComparison = $this->resolveSessionSortWeight($left) <=> $this->resolveSessionSortWeight($right);
            if (0 !== $typeComparison) {
                return $typeComparison;
            }

            return strcmp((string) $left->getTitle(), (string) $right->getTitle());
        });
    }

    private function resolveSessionSortWeight(ProposedSession $session): int
    {
        $weight = match ($session->getActivityType()) {
            ActivityType::WATER_SPORTS => 10,
            ActivityType::RIDE => 20,
            ActivityType::RUN => 30,
            default => 40,
        };

        if ($session->isBrickSession()) {
            $weight += 5;
        }

        if ($this->isSecondaryRunTitle($session->getTitle())) {
            $weight += 10;
        }

        if ($session->isKeySession()) {
            --$weight;
        }

        return $weight;
    }

    private function isSecondaryRunTitle(?string $title): bool
    {
        return null !== $title && str_starts_with(strtolower(trim($title)), 'secondary ');
    }

    /**
     * @param list<ProposedSession> $sessions
     */
    private function countSessionsOnDay(array $sessions, SerializableDateTime $day): int
    {
        return count(array_filter($sessions, static function (ProposedSession $session) use ($day): bool {
            return $session->getDay()->format('Y-m-d') === $day->format('Y-m-d');
        }));
    }

    /**
     * @param list<ProposedSession> $sessions
     */
    private function countRunSessionsOnDay(array $sessions, SerializableDateTime $day): int
    {
        return count(array_filter($sessions, static function (ProposedSession $session) use ($day): bool {
            return $session->getDay()->format('Y-m-d') === $day->format('Y-m-d')
                && ActivityType::RUN === $session->getActivityType();
        }));
    }

    /**
     * @param list<ProposedSession> $sessions
     *
     * @return list<ProposedSession>
     */
    private function addBrickSessionIfMissing(array $sessions, SerializableDateTime $weekStart): array
    {
        $hasRide = false;
        $hasRun = false;

        foreach ($sessions as $session) {
            if (ActivityType::RIDE === $session->getActivityType()) {
                $hasRide = true;
            }
            if (ActivityType::RUN === $session->getActivityType()) {
                $hasRun = true;
            }
        }

        if (!$hasRide || !$hasRun) {
            return $sessions;
        }

        $rideDays = [];
        foreach ($sessions as $session) {
            if (ActivityType::RIDE === $session->getActivityType()) {
                $rideDays[$session->getDay()->format('Y-m-d')] = true;
            }
        }

        foreach ($sessions as $session) {
            if (ActivityType::RUN === $session->getActivityType()
                && isset($rideDays[$session->getDay()->format('Y-m-d')])) {
                return $sessions;
            }
        }

        $lastRideDay = null;
        foreach ($sessions as $session) {
            if (ActivityType::RIDE === $session->getActivityType()
                && $session->isKeySession()) {
                $lastRideDay = $session->getDay();
            }
        }

        if (null !== $lastRideDay) {
            $sessions[] = ProposedSession::create(
                day: $lastRideDay,
                activityType: ActivityType::RUN,
                targetIntensity: PlannedSessionIntensity::EASY,
                title: 'Brick run',
                notes: 'Short transition run off the bike',
                targetDurationInSeconds: 1200,
                isBrickSession: true,
            );
        }

        return $sessions;
    }

    /**
     * @param list<PlannedSession> $plannedSessions
     *
     * @return list<ProposedSession>
     */
    private function mapExistingPlannedSessionsToProposedSessions(array $plannedSessions): array
    {
        return array_values(array_map(function (PlannedSession $plannedSession): ProposedSession {
            return ProposedSession::create(
                day: $plannedSession->getDay(),
                activityType: $plannedSession->getActivityType(),
                targetIntensity: $this->resolvePlannedSessionIntensity($plannedSession),
                title: $plannedSession->getTitle() ?? $this->buildEasySessionTitle($plannedSession->getActivityType()),
                notes: $plannedSession->getNotes(),
                targetDurationInSeconds: $plannedSession->getTargetDurationInSeconds() ?? $plannedSession->getWorkoutDurationInSeconds(),
                isKeySession: $this->isKeyPlannedSession($plannedSession),
                isBrickSession: $this->isBrickPlannedSession($plannedSession),
                workoutSteps: $plannedSession->getWorkoutSteps(),
            );
        }, $plannedSessions));
    }

    /**
     * @param list<ProposedSession> $existingSessions
     * @param list<ProposedSession> $suggestedSessions
     *
     * @return list<ProposedSession>
     */
    private function mergeSuggestedSessionsIntoExistingWeek(array $existingSessions, array $suggestedSessions): array
    {
        $mergedSessions = $existingSessions;
        $targetCount = max(count($existingSessions), count($suggestedSessions));

        foreach ($suggestedSessions as $suggestedSession) {
            if (count($mergedSessions) >= $targetCount) {
                break;
            }

            if ($this->hasConflictingSession($mergedSessions, $suggestedSession)) {
                continue;
            }

            $mergedSessions[] = $suggestedSession;
        }

        return $mergedSessions;
    }

    /**
     * @param list<ProposedSession> $sessions
     */
    private function hasConflictingSession(array $sessions, ProposedSession $candidate): bool
    {
        foreach ($sessions as $session) {
            if ($session->getDay()->format('Y-m-d') !== $candidate->getDay()->format('Y-m-d')) {
                continue;
            }

            if ($session->getActivityType() === $candidate->getActivityType()) {
                if ($this->canCoexistAsDoubleRun($sessions, $session, $candidate)) {
                    continue;
                }

                return true;
            }

            if (null !== $session->getTitle() && $session->getTitle() === $candidate->getTitle()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<ProposedSession> $sessions
     */
    private function canCoexistAsDoubleRun(array $sessions, ProposedSession $existingSession, ProposedSession $candidate): bool
    {
        if (ActivityType::RUN !== $existingSession->getActivityType() || ActivityType::RUN !== $candidate->getActivityType()) {
            return false;
        }

        if ($existingSession->isBrickSession() || $candidate->isBrickSession()) {
            return false;
        }

        if (!$this->isSecondaryRunTitle($existingSession->getTitle()) && !$this->isSecondaryRunTitle($candidate->getTitle())) {
            return false;
        }

        return $this->countRunSessionsOnDay($sessions, $candidate->getDay()) < 2;
    }

    private function resolvePlannedSessionIntensity(PlannedSession $plannedSession): PlannedSessionIntensity
    {
        if ($plannedSession->getTargetIntensity() instanceof PlannedSessionIntensity) {
            return $plannedSession->getTargetIntensity();
        }

        $titleAndNotes = strtolower(trim(($plannedSession->getTitle() ?? '').' '.($plannedSession->getNotes() ?? '')));
        if (str_contains($titleAndNotes, 'race')) {
            return PlannedSessionIntensity::RACE;
        }

        if (str_contains($titleAndNotes, 'interval')
            || str_contains($titleAndNotes, 'tempo')
            || str_contains($titleAndNotes, 'threshold')
            || str_contains($titleAndNotes, 'vo2')) {
            return PlannedSessionIntensity::HARD;
        }

        $targetDurationInSeconds = $plannedSession->getTargetDurationInSeconds() ?? $plannedSession->getWorkoutDurationInSeconds() ?? 0;
        if ((ActivityType::RIDE === $plannedSession->getActivityType() && $targetDurationInSeconds >= 5_400)
            || (ActivityType::RUN === $plannedSession->getActivityType() && $targetDurationInSeconds >= 4_500)) {
            return PlannedSessionIntensity::MODERATE;
        }

        return PlannedSessionIntensity::EASY;
    }

    private function isKeyPlannedSession(PlannedSession $plannedSession): bool
    {
        $intensity = $this->resolvePlannedSessionIntensity($plannedSession);
        if (in_array($intensity, [PlannedSessionIntensity::HARD, PlannedSessionIntensity::RACE], true)) {
            return true;
        }

        if ($this->isBrickPlannedSession($plannedSession)) {
            return true;
        }

        $targetDurationInSeconds = $plannedSession->getTargetDurationInSeconds() ?? $plannedSession->getWorkoutDurationInSeconds() ?? 0;

        if ($plannedSession->hasWorkoutSteps() && PlannedSessionIntensity::MODERATE === $intensity && $targetDurationInSeconds >= 3_600) {
            return true;
        }

        return (ActivityType::RIDE === $plannedSession->getActivityType() && $targetDurationInSeconds >= 5_400)
            || (ActivityType::RUN === $plannedSession->getActivityType() && $targetDurationInSeconds >= 4_500);
    }

    private function isBrickPlannedSession(PlannedSession $plannedSession): bool
    {
        $titleAndNotes = strtolower(trim(($plannedSession->getTitle() ?? '').' '.($plannedSession->getNotes() ?? '')));

        return str_contains($titleAndNotes, 'brick');
    }

    private function activityTypeLabel(ActivityType $activityType): string
    {
        return match ($activityType) {
            ActivityType::RIDE => 'Bike',
            ActivityType::RUN => 'Run',
            ActivityType::WATER_SPORTS => 'Swim',
            default => 'Workout',
        };
    }

    /**
     * @param list<ProposedTrainingBlock> $proposedBlocks
     */
    private function resolvePlanEndDay(RaceEvent $targetRace, array $proposedBlocks): SerializableDateTime
    {
        if ([] === $proposedBlocks) {
            return $targetRace->getDay()->setTime(23, 59, 59);
        }

        return $proposedBlocks[array_key_last($proposedBlocks)]->getEndDay()->setTime(23, 59, 59);
    }
}
