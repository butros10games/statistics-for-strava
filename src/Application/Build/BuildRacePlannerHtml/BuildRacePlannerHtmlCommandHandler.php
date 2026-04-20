<?php

declare(strict_types=1);

namespace App\Application\Build\BuildRacePlannerHtml;

use App\Domain\Activity\ActivityType;
use App\Domain\TrainingPlanner\AdaptivePlanningContextBuilder;
use App\Domain\TrainingPlanner\PlanGenerator\PlanAdaptationRecommendation;
use App\Domain\TrainingPlanner\PlanGenerator\PlanAdaptationRecommendationType;
use App\Domain\TrainingPlanner\PlanGenerator\PlanAdaptationRecommender;
use App\Domain\TrainingPlanner\PlanGenerator\PlanAdaptationWarningSeverity;
use App\Domain\TrainingPlanner\PlanGenerator\RaceProfileTrainingRules;
use App\Domain\TrainingPlanner\Prediction\RunningPlanPerformancePrediction;
use App\Domain\TrainingPlanner\Prediction\RunningPlanPerformancePredictor;
use App\Domain\TrainingPlanner\PlanGenerator\TrainingPlanGenerator;
use App\Domain\TrainingPlanner\Prediction\RunningRaceBenchmarkPrediction;
use App\Domain\TrainingPlanner\PlannedSessionLoadEstimator;
use App\Domain\TrainingPlanner\PlannedSessionRepository;
use App\Domain\TrainingPlanner\RaceEvent;
use App\Domain\TrainingPlanner\RaceEventFamily;
use App\Domain\TrainingPlanner\RaceEventPriority;
use App\Domain\TrainingPlanner\RaceEventProfile;
use App\Domain\TrainingPlanner\RaceEventType;
use App\Domain\TrainingPlanner\RacePlannerExistingBlockSelector;
use App\Domain\TrainingPlanner\RacePlannerConfiguration;
use App\Domain\TrainingPlanner\RacePlannerRecoveryManager;
use App\Domain\TrainingPlanner\RaceEventRepository;
use App\Domain\TrainingPlanner\TrainingFocus;
use App\Domain\TrainingPlanner\TrainingPlan;
use App\Domain\TrainingPlanner\TrainingPlanRepository;
use App\Domain\TrainingPlanner\TrainingPlanDiscipline;
use App\Domain\TrainingPlanner\TrainingPlanType;
use App\Domain\TrainingPlanner\TrainingBlock;
use App\Domain\TrainingPlanner\TrainingBlockRepository;
use App\Infrastructure\CQRS\Command\Command;
use App\Infrastructure\CQRS\Command\CommandHandler;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use League\Flysystem\FilesystemOperator;
use Twig\Environment;

