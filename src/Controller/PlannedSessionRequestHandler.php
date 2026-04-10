<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Build\BuildMonthlyStatsHtml\BuildMonthlyStatsHtml;
use App\Application\Build\BuildDashboardHtml\BuildDashboardHtml;
use App\Domain\Activity\Activity;
use App\Domain\Activity\DbalActivityRepository;
use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityType;
use App\Domain\TrainingPlanner\DbalPlannedSessionRepository;
use App\Domain\TrainingPlanner\PlannedSession;
use App\Domain\TrainingPlanner\PlannedSessionActivityMatcher;
use App\Domain\TrainingPlanner\PlannedSessionEstimationSource;
use App\Domain\TrainingPlanner\PlannedSessionForecast;
use App\Domain\TrainingPlanner\PlannedSessionForecastBuilder;
use App\Domain\TrainingPlanner\PlannedSessionId;
use App\Domain\TrainingPlanner\PlannedSessionIntensity;
use App\Domain\TrainingPlanner\PlannedSessionLinkStatus;
use App\Domain\TrainingPlanner\PlannedSessionLoadEstimator;
use App\Domain\TrainingPlanner\PlannedSessionStepConditionType;
use App\Domain\TrainingPlanner\PlannedSessionStepType;
use App\Domain\TrainingPlanner\PlannedSessionStepTargetType;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[AsController]
final readonly class PlannedSessionRequestHandler
{
    private const int TEMPLATE_ACTIVITY_SUGGESTION_LIMIT = 12;
    private const int PLANNER_OUTLOOK_HORIZON = 14;

    public function __construct(
        private DbalPlannedSessionRepository $repository,
        private DbalActivityRepository $activityRepository,
        private PlannedSessionActivityMatcher $plannedSessionActivityMatcher,
        private PlannedSessionLoadEstimator $plannedSessionLoadEstimator,
        private PlannedSessionForecastBuilder $plannedSessionForecastBuilder,
        private CommandBus $commandBus,
        private Clock $clock,
        private Environment $twig,
    ) {
    }

    #[Route(path: '/planned-session', methods: ['GET', 'POST'])]
    public function handle(Request $request): Response
    {
        if ($request->isMethod('GET')) {
            return $this->renderModal($request);
        }

        $plannedSessionId = $request->request->getString('plannedSessionId');
        $existing = '' === $plannedSessionId ? null : $this->repository->findById(PlannedSessionId::fromString($plannedSessionId));
        $now = $this->clock->getCurrentDateTimeImmutable();

        $day = SerializableDateTime::fromString($request->request->getString('day', $now->format('Y-m-d')));
        $activityType = ActivityType::from($request->request->getString('activityType', ActivityType::RUN->value));
        $title = $this->nullableString($request->request->getString('title'));
        $notes = $this->nullableString($request->request->getString('notes'));
        $targetLoad = $this->nullableFloat($request->request->getString('targetLoad'));
        $requestedTargetDurationInSeconds = $this->parseDurationInputToSeconds(
            $request->request->getString('targetDurationInMinutes'),
            $request->request->getString('targetDurationInSecondsPart'),
        );
        $targetIntensity = $this->nullableIntensity($request->request->getString('targetIntensity'));
        $templateActivityId = $this->nullableActivityId($request->request->getString('templateActivityId'));
        $workoutSteps = $this->parseWorkoutSteps($request, $activityType);
        $targetDurationInSeconds = $this->resolveTargetDurationInSeconds($requestedTargetDurationInSeconds, $workoutSteps);

        $plannedSession = PlannedSession::create(
            plannedSessionId: $existing?->getId() ?? PlannedSessionId::random(),
            day: $day,
            activityType: $activityType,
            title: $title,
            notes: $notes,
            targetLoad: $targetLoad,
            targetDurationInSeconds: $targetDurationInSeconds,
            targetIntensity: $targetIntensity,
            templateActivityId: $templateActivityId,
            workoutSteps: $workoutSteps,
            estimationSource: $this->determineEstimationSource($targetLoad, $targetDurationInSeconds, $targetIntensity, $templateActivityId),
            linkedActivityId: $existing?->getLinkedActivityId(),
            linkStatus: $existing?->getLinkStatus() ?? PlannedSessionLinkStatus::UNLINKED,
            createdAt: $existing?->getCreatedAt() ?? $now,
            updatedAt: $now,
        );

        if (($existing?->getLinkStatus() ?? PlannedSessionLinkStatus::UNLINKED) !== PlannedSessionLinkStatus::LINKED) {
            $suggestedActivity = $this->plannedSessionActivityMatcher->findSuggestedMatch($plannedSession);
            $plannedSession = null === $suggestedActivity
                ? $plannedSession->withoutLink($now)
                : $plannedSession->withSuggestedLink($suggestedActivity->getId(), $now);
        }

        $this->repository->upsert($plannedSession);

        $this->rebuildPlannerViews($now);

        return $this->createRedirectResponse($request);
    }

    #[Route(path: '/planned-session/confirm-link', methods: ['POST'])]
    public function confirmLink(Request $request): Response
    {
        $plannedSession = $this->findRequestedPlannedSession($request);
        if (null === $plannedSession) {
            return $this->createRedirectResponse($request);
        }

        $linkedActivityId = $this->nullableActivityId($request->request->getString('linkedActivityId'))
            ?? $plannedSession->getLinkedActivityId();

        if (null !== $linkedActivityId) {
            $this->repository->upsert($plannedSession->withConfirmedLink($linkedActivityId, $this->clock->getCurrentDateTimeImmutable()));
            $this->rebuildPlannerViews();
        }

        return $this->createRedirectResponse($request);
    }

    #[Route(path: '/planned-session/unlink', methods: ['POST'])]
    public function unlink(Request $request): Response
    {
        $plannedSession = $this->findRequestedPlannedSession($request);
        if (null !== $plannedSession) {
            $this->repository->upsert($plannedSession->withoutLink($this->clock->getCurrentDateTimeImmutable()));
            $this->rebuildPlannerViews();
        }

        return $this->createRedirectResponse($request);
    }

    #[Route(path: '/planned-session/delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $plannedSessionId = $request->request->getString('plannedSessionId');
        if ('' !== $plannedSessionId) {
            $this->repository->delete(PlannedSessionId::fromString($plannedSessionId));
            $this->rebuildPlannerViews();
        }

        return $this->createRedirectResponse($request);
    }

    private function rebuildPlannerViews(?SerializableDateTime $now = null): void
    {
        $now ??= $this->clock->getCurrentDateTimeImmutable();

        $this->commandBus->dispatch(new BuildDashboardHtml());
        $this->commandBus->dispatch(new BuildMonthlyStatsHtml($now));
    }

    private function createRedirectResponse(Request $request): RedirectResponse
    {
        return new RedirectResponse($this->resolveRedirectTarget($request), Response::HTTP_FOUND);
    }

    private function resolveRedirectTarget(Request $request): string
    {
        $requestedRedirectTarget = $this->sanitizeRedirectTarget(
            $request->request->getString('redirectTo', $request->query->getString('redirectTo'))
        );
        if (null !== $requestedRedirectTarget) {
            return $requestedRedirectTarget;
        }

        $referer = $request->headers->get('referer');

        return $this->sanitizeRedirectTarget(is_string($referer) ? $referer : null) ?? '/dashboard';
    }

    private function sanitizeRedirectTarget(?string $redirectTarget): ?string
    {
        $redirectTarget = null === $redirectTarget ? null : trim($redirectTarget);
        if (null === $redirectTarget || '' === $redirectTarget || str_starts_with($redirectTarget, '//')) {
            return null;
        }

        if (str_starts_with($redirectTarget, '/')) {
            return $redirectTarget;
        }

        $parsedRedirectTarget = parse_url($redirectTarget);
        if (!is_array($parsedRedirectTarget)) {
            return null;
        }

        $path = $parsedRedirectTarget['path'] ?? null;
        if (!is_string($path) || '' === $path || !str_starts_with($path, '/')) {
            return null;
        }

        $query = isset($parsedRedirectTarget['query']) && is_string($parsedRedirectTarget['query'])
            ? '?'.$parsedRedirectTarget['query']
            : '';
        $fragment = isset($parsedRedirectTarget['fragment']) && is_string($parsedRedirectTarget['fragment'])
            ? '#'.$parsedRedirectTarget['fragment']
            : '';

        return $path.$query.$fragment;
    }

    private function renderModal(Request $request): Response
    {
        $today = $this->clock->getCurrentDateTimeImmutable()->setTime(0, 0);
        $plannedSessionId = $request->query->getString('plannedSessionId');
        $defaultDay = $request->query->getString('day', $today->format('Y-m-d'));
        $plannedSession = '' === $plannedSessionId ? null : $this->repository->findById(PlannedSessionId::fromString($plannedSessionId));
        $latestPlannedSession = $this->repository->findLatest();
        [$matchedActivity, $plannedSessionMatchStatus] = $this->resolveMatchedActivity($plannedSession);
        $plannerOutlookForecast = $this->plannedSessionForecastBuilder->build($today, self::PLANNER_OUTLOOK_HORIZON);
        $templateActivities = $this->buildTemplateActivityOptions($plannedSession?->getTemplateActivityId());
        $plannedSessionEstimatedLoad = null === $plannedSession
            ? null
            : $this->plannedSessionLoadEstimator->estimate($plannedSession)?->getEstimatedLoad();

        return new Response($this->twig->render('html/dashboard/planned-session.html.twig', [
            'plannedSession' => null === $plannedSession ? null : $this->toViewRecord($plannedSession),
            'latestPlannedSession' => null === $latestPlannedSession ? null : $this->toViewRecord($latestPlannedSession),
            'plannedSessionDefaultDay' => null === $plannedSession ? $defaultDay : $plannedSession->getDay()->format('Y-m-d'),
            'plannedSessionFormDefaults' => $this->plannedSessionFormDefaults($plannedSession, $plannedSessionEstimatedLoad),
            'matchedActivity' => $matchedActivity,
            'plannedSessionMatchStatus' => $plannedSessionMatchStatus,
            'activityTypes' => ActivityType::cases(),
            'plannedSessionIntensities' => PlannedSessionIntensity::cases(),
            'plannedSessionStepTypes' => PlannedSessionStepType::cases(),
            'plannedSessionStepTargetTypes' => PlannedSessionStepTargetType::cases(),
            'plannedSessionStepConditionTypes' => PlannedSessionStepConditionType::cases(),
            'plannedSessionStepTypeLabels' => $this->buildPlannedSessionStepTypeLabels(),
            'templateActivities' => $templateActivities,
            'selectedTemplateActivity' => $this->resolveTemplateActivity($plannedSession?->getTemplateActivityId()),
            'plannedSessionEstimatedLoad' => $plannedSessionEstimatedLoad,
            'plannedSessionEstimationContext' => $this->buildPlannerEstimationContext(),
            'plannedSessionHasManualTargetLoad' => null !== $plannedSession?->getTargetLoad(),
            'plannedSessionWorkoutPreview' => $this->buildWorkoutPreviewRows($plannedSession?->getWorkoutSteps() ?? [], $plannedSession?->getActivityType() ?? ActivityType::RUN),
            'plannerOutlookForecast' => $plannerOutlookForecast,
            'plannerOutlookProjectedDayCount' => $this->countProjectedDays($plannerOutlookForecast),
            'plannerOutlookHorizon' => self::PLANNER_OUTLOOK_HORIZON,
            'redirectTo' => $this->resolveRedirectTarget($request),
        ]));
    }

    /**
     * @return array{
     *     loadPerHourByActivityType: array<string, ?float>,
     *     globalLoadPerHour: ?float,
     *     intensityMultipliers: array<string, float>,
     *     labels: array<string, string>
     * }
     */
    private function buildPlannerEstimationContext(): array
    {
        $loadPerHourByActivityType = [];
        foreach (ActivityType::cases() as $activityType) {
            $loadPerHourByActivityType[$activityType->value] = $this->plannedSessionLoadEstimator->getHistoricalLoadPerHourForActivityType($activityType);
        }

        $intensityMultipliers = [];
        foreach (PlannedSessionIntensity::cases() as $plannedSessionIntensity) {
            $intensityMultipliers[$plannedSessionIntensity->value] = $this->plannedSessionLoadEstimator->getIntensityMultiplier($plannedSessionIntensity);
        }

        return [
            'loadPerHourByActivityType' => $loadPerHourByActivityType,
            'globalLoadPerHour' => $this->plannedSessionLoadEstimator->getGlobalHistoricalLoadPerHour(),
            'intensityMultipliers' => $intensityMultipliers,
            'labels' => [
                'estimatedPrefix' => 'Est.',
                'manualTargetLoad' => PlannedSessionEstimationSource::MANUAL_TARGET_LOAD->getLabel(),
                'durationIntensity' => PlannedSessionEstimationSource::DURATION_INTENSITY->getLabel(),
                'template' => PlannedSessionEstimationSource::TEMPLATE->getLabel(),
                'durationDerived' => 'Duration derived from workout steps.',
                'durationPending' => 'Duration stays manual until every set has a time target or a calculable distance target.',
                'manualOverride' => 'Manual load override',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function buildPlannedSessionStepTypeLabels(): array
    {
        $labels = [];

        foreach (PlannedSessionStepType::cases() as $plannedSessionStepType) {
            $labels[$plannedSessionStepType->value] = $plannedSessionStepType->getLabel();
        }

        return $labels;
    }

    /**
    * @return list<array{activityId: string, activityType: string, name: string, day: string, movingTimeLabel: string, movingTimeInSeconds: int, estimatedLoad: ?float}>
     */
    private function buildTemplateActivityOptions(?ActivityId $selectedTemplateActivityId): array
    {
        $templateActivities = [];

        foreach ($this->activityRepository->findAll() as $activity) {
            $templateActivities[(string) $activity->getId()] = $this->toTemplateActivityRecord($activity);

            if (count($templateActivities) >= self::TEMPLATE_ACTIVITY_SUGGESTION_LIMIT) {
                break;
            }
        }

        if (null !== $selectedTemplateActivityId && !isset($templateActivities[(string) $selectedTemplateActivityId])) {
            try {
                $templateActivities[(string) $selectedTemplateActivityId] = $this->toTemplateActivityRecord(
                    $this->activityRepository->find($selectedTemplateActivityId)
                );
            } catch (\Throwable) {
            }
        }

        return array_values($templateActivities);
    }

    /**
    * @return array{activityId: string, activityType: string, name: string, day: string, movingTimeLabel: string, movingTimeInSeconds: int, estimatedLoad: ?float}|null
     */
    private function resolveTemplateActivity(?ActivityId $templateActivityId): ?array
    {
        if (null === $templateActivityId) {
            return null;
        }

        try {
            return $this->toTemplateActivityRecord($this->activityRepository->find($templateActivityId));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
    * @return array{activityId: string, activityType: string, name: string, day: string, movingTimeLabel: string, movingTimeInSeconds: int, estimatedLoad: ?float}
     */
    private function toTemplateActivityRecord(Activity $activity): array
    {
        return [
            'activityId' => (string) $activity->getId(),
            'activityType' => $activity->getSportType()->getActivityType()->value,
            'name' => $activity->getName(),
            'day' => $activity->getStartDate()->format('Y-m-d'),
            'movingTimeLabel' => $activity->getMovingTimeFormatted(),
            'movingTimeInSeconds' => $activity->getMovingTimeInSeconds(),
            'estimatedLoad' => $this->plannedSessionLoadEstimator->estimateActivityLoad($activity),
        ];
    }

    private function countProjectedDays(PlannedSessionForecast $forecast): int
    {
        return count(array_filter(
            $forecast->getProjectedLoads(),
            static fn (float $projectedLoad): bool => $projectedLoad > 0,
        ));
    }

    /**
    * @return list<array{itemId: string, parentBlockId: ?string, type: string, label: ?string, repetitions: int, targetType: ?string, durationInSeconds: ?int, distanceInMeters: ?int, targetPace: ?string, targetPower: ?int, targetHeartRate: ?int, recoveryAfterInSeconds: null}>
     */
    private function parseWorkoutSteps(Request $request, ActivityType $activityType): array
    {
        $payload = $request->request->all();
        $workoutSteps = $payload['workoutSteps'] ?? [];
        if (!is_array($workoutSteps)) {
            return [];
        }

        $parsedWorkoutSteps = [];
        foreach (array_values($workoutSteps) as $index => $workoutStep) {
            if (!is_array($workoutStep)) {
                continue;
            }

            $type = PlannedSessionStepType::tryFrom((string) ($workoutStep['type'] ?? PlannedSessionStepType::INTERVAL->value)) ?? PlannedSessionStepType::INTERVAL;
            $itemId = $this->nullableString(is_string($workoutStep['itemId'] ?? null) ? $workoutStep['itemId'] : null) ?? sprintf('posted-item-%d', $index);
            $parentBlockId = $this->nullableString(is_string($workoutStep['parentBlockId'] ?? null) ? $workoutStep['parentBlockId'] : null);
            $label = $this->nullableString(is_string($workoutStep['label'] ?? null) ? $workoutStep['label'] : null);
            $repetitions = $this->nullableInt(is_string($workoutStep['repetitions'] ?? null) ? $workoutStep['repetitions'] : null) ?? 1;

            if ($type->isContainer()) {
                $parsedWorkoutSteps[] = [
                    'itemId' => $itemId,
                    'parentBlockId' => null,
                    'type' => $type->value,
                    'label' => $label,
                    'repetitions' => max(1, $repetitions),
                    'targetType' => null,
                    'conditionType' => null,
                    'durationInSeconds' => null,
                    'distanceInMeters' => null,
                    'targetPace' => null,
                    'targetPower' => null,
                    'targetHeartRate' => null,
                    'recoveryAfterInSeconds' => null,
                ];

                continue;
            }

            $durationInSeconds = $this->parseDurationInputToSeconds(
                is_string($workoutStep['durationInMinutes'] ?? null) ? $workoutStep['durationInMinutes'] : null,
                is_string($workoutStep['durationInSecondsPart'] ?? null) ? $workoutStep['durationInSecondsPart'] : null,
            );
            $distanceInMeters = $this->nullableInt(is_string($workoutStep['distanceInMeters'] ?? null) ? $workoutStep['distanceInMeters'] : null);
            $targetHeartRate = $this->nullableInt(is_string($workoutStep['targetHeartRate'] ?? null) ? $workoutStep['targetHeartRate'] : null);
            $targetPace = $this->nullableString(is_string($workoutStep['targetPace'] ?? null) ? $workoutStep['targetPace'] : null);
            $targetPower = $this->nullableInt(is_string($workoutStep['targetPower'] ?? null) ? $workoutStep['targetPower'] : null);
            [$targetPace, $targetPower] = $this->normalizeWorkoutStepEffortTargets($activityType, $targetPace, $targetPower);
            $targetType = PlannedSessionStepTargetType::tryFrom((string) ($workoutStep['targetType'] ?? ''));
            $targetType ??= $this->inferWorkoutStepTargetType($durationInSeconds, $distanceInMeters, $targetHeartRate);
            $conditionType = PlannedSessionStepConditionType::tryFrom((string) ($workoutStep['conditionType'] ?? ''));
            $conditionType ??= $this->inferWorkoutStepConditionType($targetType);

            if (!$this->isValidWorkoutStepTarget($targetType, $conditionType, $durationInSeconds, $distanceInMeters, $targetHeartRate)) {
                continue;
            }

            $parsedWorkoutSteps[] = [
                'itemId' => $itemId,
                'parentBlockId' => $parentBlockId,
                'type' => $type->value,
                'label' => $label,
                'repetitions' => max(1, $repetitions),
                'targetType' => $targetType?->value,
                'conditionType' => $conditionType?->value,
                'durationInSeconds' => $durationInSeconds,
                'distanceInMeters' => null === $distanceInMeters ? null : max(0, $distanceInMeters),
                'targetPace' => $targetPace,
                'targetPower' => null === $targetPower ? null : max(0, $targetPower),
                'targetHeartRate' => null === $targetHeartRate ? null : max(0, $targetHeartRate),
                'recoveryAfterInSeconds' => null,
            ];
        }

        $validBlockIds = [];
        foreach ($parsedWorkoutSteps as $parsedWorkoutStep) {
            if ($parsedWorkoutStep['type'] === PlannedSessionStepType::REPEAT_BLOCK->value) {
                $validBlockIds[$parsedWorkoutStep['itemId']] = true;
            }
        }

        foreach ($parsedWorkoutSteps as $index => $parsedWorkoutStep) {
            if (null !== $parsedWorkoutStep['parentBlockId'] && !isset($validBlockIds[$parsedWorkoutStep['parentBlockId']])) {
                $parsedWorkoutSteps[$index]['parentBlockId'] = null;
            }
        }

        return $parsedWorkoutSteps;
    }

    /**
     * @param list<array{itemId: string, parentBlockId: ?string, type: string, label: ?string, repetitions: int, targetType: ?string, durationInSeconds: ?int, distanceInMeters: ?int, targetPace: ?string, targetHeartRate: ?int, recoveryAfterInSeconds: ?int}> $workoutSteps
     */
    private function resolveTargetDurationInSeconds(?int $requestedTargetDurationInSeconds, array $workoutSteps): ?int
    {
        if ([] === $workoutSteps) {
            return $requestedTargetDurationInSeconds;
        }

        $calculatedDurationInSeconds = $this->calculateWorkoutSequenceDuration($workoutSteps);

        return null === $calculatedDurationInSeconds
            ? $requestedTargetDurationInSeconds
            : $calculatedDurationInSeconds;
    }

    /**
     * @param list<array{itemId: string, parentBlockId: ?string, type: string, label: ?string, repetitions: int, targetType: ?string, durationInSeconds: ?int, distanceInMeters: ?int, targetPace: ?string, targetPower: ?int, targetHeartRate: ?int, recoveryAfterInSeconds: ?int}> $workoutSteps
     *
     * @return list<array{headline: string, meta: string, depth: int}>
     */
    private function buildWorkoutPreviewRows(array $workoutSteps, ActivityType $activityType): array
    {
        $previewRows = [];

        $this->appendWorkoutPreviewRows($previewRows, $workoutSteps, $activityType);

        return $previewRows;
    }

    /**
     * @param list<array{itemId: string, parentBlockId: ?string, type: string, label: ?string, repetitions: int, targetType: ?string, durationInSeconds: ?int, distanceInMeters: ?int, targetPace: ?string, targetPower: ?int, targetHeartRate: ?int, recoveryAfterInSeconds: ?int}> $workoutSteps
     */
    private function appendWorkoutPreviewRows(array &$previewRows, array $workoutSteps, ActivityType $activityType, ?string $parentBlockId = null, int $depth = 0): void
    {
        foreach ($workoutSteps as $workoutStep) {
            if (($workoutStep['parentBlockId'] ?? null) !== $parentBlockId) {
                continue;
            }

            $type = PlannedSessionStepType::tryFrom($workoutStep['type']) ?? PlannedSessionStepType::INTERVAL;
            if ($type->isContainer()) {
                $previewRows[] = [
                    'headline' => null === $workoutStep['label'] ? $type->getLabel() : $type->getLabel().' · '.$workoutStep['label'],
                    'meta' => sprintf('%d repeats', max(1, $workoutStep['repetitions'])),
                    'depth' => $depth,
                ];

                $this->appendWorkoutPreviewRows($previewRows, $workoutSteps, $activityType, $workoutStep['itemId'], $depth + 1);

                continue;
            }

            $headline = null === $workoutStep['label'] ? $type->getLabel() : $type->getLabel().' · '.$workoutStep['label'];
            $meta = array_values(array_filter([
                $workoutStep['repetitions'] > 1 ? sprintf('%dx', $workoutStep['repetitions']) : null,
                $this->formatWorkoutTargetLabel($workoutStep, $activityType),
            ]));

            $previewRows[] = [
                'headline' => $headline,
                'meta' => implode(' · ', $meta),
                'depth' => $depth,
            ];
        }
    }

    /**
    * @param list<array{itemId: string, parentBlockId: ?string, type: string, label: ?string, repetitions: int, targetType: ?string, durationInSeconds: ?int, distanceInMeters: ?int, targetPace: ?string, targetPower: ?int, targetHeartRate: ?int, recoveryAfterInSeconds: ?int}> $workoutSteps
     */
    private function calculateWorkoutSequenceDuration(array $workoutSteps, ?string $parentBlockId = null): ?int
    {
        $totalDurationInSeconds = 0;

        foreach ($workoutSteps as $workoutStep) {
            if (($workoutStep['parentBlockId'] ?? null) !== $parentBlockId) {
                continue;
            }

            $type = PlannedSessionStepType::tryFrom($workoutStep['type']) ?? PlannedSessionStepType::INTERVAL;
            if ($type->isContainer()) {
                $childDurationInSeconds = $this->calculateWorkoutSequenceDuration($workoutSteps, $workoutStep['itemId']);
                if (null === $childDurationInSeconds) {
                    return null;
                }

                $totalDurationInSeconds += max(1, $workoutStep['repetitions']) * $childDurationInSeconds;

                continue;
            }

            $estimatedStepDurationInSeconds = $this->estimateWorkoutStepDurationInSeconds($workoutStep);
            if (null === $estimatedStepDurationInSeconds) {
                return null;
            }

            $totalDurationInSeconds += (max(1, $workoutStep['repetitions']) * $estimatedStepDurationInSeconds)
                + (max(0, $workoutStep['repetitions'] - 1) * ($workoutStep['recoveryAfterInSeconds'] ?? 0));
        }

        return $totalDurationInSeconds;
    }

    /**
    * @param array{targetType: ?string, conditionType: ?string, durationInSeconds: ?int, distanceInMeters: ?int, targetPace: ?string, targetPower: ?int, targetHeartRate: ?int} $workoutStep
     */
    private function estimateWorkoutStepDurationInSeconds(array $workoutStep): ?int
    {
        $targetType = PlannedSessionStepTargetType::tryFrom((string) ($workoutStep['targetType'] ?? ''));
        if (PlannedSessionStepTargetType::HEART_RATE === $targetType) {
            return null !== $workoutStep['durationInSeconds'] && null !== $workoutStep['targetHeartRate']
                ? $workoutStep['durationInSeconds']
                : null;
        }

        if (null !== $workoutStep['durationInSeconds'] && $workoutStep['durationInSeconds'] > 0) {
            return $workoutStep['durationInSeconds'];
        }

        if (null === $workoutStep['distanceInMeters'] || $workoutStep['distanceInMeters'] <= 0) {
            return null;
        }

        $secondsPerMeter = $this->parsePaceSecondsPerMeter($workoutStep['targetPace']);
        if (null === $secondsPerMeter) {
            return null;
        }

        return (int) round($secondsPerMeter * $workoutStep['distanceInMeters']);
    }

    /**
     * @param array{targetType: ?string, conditionType: ?string, durationInSeconds: ?int, distanceInMeters: ?int, targetPace: ?string, targetPower: ?int, targetHeartRate: ?int} $workoutStep
     */
    private function formatWorkoutTargetLabel(array $workoutStep, ActivityType $activityType = ActivityType::RUN): string
    {
        $targetType = PlannedSessionStepTargetType::tryFrom((string) ($workoutStep['targetType'] ?? ''));
        $conditionType = PlannedSessionStepConditionType::tryFrom((string) ($workoutStep['conditionType'] ?? ''));
        $effortLabel = $this->formatWorkoutEffortLabel($activityType, $workoutStep);

        if (PlannedSessionStepTargetType::HEART_RATE === $targetType) {
            return match ($conditionType ?? PlannedSessionStepConditionType::HOLD_TARGET) {
                PlannedSessionStepConditionType::UNTIL_BELOW => null === $workoutStep['targetHeartRate']
                    ? 'until recovered'
                    : sprintf('until < %s bpm', $workoutStep['targetHeartRate']),
                PlannedSessionStepConditionType::UNTIL_ABOVE => null === $workoutStep['targetHeartRate']
                    ? 'until above threshold'
                    : sprintf('until > %s bpm', $workoutStep['targetHeartRate']),
                PlannedSessionStepConditionType::LAP_BUTTON => 'until button press',
                PlannedSessionStepConditionType::HOLD_TARGET => null === $workoutStep['targetHeartRate']
                    ? (null === $workoutStep['durationInSeconds'] ? 'heart-rate set' : $this->formatDurationLabel($workoutStep['durationInSeconds']))
                    : sprintf('%s @ %s bpm', null === $workoutStep['durationInSeconds'] ? 'Heart rate' : $this->formatDurationLabel($workoutStep['durationInSeconds']), $workoutStep['targetHeartRate']),
            };
        }

        if (null !== $workoutStep['distanceInMeters'] && $workoutStep['distanceInMeters'] > 0) {
            $targetLabel = sprintf('%dm', $workoutStep['distanceInMeters']);

            return null === $effortLabel ? $targetLabel : $targetLabel.' @ '.$effortLabel;
        }

        $durationLabel = $this->formatDurationLabel((int) ($workoutStep['durationInSeconds'] ?? 0));

        if (PlannedSessionStepConditionType::LAP_BUTTON === $conditionType) {
            return null === $effortLabel
                ? sprintf('%s or until button press', $durationLabel)
                : sprintf('%s @ %s or until button press', $durationLabel, $effortLabel);
        }

        return null === $effortLabel ? $durationLabel : sprintf('%s @ %s', $durationLabel, $effortLabel);
    }

    /**
     * @param array{targetPace: ?string, targetPower: ?int} $workoutStep
     */
    private function formatWorkoutEffortLabel(ActivityType $activityType, array $workoutStep): ?string
    {
        if (ActivityType::RIDE === $activityType) {
            if (null !== ($workoutStep['targetPower'] ?? null) && $workoutStep['targetPower'] > 0) {
                return sprintf('%d W', $workoutStep['targetPower']);
            }

            return $workoutStep['targetPace'] ?? null;
        }

        if (null !== ($workoutStep['targetPace'] ?? null)) {
            return $workoutStep['targetPace'];
        }

        return null !== ($workoutStep['targetPower'] ?? null) && $workoutStep['targetPower'] > 0
            ? sprintf('%d W', $workoutStep['targetPower'])
            : null;
    }

    /**
     * @return array{0: ?string, 1: ?int}
     */
    private function normalizeWorkoutStepEffortTargets(ActivityType $activityType, ?string $targetPace, ?int $targetPower): array
    {
        if (ActivityType::RIDE === $activityType) {
            if (null !== $targetPower && $targetPower > 0) {
                return [null, $targetPower];
            }

            return [$targetPace, null];
        }

        return [$targetPace, null];
    }

    private function parsePaceSecondsPerMeter(?string $targetPace): ?float
    {
        if (null === $targetPace) {
            return null;
        }

        if (!preg_match('/^\s*(\d+):(\d{2})(?:\s*\/\s*(km|mi))?\s*$/i', $targetPace, $matches)) {
            return null;
        }

        $seconds = ((int) $matches[1] * 60) + (int) $matches[2];
        $unit = strtolower($matches[3] ?? 'km');
        $meters = 'mi' === $unit ? 1609.344 : 1000.0;

        return $seconds / $meters;
    }

    private function inferWorkoutStepTargetType(?int $durationInSeconds, ?int $distanceInMeters, ?int $targetHeartRate): ?PlannedSessionStepTargetType
    {
        return match (true) {
            null !== $targetHeartRate => PlannedSessionStepTargetType::HEART_RATE,
            null !== $distanceInMeters => PlannedSessionStepTargetType::DISTANCE,
            null !== $durationInSeconds => PlannedSessionStepTargetType::TIME,
            default => null,
        };
    }

    private function inferWorkoutStepConditionType(?PlannedSessionStepTargetType $targetType): ?PlannedSessionStepConditionType
    {
        return PlannedSessionStepTargetType::HEART_RATE === $targetType
            ? PlannedSessionStepConditionType::HOLD_TARGET
            : null;
    }

    private function isValidWorkoutStepTarget(?PlannedSessionStepTargetType $targetType, ?PlannedSessionStepConditionType $conditionType, ?int $durationInSeconds, ?int $distanceInMeters, ?int $targetHeartRate): bool
    {
        return match ($targetType) {
            PlannedSessionStepTargetType::TIME => match ($conditionType) {
                null, PlannedSessionStepConditionType::HOLD_TARGET, PlannedSessionStepConditionType::LAP_BUTTON => null !== $durationInSeconds && $durationInSeconds > 0,
                default => false,
            },
            PlannedSessionStepTargetType::DISTANCE => null !== $distanceInMeters && $distanceInMeters > 0,
            PlannedSessionStepTargetType::HEART_RATE => match ($conditionType ?? PlannedSessionStepConditionType::HOLD_TARGET) {
                PlannedSessionStepConditionType::HOLD_TARGET => null !== $durationInSeconds && $durationInSeconds > 0 && null !== $targetHeartRate && $targetHeartRate > 0,
                PlannedSessionStepConditionType::UNTIL_BELOW, PlannedSessionStepConditionType::UNTIL_ABOVE => null !== $targetHeartRate && $targetHeartRate > 0,
                PlannedSessionStepConditionType::LAP_BUTTON => true,
            },
            null => false,
        };
    }

    private function parseDurationInputToSeconds(?string $minutesValue, ?string $secondsValue): ?int
    {
        $minutes = $this->nullableInt($minutesValue);
        $seconds = $this->nullableInt($secondsValue);

        if (null === $minutes && null === $seconds) {
            return null;
        }

        return (($minutes ?? 0) * 60) + ($seconds ?? 0);
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function splitDurationInMinutesAndSeconds(?int $durationInSeconds): array
    {
        if (null === $durationInSeconds) {
            return [null, null];
        }

        return [intdiv($durationInSeconds, 60), $durationInSeconds % 60];
    }

    private function formatDurationLabel(int $seconds): string
    {
        if ($seconds % 60 === 0) {
            return sprintf('%d min', (int) round($seconds / 60));
        }

        $minutes = intdiv($seconds, 60);
        $remainingSeconds = $seconds % 60;

        return sprintf('%d:%02d', $minutes, $remainingSeconds);
    }

    private function findRequestedPlannedSession(Request $request): ?PlannedSession
    {
        $plannedSessionId = $request->request->getString('plannedSessionId');
        if ('' === $plannedSessionId) {
            return null;
        }

        return $this->repository->findById(PlannedSessionId::fromString($plannedSessionId));
    }

    /**
     * @return array{0: array{activityId: string, name: string, activityType: string, movingTime: int}|null, 1: ?string}
     */
    private function resolveMatchedActivity(?PlannedSession $plannedSession): array
    {
        if (null === $plannedSession) {
            return [null, null];
        }

        $matchStatus = null;
        $matchedActivity = null;

        $linkedActivityId = $plannedSession->getLinkedActivityId();
        if (null !== $linkedActivityId) {
            try {
                $matchedActivity = $this->activityRepository->find($linkedActivityId);
                $matchStatus = $plannedSession->getLinkStatus()->value;
            } catch (\Throwable) {
                $matchedActivity = null;
            }
        }

        if (null === $matchedActivity && PlannedSessionLinkStatus::LINKED !== $plannedSession->getLinkStatus()) {
            $matchedActivity = $this->plannedSessionActivityMatcher->findSuggestedMatch($plannedSession);
            $matchStatus = null === $matchedActivity ? null : PlannedSessionLinkStatus::SUGGESTED->value;
        }

        if (null === $matchedActivity) {
            return [null, null];
        }

        return [[
            'activityId' => (string) $matchedActivity->getId(),
            'name' => $matchedActivity->getName(),
            'activityType' => $matchedActivity->getSportType()->getActivityType()->value,
            'movingTime' => $matchedActivity->getMovingTimeInSeconds(),
        ], $matchStatus];
    }

    private function determineEstimationSource(?float $targetLoad, ?int $targetDurationInSeconds, ?PlannedSessionIntensity $targetIntensity, ?ActivityId $templateActivityId): PlannedSessionEstimationSource
    {
        return match (true) {
            null !== $templateActivityId => PlannedSessionEstimationSource::TEMPLATE,
            null !== $targetLoad => PlannedSessionEstimationSource::MANUAL_TARGET_LOAD,
            null !== $targetDurationInSeconds && null !== $targetIntensity => PlannedSessionEstimationSource::DURATION_INTENSITY,
            default => PlannedSessionEstimationSource::UNKNOWN,
        };
    }

    private function nullableString(?string $value): ?string
    {
        $value = null === $value ? null : trim($value);

        return '' === $value ? null : $value;
    }

    private function nullableFloat(?string $value): ?float
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        return (float) $value;
    }

    private function nullableInt(?string $value): ?int
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        return max(0, (int) $value);
    }

    private function nullableIntensity(?string $value): ?PlannedSessionIntensity
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        return PlannedSessionIntensity::from($value);
    }

    private function nullableActivityId(?string $value): ?ActivityId
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        $value = trim($value);

        return str_starts_with($value, ActivityId::getPrefix())
            ? ActivityId::fromString($value)
            : ActivityId::fromUnprefixed($value);
    }

    /**
     * @return array{
     *     plannedSessionId: string,
     *     day: string,
     *     activityType: string,
     *     title: ?string,
     *     notes: ?string,
     *     targetLoad: ?float,
     *     targetDurationInMinutes: ?int,
     *     targetIntensity: ?string,
     *     templateActivityId: ?string,
    *     workoutSteps: list<array{itemId: string, parentBlockId: ?string, type: string, label: string, repetitions: string, targetType: string, conditionType: string, durationInMinutes: string, distanceInMeters: string, targetPace: string, targetPower: string, targetHeartRate: string, recoveryAfterInSeconds: string}>,
     *     estimationSource: string,
     *     linkedActivityId: ?string,
     *     linkStatus: string
     * }
     */
    private function toViewRecord(PlannedSession $plannedSession): array
    {
        [$targetDurationInMinutes, $targetDurationInSecondsPart] = $this->splitDurationInMinutesAndSeconds($plannedSession->getTargetDurationInSeconds());

        return [
            'plannedSessionId' => (string) $plannedSession->getId(),
            'day' => $plannedSession->getDay()->format('Y-m-d'),
            'activityType' => $plannedSession->getActivityType()->value,
            'title' => $plannedSession->getTitle(),
            'notes' => $plannedSession->getNotes(),
            'targetLoad' => $plannedSession->getTargetLoad(),
            'targetDurationInMinutes' => $targetDurationInMinutes,
            'targetDurationInSecondsPart' => $targetDurationInSecondsPart,
            'targetIntensity' => $plannedSession->getTargetIntensity()?->value,
            'templateActivityId' => $plannedSession->getTemplateActivityId()?->__toString(),
            'workoutSteps' => array_map(function (array $workoutStep): array {
                [$durationInMinutes, $durationInSecondsPart] = $this->splitDurationInMinutesAndSeconds($workoutStep['durationInSeconds'] ?? null);

                return [
                    'itemId' => $workoutStep['itemId'],
                    'parentBlockId' => $workoutStep['parentBlockId'],
                    'type' => $workoutStep['type'],
                    'label' => $workoutStep['label'] ?? '',
                    'repetitions' => (string) $workoutStep['repetitions'],
                    'targetType' => $workoutStep['targetType'] ?? PlannedSessionStepTargetType::TIME->value,
                    'conditionType' => $workoutStep['conditionType'] ?? '',
                    'durationInMinutes' => null === $durationInMinutes ? '' : (string) $durationInMinutes,
                    'durationInSecondsPart' => null === $durationInSecondsPart ? '' : (string) $durationInSecondsPart,
                    'distanceInMeters' => null === ($workoutStep['distanceInMeters'] ?? null) ? '' : (string) $workoutStep['distanceInMeters'],
                    'targetPace' => $workoutStep['targetPace'] ?? '',
                    'targetPower' => null === ($workoutStep['targetPower'] ?? null) ? '' : (string) $workoutStep['targetPower'],
                    'targetHeartRate' => null === ($workoutStep['targetHeartRate'] ?? null) ? '' : (string) $workoutStep['targetHeartRate'],
                    'recoveryAfterInSeconds' => null === $workoutStep['recoveryAfterInSeconds'] ? '' : (string) $workoutStep['recoveryAfterInSeconds'],
                ];
            }, $plannedSession->getWorkoutSteps()),
            'estimationSource' => $plannedSession->getEstimationSource()->getLabel(),
            'linkedActivityId' => $plannedSession->getLinkedActivityId()?->__toString(),
            'linkStatus' => $plannedSession->getLinkStatus()->getLabel(),
        ];
    }

    /**
    * @return array{title: string, activityType: string, targetLoad: ?float, targetDurationInMinutes: ?int, targetDurationInSecondsPart: ?int, targetIntensity: ?string, templateActivityId: ?string, workoutItems: list<array{itemId: string, parentBlockId: ?string, type: string, label: string, repetitions: string, targetType: string, conditionType: string, durationInMinutes: string, distanceInMeters: string, targetPace: string, targetPower: string, targetHeartRate: string, children: array<int, array<string, mixed>>}>, notes: ?string}
     */
    private function plannedSessionFormDefaults(?PlannedSession $plannedSession, ?float $estimatedTargetLoad = null): array
    {
        if (null !== $plannedSession) {
            [$targetDurationInMinutes, $targetDurationInSecondsPart] = $this->splitDurationInMinutesAndSeconds($plannedSession->getTargetDurationInSeconds());
            $workoutSteps = array_map(function (array $workoutStep): array {
                [$durationInMinutes, $durationInSecondsPart] = $this->splitDurationInMinutesAndSeconds($workoutStep['durationInSeconds'] ?? null);

                return [
                    'itemId' => $workoutStep['itemId'],
                    'parentBlockId' => $workoutStep['parentBlockId'],
                    'type' => $workoutStep['type'],
                    'label' => $workoutStep['label'] ?? '',
                    'repetitions' => (string) $workoutStep['repetitions'],
                    'targetType' => $workoutStep['targetType'] ?? PlannedSessionStepTargetType::TIME->value,
                    'conditionType' => $workoutStep['conditionType'] ?? '',
                    'durationInMinutes' => null === $durationInMinutes ? '' : (string) $durationInMinutes,
                    'durationInSecondsPart' => null === $durationInSecondsPart ? '' : (string) $durationInSecondsPart,
                    'distanceInMeters' => null === ($workoutStep['distanceInMeters'] ?? null) ? '' : (string) $workoutStep['distanceInMeters'],
                    'targetPace' => $workoutStep['targetPace'] ?? '',
                    'targetPower' => null === ($workoutStep['targetPower'] ?? null) ? '' : (string) $workoutStep['targetPower'],
                    'targetHeartRate' => null === ($workoutStep['targetHeartRate'] ?? null) ? '' : (string) $workoutStep['targetHeartRate'],
                    'recoveryAfterInSeconds' => null === $workoutStep['recoveryAfterInSeconds'] ? '' : (string) $workoutStep['recoveryAfterInSeconds'],
                ];
            }, $plannedSession->getWorkoutSteps());

            return [
                'title' => $plannedSession->getTitle() ?? '',
                'activityType' => $plannedSession->getActivityType()->value,
                'targetLoad' => $plannedSession->getTargetLoad() ?? $estimatedTargetLoad,
                'targetDurationInMinutes' => $targetDurationInMinutes,
                'targetDurationInSecondsPart' => $targetDurationInSecondsPart,
                'targetIntensity' => $plannedSession->getTargetIntensity()?->value,
                'templateActivityId' => $plannedSession->getTemplateActivityId()?->__toString(),
                'workoutItems' => $this->buildWorkoutItemTree($this->padWorkoutStepsForForm($workoutSteps)),
                'notes' => $plannedSession->getNotes() ?? '',
            ];
        }

        return [
            'title' => '',
            'activityType' => ActivityType::RUN->value,
            'targetLoad' => null,
            'targetDurationInMinutes' => null,
            'targetDurationInSecondsPart' => null,
            'targetIntensity' => null,
            'templateActivityId' => null,
            'workoutItems' => $this->buildWorkoutItemTree($this->padWorkoutStepsForForm([])),
            'notes' => '',
        ];
    }

    /**
    * @param list<array{itemId: string, parentBlockId: ?string, type: string, label: string, repetitions: string, targetType: string, conditionType: string, durationInMinutes: string, distanceInMeters: string, targetPace: string, targetPower: string, targetHeartRate: string, recoveryAfterInSeconds: string}> $workoutSteps
     *
    * @return list<array{itemId: string, parentBlockId: ?string, type: string, label: string, repetitions: string, targetType: string, conditionType: string, durationInMinutes: string, distanceInMeters: string, targetPace: string, targetPower: string, targetHeartRate: string, recoveryAfterInSeconds: string}>
     */
    private function padWorkoutStepsForForm(array $workoutSteps): array
    {
        if ([] === $workoutSteps) {
            $workoutSteps[] = [
                'itemId' => 'workout-item-default-step',
                'parentBlockId' => null,
                'type' => PlannedSessionStepType::INTERVAL->value,
                'label' => '',
                'repetitions' => '1',
                'targetType' => PlannedSessionStepTargetType::TIME->value,
                'conditionType' => '',
                'durationInMinutes' => '',
                'durationInSecondsPart' => '',
                'distanceInMeters' => '',
                'targetPace' => '',
                'targetPower' => '',
                'targetHeartRate' => '',
                'recoveryAfterInSeconds' => '',
            ];
        }

        return $workoutSteps;
    }

    /**
    * @param list<array{itemId: string, parentBlockId: ?string, type: string, label: string, repetitions: string, targetType: string, conditionType: string, durationInMinutes: string, distanceInMeters: string, targetPace: string, targetPower: string, targetHeartRate: string, recoveryAfterInSeconds: string}> $workoutSteps
     *
     * @return list<array{itemId: string, parentBlockId: ?string, type: string, label: string, repetitions: string, targetType: string, durationInMinutes: string, distanceInMeters: string, targetPace: string, targetPower: string, targetHeartRate: string, recoveryAfterInSeconds: string, children: array<int, array<string, mixed>>}>
     */
    private function buildWorkoutItemTree(array $workoutSteps): array
    {
        $itemsByParent = [];
        foreach ($workoutSteps as $index => $workoutStep) {
            $workoutStep['formIndex'] = (string) $index;
            $itemsByParent[$workoutStep['parentBlockId'] ?? ''][] = $workoutStep;
        }

        $build = function (?string $parentBlockId) use (&$build, $itemsByParent): array {
            $items = $itemsByParent[$parentBlockId ?? ''] ?? [];

            return array_map(function (array $workoutStep) use (&$build): array {
                $workoutStep['children'] = PlannedSessionStepType::REPEAT_BLOCK->value === $workoutStep['type']
                    ? $build($workoutStep['itemId'])
                    : [];

                return $workoutStep;
            }, $items);
        };

        return $build(null);
    }
}
