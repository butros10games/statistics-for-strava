<?php

declare(strict_types=1);

namespace App\Application\ReactPreview;

use App\Domain\Activity\ActivityType;
use App\Domain\TrainingPlanner\AdaptivePlanningContextBuilder;
use App\Domain\TrainingPlanner\PlanGenerator\PlanAdaptationRecommendation;
use App\Domain\TrainingPlanner\PlanGenerator\PlanAdaptationRecommendationType;
use App\Domain\TrainingPlanner\PlanGenerator\PlanAdaptationWarning;
use App\Domain\TrainingPlanner\PlanGenerator\PlanAdaptationWarningSeverity;
use App\Domain\TrainingPlanner\PlanGenerator\PlanAdaptationRecommender;
use App\Domain\TrainingPlanner\PlanGenerator\ProposedSession;
use App\Domain\TrainingPlanner\PlanGenerator\ProposedTrainingBlock;
use App\Domain\TrainingPlanner\PlanGenerator\ProposedWeekSkeleton;
use App\Domain\TrainingPlanner\PlanGenerator\RaceProfileTrainingRules;
use App\Domain\TrainingPlanner\PlanGenerator\TrainingPlanGenerator;
use App\Domain\TrainingPlanner\PlanGenerator\TrainingPlanProposal;
use App\Domain\TrainingPlanner\PlannedSessionLoadEstimator;
use App\Domain\TrainingPlanner\PlannedSessionRepository;
use App\Domain\TrainingPlanner\Prediction\RunningPlanPerformancePrediction;
use App\Domain\TrainingPlanner\Prediction\RunningPlanPerformancePredictor;
use App\Domain\TrainingPlanner\Prediction\RunningRaceBenchmarkPrediction;
use App\Domain\TrainingPlanner\RaceEvent;
use App\Domain\TrainingPlanner\RaceEventFamily;
use App\Domain\TrainingPlanner\RaceEventPriority;
use App\Domain\TrainingPlanner\RaceEventProfile;
use App\Domain\TrainingPlanner\RaceEventRepository;
use App\Domain\TrainingPlanner\RaceEventType;
use App\Domain\TrainingPlanner\RacePlannerConfiguration;
use App\Domain\TrainingPlanner\RacePlannerExistingBlockSelector;
use App\Domain\TrainingPlanner\RacePlannerRecoveryManager;
use App\Domain\TrainingPlanner\RacePlannerRecoverySaveSummary;
use App\Domain\TrainingPlanner\TrainingBlock;
use App\Domain\TrainingPlanner\TrainingBlockPhase;
use App\Domain\TrainingPlanner\TrainingBlockRepository;
use App\Domain\TrainingPlanner\TrainingFocus;
use App\Domain\TrainingPlanner\TrainingPlan;
use App\Domain\TrainingPlanner\TrainingPlanDiscipline;
use App\Domain\TrainingPlanner\TrainingPlanRepository;
use App\Domain\TrainingPlanner\TrainingPlanType;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final readonly class RacePlannerPreviewPayloadBuilder
{
    public function __construct(
        private RaceEventRepository $raceEventRepository,
        private TrainingBlockRepository $trainingBlockRepository,
        private PlannedSessionRepository $plannedSessionRepository,
        private PlannedSessionLoadEstimator $plannedSessionLoadEstimator,
        private AdaptivePlanningContextBuilder $adaptivePlanningContextBuilder,
        private TrainingPlanGenerator $planGenerator,
        private RunningPlanPerformancePredictor $runningPlanPerformancePredictor,
        private PlanAdaptationRecommender $adaptationRecommender,
        private RacePlannerExistingBlockSelector $existingBlockSelector,
        private RacePlannerConfiguration $racePlannerConfiguration,
        private RacePlannerRecoveryManager $racePlannerRecoveryManager,
        private TrainingPlanRepository $trainingPlanRepository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildLandingPayload(SerializableDateTime $now): array
    {
        $upcomingRaces = $this->raceEventRepository->findUpcoming($now, 20);

        if ([] === $upcomingRaces) {
            return $this->buildSerializedPayload(
                mode: 'global',
                plannerRoute: 'race-planner',
                legacyPlannerPath: 'race-planner',
                hasUpcomingRaces: false,
                plannerSupportsRaceActions: false,
                plannerUsesExistingBlocks: false,
                hasCustomPlanStartDay: false,
                planStartDayInputValue: null,
                displayedUpcomingRaces: [],
                raceEventCountdownDaysById: [],
                targetRace: null,
                linkedTrainingPlan: null,
                linkedTrainingPlanNeedsSync: false,
                rules: null,
                proposal: null,
                recommendations: [],
                existingBlocks: [],
                runningPerformancePrediction: null,
                recoverySaveSummary: null,
                requestedAt: $now,
            );
        }

        $targetRace = $this->findTargetARace($upcomingRaces);
        $linkedTrainingPlan = $this->trainingPlanRepository->findByTargetRaceEventId($targetRace->getId());
        $raceEventCountdownDaysById = $this->buildRaceEventCountdownDaysById($upcomingRaces, $now);
        $rules = RaceProfileTrainingRules::forProfile($linkedTrainingPlan?->getTargetRaceProfile() ?? $targetRace->getProfile());
        $configuredPlanStartDay = $this->racePlannerConfiguration->findPlanStartDay();
        $planningEndDay = $this->resolvePlanningEndDay($targetRace, $rules);
        $searchRange = DateRange::fromDates(
            $targetRace->getDay()->modify(sprintf('-%d weeks', max(32, $rules->getMaximumPlanWeeks() + 6)))->setTime(0, 0),
            $planningEndDay,
        );
        $blocksInPlanningWindow = $this->trainingBlockRepository->findByDateRange($searchRange);
        $reusableExistingBlocks = $this->existingBlockSelector->selectReusableBlocks($targetRace, $blocksInPlanningWindow, $planningEndDay);
        $plannerUsesExistingBlocks = [] !== $reusableExistingBlocks;
        $effectivePlanStartDay = $this->resolveEffectivePlanStartDay(
            $configuredPlanStartDay,
            $targetRace,
            $now,
            ($reusableExistingBlocks[0] ?? null)?->getStartDay(),
        );
        $dateRange = DateRange::fromDates($effectivePlanStartDay, $planningEndDay);
        $existingBlocks = $plannerUsesExistingBlocks
            ? $reusableExistingBlocks
            : $this->trainingBlockRepository->findByDateRange($dateRange);
        $existingSessions = $this->plannedSessionRepository->findByDateRange($dateRange);
        $predictionSessions = null !== $linkedTrainingPlan
            ? $this->plannedSessionRepository->findByDateRange(DateRange::fromDates(
                $linkedTrainingPlan->getStartDay()->setTime(0, 0),
                $planningEndDay,
            ))
            : $existingSessions;
        $plannedSessionEstimatesById = $this->buildPlannedSessionEstimatesById($existingSessions);
        $currentWeekSessions = $this->findSessionsInCurrentWeek($existingSessions, $now);
        $currentWeekSessionEstimatesById = $this->buildPlannedSessionEstimatesById($currentWeekSessions);
        $adaptivePlanningContext = $this->adaptivePlanningContextBuilder->build(
            referenceDate: $now,
            plannedSessions: $existingSessions,
            raceEvents: $upcomingRaces,
            trainingBlocks: $existingBlocks,
        );
        $proposal = $this->planGenerator->generate(
            targetRace: $targetRace,
            planStartDay: $effectivePlanStartDay,
            allRaceEvents: $upcomingRaces,
            existingBlocks: $existingBlocks,
            existingSessions: $existingSessions,
            referenceDate: $now,
            linkedTrainingPlan: $linkedTrainingPlan,
            adaptivePlanningContext: $adaptivePlanningContext,
        );
        $readinessContext = [] !== $currentWeekSessions
            ? $adaptivePlanningContext->getCurrentWeekReadinessContext()
            : null;
        $recommendations = $this->adaptationRecommender->recommend(
            targetRace: $targetRace,
            existingBlocks: $existingBlocks,
            existingSessions: $currentWeekSessions,
            upcomingRaces: $upcomingRaces,
            plannedSessionEstimatesById: $currentWeekSessionEstimatesById,
            readinessContext: $readinessContext,
            now: $now,
            planWindowSessions: $existingSessions,
            planWindowSessionEstimatesById: $plannedSessionEstimatesById,
        );
        $recoverySaveSummary = $this->racePlannerRecoveryManager->summarizeFromProposal(
            $proposal,
            $existingBlocks,
            $existingSessions,
        );
        $linkedTrainingPlanNeedsSync = null !== $linkedTrainingPlan
            && (
                TrainingPlanType::RACE !== $linkedTrainingPlan->getType()
                || $linkedTrainingPlan->getStartDay()->format('Y-m-d') !== $effectivePlanStartDay->format('Y-m-d')
                || $linkedTrainingPlan->getEndDay()->format('Y-m-d') !== $proposal->getPlanEndDay()->format('Y-m-d')
            );

        return $this->buildSerializedPayload(
            mode: 'global',
            plannerRoute: 'race-planner',
            legacyPlannerPath: 'race-planner',
            hasUpcomingRaces: true,
            plannerSupportsRaceActions: true,
            plannerUsesExistingBlocks: $plannerUsesExistingBlocks,
            hasCustomPlanStartDay: !$plannerUsesExistingBlocks && null !== $configuredPlanStartDay,
            planStartDayInputValue: $effectivePlanStartDay->format('Y-m-d'),
            displayedUpcomingRaces: $upcomingRaces,
            raceEventCountdownDaysById: $raceEventCountdownDaysById,
            targetRace: $targetRace,
            linkedTrainingPlan: $linkedTrainingPlan,
            linkedTrainingPlanNeedsSync: $linkedTrainingPlanNeedsSync,
            rules: $rules,
            proposal: $proposal,
            recommendations: $recommendations,
            existingBlocks: $existingBlocks,
            runningPerformancePrediction: $this->buildRunningPerformancePredictionViewModel($linkedTrainingPlan, $proposal, $predictionSessions, $now),
            recoverySaveSummary: $recoverySaveSummary,
            requestedAt: $now,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPlanPreviewPayload(TrainingPlan $plan, SerializableDateTime $now): array
    {
        $plannerRoute = sprintf('race-planner/plan-%s', $plan->getId());
        $planRaces = $this->loadRacesForPlan($plan);
        $linkedRace = null === $plan->getTargetRaceEventId()
            ? null
            : $this->raceEventRepository->findById($plan->getTargetRaceEventId());
        $targetRace = $linkedRace ?? $this->findPrimaryPlanRace($planRaces) ?? $this->createSyntheticTargetRace($plan, $now);
        $usesSyntheticTarget = null === $linkedRace && !in_array($targetRace, $planRaces, true);
        $allPlannerRaces = $planRaces;

        if ($usesSyntheticTarget) {
            $allPlannerRaces[] = $targetRace;
        }

        $raceEventCountdownDaysById = $this->buildRaceEventCountdownDaysById($allPlannerRaces, $now);
        $profile = $plan->getTargetRaceProfile() ?? $targetRace->getProfile();
        $rules = RaceProfileTrainingRules::forProfile($profile);
        $planDateRange = DateRange::fromDates(
            $plan->getStartDay()->setTime(0, 0),
            $plan->getEndDay()->setTime(23, 59, 59),
        );
        $existingBlocks = $this->trainingBlockRepository->findByDateRange($planDateRange);
        $existingSessions = $this->plannedSessionRepository->findByDateRange($planDateRange);
        $plannedSessionEstimatesById = $this->buildPlannedSessionEstimatesById($existingSessions);
        $currentWeekSessions = $this->findSessionsInCurrentWeek($existingSessions, $now);
        $currentWeekSessionEstimatesById = $this->buildPlannedSessionEstimatesById($currentWeekSessions);
        $adaptivePlanningContext = $this->adaptivePlanningContextBuilder->build(
            referenceDate: $now,
            plannedSessions: $existingSessions,
            raceEvents: $allPlannerRaces,
            trainingBlocks: $existingBlocks,
        );
        $proposal = $this->planGenerator->generate(
            targetRace: $targetRace,
            planStartDay: $plan->getStartDay()->setTime(0, 0),
            allRaceEvents: $allPlannerRaces,
            existingBlocks: $existingBlocks,
            existingSessions: $existingSessions,
            referenceDate: $now,
            linkedTrainingPlan: $plan,
            adaptivePlanningContext: $adaptivePlanningContext,
        );
        $readinessContext = [] !== $currentWeekSessions
            ? $adaptivePlanningContext->getCurrentWeekReadinessContext()
            : null;
        $isDevelopmentPlanPreview = $this->isDevelopmentPlanPreview($plan, $linkedRace);
        $displayedUpcomingRaces = $isDevelopmentPlanPreview ? [] : $allPlannerRaces;
        $recommendations = $isDevelopmentPlanPreview
            ? $this->buildDevelopmentPlanPreviewRecommendations($plan, $existingBlocks)
            : $this->adaptationRecommender->recommend(
                targetRace: $targetRace,
                existingBlocks: $existingBlocks,
                existingSessions: $currentWeekSessions,
                upcomingRaces: $allPlannerRaces,
                plannedSessionEstimatesById: $currentWeekSessionEstimatesById,
                readinessContext: $readinessContext,
                now: $now,
                planWindowSessions: $existingSessions,
                planWindowSessionEstimatesById: $plannedSessionEstimatesById,
            );

        return $this->buildSerializedPayload(
            mode: 'plan-preview',
            plannerRoute: $plannerRoute,
            legacyPlannerPath: $plannerRoute,
            hasUpcomingRaces: true,
            plannerSupportsRaceActions: null !== $linkedRace,
            plannerUsesExistingBlocks: [] !== $existingBlocks,
            hasCustomPlanStartDay: false,
            planStartDayInputValue: $plan->getStartDay()->format('Y-m-d'),
            displayedUpcomingRaces: $displayedUpcomingRaces,
            raceEventCountdownDaysById: $raceEventCountdownDaysById,
            targetRace: $targetRace,
            linkedTrainingPlan: $plan,
            linkedTrainingPlanNeedsSync: false,
            rules: $rules,
            proposal: $proposal,
            recommendations: $recommendations,
            existingBlocks: $existingBlocks,
            runningPerformancePrediction: $this->buildRunningPerformancePredictionViewModel($plan, $proposal, $existingSessions, $now),
            recoverySaveSummary: $this->racePlannerRecoveryManager->summarizeFromProposal(
                $proposal,
                $existingBlocks,
                $existingSessions,
            ),
            requestedAt: $now,
        );
    }

    /**
     * @param list<RaceEvent> $displayedUpcomingRaces
     * @param list<PlanAdaptationRecommendation> $recommendations
     * @param list<TrainingBlock> $existingBlocks
     * @return array<string, mixed>
     */
    private function buildSerializedPayload(
        string $mode,
        string $plannerRoute,
        string $legacyPlannerPath,
        bool $hasUpcomingRaces,
        bool $plannerSupportsRaceActions,
        bool $plannerUsesExistingBlocks,
        bool $hasCustomPlanStartDay,
        ?string $planStartDayInputValue,
        array $displayedUpcomingRaces,
        array $raceEventCountdownDaysById,
        ?RaceEvent $targetRace,
        ?TrainingPlan $linkedTrainingPlan,
        bool $linkedTrainingPlanNeedsSync,
        ?RaceProfileTrainingRules $rules,
        ?TrainingPlanProposal $proposal,
        array $recommendations,
        array $existingBlocks,
        ?array $runningPerformancePrediction,
        ?RacePlannerRecoverySaveSummary $recoverySaveSummary,
        SerializableDateTime $requestedAt,
    ): array {
        $countdownDays = null === $targetRace
            ? null
            : ($raceEventCountdownDaysById[(string) $targetRace->getId()] ?? null);
        $trainingPlanExportPath = null === $linkedTrainingPlan
            ? null
            : sprintf('api/exports/training-plans/%s.json', $linkedTrainingPlan->getId());
        $canRegenerateUpcomingSessions = null !== $linkedTrainingPlan && $plannerSupportsRaceActions && !$linkedTrainingPlanNeedsSync;
        $warnings = $proposal?->getWarnings() ?? [];

        return [
            'requestedAt' => $requestedAt->format(DATE_ATOM),
            'mode' => $mode,
            'plannerRoute' => $plannerRoute,
            'legacyPlannerPath' => $legacyPlannerPath,
            'legacyTrainingPlansPath' => 'training-plans',
            'hasUpcomingRaces' => $hasUpcomingRaces,
            'plannerSupportsRaceActions' => $plannerSupportsRaceActions,
            'plannerUsesExistingBlocks' => $plannerUsesExistingBlocks,
            'hasCustomPlanStartDay' => $hasCustomPlanStartDay,
            'planStartDayInputValue' => $planStartDayInputValue,
            'targetRace' => null === $targetRace
                ? null
                : $this->serializeRaceEvent($targetRace, $raceEventCountdownDaysById[(string) $targetRace->getId()] ?? null),
            'countdownDays' => $countdownDays,
            'linkedTrainingPlan' => null === $linkedTrainingPlan
                ? null
                : $this->serializeTrainingPlan($linkedTrainingPlan, $trainingPlanExportPath),
            'linkedTrainingPlanNeedsSync' => $linkedTrainingPlanNeedsSync,
            'displayedUpcomingRaces' => array_map(
                fn (RaceEvent $raceEvent): array => $this->serializeRaceEvent(
                    $raceEvent,
                    $raceEventCountdownDaysById[(string) $raceEvent->getId()] ?? null,
                ),
                $displayedUpcomingRaces,
            ),
            'rules' => null === $rules ? null : $this->serializeRules($rules),
            'warnings' => array_map($this->serializeWarning(...), $warnings),
            'recommendations' => array_map($this->serializeRecommendation(...), $recommendations),
            'proposal' => null === $proposal
                ? null
                : $this->serializeProposal($proposal, $rules, $runningPerformancePrediction),
            'existingBlocks' => array_map($this->serializeExistingTrainingBlock(...), $existingBlocks),
            'runningPerformancePrediction' => $runningPerformancePrediction,
            'recoverySaveSummary' => null === $recoverySaveSummary
                ? null
                : [
                    'missingRecoveryBlockCount' => $recoverySaveSummary->getMissingRecoveryBlockCount(),
                    'missingRecoverySessionCount' => $recoverySaveSummary->getMissingRecoverySessionCount(),
                    'hasAnythingToSave' => $recoverySaveSummary->hasAnythingToSave(),
                ],
            'actions' => [
                'canEditLinkedTrainingPlan' => null !== $linkedTrainingPlan,
                'canRegenerateUpcomingSessions' => $canRegenerateUpcomingSessions,
                'canSetupPlan' => null !== $targetRace && $plannerSupportsRaceActions && (null === $linkedTrainingPlan || $linkedTrainingPlanNeedsSync),
                'canSaveRecovery' => null !== $targetRace && $plannerSupportsRaceActions && $recoverySaveSummary?->hasAnythingToSave() === true,
                'canChangeStartDay' => !$plannerUsesExistingBlocks && 'global' === $mode && null !== $targetRace,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRaceEvent(RaceEvent $raceEvent, ?int $countdownDays): array
    {
        return [
            'id' => (string) $raceEvent->getId(),
            'day' => $raceEvent->getDay()->format('Y-m-d'),
            'title' => $raceEvent->getTitle() ?? $raceEvent->getProfile()->value,
            'location' => $raceEvent->getLocation(),
            'priority' => $raceEvent->getPriority()->value,
            'profile' => $raceEvent->getProfile()->value,
            'type' => $raceEvent->getType()->value,
            'family' => $raceEvent->getFamily()->value,
            'targetFinishTimeInSeconds' => $raceEvent->getTargetFinishTimeInSeconds(),
            'countdownDays' => $countdownDays,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTrainingPlan(TrainingPlan $trainingPlan, ?string $exportPath): array
    {
        return [
            'id' => (string) $trainingPlan->getId(),
            'title' => $trainingPlan->getTitle(),
            'type' => $trainingPlan->getType()->value,
            'startDay' => $trainingPlan->getStartDay()->format('Y-m-d'),
            'endDay' => $trainingPlan->getEndDay()->format('Y-m-d'),
            'targetRaceProfile' => $trainingPlan->getTargetRaceProfile()?->value,
            'discipline' => $trainingPlan->getDiscipline()?->value,
            'notes' => $trainingPlan->getNotes(),
            'exportPath' => $exportPath,
            'legacyPlannerPath' => sprintf('race-planner/plan-%s', $trainingPlan->getId()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRules(RaceProfileTrainingRules $rules): array
    {
        return [
            'minimumPlanWeeks' => $rules->getMinimumPlanWeeks(),
            'idealPlanWeeks' => $rules->getIdealPlanWeeks(),
            'maximumPlanWeeks' => $rules->getMaximumPlanWeeks(),
            'taperWeeks' => $rules->getTaperWeeks(),
            'peakWeeks' => $rules->getPeakWeeks(),
            'postRaceRecoveryWeeks' => $rules->getPostRaceRecoveryWeeks(),
            'sessionsPerWeekMinimum' => $rules->getSessionsPerWeekMinimum(),
            'sessionsPerWeekIdeal' => $rules->getSessionsPerWeekIdeal(),
            'sessionsPerWeekMaximum' => $rules->getSessionsPerWeekMaximum(),
            'hardSessionsPerWeek' => $rules->getHardSessionsPerWeek(),
            'longSessionsPerWeek' => $rules->getLongSessionsPerWeek(),
            'needsBrickSessions' => $rules->needsBrickSessions(),
            'needsSwimSessions' => $rules->needsSwimSessions(),
            'needsBikeSessions' => $rules->needsBikeSessions(),
            'needsRunSessions' => $rules->needsRunSessions(),
            'disciplines' => array_values(array_filter([
                $rules->needsSwimSessions() ? 'Swim' : null,
                $rules->needsBikeSessions() ? 'Bike' : null,
                $rules->needsRunSessions() ? 'Run' : null,
            ])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeWarning(PlanAdaptationWarning $warning): array
    {
        return [
            'type' => $warning->getType()->value,
            'title' => $warning->getTitle(),
            'body' => $warning->getBody(),
            'severity' => $warning->getSeverity()->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRecommendation(PlanAdaptationRecommendation $recommendation): array
    {
        return [
            'type' => $recommendation->getType()->value,
            'title' => $recommendation->getTitle(),
            'body' => $recommendation->getBody(),
            'severity' => $recommendation->getSeverity()->value,
            'suggestedBlock' => null === $recommendation->getSuggestedBlock()
                ? null
                : $this->serializeRecommendedBlock($recommendation->getSuggestedBlock()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRecommendedBlock(ProposedTrainingBlock $block): array
    {
        return [
            'title' => $block->getTitle(),
            'phase' => $block->getPhase()->value,
            'focus' => $block->getFocus(),
            'startDay' => $block->getStartDay()->format('Y-m-d'),
            'endDay' => $block->getEndDay()->format('Y-m-d'),
            'durationInWeeks' => $block->getDurationInWeeks(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeProposal(TrainingPlanProposal $proposal, ?RaceProfileTrainingRules $rules, ?array $runningPerformancePrediction): array
    {
        $projectedThresholdPacesByWeekStartDate = is_array($runningPerformancePrediction['projectedThresholdPacesByWeekStartDate'] ?? null)
            ? $runningPerformancePrediction['projectedThresholdPacesByWeekStartDate']
            : [];

        return [
            'planStartDay' => $proposal->getPlanStartDay()->format('Y-m-d'),
            'planEndDay' => $proposal->getPlanEndDay()->format('Y-m-d'),
            'totalWeeks' => $proposal->getTotalWeeks(),
            'totalProposedSessions' => $proposal->getTotalProposedSessions(),
            'blocks' => array_map(
                fn (ProposedTrainingBlock $block): array => $this->serializeProposedTrainingBlock(
                    $block,
                    $rules,
                    $projectedThresholdPacesByWeekStartDate,
                ),
                $proposal->getProposedBlocks(),
            ),
        ];
    }

    /**
     * @param array<string, string> $projectedThresholdPacesByWeekStartDate
     * @return array<string, mixed>
     */
    private function serializeProposedTrainingBlock(
        ProposedTrainingBlock $block,
        ?RaceProfileTrainingRules $rules,
        array $projectedThresholdPacesByWeekStartDate,
    ): array {
        $totalSessions = 0;
        foreach ($block->getWeekSkeletons() as $week) {
            $totalSessions += $week->getSessionCount();
        }

        return [
            'title' => $block->getTitle(),
            'phase' => $block->getPhase()->value,
            'phaseLabel' => $this->formatBlockPhaseLabel($block->getPhase()),
            'focus' => $block->getFocus(),
            'startDay' => $block->getStartDay()->format('Y-m-d'),
            'endDay' => $block->getEndDay()->format('Y-m-d'),
            'durationInWeeks' => $block->getDurationInWeeks(),
            'totalSessions' => $totalSessions,
            'weeks' => array_map(
                fn (ProposedWeekSkeleton $week): array => $this->serializeProposedWeek(
                    $week,
                    $rules,
                    $projectedThresholdPacesByWeekStartDate,
                ),
                $block->getWeekSkeletons(),
            ),
        ];
    }

    /**
     * @param array<string, string> $projectedThresholdPacesByWeekStartDate
     * @return array<string, mixed>
     */
    private function serializeProposedWeek(
        ProposedWeekSkeleton $week,
        ?RaceProfileTrainingRules $rules,
        array $projectedThresholdPacesByWeekStartDate,
    ): array {
        $doubleRunDays = $this->findDoubleRunDays($week->getSessions());
        $weekStartDate = $week->getStartDay()->format('Y-m-d');

        return [
            'weekNumber' => $week->getWeekNumber(),
            'startDay' => $week->getStartDay()->format('Y-m-d'),
            'endDay' => $week->getEndDay()->format('Y-m-d'),
            'sessionCount' => $week->getSessionCount(),
            'targetLoadPercentage' => $week->getTargetLoadPercentage(),
            'isManuallyPlanned' => $week->isManuallyPlanned(),
            'isRecoveryWeek' => $week->isRecoveryWeek(),
            'hasRaceEffortSession' => $week->hasRaceEffortSession(),
            'raceSummaryLabel' => $week->getRaceSummaryLabel(),
            'projectedThresholdPace' => $projectedThresholdPacesByWeekStartDate[$weekStartDate] ?? null,
            'doubleRunDayCount' => count($doubleRunDays),
            'disciplineDurations' => [
                'swim' => $rules?->needsSwimSessions() ? $week->getFormattedTargetDurationForActivityType(ActivityType::WATER_SPORTS) : null,
                'bike' => $rules?->needsBikeSessions() ? $week->getFormattedTargetDurationForActivityType(ActivityType::RIDE) : null,
                'run' => $rules?->needsRunSessions() ? $week->getFormattedTargetDurationForActivityType(ActivityType::RUN) : null,
            ],
            'sessions' => array_map(
                fn (ProposedSession $session): array => $this->serializeProposedSession(
                    $session,
                    $doubleRunDays,
                    $projectedThresholdPacesByWeekStartDate[$weekStartDate] ?? null,
                    $week->isManuallyPlanned(),
                ),
                $week->getSessions(),
            ),
        ];
    }

    /**
     * @param array<string, true> $doubleRunDays
     * @return array<string, mixed>
     */
    private function serializeProposedSession(
        ProposedSession $session,
        array $doubleRunDays,
        ?string $projectedThresholdPace,
        bool $weekIsManuallyPlanned,
    ): array {
        $sessionDay = $session->getDay()->format('Y-m-d');
        $sessionTitle = $session->getTitle() ?? $this->formatActivityTypeLabel($session->getActivityType());
        $isDoubleRunSession = ActivityType::RUN === $session->getActivityType() && isset($doubleRunDays[$sessionDay]);

        return [
            'day' => $sessionDay,
            'dayLabel' => $session->getDay()->format('D'),
            'activityType' => $session->getActivityType()->value,
            'activityLabel' => $this->formatActivityTypeLabel($session->getActivityType()),
            'targetIntensity' => $session->getTargetIntensity()->value,
            'targetIntensityLabel' => $session->getTargetIntensity()->getLabel(),
            'title' => $sessionTitle,
            'notes' => $session->getNotes(),
            'targetDurationInSeconds' => $session->getTargetDurationInSeconds(),
            'durationLabel' => null === $session->getTargetDurationInSeconds()
                ? null
                : $this->formatDuration((int) $session->getTargetDurationInSeconds()),
            'isKeySession' => $session->isKeySession(),
            'isBrickSession' => $session->isBrickSession(),
            'isDoubleRunSession' => $isDoubleRunSession,
            'isSecondaryRunSession' => str_starts_with($sessionTitle, 'Secondary '),
            'projectedThresholdPace' => ActivityType::RUN === $session->getActivityType() ? $projectedThresholdPace : null,
            'usesWeekForecastCopy' => $weekIsManuallyPlanned,
            'workoutPreviewRows' => $session->getWorkoutPreviewRows(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeExistingTrainingBlock(TrainingBlock $trainingBlock): array
    {
        return [
            'id' => (string) $trainingBlock->getId(),
            'title' => $trainingBlock->getTitle(),
            'phase' => $trainingBlock->getPhase()->value,
            'phaseLabel' => $this->formatBlockPhaseLabel($trainingBlock->getPhase()),
            'focus' => $trainingBlock->getFocus(),
            'startDay' => $trainingBlock->getStartDay()->format('Y-m-d'),
            'endDay' => $trainingBlock->getEndDay()->format('Y-m-d'),
        ];
    }

    /**
     * @param list<ProposedSession> $sessions
     * @return array<string, true>
     */
    private function findDoubleRunDays(array $sessions): array
    {
        $runCountsByDay = [];

        foreach ($sessions as $session) {
            if (ActivityType::RUN !== $session->getActivityType()) {
                continue;
            }

            $day = $session->getDay()->format('Y-m-d');
            $runCountsByDay[$day] = ($runCountsByDay[$day] ?? 0) + 1;
        }

        $doubleRunDays = [];
        foreach ($runCountsByDay as $day => $count) {
            if ($count > 1) {
                $doubleRunDays[$day] = true;
            }
        }

        return $doubleRunDays;
    }

    private function formatActivityTypeLabel(ActivityType $activityType): string
    {
        return match ($activityType) {
            ActivityType::RIDE => 'Cycling',
            ActivityType::RUN => 'Running',
            ActivityType::WATER_SPORTS => 'Swimming',
            ActivityType::WALK => 'Walking',
            default => $activityType->value,
        };
    }

    private function formatBlockPhaseLabel(TrainingBlockPhase $phase): string
    {
        return match ($phase) {
            TrainingBlockPhase::BASE => 'Base',
            TrainingBlockPhase::BUILD => 'Build',
            TrainingBlockPhase::PEAK => 'Peak',
            TrainingBlockPhase::TAPER => 'Taper',
            TrainingBlockPhase::RECOVERY => 'Recovery',
        };
    }

    private function formatDuration(int $durationInSeconds): string
    {
        if ($durationInSeconds < 60) {
            return sprintf('%ds', $durationInSeconds);
        }

        $hours = intdiv($durationInSeconds, 3600);
        $minutes = intdiv($durationInSeconds % 3600, 60);

        if (0 === $hours) {
            return sprintf('%dm', $minutes);
        }

        if (0 === $minutes) {
            return sprintf('%dh', $hours);
        }

        return sprintf('%dh %02dm', $hours, $minutes);
    }

    /**
     * @param list<TrainingBlock> $existingBlocks
     * @return list<PlanAdaptationRecommendation>
     */
    private function buildDevelopmentPlanPreviewRecommendations(TrainingPlan $plan, array $existingBlocks): array
    {
        if ([] !== $existingBlocks) {
            return [];
        }

        return [
            PlanAdaptationRecommendation::create(
                type: PlanAdaptationRecommendationType::ADD_BLOCK,
                title: 'No training blocks yet',
                body: sprintf(
                    'This is a development plan, so use the next %d weeks to build a progressive base/build structure for the target distance instead of a race taper.',
                    $plan->getDurationInWeeks(),
                ),
                severity: PlanAdaptationWarningSeverity::WARNING,
            ),
        ];
    }

    /**
     * @param list<\App\Domain\TrainingPlanner\PlannedSession> $existingSessions
     *
     * @return null|array{confidenceLabel: string, currentThresholdPace: string, trajectoryThresholdPace: ?string, trajectoryGainLabel: ?string, trajectoryStatusLabel: ?string, projectedThresholdPace: string, projectedGainLabel: string, benchmarkPredictions: list<array{label: string, currentFinishTimeInSeconds: int, projectedFinishTimeInSeconds: int}>, projectedThresholdPacesByWeekStartDate: array<string, string>, basisRows: list<array{label: string, value: string}>, basisNote: string}
     */
    private function buildRunningPerformancePredictionViewModel(
        ?TrainingPlan $trainingPlan,
        TrainingPlanProposal $proposal,
        array $existingSessions,
        SerializableDateTime $referenceDate,
    ): ?array {
        if (null === $trainingPlan) {
            return null;
        }

        $prediction = $this->runningPlanPerformancePredictor->predict($trainingPlan, $proposal, $existingSessions, $referenceDate);
        if (!$prediction instanceof RunningPlanPerformancePrediction) {
            return null;
        }

        $performanceMetrics = $trainingPlan->getPerformanceMetrics();
        $weeklyRunningVolume = is_array($performanceMetrics)
            && isset($performanceMetrics['weeklyRunningVolume'])
            && is_numeric($performanceMetrics['weeklyRunningVolume'])
            ? (float) $performanceMetrics['weeklyRunningVolume']
            : null;
        $runningStructure = $this->summarizeRunningStructure($proposal);

        return [
            'confidenceLabel' => $prediction->getConfidenceLabel(),
            'currentThresholdPace' => sprintf('%s/km', $this->formatPace($prediction->getCurrentThresholdPaceInSeconds())),
            'trajectoryThresholdPace' => null === $prediction->getTrajectoryThresholdPaceInSeconds()
                ? null
                : sprintf('%s/km', $this->formatPace($prediction->getTrajectoryThresholdPaceInSeconds())),
            'trajectoryGainLabel' => null === $prediction->getTrajectoryGainInSecondsPerKm()
                ? null
                : sprintf('-%ds/km', $prediction->getTrajectoryGainInSecondsPerKm()),
            'trajectoryStatusLabel' => $this->formatTrajectoryStatusLabel($prediction),
            'projectedThresholdPace' => sprintf('%s/km', $this->formatPace($prediction->getProjectedThresholdPaceInSeconds())),
            'projectedGainLabel' => sprintf('-%ds/km', $prediction->getProjectedGainInSecondsPerKm()),
            'benchmarkPredictions' => array_map(
                fn (RunningRaceBenchmarkPrediction $benchmark): array => [
                    'label' => $benchmark->getLabel(),
                    'currentFinishTimeInSeconds' => $benchmark->getCurrentFinishTimeInSeconds(),
                    'projectedFinishTimeInSeconds' => $benchmark->getProjectedFinishTimeInSeconds(),
                ],
                $prediction->getBenchmarkPredictions(),
            ),
            'projectedThresholdPacesByWeekStartDate' => array_map(
                fn (int $paceInSeconds): string => sprintf('%s/km', $this->formatPace($paceInSeconds)),
                $prediction->getProjectedThresholdPaceByWeekStartDate(),
            ),
            'basisRows' => array_values(array_filter([
                [
                    'label' => 'Baseline',
                    'value' => sprintf('%s threshold', $this->formatPace($prediction->getCurrentThresholdPaceInSeconds()).'/km'),
                ],
                null !== $weeklyRunningVolume
                    ? [
                        'label' => 'Weekly run volume',
                        'value' => $this->formatWeeklyRunningVolume($weeklyRunningVolume),
                    ]
                    : null,
                $runningStructure['effectiveRunningWeeks'] > 0
                    ? [
                        'label' => 'Planned run structure',
                        'value' => sprintf(
                            '%.1f runs/wk · %.1f key/wk · %s/wk',
                            $runningStructure['averageRunSessionsPerWeek'],
                            $runningStructure['averageKeyRunsPerWeek'],
                            $this->formatWeeklyRunningDuration($runningStructure['averageRunningMinutesPerWeek']),
                        ),
                    ]
                    : null,
                null !== $prediction->getAdherenceSnapshot()
                    ? [
                        'label' => 'Completed run work',
                        'value' => $this->formatAdherenceSummary($prediction),
                    ]
                    : null,
                [
                    'label' => 'Plan length',
                    'value' => sprintf('%dw', $proposal->getTotalWeeks()),
                ],
                [
                    'label' => 'Plan setup',
                    'value' => $this->formatTrainingPlanContext($trainingPlan),
                ],
            ])),
            'basisNote' => null === $prediction->getAdherenceSnapshot()
                ? 'Directional estimate only — this currently shows your ideal full-plan potential if you execute the planned running work well. A trajectory forecast appears once planned run sessions are linked to completed activities.'
                : 'Directional estimate only — trajectory uses completed linked run sessions scheduled before today, while projected threshold still shows your ideal full-plan potential if you execute the remaining running work well. Neither is a guaranteed race result.',
        ];
    }

    private function formatTrajectoryStatusLabel(RunningPlanPerformancePrediction $prediction): ?string
    {
        if (null === $prediction->getAdherenceSnapshot()) {
            return null;
        }

        return $this->formatAdherenceSummary($prediction);
    }

    private function formatAdherenceSummary(RunningPlanPerformancePrediction $prediction): string
    {
        $adherenceSnapshot = $prediction->getAdherenceSnapshot();
        if (null === $adherenceSnapshot) {
            return 'No completed run sessions yet';
        }

        return sprintf(
            '%d/%d runs · %d/%d key · %d/%d long · %d%% duration',
            $adherenceSnapshot->getCompletedRunSessionCount(),
            $adherenceSnapshot->getPlannedRunSessionCount(),
            $adherenceSnapshot->getCompletedKeyRunSessionCount(),
            $adherenceSnapshot->getPlannedKeyRunSessionCount(),
            $adherenceSnapshot->getCompletedLongRunCount(),
            $adherenceSnapshot->getPlannedLongRunCount(),
            (int) round($adherenceSnapshot->getRunningDurationCompletionRatio() * 100),
        );
    }

    /**
     * @return list<RaceEvent>
     */
    private function loadRacesForPlan(TrainingPlan $plan): array
    {
        $races = $this->raceEventRepository->findByDateRange(DateRange::fromDates(
            $plan->getStartDay()->setTime(0, 0),
            $plan->getEndDay()->setTime(23, 59, 59),
        ));

        if (null !== $plan->getTargetRaceEventId()) {
            $linkedRace = $this->raceEventRepository->findById($plan->getTargetRaceEventId());
            if (null !== $linkedRace) {
                $races[(string) $linkedRace->getId()] = $linkedRace;
            }
        }

        usort($races, static fn (RaceEvent $left, RaceEvent $right): int => $left->getDay() <=> $right->getDay());

        return array_values($races);
    }

    /**
     * @param list<RaceEvent> $raceEvents
     */
    private function findPrimaryPlanRace(array $raceEvents): ?RaceEvent
    {
        if ([] === $raceEvents) {
            return null;
        }

        usort($raceEvents, static function (RaceEvent $left, RaceEvent $right): int {
            $priorityRank = [
                RaceEventPriority::A->value => 0,
                RaceEventPriority::B->value => 1,
                RaceEventPriority::C->value => 2,
            ];

            $priorityComparison = ($priorityRank[$left->getPriority()->value] ?? 9) <=> ($priorityRank[$right->getPriority()->value] ?? 9);
            if (0 !== $priorityComparison) {
                return $priorityComparison;
            }

            return $right->getDay() <=> $left->getDay();
        });

        return $raceEvents[0];
    }

    private function createSyntheticTargetRace(TrainingPlan $plan, SerializableDateTime $now): RaceEvent
    {
        $profile = $this->inferPlanPreviewProfile($plan);
        $rules = RaceProfileTrainingRules::forProfile($profile);
        $syntheticRaceDay = $plan->getEndDay()->setTime(0, 0);

        if (!$this->isDevelopmentPlanPreview($plan) && $rules->getPostRaceRecoveryWeeks() > 0) {
            $candidateRaceDay = $syntheticRaceDay->modify(sprintf('-%d weeks', $rules->getPostRaceRecoveryWeeks()))->setTime(0, 0);
            if ($candidateRaceDay >= $plan->getStartDay()->setTime(0, 0)) {
                $syntheticRaceDay = $candidateRaceDay;
            }
        }

        return RaceEvent::create(
            raceEventId: \App\Domain\TrainingPlanner\RaceEventId::random(),
            day: $syntheticRaceDay,
            type: RaceEventType::fromProfile($profile),
            title: $plan->getTitle() ?: (TrainingPlanType::RACE === $plan->getType() ? 'Race plan' : 'Training plan'),
            location: null,
            notes: null,
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function isDevelopmentPlanPreview(TrainingPlan $plan, ?RaceEvent $linkedRace = null): bool
    {
        return TrainingPlanType::TRAINING === $plan->getType()
            && null === $plan->getTargetRaceEventId()
            && null === $linkedRace;
    }

    private function inferPlanPreviewProfile(TrainingPlan $plan): RaceEventProfile
    {
        if ($plan->getTargetRaceProfile() instanceof RaceEventProfile) {
            return $plan->getTargetRaceProfile();
        }

        return match ($plan->getDiscipline()) {
            TrainingPlanDiscipline::TRIATHLON => match ($plan->getTrainingFocus()) {
                TrainingFocus::SWIM => RaceEventProfile::SWIM,
                default => RaceEventProfile::OLYMPIC_TRIATHLON,
            },
            TrainingPlanDiscipline::CYCLING => RaceEventProfile::RIDE,
            TrainingPlanDiscipline::RUNNING => match ($plan->getTrainingFocus()) {
                TrainingFocus::GENERAL => RaceEventProfile::RUN,
                default => RaceEventProfile::HALF_MARATHON,
            },
            default => match ($this->inferPlanPreviewFamily($plan)) {
                RaceEventFamily::TRIATHLON => RaceEventProfile::OLYMPIC_TRIATHLON,
                RaceEventFamily::RIDE => RaceEventProfile::RIDE,
                RaceEventFamily::SWIM => RaceEventProfile::SWIM,
                default => RaceEventProfile::RUN,
            },
        };
    }

    private function inferPlanPreviewFamily(TrainingPlan $plan): RaceEventFamily
    {
        return match ($plan->getDiscipline()) {
            TrainingPlanDiscipline::TRIATHLON => RaceEventFamily::TRIATHLON,
            TrainingPlanDiscipline::CYCLING => RaceEventFamily::RIDE,
            TrainingPlanDiscipline::RUNNING => RaceEventFamily::RUN,
            default => RaceEventFamily::RUN,
        };
    }

    /**
     * @param list<RaceEvent> $upcomingRaces
     */
    private function findTargetARace(array $upcomingRaces): RaceEvent
    {
        foreach ($upcomingRaces as $race) {
            if (RaceEventPriority::A === $race->getPriority()) {
                return $race;
            }
        }

        return $upcomingRaces[0];
    }

    /**
     * @param list<RaceEvent> $raceEvents
     * @return array<string, int>
     */
    private function buildRaceEventCountdownDaysById(array $raceEvents, SerializableDateTime $now): array
    {
        $countdowns = [];
        $referenceDate = SerializableDateTime::fromString($now->format('Y-m-d 00:00:00'));

        foreach ($raceEvents as $raceEvent) {
            $countdowns[(string) $raceEvent->getId()] = (int) $referenceDate->diff($raceEvent->getDay())->format('%r%a');
        }

        return $countdowns;
    }

    /**
     * @param list<\App\Domain\TrainingPlanner\PlannedSession> $plannedSessions
     * @return array<string, null|float>
     */
    private function buildPlannedSessionEstimatesById(array $plannedSessions): array
    {
        $estimates = [];

        foreach ($plannedSessions as $session) {
            $estimates[(string) $session->getId()] = $this->plannedSessionLoadEstimator
                ->estimate($session)?->getEstimatedLoad();
        }

        return $estimates;
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

    private function formatPace(int $seconds): string
    {
        $minutes = intdiv(max(0, $seconds), 60);
        $remainingSeconds = max(0, $seconds % 60);

        return sprintf('%d:%02d', $minutes, $remainingSeconds);
    }

    private function formatWeeklyRunningVolume(float $weeklyRunningVolume): string
    {
        $formattedVolume = floor($weeklyRunningVolume) === $weeklyRunningVolume
            ? (string) (int) $weeklyRunningVolume
            : number_format($weeklyRunningVolume, 1);

        return sprintf('%s km/week', $formattedVolume);
    }

    private function formatWeeklyRunningDuration(float $weeklyRunningMinutes): string
    {
        $roundedMinutes = (int) round(max(0.0, $weeklyRunningMinutes));
        $hours = intdiv($roundedMinutes, 60);
        $minutes = $roundedMinutes % 60;

        if (0 === $hours) {
            return sprintf('%dm', $minutes);
        }

        if (0 === $minutes) {
            return sprintf('%dh', $hours);
        }

        return sprintf('%dh %02dm', $hours, $minutes);
    }

    /**
     * @return array{effectiveRunningWeeks: int, averageRunSessionsPerWeek: float, averageKeyRunsPerWeek: float, averageRunningMinutesPerWeek: float}
     */
    private function summarizeRunningStructure(TrainingPlanProposal $proposal): array
    {
        $effectiveRunningWeeks = 0;
        $runningSessionCount = 0;
        $keyRunningSessionCount = 0;
        $runningDurationInSeconds = 0;

        foreach ($proposal->getProposedBlocks() as $block) {
            foreach ($block->getWeekSkeletons() as $week) {
                $weekRunSessions = 0;

                foreach ($week->getSessions() as $session) {
                    if (ActivityType::RUN !== $session->getActivityType()) {
                        continue;
                    }

                    ++$weekRunSessions;
                    ++$runningSessionCount;
                    $runningDurationInSeconds += max(0, $session->getTargetDurationInSeconds() ?? 0);

                    if ($session->isKeySession()) {
                        ++$keyRunningSessionCount;
                    }
                }

                if ($weekRunSessions > 0) {
                    ++$effectiveRunningWeeks;
                }
            }
        }

        $divisor = max(1, $effectiveRunningWeeks);

        return [
            'effectiveRunningWeeks' => $effectiveRunningWeeks,
            'averageRunSessionsPerWeek' => $runningSessionCount / $divisor,
            'averageKeyRunsPerWeek' => $keyRunningSessionCount / $divisor,
            'averageRunningMinutesPerWeek' => ($runningDurationInSeconds / 60) / $divisor,
        ];
    }

    private function formatTrainingPlanContext(TrainingPlan $trainingPlan): string
    {
        $disciplineLabel = match ($trainingPlan->getDiscipline()) {
            TrainingPlanDiscipline::RUNNING => 'Running plan',
            TrainingPlanDiscipline::CYCLING => 'Cycling plan',
            TrainingPlanDiscipline::TRIATHLON => 'Triathlon plan',
            default => 'Training plan',
        };

        $focusLabel = match ($trainingPlan->getTrainingFocus()) {
            TrainingFocus::RUN => 'Run focus',
            TrainingFocus::BIKE => 'Bike focus',
            TrainingFocus::SWIM => 'Swim focus',
            TrainingFocus::GENERAL => 'General focus',
            null => null,
        };

        return null === $focusLabel
            ? $disciplineLabel
            : sprintf('%s · %s', $disciplineLabel, $focusLabel);
    }

    /**
     * @param list<\App\Domain\TrainingPlanner\PlannedSession> $plannedSessions
     * @return list<\App\Domain\TrainingPlanner\PlannedSession>
     */
    private function findSessionsInCurrentWeek(array $plannedSessions, SerializableDateTime $now): array
    {
        $weekStart = $now->modify('Monday this week')->setTime(0, 0);
        $weekEnd = $weekStart->modify('+6 days')->setTime(23, 59, 59);

        return array_values(array_filter(
            $plannedSessions,
            static fn (\App\Domain\TrainingPlanner\PlannedSession $session): bool => $session->getDay() >= $weekStart && $session->getDay() <= $weekEnd,
        ));
    }
}