final readonly class BuildRacePlannerHtmlCommandHandler implements CommandHandler
{
    private const int TRAINING_PLAN_EXPORT_VERSION = 1;

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
        private Environment $twig,
        private FilesystemOperator $buildStorage,
        private FilesystemOperator $apiStorage,
    ) {
    }

    public function handle(Command $command): void
    {
        assert($command instanceof BuildRacePlannerHtml);

        $now = $command->getCurrentDateTime();
        $plans = $this->trainingPlanRepository->findAll();
        $upcomingRaces = $this->raceEventRepository->findUpcoming($now, 20);

        if ([] === $upcomingRaces) {
            $this->buildStorage->write(
                'race-planner.html',
                $this->twig->load('html/race-planner/race-planner.html.twig')->render([
                    'hasUpcomingRaces' => false,
                    'targetRace' => null,
                    'upcomingRaces' => [],
                    'displayedUpcomingRaces' => [],
                    'rules' => null,
                    'proposal' => null,
                    'recommendations' => [],
                    'existingBlocks' => [],
                    'raceEventsById' => [],
                    'raceEventCountdownDaysById' => [],
                    'plannerRoute' => '/race-planner',
                    'plannerSupportsRaceActions' => false,
                    'isPlanPreview' => false,
                ]),
            );

            $this->writePlanPreviewPages($plans, $now);

            return;
        }

        $targetRace = $this->findTargetARace($upcomingRaces);
        $linkedTrainingPlan = $this->trainingPlanRepository->findByTargetRaceEventId($targetRace->getId());
        $raceEventsById = $this->buildRaceEventsById($upcomingRaces);
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
        $existingBlockStartDay = ($reusableExistingBlocks[0] ?? null)?->getStartDay();
        $effectivePlanStartDay = $this->resolveEffectivePlanStartDay(
            $configuredPlanStartDay,
            $targetRace,
            $now,
            $existingBlockStartDay,
        );

        $dateRange = DateRange::fromDates(
            $effectivePlanStartDay,
            $planningEndDay,
        );

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

        $readinessContext = null;

        if ([] !== $currentWeekSessions) {
            $readinessContext = $adaptivePlanningContext->getCurrentWeekReadinessContext();
        }

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

        $this->buildStorage->write(
            'race-planner.html',
            $this->twig->load('html/race-planner/race-planner.html.twig')->render([
                'hasUpcomingRaces' => true,
                'targetRace' => $targetRace,
                'upcomingRaces' => $upcomingRaces,
                'displayedUpcomingRaces' => $upcomingRaces,
                'runningPerformancePrediction' => $this->buildRunningPerformancePredictionViewModel($linkedTrainingPlan, $proposal, $predictionSessions, $now),
                'rules' => $rules,
                'proposal' => $proposal,
                'recommendations' => $recommendations,
                'existingBlocks' => $existingBlocks,
                'raceEventsById' => $raceEventsById,
                'raceEventCountdownDaysById' => $raceEventCountdownDaysById,
                'planStartDayInputValue' => $effectivePlanStartDay->format('Y-m-d'),
                'hasCustomPlanStartDay' => !$plannerUsesExistingBlocks && null !== $configuredPlanStartDay,
                'plannerUsesExistingBlocks' => $plannerUsesExistingBlocks,
                'recoverySaveSummary' => $recoverySaveSummary,
                'linkedTrainingPlan' => $linkedTrainingPlan,
                'linkedTrainingPlanNeedsSync' => $linkedTrainingPlanNeedsSync,
                'plannerRoute' => '/race-planner',
                'plannerSupportsRaceActions' => true,
                'isPlanPreview' => false,
            ]),
        );

        $this->writePlanPreviewPages($plans, $now);
    }

    /**
     * @param list<TrainingPlan> $plans
     */
    private function writePlanPreviewPages(array $plans, SerializableDateTime $now): void
    {
        foreach ($plans as $plan) {
            $context = $this->buildPlanPreviewContext($plan, $now);

            $this->buildStorage->write(
                sprintf('race-planner/plan-%s.html', $plan->getId()),
                $this->twig->load('html/race-planner/race-planner.html.twig')->render($context),
            );

            $this->apiStorage->write(
                sprintf('exports/training-plans/%s.json', $plan->getId()),
                (string) Json::encodeAndCompress($this->buildPlanPreviewExportPayload($plan, $context, $now)),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPlanPreviewContext(TrainingPlan $plan, SerializableDateTime $now): array
    {
        $plannerRoute = sprintf('/race-planner/plan-%s', $plan->getId());
        $planRaces = $this->loadRacesForPlan($plan);
        $raceEventsById = $this->buildRaceEventsById($planRaces);
        $linkedRace = null === $plan->getTargetRaceEventId()
            ? null
            : $this->raceEventRepository->findById($plan->getTargetRaceEventId());
        $targetRace = $linkedRace ?? $this->findPrimaryPlanRace($planRaces) ?? $this->createSyntheticTargetRace($plan, $now);
        $usesSyntheticTarget = null === $linkedRace && !in_array($targetRace, $planRaces, true);
        $allPlannerRaces = $planRaces;

        if ($usesSyntheticTarget) {
            $allPlannerRaces[] = $targetRace;
            $raceEventsById[(string) $targetRace->getId()] = $targetRace;
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
        $readinessContext = null;

        if ([] !== $currentWeekSessions) {
            $readinessContext = $adaptivePlanningContext->getCurrentWeekReadinessContext();
        }

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

        return [
            'hasUpcomingRaces' => true,
            'targetRace' => $targetRace,
            'upcomingRaces' => $allPlannerRaces,
            'displayedUpcomingRaces' => $displayedUpcomingRaces,
            'runningPerformancePrediction' => $this->buildRunningPerformancePredictionViewModel($plan, $proposal, $existingSessions, $now),
            'rules' => $rules,
            'proposal' => $proposal,
            'recommendations' => $recommendations,
            'existingBlocks' => $existingBlocks,
            'existingSessions' => $existingSessions,
            'plannedSessionEstimatesById' => $plannedSessionEstimatesById,
            'raceEventsById' => $raceEventsById,
            'raceEventCountdownDaysById' => $raceEventCountdownDaysById,
            'planStartDayInputValue' => $plan->getStartDay()->format('Y-m-d'),
            'hasCustomPlanStartDay' => false,
            'plannerUsesExistingBlocks' => [] !== $existingBlocks,
            'recoverySaveSummary' => $this->racePlannerRecoveryManager->summarizeFromProposal(
                $proposal,
                $existingBlocks,
                $existingSessions,
            ),
            'linkedTrainingPlan' => $plan,
            'linkedTrainingPlanNeedsSync' => false,
            'plannerRoute' => $plannerRoute,
            'plannerSupportsRaceActions' => null !== $linkedRace,
            'isPlanPreview' => true,
        ];
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function buildPlanPreviewExportPayload(TrainingPlan $plan, array $context, SerializableDateTime $now): array
    {
        $targetRace = $context['targetRace'] instanceof RaceEvent ? $context['targetRace'] : null;
        $proposal = $context['proposal'] instanceof \App\Domain\TrainingPlanner\PlanGenerator\TrainingPlanProposal ? $context['proposal'] : null;
        $recoverySaveSummary = $context['recoverySaveSummary'] instanceof \App\Domain\TrainingPlanner\RacePlannerRecoverySaveSummary
            ? $context['recoverySaveSummary']
            : null;
        $existingBlocks = array_values(array_filter(
            $context['existingBlocks'] ?? [],
            static fn (mixed $block): bool => $block instanceof TrainingBlock,
        ));
        $existingSessions = array_values(array_filter(
            $context['existingSessions'] ?? [],
            static fn (mixed $session): bool => $session instanceof \App\Domain\TrainingPlanner\PlannedSession,
        ));
        $raceEvents = array_values(array_filter(
            $context['upcomingRaces'] ?? [],
            static fn (mixed $raceEvent): bool => $raceEvent instanceof RaceEvent,
        ));
        $recommendations = array_values(array_filter(
            $context['recommendations'] ?? [],
            static fn (mixed $recommendation): bool => $recommendation instanceof PlanAdaptationRecommendation,
        ));
        $plannedSessionEstimatesById = is_array($context['plannedSessionEstimatesById'] ?? null)
            ? $context['plannedSessionEstimatesById']
            : [];
        $plannerRoute = is_string($context['plannerRoute'] ?? null)
            ? $context['plannerRoute']
            : sprintf('/race-planner/plan-%s', $plan->getId());
        $exportPath = sprintf('/api/exports/training-plans/%s.json', $plan->getId());
        $planTitle = $plan->getTitle() ?: 'Untitled training plan';

        return [
            'version' => self::TRAINING_PLAN_EXPORT_VERSION,
            'exportType' => 'training-plan',
            'generatedAt' => $now->format('Y-m-d H:i:s'),
            'usageGuide' => [
                'goal' => 'Share this JSON with an LLM and ask for a plan review, recovery risks, block sequencing improvements, and session-level changes.',
                'reviewPromptTemplate' => $this->buildTrainingPlanAnalysisPrompt(
                    $planTitle,
                    $exportPath,
                    $plannerRoute,
                ),
                'suggestedPrompts' => [
                    'Review this training plan and suggest improvements to the block order, weekly load progression, and race specificity.',
                    'Find the biggest weaknesses in this plan and suggest the smallest changes that would improve it.',
                    'Look for overload, missing recovery, weak taper timing, and mismatches between the goal race and the planned sessions.',
                    'Summarize what should stay, what should change, and what I should monitor week to week.',
                ],
            ],
            'urls' => [
                'planner' => $plannerRoute,
                'export' => $exportPath,
            ],
            'summary' => [
                'blockCount' => count($existingBlocks),
                'plannedSessionCount' => count($existingSessions),
                'raceCount' => count($raceEvents),
                'recommendationCount' => count($recommendations),
                'warningCount' => $proposal?->hasWarnings() ? count($proposal->getWarnings()) : 0,
            ],
            'plan' => [
                'id' => (string) $plan->getId(),
                'type' => $plan->getType()->value,
                'title' => $plan->getTitle(),
                'notes' => $plan->getNotes(),
                'startDay' => $plan->getStartDay()->format('Y-m-d'),
                'endDay' => $plan->getEndDay()->format('Y-m-d'),
                'durationInDays' => $plan->getDurationInDays(),
                'durationInWeeks' => $plan->getDurationInWeeks(),
                'discipline' => $plan->getDiscipline()?->value,
                'sportSchedule' => $plan->getSportSchedule(),
                'performanceMetrics' => $plan->getPerformanceMetrics(),
                'targetRaceProfile' => $plan->getTargetRaceProfile()?->value,
                'trainingFocus' => $plan->getTrainingFocus()?->value,
                'targetRaceEventId' => $plan->getTargetRaceEventId()?->__toString(),
                'createdAt' => $plan->getCreatedAt()->format('Y-m-d H:i:s'),
                'updatedAt' => $plan->getUpdatedAt()->format('Y-m-d H:i:s'),
            ],
            'targetRace' => null === $targetRace ? null : $this->mapRaceEventForExport($targetRace),
            'racesInPlanWindow' => array_map($this->mapRaceEventForExport(...), $raceEvents),
            'existingBlocks' => array_map($this->mapTrainingBlockForExport(...), $existingBlocks),
            'plannedSessions' => array_map(
                fn (\App\Domain\TrainingPlanner\PlannedSession $plannedSession): array => $this->mapPlannedSessionForExport(
                    $plannedSession,
                    isset($plannedSessionEstimatesById[(string) $plannedSession->getId()]) && is_numeric($plannedSessionEstimatesById[(string) $plannedSession->getId()])
                        ? (float) $plannedSessionEstimatesById[(string) $plannedSession->getId()]
                        : null,
                ),
                $existingSessions,
            ),
            'proposal' => null === $proposal ? null : $this->mapTrainingPlanProposalForExport($proposal),
            'recommendations' => array_map($this->mapPlanRecommendationForExport(...), $recommendations),
            'runningPerformancePrediction' => $context['runningPerformancePrediction'] ?? null,
            'recoverySaveSummary' => null === $recoverySaveSummary ? null : [
                'missingRecoveryBlockCount' => $recoverySaveSummary->getMissingRecoveryBlockCount(),
                'missingRecoverySessionCount' => $recoverySaveSummary->getMissingRecoverySessionCount(),
                'hasAnythingToSave' => $recoverySaveSummary->hasAnythingToSave(),
            ],
        ];
    }

    private function buildTrainingPlanAnalysisPrompt(string $planTitle, string $exportPath, string $plannerRoute): string
    {
        return implode("\n", [
            'Please review my training plan using this JSON export as the source of truth:',
            $exportPath,
            '',
            sprintf('Plan title: %s', $planTitle),
            sprintf('Planner view: %s', $plannerRoute),
            '',
            'What I want from you:',
            '1. Summarize what should stay exactly as it is.',
            '2. Identify the top risks, weak spots, or missing elements in the plan.',
            '3. Suggest the smallest changes that would improve block order, load progression, recovery, taper timing, and race specificity.',
            '4. Call out any weeks or sessions that look overloaded, too light, or poorly timed.',
            '5. Give me a short watch list for what to monitor week to week.',
            '',
            'Please be concrete and refer to block names, weeks, dates, and sessions from the JSON.',
            'If something already looks good, say that too.',
            'If you cannot open URLs directly, tell me and I will paste the JSON export.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapRaceEventForExport(RaceEvent $raceEvent): array
    {
        return [
            'id' => (string) $raceEvent->getId(),
            'day' => $raceEvent->getDay()->format('Y-m-d'),
            'title' => $raceEvent->getTitle(),
            'location' => $raceEvent->getLocation(),
            'type' => $raceEvent->getType()->value,
            'family' => $raceEvent->getFamily()->value,
            'profile' => $raceEvent->getProfile()->value,
            'priority' => $raceEvent->getPriority()->value,
            'targetFinishTimeInSeconds' => $raceEvent->getTargetFinishTimeInSeconds(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapTrainingBlockForExport(TrainingBlock $trainingBlock): array
    {
        return [
            'id' => (string) $trainingBlock->getId(),
            'startDay' => $trainingBlock->getStartDay()->format('Y-m-d'),
            'endDay' => $trainingBlock->getEndDay()->format('Y-m-d'),
            'durationInDays' => $trainingBlock->getDurationInDays(),
            'phase' => $trainingBlock->getPhase()->value,
            'title' => $trainingBlock->getTitle(),
            'focus' => $trainingBlock->getFocus(),
            'notes' => $trainingBlock->getNotes(),
            'targetRaceEventId' => $trainingBlock->getTargetRaceEventId()?->__toString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapPlannedSessionForExport(\App\Domain\TrainingPlanner\PlannedSession $plannedSession, ?float $estimatedLoad): array
    {
        return [
            'id' => (string) $plannedSession->getId(),
            'day' => $plannedSession->getDay()->format('Y-m-d'),
            'activityType' => $plannedSession->getActivityType()->value,
            'title' => $plannedSession->getTitle(),
            'notes' => $plannedSession->getNotes(),
            'targetLoad' => $plannedSession->getTargetLoad(),
            'estimatedLoad' => $estimatedLoad,
            'targetDurationInSeconds' => $plannedSession->getTargetDurationInSeconds(),
            'targetIntensity' => $plannedSession->getTargetIntensity()?->value,
            'templateActivityId' => $plannedSession->getTemplateActivityId()?->__toString(),
            'estimationSource' => $plannedSession->getEstimationSource()->value,
            'linkStatus' => $plannedSession->getLinkStatus()->value,
            'linkedActivityId' => $plannedSession->getLinkedActivityId()?->__toString(),
            'workoutSteps' => $plannedSession->getWorkoutSteps(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapTrainingPlanProposalForExport(\App\Domain\TrainingPlanner\PlanGenerator\TrainingPlanProposal $proposal): array
    {
        return [
            'planStartDay' => $proposal->getPlanStartDay()->format('Y-m-d'),
            'planEndDay' => $proposal->getPlanEndDay()->format('Y-m-d'),
            'totalWeeks' => $proposal->getTotalWeeks(),
            'totalProposedSessions' => $proposal->getTotalProposedSessions(),
            'warnings' => array_map($this->mapPlanWarningForExport(...), $proposal->getWarnings()),
            'proposedBlocks' => array_map($this->mapProposedTrainingBlockForExport(...), $proposal->getProposedBlocks()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapPlanWarningForExport(\App\Domain\TrainingPlanner\PlanGenerator\PlanAdaptationWarning $warning): array
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
    private function mapPlanRecommendationForExport(PlanAdaptationRecommendation $recommendation): array
    {
        return [
            'type' => $recommendation->getType()->value,
            'title' => $recommendation->getTitle(),
            'body' => $recommendation->getBody(),
            'severity' => $recommendation->getSeverity()->value,
            'suggestedBlock' => null === $recommendation->getSuggestedBlock()
                ? null
                : $this->mapProposedTrainingBlockForExport($recommendation->getSuggestedBlock()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapProposedTrainingBlockForExport(\App\Domain\TrainingPlanner\PlanGenerator\ProposedTrainingBlock $proposedTrainingBlock): array
    {
        return [
            'startDay' => $proposedTrainingBlock->getStartDay()->format('Y-m-d'),
            'endDay' => $proposedTrainingBlock->getEndDay()->format('Y-m-d'),
            'durationInWeeks' => $proposedTrainingBlock->getDurationInWeeks(),
            'phase' => $proposedTrainingBlock->getPhase()->value,
            'title' => $proposedTrainingBlock->getTitle(),
            'focus' => $proposedTrainingBlock->getFocus(),
            'targetRaceEventId' => $proposedTrainingBlock->getTargetRaceEventId()?->__toString(),
            'weekSkeletons' => array_map($this->mapProposedWeekSkeletonForExport(...), $proposedTrainingBlock->getWeekSkeletons()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapProposedWeekSkeletonForExport(\App\Domain\TrainingPlanner\PlanGenerator\ProposedWeekSkeleton $proposedWeekSkeleton): array
    {
        return [
            'weekNumber' => $proposedWeekSkeleton->getWeekNumber(),
            'startDay' => $proposedWeekSkeleton->getStartDay()->format('Y-m-d'),
            'endDay' => $proposedWeekSkeleton->getEndDay()->format('Y-m-d'),
            'sessionCount' => $proposedWeekSkeleton->getSessionCount(),
            'targetLoadMultiplier' => $proposedWeekSkeleton->getTargetLoadMultiplier(),
            'sessions' => array_map($this->mapProposedSessionForExport(...), $proposedWeekSkeleton->getSessions()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapProposedSessionForExport(\App\Domain\TrainingPlanner\PlanGenerator\ProposedSession $proposedSession): array
    {
        return [
            'day' => $proposedSession->getDay()->format('Y-m-d'),
            'activityType' => $proposedSession->getActivityType()->value,
            'targetIntensity' => $proposedSession->getTargetIntensity()->value,
            'title' => $proposedSession->getTitle(),
            'notes' => $proposedSession->getNotes(),
            'targetDurationInSeconds' => $proposedSession->getTargetDurationInSeconds(),
            'isKeySession' => $proposedSession->isKeySession(),
            'isBrickSession' => $proposedSession->isBrickSession(),
            'workoutSteps' => $proposedSession->getWorkoutSteps(),
            'workoutPreviewRows' => $proposedSession->getWorkoutPreviewRows(),
        ];
    }

    /**
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
        \App\Domain\TrainingPlanner\PlanGenerator\TrainingPlanProposal $proposal,
        array $existingSessions,
        SerializableDateTime $referenceDate,
    ): ?array
    {
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
     * @param list<RaceEvent> $raceEvents
     *
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
     *
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
    private function summarizeRunningStructure(\App\Domain\TrainingPlanner\PlanGenerator\TrainingPlanProposal $proposal): array
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
     * @param list<TrainingBlock> $blocks
     */
    private function findCurrentBlock(array $blocks, SerializableDateTime $now): ?TrainingBlock
    {
        foreach ($blocks as $block) {
            if ($block->containsDay($now)) {
                return $block;
            }
        }

        return null;
    }

    /**
     * @param list<\App\Domain\TrainingPlanner\PlannedSession> $plannedSessions
     *
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

    /**
     * @param list<RaceEvent> $raceEvents
     *
     * @return list<RaceEvent>
     */
    private function findRacesInCurrentWeek(array $raceEvents, SerializableDateTime $now): array
    {
        $weekStart = $now->modify('Monday this week')->setTime(0, 0);
        $weekEnd = $weekStart->modify('+6 days')->setTime(23, 59, 59);

        return array_values(array_filter(
            $raceEvents,
            static fn (RaceEvent $event): bool => $event->getDay() >= $weekStart && $event->getDay() <= $weekEnd,
        ));
    }

    /**
     * @param list<TrainingBlock> $blocks
     *
     * @return list<TrainingBlock>
     */
    private function findBlocksInCurrentWeek(array $blocks, SerializableDateTime $now): array
    {
        $weekStart = $now->modify('Monday this week')->setTime(0, 0);
        $weekEnd = $weekStart->modify('+6 days')->setTime(23, 59, 59);

        return array_values(array_filter(
            $blocks,
            static fn (TrainingBlock $block): bool => $block->getEndDay() >= $weekStart && $block->getStartDay() <= $weekEnd,
        ));
    }
}
