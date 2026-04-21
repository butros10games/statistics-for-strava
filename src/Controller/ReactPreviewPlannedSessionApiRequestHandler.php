<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Activity\Activity;
use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityType;
use App\Domain\Activity\DbalActivityRepository;
use App\Domain\TrainingPlanner\DbalPlannedSessionRepository;
use App\Domain\TrainingPlanner\DbalTrainingSessionRepository;
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
use App\Domain\TrainingPlanner\PlannedSessionStepTargetType;
use App\Domain\TrainingPlanner\PlannedSessionStepType;
use App\Domain\TrainingPlanner\TrainingSession;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsController]
final readonly class ReactPreviewPlannedSessionApiRequestHandler
{
    private const int TEMPLATE_ACTIVITY_SUGGESTION_LIMIT = 12;
    private const int TRAINING_SESSION_RECOMMENDATION_LIMIT = 6;
    private const int PLANNER_OUTLOOK_HORIZON = 14;

    public function __construct(
        private CurrentAppUser $currentAppUser,
        private DbalPlannedSessionRepository $repository,
        private DbalTrainingSessionRepository $trainingSessionRepository,
        private DbalActivityRepository $activityRepository,
        private PlannedSessionActivityMatcher $plannedSessionActivityMatcher,
        private PlannedSessionLoadEstimator $plannedSessionLoadEstimator,
        private PlannedSessionForecastBuilder $plannedSessionForecastBuilder,
        private PlannedSessionRequestHandler $plannedSessionRequestHandler,
        private Clock $clock,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/react-preview/api/planned-session', methods: ['GET'], priority: 7)]
    public function handle(Request $request): JsonResponse
    {
        $this->currentAppUser->require();

        $today = $this->clock->getCurrentDateTimeImmutable()->setTime(0, 0);
        $plannedSessionId = trim($request->query->getString('plannedSessionId'));
        $requestedDay = trim($request->query->getString('day', $today->format('Y-m-d')));
        $plannedSession = '' === $plannedSessionId ? null : $this->repository->findById(PlannedSessionId::fromString($plannedSessionId));
        $defaultDay = null === $plannedSession ? SerializableDateTime::fromString($requestedDay) : $plannedSession->getDay();
        $latestPlannedSession = $this->repository->findLatest();
        [$matchedActivity, $matchStatus] = $this->resolveMatchedActivity($plannedSession);
        $plannerOutlookForecast = $this->plannedSessionForecastBuilder->build($today, self::PLANNER_OUTLOOK_HORIZON);
        $loadEstimate = null === $plannedSession ? null : $this->plannedSessionLoadEstimator->estimate($plannedSession);
        $estimatedLoad = $loadEstimate?->getEstimatedLoad();
        $estimatedSourceLabel = $loadEstimate?->getEstimationSource()->getLabel();

        return new JsonResponse([
            'requestedAt' => $today->format(DATE_ATOM),
            'mode' => $plannedSession instanceof PlannedSession ? 'edit' : 'create',
            'legacyPath' => $this->buildLegacyPath($plannedSession, $defaultDay),
            'context' => [
                'plannedSession' => $plannedSession ? $this->serializeContextPlannedSession($plannedSession) : null,
                'latestPlannedSession' => $latestPlannedSession ? $this->serializeContextPlannedSession($latestPlannedSession) : null,
                'matchedActivity' => $matchedActivity,
                'matchStatus' => $matchStatus,
            ],
            'defaults' => $this->buildDefaults($plannedSession, $defaultDay),
            'estimatedLoad' => null === $estimatedLoad ? null : round($estimatedLoad, 1),
            'estimatedSourceLabel' => $estimatedSourceLabel,
            'options' => [
                'activityTypes' => array_map(
                    fn (ActivityType $activityType): array => [
                        'value' => $activityType->value,
                        'label' => $activityType->trans($this->translator),
                        'supportsPower' => $activityType->supportsPowerData(),
                    ],
                    ActivityType::cases(),
                ),
                'intensities' => array_map(
                    fn (PlannedSessionIntensity $plannedSessionIntensity): array => [
                        'value' => $plannedSessionIntensity->value,
                        'label' => $plannedSessionIntensity->getLabel(),
                    ],
                    PlannedSessionIntensity::cases(),
                ),
                'stepTypes' => array_map(
                    fn (PlannedSessionStepType $plannedSessionStepType): array => [
                        'value' => $plannedSessionStepType->value,
                        'label' => $plannedSessionStepType->getLabel(),
                        'isContainer' => $plannedSessionStepType->isContainer(),
                    ],
                    PlannedSessionStepType::cases(),
                ),
                'targetTypes' => array_map(
                    fn (PlannedSessionStepTargetType $targetType): array => [
                        'value' => $targetType->value,
                        'label' => $targetType->getLabel(),
                    ],
                    PlannedSessionStepTargetType::cases(),
                ),
                'conditionTypes' => array_map(
                    fn (PlannedSessionStepConditionType $conditionType): array => [
                        'value' => $conditionType->value,
                        'label' => $conditionType->getLabel(),
                    ],
                    PlannedSessionStepConditionType::cases(),
                ),
                'templateActivities' => $this->buildTemplateActivityOptions($plannedSession?->getTemplateActivityId()),
                'recommendations' => $this->buildTrainingSessionRecommendations(),
            ],
            'plannerOutlook' => $this->serializePlannerOutlook($plannerOutlookForecast),
        ]);
    }

    #[Route(path: '/react-preview/api/planned-session', methods: ['POST'], priority: 7)]
    public function save(Request $request): JsonResponse
    {
        $this->currentAppUser->require();

        $payload = $request->toArray();
        $submitRequest = new Request(
            request: [
                ...$payload,
                'redirectTo' => '/react-preview/planned-session-editor',
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        );

        $this->plannedSessionRequestHandler->handle($submitRequest);

        return new JsonResponse(['ok' => true]);
    }

    #[Route(path: '/react-preview/api/planned-session/{plannedSessionId}', methods: ['DELETE'], priority: 7)]
    public function delete(string $plannedSessionId): JsonResponse
    {
        $this->currentAppUser->require();

        $this->plannedSessionRequestHandler->delete(new Request(
            request: [
                'plannedSessionId' => $plannedSessionId,
                'redirectTo' => '/react-preview/planned-session-editor',
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        ));

        return new JsonResponse(['ok' => true]);
    }

    #[Route(path: '/react-preview/api/planned-session/{plannedSessionId}/confirm-link', methods: ['POST'], priority: 7)]
    public function confirmLink(string $plannedSessionId, Request $request): JsonResponse
    {
        $this->currentAppUser->require();

        $payload = $request->toArray();

        $this->plannedSessionRequestHandler->confirmLink(new Request(
            request: [
                'plannedSessionId' => $plannedSessionId,
                'linkedActivityId' => (string) ($payload['linkedActivityId'] ?? ''),
                'redirectTo' => '/react-preview/planned-session-editor',
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        ));

        return new JsonResponse(['ok' => true]);
    }

    #[Route(path: '/react-preview/api/planned-session/{plannedSessionId}/unlink', methods: ['POST'], priority: 7)]
    public function unlink(string $plannedSessionId): JsonResponse
    {
        $this->currentAppUser->require();

        $this->plannedSessionRequestHandler->unlink(new Request(
            request: [
                'plannedSessionId' => $plannedSessionId,
                'redirectTo' => '/react-preview/planned-session-editor',
            ],
            server: ['REQUEST_METHOD' => 'POST'],
        ));

        return new JsonResponse(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDefaults(?PlannedSession $plannedSession, SerializableDateTime $defaultDay): array
    {
        [$targetDurationInMinutes, $targetDurationInSecondsPart] = $this->splitDurationInMinutesAndSeconds(
            $plannedSession?->getTargetDurationInSeconds(),
        );
        $loadEstimate = null === $plannedSession ? null : $this->plannedSessionLoadEstimator->estimate($plannedSession);

        return [
            'day' => $defaultDay->format('Y-m-d'),
            'title' => $plannedSession?->getTitle() ?? '',
            'activityType' => $plannedSession?->getActivityType()->value ?? ActivityType::RUN->value,
            'notes' => $plannedSession?->getNotes() ?? '',
            'targetLoad' => $plannedSession?->getTargetLoad() ?? $loadEstimate?->getEstimatedLoad(),
            'manualTargetLoadOverride' => PlannedSessionEstimationSource::MANUAL_TARGET_LOAD === $plannedSession?->getEstimationSource(),
            'targetDurationInMinutes' => $targetDurationInMinutes,
            'targetDurationInSecondsPart' => $targetDurationInSecondsPart,
            'targetIntensity' => $plannedSession?->getTargetIntensity()?->value,
            'templateActivityId' => $plannedSession?->getTemplateActivityId()?->__toString(),
            'workoutSteps' => null === $plannedSession
                ? $this->defaultWorkoutSteps()
                : $this->mapWorkoutStepsForForm($plannedSession->getWorkoutSteps()),
        ];
    }

    private function buildLegacyPath(?PlannedSession $plannedSession, SerializableDateTime $defaultDay): string
    {
        if ($plannedSession instanceof PlannedSession) {
            return sprintf('planned-session?plannedSessionId=%s&redirectTo=/monthly-stats/month-%s', $plannedSession->getId(), $plannedSession->getDay()->format('Y-m'));
        }

        return sprintf('planned-session?day=%s&redirectTo=/monthly-stats/month-%s', $defaultDay->format('Y-m-d'), $defaultDay->format('Y-m'));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeContextPlannedSession(PlannedSession $plannedSession): array
    {
        return [
            'id' => (string) $plannedSession->getId(),
            'day' => $plannedSession->getDay()->format('Y-m-d'),
            'activityType' => $plannedSession->getActivityType()->value,
            'activityTypeLabel' => $plannedSession->getActivityType()->trans($this->translator),
            'title' => $plannedSession->getTitle(),
            'linkStatus' => $plannedSession->getLinkStatus()->value,
            'linkStatusLabel' => $plannedSession->getLinkStatus()->getLabel(),
        ];
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: ?string}
     */
    private function resolveMatchedActivity(?PlannedSession $plannedSession): array
    {
        if (null === $plannedSession) {
            return [null, null];
        }

        $linkedActivityId = $plannedSession->getLinkedActivityId();
        if (null !== $linkedActivityId) {
            try {
                $matchedActivity = $this->activityRepository->find($linkedActivityId);

                return [[
                    'activityId' => (string) $matchedActivity->getId(),
                    'name' => $matchedActivity->getName(),
                    'activityType' => $matchedActivity->getSportType()->getActivityType()->value,
                    'activityTypeLabel' => $matchedActivity->getSportType()->getActivityType()->trans($this->translator),
                    'movingTime' => $matchedActivity->getMovingTimeInSeconds(),
                ], $plannedSession->getLinkStatus()->value];
            } catch (\Throwable) {
            }
        }

        if (PlannedSessionLinkStatus::LINKED === $plannedSession->getLinkStatus()) {
            return [null, $plannedSession->getLinkStatus()->value];
        }

        $suggestedMatch = $this->plannedSessionActivityMatcher->findSuggestedMatch($plannedSession);
        if (!$suggestedMatch instanceof Activity) {
            return [null, null];
        }

        return [[
            'activityId' => (string) $suggestedMatch->getId(),
            'name' => $suggestedMatch->getName(),
            'activityType' => $suggestedMatch->getSportType()->getActivityType()->value,
            'activityTypeLabel' => $suggestedMatch->getSportType()->getActivityType()->trans($this->translator),
            'movingTime' => $suggestedMatch->getMovingTimeInSeconds(),
        ], PlannedSessionLinkStatus::SUGGESTED->value];
    }

    /**
     * @return list<array{activityId: string, activityType: string, activityTypeLabel: string, name: string, day: string, movingTimeLabel: string, movingTimeInSeconds: int, estimatedLoad: ?float}>
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
                    $this->activityRepository->find($selectedTemplateActivityId),
                );
            } catch (\Throwable) {
            }
        }

        return array_values($templateActivities);
    }

    /**
     * @return array{activityId: string, activityType: string, activityTypeLabel: string, name: string, day: string, movingTimeLabel: string, movingTimeInSeconds: int, estimatedLoad: ?float}
     */
    private function toTemplateActivityRecord(Activity $activity): array
    {
        return [
            'activityId' => (string) $activity->getId(),
            'activityType' => $activity->getSportType()->getActivityType()->value,
            'activityTypeLabel' => $activity->getSportType()->getActivityType()->trans($this->translator),
            'name' => $activity->getName(),
            'day' => $activity->getStartDate()->format('Y-m-d'),
            'movingTimeLabel' => $activity->getMovingTimeFormatted(),
            'movingTimeInSeconds' => $activity->getMovingTimeInSeconds(),
            'estimatedLoad' => $this->plannedSessionLoadEstimator->estimateActivityLoad($activity),
        ];
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function buildTrainingSessionRecommendations(): array
    {
        $recommendations = [];

        foreach (ActivityType::cases() as $activityType) {
            $recommendations[$activityType->value] = array_map(
                $this->toTrainingSessionRecommendationRecord(...),
                $this->trainingSessionRepository->findRecommended($activityType, self::TRAINING_SESSION_RECOMMENDATION_LIMIT),
            );
        }

        return $recommendations;
    }

    /**
     * @return array<string, mixed>
     */
    private function toTrainingSessionRecommendationRecord(TrainingSession $trainingSession): array
    {
        [$targetDurationInMinutes, $targetDurationInSecondsPart] = $this->splitDurationInMinutesAndSeconds($trainingSession->getTargetDurationInSeconds());

        return [
            'trainingSessionId' => (string) $trainingSession->getId(),
            'activityType' => $trainingSession->getActivityType()->value,
            'activityTypeLabel' => $trainingSession->getActivityType()->trans($this->translator),
            'title' => $trainingSession->getTitle(),
            'notes' => $trainingSession->getNotes(),
            'targetLoad' => $trainingSession->getTargetLoad(),
            'targetDurationInMinutes' => $targetDurationInMinutes,
            'targetDurationInSecondsPart' => $targetDurationInSecondsPart,
            'targetDurationLabel' => null === $trainingSession->getTargetDurationInSeconds()
                ? null
                : $this->formatDurationLabel($trainingSession->getTargetDurationInSeconds()),
            'targetIntensity' => $trainingSession->getTargetIntensity()?->value,
            'targetIntensityLabel' => $trainingSession->getTargetIntensity()?->getLabel(),
            'templateActivityId' => $trainingSession->getTemplateActivityId()?->__toString(),
            'estimationSource' => $trainingSession->getEstimationSource()->value,
            'estimationSourceLabel' => $trainingSession->getEstimationSource()->getLabel(),
            'manualTargetLoadOverride' => \App\Domain\TrainingPlanner\PlannedSessionEstimationSource::MANUAL_TARGET_LOAD === $trainingSession->getEstimationSource(),
            'lastPlannedOn' => $trainingSession->getLastPlannedOn()?->format('Y-m-d'),
            'lastPlannedOnLabel' => $trainingSession->getLastPlannedOn()?->format('D j M'),
            'workoutSteps' => $this->mapWorkoutStepsForForm($trainingSession->getWorkoutSteps()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePlannerOutlook(PlannedSessionForecast $forecast): array
    {
        $projectedLoads = [];
        foreach ($forecast->getProjectedLoads() as $dayOffset => $load) {
            $projectedLoads[] = [
                'dayOffset' => $dayOffset,
                'load' => round($load, 1),
            ];
        }

        return [
            'horizon' => self::PLANNER_OUTLOOK_HORIZON,
            'currentDayProjectedLoad' => $forecast->getCurrentDayProjectedLoad(),
            'totalProjectedLoad' => $forecast->getTotalProjectedLoad(),
            'projectedDayCount' => count(array_filter(
                $forecast->getProjectedLoads(),
                static fn (float $projectedLoad): bool => $projectedLoad > 0,
            )),
            'projectedLoads' => $projectedLoads,
        ];
    }

    /**
     * @param list<array<string, mixed>> $workoutSteps
     *
     * @return list<array{itemId: string, parentBlockId: ?string, type: string, label: string, repetitions: string, targetType: string, conditionType: string, durationInMinutes: string, durationInSecondsPart: string, distanceInMeters: string, targetPace: string, targetPower: string, targetHeartRate: string}>
     */
    private function mapWorkoutStepsForForm(array $workoutSteps): array
    {
        return array_map(function (array $workoutStep): array {
            [$durationInMinutes, $durationInSecondsPart] = $this->splitDurationInMinutesAndSeconds($workoutStep['durationInSeconds'] ?? null);

            return [
                'itemId' => (string) ($workoutStep['itemId'] ?? ''),
                'parentBlockId' => isset($workoutStep['parentBlockId']) && is_string($workoutStep['parentBlockId']) && '' !== $workoutStep['parentBlockId']
                    ? $workoutStep['parentBlockId']
                    : null,
                'type' => (string) ($workoutStep['type'] ?? PlannedSessionStepType::STEADY->value),
                'label' => (string) ($workoutStep['label'] ?? ''),
                'repetitions' => (string) ($workoutStep['repetitions'] ?? '1'),
                'targetType' => (string) ($workoutStep['targetType'] ?? PlannedSessionStepTargetType::TIME->value),
                'conditionType' => (string) ($workoutStep['conditionType'] ?? ''),
                'durationInMinutes' => null === $durationInMinutes ? '' : (string) $durationInMinutes,
                'durationInSecondsPart' => null === $durationInSecondsPart ? '' : (string) $durationInSecondsPart,
                'distanceInMeters' => null === ($workoutStep['distanceInMeters'] ?? null) ? '' : (string) $workoutStep['distanceInMeters'],
                'targetPace' => (string) ($workoutStep['targetPace'] ?? ''),
                'targetPower' => null === ($workoutStep['targetPower'] ?? null) ? '' : (string) $workoutStep['targetPower'],
                'targetHeartRate' => null === ($workoutStep['targetHeartRate'] ?? null) ? '' : (string) $workoutStep['targetHeartRate'],
            ];
        }, $workoutSteps);
    }

    /**
     * @return list<array{itemId: string, parentBlockId: ?string, type: string, label: string, repetitions: string, targetType: string, conditionType: string, durationInMinutes: string, durationInSecondsPart: string, distanceInMeters: string, targetPace: string, targetPower: string, targetHeartRate: string}>
     */
    private function defaultWorkoutSteps(): array
    {
        return [[
            'itemId' => 'workout-item-default-step',
            'parentBlockId' => null,
            'type' => PlannedSessionStepType::STEADY->value,
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
        ]];
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
}
