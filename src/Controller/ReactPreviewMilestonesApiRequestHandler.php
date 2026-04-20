<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Activity\EnrichedActivities;
use App\Domain\Milestone\Context\ActivityCountContext;
use App\Domain\Milestone\Context\ActivityRecordContext;
use App\Domain\Milestone\Context\CumulativeDistanceContext;
use App\Domain\Milestone\Context\CumulativeElevationContext;
use App\Domain\Milestone\Context\CumulativeMovingTimeContext;
use App\Domain\Milestone\Context\EddingtonContext;
use App\Domain\Milestone\Context\FirstActivityInCountryContext;
use App\Domain\Milestone\Context\FirstContext;
use App\Domain\Milestone\Context\GearDistanceContext;
use App\Domain\Milestone\Context\GearElevationContext;
use App\Domain\Milestone\Context\GearMovingTimeContext;
use App\Domain\Milestone\Context\MilestoneContext;
use App\Domain\Milestone\Context\PersonalBestContext;
use App\Domain\Milestone\Context\StreakContext;
use App\Domain\Milestone\Milestone;
use App\Domain\Milestone\MilestoneCategory;
use App\Domain\Milestone\MilestoneCollector;
use App\Domain\Milestone\MilestoneFilterGroup;
use App\Infrastructure\Exception\EntityNotFound;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\Twig\MeasurementTwigExtension;
use App\Infrastructure\User\CurrentAppUser;
use App\Infrastructure\ValueObject\Measurement\Unit;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsController]
final readonly class ReactPreviewMilestonesApiRequestHandler
{
    public function __construct(
        private CurrentAppUser $currentAppUser,
        private Clock $clock,
        private MilestoneCollector $milestoneCollector,
        private EnrichedActivities $enrichedActivities,
        private MeasurementTwigExtension $measurementTwigExtension,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/react-preview/api/milestones', methods: ['GET'], priority: 6)]
    public function handle(): JsonResponse
    {
        $this->currentAppUser->require();

        $milestones = $this->loadMilestones();
        $groupFilters = $this->buildGroupFilters($milestones);
        $sportTypeFilters = $this->buildSportTypeFilters($milestones);
        $yearFilters = $this->buildYearFilters($milestones);
        $currentYear = (int) $this->clock->getCurrentDateTimeImmutable()->format('Y');

        return new JsonResponse([
            'requestedAt' => $this->clock->getCurrentDateTimeImmutable()->format(DATE_ATOM),
            'summary' => [
                'totalMilestones' => count($milestones),
                'groupsCount' => count($groupFilters),
                'yearsCount' => count($yearFilters),
                'linkedActivitiesCount' => count(array_filter(
                    $milestones,
                    static fn (array $milestone): bool => null !== $milestone['activity'] && null !== $milestone['activity']['url'],
                )),
                'achievedThisYear' => count(array_filter(
                    $milestones,
                    static fn (array $milestone): bool => $currentYear === $milestone['year'],
                )),
            ],
            'filters' => [
                'groups' => $groupFilters,
                'sportTypes' => $sportTypeFilters,
                'years' => $yearFilters,
            ],
            'milestones' => $milestones,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadMilestones(): array
    {
        return array_map(
            fn (Milestone $milestone): array => $this->serializeMilestone($milestone),
            $this->milestoneCollector->discoverAll()->toArray(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMilestone(Milestone $milestone): array
    {
        $filterGroup = $milestone->getCategory()->getFilterGroup();

        return [
            'id' => (string) $milestone->getId(),
            'achievedOn' => $milestone->getAchievedOn()->format(DATE_ATOM),
            'year' => $milestone->getAchievedOn()->getYear(),
            'category' => $milestone->getCategory()->value,
            'title' => $this->buildTitle($milestone),
            'details' => $this->buildDetails($milestone),
            'filterGroup' => [
                'value' => $filterGroup->value,
                'label' => $filterGroup->trans($this->translator),
                'icon' => $filterGroup->getSvgIcon(),
            ],
            'sportType' => $milestone->getSportType() ? [
                'value' => $milestone->getSportType()->value,
                'label' => $milestone->getSportType()->trans($this->translator),
            ] : null,
            'country' => $this->serializeCountry($milestone->getContext()),
            'activity' => $this->serializeActivity($milestone),
            'previous' => $this->serializePreviousMilestone($milestone),
        ];
    }

    /**
     * @return list<string>
     */
    private function buildDetails(Milestone $milestone): array
    {
        $details = [];
        $context = $milestone->getContext();

        if ($milestone->getFunComparison()) {
            $details[] = $milestone->getFunComparison()->trans($this->translator);
        }

        if ($milestone->getCategory() === MilestoneCategory::EDDINGTON && $context instanceof EddingtonContext) {
            $details[] = $this->translator->trans(
                "That's {number} days of at least {distance}",
                [
                    '{number}' => (string) $context->getNumber(),
                    '{distance}' => $this->renderPlainMeasurement($context->getDistance(), 0),
                ],
            );
        }

        return $details;
    }

    private function buildTitle(Milestone $milestone): string
    {
        $context = $milestone->getContext();

        return match ($milestone->getCategory()) {
            MilestoneCategory::FIRST_ACTIVITY_IN_COUNTRY => $this->translator->trans(
                'First activity in {countryName}',
                ['{countryName}' => $context instanceof FirstActivityInCountryContext ? $context->getCountryName() : ''],
            ),
            MilestoneCategory::FIRST_ACTIVITY_OF_SPORT_TYPE => $this->translator->trans('First-ever activity recorded'),
            MilestoneCategory::ACTIVITY_COUNT => $this->translator->trans(
                'Completed {threshold} activities',
                ['{threshold}' => (string) ($context instanceof ActivityCountContext ? $context->getThreshold() : 0)],
            ),
            MilestoneCategory::CUMULATIVE_DISTANCE => $this->translator->trans(
                'Reached {threshold} total distance',
                ['{threshold}' => $context instanceof CumulativeDistanceContext ? $this->renderPlainMeasurement($context->getThreshold(), 0) : '0'],
            ),
            MilestoneCategory::CUMULATIVE_ELEVATION => $this->translator->trans(
                'Reached {threshold} total elevation',
                ['{threshold}' => $context instanceof CumulativeElevationContext ? $this->renderPlainMeasurement($context->getThreshold(), 0) : '0'],
            ),
            MilestoneCategory::CUMULATIVE_MOVING_TIME => $this->translator->trans(
                'Reached {threshold} hours total moving time',
                ['{threshold}' => $context instanceof CumulativeMovingTimeContext ? $this->measurementTwigExtension->formatNumber($context->getThreshold()->toFloat(), 0) : '0'],
            ),
            MilestoneCategory::ACTIVITY_DISTANCE => $this->translator->trans(
                'Longest distance',
            ).': '.($context instanceof ActivityRecordContext ? $this->renderPlainMeasurement($context->getValue(), 1) : '0'),
            MilestoneCategory::ACTIVITY_ELEVATION => $this->translator->trans(
                'Most elevation',
            ).': '.($context instanceof ActivityRecordContext ? $this->renderPlainMeasurement($context->getValue(), 0) : '0'),
            MilestoneCategory::ACTIVITY_MOVING_TIME => $this->translator->trans(
                'Longest activity',
            ).': '.($context instanceof ActivityRecordContext ? $this->measurementTwigExtension->formatSeconds($context->getValue()->toInt()) : '0'),
            MilestoneCategory::PERSONAL_BEST => $this->buildPersonalBestTitle($context),
            MilestoneCategory::EDDINGTON => $context instanceof EddingtonContext
                ? sprintf('%s: %s', $context->getLabel(), $this->translator->trans('reached Eddington number {number}', ['{number}' => (string) $context->getNumber()]))
                : $this->translator->trans('Eddington milestone'),
            MilestoneCategory::STREAK => $this->translator->trans(
                '{days} consecutive days of activity',
                ['{days}' => (string) ($context instanceof StreakContext ? $context->getDays() : 0)],
            ),
            MilestoneCategory::GEAR_DISTANCE => $this->translator->trans(
                '{threshold} total distance using {gearName}',
                [
                    '{threshold}' => $context instanceof GearDistanceContext ? $this->renderPlainMeasurement($context->getThreshold(), 0) : '0',
                    '{gearName}' => $context instanceof GearDistanceContext ? $context->getGearName() : '',
                ],
            ),
            MilestoneCategory::GEAR_ELEVATION => $this->translator->trans(
                '{threshold} total elevation using {gearName}',
                [
                    '{threshold}' => $context instanceof GearElevationContext ? $this->renderPlainMeasurement($context->getThreshold(), 0) : '0',
                    '{gearName}' => $context instanceof GearElevationContext ? $context->getGearName() : '',
                ],
            ),
            MilestoneCategory::GEAR_MOVING_TIME => $this->translator->trans(
                '{threshold} hours using {gearName}',
                [
                    '{threshold}' => $context instanceof GearMovingTimeContext ? $this->measurementTwigExtension->formatNumber($context->getThreshold()->toFloat(), 0) : '0',
                    '{gearName}' => $context instanceof GearMovingTimeContext ? $context->getGearName() : '',
                ],
            ),
        };
    }

    private function buildPersonalBestTitle(MilestoneContext $context): string
    {
        if (!$context instanceof PersonalBestContext) {
            return $this->translator->trans('New personal best');
        }

        $distance = $context->getDistance();
        $precision = $distance->isLowerThanOne() ? 1 : 0;

        return sprintf(
            '%s %s: %s',
            $this->translator->trans('New personal best for'),
            $this->renderPlainMeasurement($distance, $precision),
            $this->measurementTwigExtension->formatSecondsAsPaddedClock($context->getTime()),
        );
    }

    /**
     * @return array{id: string|null, name: string, url: string|null, achievedOn: string|null}|null
     */
    private function serializeActivity(Milestone $milestone): ?array
    {
        if ($milestone->getActivityId()) {
            try {
                $activity = $this->enrichedActivities->find($milestone->getActivityId());

                return [
                    'id' => $milestone->getActivityId()->toUnprefixedString(),
                    'name' => $activity->getName(),
                    'url' => $activity->getUrl(),
                    'achievedOn' => $activity->getStartDate()->format(DATE_ATOM),
                ];
            } catch (EntityNotFound) {
                // Fall through to context fallback.
            }
        }

        $context = $milestone->getContext();

        return match (true) {
            $context instanceof FirstContext => [
                'id' => null,
                'name' => $context->getActivityName(),
                'url' => null,
                'achievedOn' => null,
            ],
            $context instanceof FirstActivityInCountryContext => [
                'id' => null,
                'name' => $context->getActivityName(),
                'url' => null,
                'achievedOn' => null,
            ],
            default => null,
        };
    }

    /**
     * @return array{code: string, label: string}|null
     */
    private function serializeCountry(MilestoneContext $context): ?array
    {
        if (!$context instanceof FirstActivityInCountryContext) {
            return null;
        }

        return [
            'code' => strtolower($context->getCountryCode()),
            'label' => $context->getCountryName(),
        ];
    }

    /**
     * @return array{id: string, threshold: string, achievedOn: string}|null
     */
    private function serializePreviousMilestone(Milestone $milestone): ?array
    {
        $previous = $milestone->getPrevious();

        if (null === $previous) {
            return null;
        }

        return [
            'id' => (string) $previous->getPreviousMilestoneId(),
            'threshold' => $this->renderPreviousThreshold($milestone->getCategory(), $previous->getThreshold()),
            'achievedOn' => $previous->getAchievedOn()->format(DATE_ATOM),
        ];
    }

    private function renderPreviousThreshold(MilestoneCategory $category, Unit $threshold): string
    {
        return match ($category) {
            MilestoneCategory::PERSONAL_BEST => $this->measurementTwigExtension->formatSecondsAsPaddedClock($threshold->toInt()),
            MilestoneCategory::ACTIVITY_MOVING_TIME => $this->measurementTwigExtension->formatSeconds($threshold->toInt()),
            default => $this->renderPlainMeasurement($threshold, 0),
        };
    }

    private function renderPlainMeasurement(Unit $measurement, int $precision, ?string $symbolSuffix = null): string
    {
        $convertedMeasurement = $this->measurementTwigExtension->convertMeasurement($measurement);
        $measurementInScalar = $convertedMeasurement->toFloat();
        $formattedNumber = $this->measurementTwigExtension->formatNumber(
            $measurementInScalar,
            $measurementInScalar < 100 ? $precision : 0,
        );

        if ('' === $convertedMeasurement->getSymbol()) {
            return $formattedNumber;
        }

        return null === $symbolSuffix
            ? sprintf('%s %s', $formattedNumber, $convertedMeasurement->getSymbol())
            : sprintf('%s %s %s', $formattedNumber, $convertedMeasurement->getSymbol(), $symbolSuffix);
    }

    /**
     * @param list<array<string, mixed>> $milestones
     *
     * @return list<array{value: string, label: string, icon: string, count: int}>
     */
    private function buildGroupFilters(array $milestones): array
    {
        $counts = [];
        foreach ($milestones as $milestone) {
            $groupValue = $milestone['filterGroup']['value'];
            $counts[$groupValue] = ($counts[$groupValue] ?? 0) + 1;
        }

        $filters = [];
        foreach (MilestoneFilterGroup::cases() as $filterGroup) {
            if (!isset($counts[$filterGroup->value])) {
                continue;
            }

            $filters[] = [
                'value' => $filterGroup->value,
                'label' => $filterGroup->trans($this->translator),
                'icon' => $filterGroup->getSvgIcon(),
                'count' => $counts[$filterGroup->value],
            ];
        }

        return $filters;
    }

    /**
     * @param list<array<string, mixed>> $milestones
     *
     * @return list<array{value: string, label: string, count: int}>
     */
    private function buildSportTypeFilters(array $milestones): array
    {
        $counts = [];

        foreach ($milestones as $milestone) {
            if (!is_array($milestone['sportType'])) {
                continue;
            }

            $sportTypeValue = $milestone['sportType']['value'];
            $counts[$sportTypeValue] = [
                'value' => $sportTypeValue,
                'label' => $milestone['sportType']['label'],
                'count' => ($counts[$sportTypeValue]['count'] ?? 0) + 1,
            ];
        }

        return array_values($counts);
    }

    /**
     * @param list<array<string, mixed>> $milestones
     *
     * @return list<array{value: int, label: string, count: int}>
     */
    private function buildYearFilters(array $milestones): array
    {
        $counts = [];

        foreach ($milestones as $milestone) {
            $year = $milestone['year'];
            $counts[$year] = ($counts[$year] ?? 0) + 1;
        }

        krsort($counts);

        $filters = [];
        foreach ($counts as $year => $count) {
            $filters[] = [
                'value' => $year,
                'label' => (string) $year,
                'count' => $count,
            ];
        }

        return $filters;
    }
}