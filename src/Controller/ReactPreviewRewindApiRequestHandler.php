<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Activity\Activity;
use App\Domain\Activity\ActivityCountPerMonthChart;
use App\Domain\Activity\Image\Image;
use App\Domain\Activity\Image\ImageOrientation;
use App\Domain\Activity\Image\ImageRepository;
use App\Domain\Activity\LeafletMap;
use App\Domain\Activity\RealWorldMap;
use App\Domain\Activity\SportType\SportTypes;
use App\Domain\Activity\EnrichedActivities;
use App\Domain\Gear\FindMovingTimePerGear\FindMovingTimePerGear;
use App\Domain\Gear\GearRepository;
use App\Domain\Gear\MovingTimePerGearChart;
use App\Domain\Rewind\ActivityLocationsChart;
use App\Domain\Rewind\ActivityStartTimesChart;
use App\Domain\Rewind\DailyActivitiesChart;
use App\Domain\Rewind\DistancePerMonthChart;
use App\Domain\Rewind\ElevationPerMonthChart;
use App\Domain\Rewind\FindActiveAndRestDays\FindActiveAndRestDays;
use App\Domain\Rewind\FindActivityCountPerMonth\FindActivityCountPerMonth;
use App\Domain\Rewind\FindActivityLocations\FindActivityLocations;
use App\Domain\Rewind\FindActivityStartTimesPerHour\FindActivityStartTimesPerHour;
use App\Domain\Rewind\FindAvailableRewindOptions\FindAvailableRewindOptions;
use App\Domain\Rewind\FindCarbonSaved\FindCarbonSaved;
use App\Domain\Rewind\FindDistancePerMonth\FindDistancePerMonth;
use App\Domain\Rewind\FindElevationPerMonth\FindElevationPerMonth;
use App\Domain\Rewind\FindLongestActivity\FindLongestActivity;
use App\Domain\Rewind\FindMovingTimePerDay\FindMovingTimePerDay;
use App\Domain\Rewind\FindMovingTimePerSportType\FindMovingTimePerSportType;
use App\Domain\Rewind\FindPersonalRecordsPerMonth\FindPersonalRecordsPerMonth;
use App\Domain\Rewind\FindSocialsMetrics\FindSocialsMetrics;
use App\Domain\Rewind\FindStreaks\FindStreaks;
use App\Domain\Rewind\FindTotalActivityCount\FindTotalActivityCount;
use App\Domain\Rewind\MovingTimePerSportTypeChart;
use App\Domain\Rewind\PersonalRecordsPerMonthChart;
use App\Domain\Rewind\RestDaysVsActiveDaysChart;
use App\Infrastructure\CQRS\Query\Bus\QueryBus;
use App\Infrastructure\Exception\EntityNotFound;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\Twig\UrlTwigExtension;
use App\Infrastructure\User\CurrentAppUser;
use App\Infrastructure\ValueObject\Geography\Coordinate;
use App\Infrastructure\ValueObject\Measurement\Unit;
use App\Infrastructure\ValueObject\Measurement\UnitSystem;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Infrastructure\ValueObject\Time\Year;
use App\Infrastructure\ValueObject\Time\Years;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsController]
final readonly class ReactPreviewRewindApiRequestHandler
{
    public function __construct(
        private CurrentAppUser $currentAppUser,
        private Clock $clock,
        private QueryBus $queryBus,
        private GearRepository $gearRepository,
        private ImageRepository $imageRepository,
        private EnrichedActivities $enrichedActivities,
        private UnitSystem $unitSystem,
        private UrlTwigExtension $urlTwigExtension,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/react-preview/api/rewind', methods: ['GET'], priority: 6)]
    public function handle(Request $request): JsonResponse
    {
        $this->currentAppUser->require();

        $now = SerializableDateTime::fromDateTimeImmutable($this->clock->getCurrentDateTimeImmutable());
        $availableOptionsResponse = $this->queryBus->ask(new FindAvailableRewindOptions($now));
        $availableOptions = $availableOptionsResponse->getAvailableOptions();
        $selectedOption = $this->resolveSelectedOption(
            requestedOption: $request->query->getString('option', ''),
            availableOptions: $availableOptions,
        );
        $yearsToQuery = $availableOptionsResponse->getYearsToQuery($selectedOption);

        [$selectedOptionPayload, $items] = $this->buildSelectedOptionPayload(
            selectedOption: $selectedOption,
            yearsToQuery: $yearsToQuery,
            now: $now,
        );

        return new JsonResponse([
            'requestedAt' => $now->format(DATE_ATOM),
            'summary' => [
                'optionCount' => count($availableOptions),
                'yearOptionCount' => count(array_filter(
                    $availableOptions,
                    static fn (string $option): bool => FindAvailableRewindOptions::ALL_TIME !== $option,
                )),
                'comparisonAvailable' => count($availableOptions) > 1,
            ],
            'options' => array_map(
                fn (string $option): array => $this->serializeOption($option),
                $availableOptions,
            ),
            'selectedOption' => $selectedOptionPayload,
            'items' => $items,
        ]);
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private function buildSelectedOptionPayload(
        string $selectedOption,
        Years $yearsToQuery,
        SerializableDateTime $now,
    ): array {
        $totalActivityCount = $this->queryBus->ask(new FindTotalActivityCount($yearsToQuery))->getTotalActivityCount();

        if (0 === $totalActivityCount) {
            return [[
                'value' => $selectedOption,
                'label' => $this->resolveOptionLabel($selectedOption),
                'isAllTime' => FindAvailableRewindOptions::ALL_TIME === $selectedOption,
                'totalActivities' => 0,
                'cardsCount' => 0,
                'chartCardsCount' => 0,
                'hasWorldMap' => false,
                'hasPhoto' => false,
            ], []];
        }

        $usedGear = $this->gearRepository->findAllUsed();

        $findMovingTimePerDayResponse = $this->queryBus->ask(new FindMovingTimePerDay($yearsToQuery));
        $findMovingTimePerSportTypeResponse = $this->queryBus->ask(new FindMovingTimePerSportType($yearsToQuery));
        $socialsMetricsResponse = $this->queryBus->ask(new FindSocialsMetrics($yearsToQuery));
        $streaksResponse = $this->queryBus->ask(new FindStreaks($yearsToQuery, null));
        $distancePerMonthResponse = $this->queryBus->ask(new FindDistancePerMonth($yearsToQuery));
        $elevationPerMonthResponse = $this->queryBus->ask(new FindElevationPerMonth($yearsToQuery));
        $activeAndRestDaysResponse = $this->queryBus->ask(new FindActiveAndRestDays($yearsToQuery));
        $carbonSavedResponse = $this->queryBus->ask(new FindCarbonSaved($yearsToQuery));
        $longestActivity = $this->queryBus->ask(new FindLongestActivity($yearsToQuery))->getActivity();

        $items = [];

        if (FindAvailableRewindOptions::ALL_TIME !== $selectedOption) {
            $items[] = [
                'id' => 'daily-activities',
                'kind' => 'chart',
                'icon' => 'calendar',
                'title' => $this->translator->trans('Daily activities'),
                'subTitle' => $this->translator->trans('{numberOfActivities} activities in {year}', [
                    '{numberOfActivities}' => $totalActivityCount,
                    '{year}' => $selectedOption,
                ]),
                'totalMetric' => null,
                'chartOptions' => DailyActivitiesChart::create(
                    movingTimePerDay: $findMovingTimePerDayResponse->getMovingTimePerDay(),
                    year: Year::fromInt((int) $selectedOption),
                    translator: $this->translator,
                )->build(),
            ];
        }

        $items[] = [
            'id' => 'gear',
            'kind' => 'chart',
            'icon' => 'tools',
            'title' => $this->translator->trans('Gear'),
            'subTitle' => $this->translator->trans('Total hours spent per gear'),
            'totalMetric' => null,
            'chartOptions' => MovingTimePerGearChart::create(
                movingTimePerGear: $this->queryBus->ask(new FindMovingTimePerGear($yearsToQuery, null))->getMovingTimePerGear(),
                gears: $usedGear,
            )->build(),
        ];

        $items[] = [
            'id' => 'longest-activity',
            'kind' => 'hero-activity',
            'icon' => 'trophy',
            'title' => $this->translator->trans('Longest activity (h)'),
            'subTitle' => $longestActivity->getName(),
            'totalMetric' => null,
            'activity' => $this->serializeActivity($longestActivity),
        ];

        $items[] = [
            'id' => 'personal-records',
            'kind' => 'chart',
            'icon' => 'medal',
            'title' => $this->translator->trans('PRs'),
            'subTitle' => $this->translator->trans('PRs achieved per month'),
            'totalMetric' => null,
            'chartOptions' => PersonalRecordsPerMonthChart::create(
                personalRecordsPerMonth: $this->queryBus->ask(new FindPersonalRecordsPerMonth($yearsToQuery))->getPersonalRecordsPerMonth(),
                translator: $this->translator,
            )->build(),
        ];

        $items[] = [
            'id' => 'socials',
            'kind' => 'socials',
            'icon' => 'thumbs-up',
            'title' => $this->translator->trans('Socials'),
            'subTitle' => $this->translator->trans('Total kudos and comments received'),
            'totalMetric' => null,
            'socials' => [
                'kudoCount' => $socialsMetricsResponse->getKudoCount(),
                'commentCount' => $socialsMetricsResponse->getCommentCount(),
            ],
        ];

        $items[] = [
            'id' => 'distance',
            'kind' => 'chart',
            'icon' => 'rocket',
            'title' => $this->translator->trans('Distance'),
            'subTitle' => $this->translator->trans('Total distance per month'),
            'totalMetric' => [
                'value' => $distancePerMonthResponse->getTotalDistance()->toUnitSystem($this->unitSystem)->toInt(),
                'label' => $this->unitSystem->distanceSymbol(),
            ],
            'chartOptions' => DistancePerMonthChart::create(
                distancePerMonth: $distancePerMonthResponse->getDistancePerMonth(),
                unitSystem: $this->unitSystem,
                translator: $this->translator,
            )->build(),
        ];

        $items[] = [
            'id' => 'elevation',
            'kind' => 'chart',
            'icon' => 'mountain',
            'title' => $this->translator->trans('Elevation'),
            'subTitle' => $this->translator->trans('Total elevation per month'),
            'totalMetric' => [
                'value' => $elevationPerMonthResponse->getTotalElevation()->toUnitSystem($this->unitSystem)->toInt(),
                'label' => $this->unitSystem->elevationSymbol(),
            ],
            'chartOptions' => ElevationPerMonthChart::create(
                elevationPerMonth: $elevationPerMonthResponse->getElevationPerMonth(),
                unitSystem: $this->unitSystem,
                translator: $this->translator,
            )->build(),
        ];

        $items[] = [
            'id' => 'moving-time-per-sport-type',
            'kind' => 'chart',
            'icon' => 'watch',
            'title' => $this->translator->trans('Total hours'),
            'subTitle' => $this->translator->trans('Total hours spent per sport type'),
            'totalMetric' => [
                'value' => (int) round($findMovingTimePerSportTypeResponse->getTotalMovingTime() / 3600),
                'label' => $this->translator->trans('hours'),
            ],
            'chartOptions' => MovingTimePerSportTypeChart::create(
                movingTimePerSportType: $findMovingTimePerSportTypeResponse->getMovingTimePerSportType(),
                translator: $this->translator,
            )->build(),
        ];

        $items[] = [
            'id' => 'streaks',
            'kind' => 'streaks',
            'icon' => 'fire',
            'title' => $this->translator->trans('Streaks'),
            'subTitle' => $this->translator->trans('Longest streaks'),
            'totalMetric' => null,
            'streaks' => [
                'dayStreak' => $streaksResponse->getLongestDayStreak(),
                'weekStreak' => $streaksResponse->getLongestWeekStreak(),
                'monthStreak' => $streaksResponse->getLongestMonthStreak(),
            ],
        ];

        $items[] = [
            'id' => 'rest-days',
            'kind' => 'chart',
            'icon' => 'bed',
            'title' => $this->translator->trans('Rest days'),
            'subTitle' => $this->translator->trans('Rest days vs. active days'),
            'totalMetric' => [
                'value' => (int) round(($activeAndRestDaysResponse->getNumberOfActiveDays() / $activeAndRestDaysResponse->getTotalNumberOfDays()) * 100),
                'label' => '%',
            ],
            'chartOptions' => RestDaysVsActiveDaysChart::create(
                numberOfActiveDays: $activeAndRestDaysResponse->getNumberOfActiveDays(),
                numberOfRestDays: $activeAndRestDaysResponse->getNumberOfRestDays(),
                translator: $this->translator,
            )->build(),
        ];

        $items[] = [
            'id' => 'start-times',
            'kind' => 'chart',
            'icon' => 'clock',
            'title' => $this->translator->trans('Start times'),
            'subTitle' => $this->translator->trans('Activity start times'),
            'totalMetric' => null,
            'chartOptions' => ActivityStartTimesChart::create(
                activityStartTimes: $this->queryBus->ask(new FindActivityStartTimesPerHour($yearsToQuery))->getActivityStartTimesPerHour(),
                translator: $this->translator,
            )->build(),
        ];

        $items[] = [
            'id' => 'activity-count',
            'kind' => 'chart',
            'icon' => 'muscle',
            'title' => $this->translator->trans('Activity count'),
            'subTitle' => $this->translator->trans('Number of activities per month'),
            'totalMetric' => [
                'value' => $totalActivityCount,
                'label' => $this->translator->trans('activities'),
            ],
            'chartOptions' => ActivityCountPerMonthChart::create(
                activityCountPerMonth: $this->queryBus->ask(new FindActivityCountPerMonth($yearsToQuery))->getActivityCountPerMonth(),
                translator: $this->translator,
            )->build(),
        ];

        $items[] = [
            'id' => 'carbon-saved',
            'kind' => 'carbon-saved',
            'icon' => 'carbon',
            'title' => $this->translator->trans('Carbon saved'),
            'subTitle' => $this->translator->trans('Reduced carbon emission by commuting'),
            'totalMetric' => [
                'value' => (int) round($this->queryBus->ask(new FindCarbonSaved(Years::all($now)))->getKgCoCarbonSaved()->toFloat()),
                'label' => 'kg CO₂',
            ],
            'carbonSaved' => [
                'kilograms' => round($carbonSavedResponse->getKgCoCarbonSaved()->toFloat(), 2),
                'petBottlesProduced' => (int) round($carbonSavedResponse->getKgCoCarbonSaved()->toFloat() / 0.067),
                'googleSearches' => (int) round($carbonSavedResponse->getKgCoCarbonSaved()->toFloat() / 0.0002),
            ],
        ];

        $activityLocations = $this->queryBus->ask(new FindActivityLocations($yearsToQuery))->getActivityLocations();
        if ([] !== $activityLocations) {
            $items[] = [
                'id' => 'activity-locations',
                'kind' => 'chart',
                'icon' => 'globe',
                'title' => $this->translator->trans('Activity locations'),
                'subTitle' => $this->translator->trans('Locations over the globe'),
                'totalMetric' => null,
                'chartOptions' => ActivityLocationsChart::create($activityLocations)->build(),
            ];
        }

        if ($randomImage = $this->findRandomImage($yearsToQuery)) {
            $items[] = [
                'id' => 'photo',
                'kind' => 'photo',
                'icon' => 'image',
                'title' => $this->translator->trans('Photo'),
                'subTitle' => $this->enrichedActivities->find($randomImage->getActivityId())->getStartDate()->translatedFormat('M d, Y'),
                'totalMetric' => null,
                'photo' => $this->serializePhoto($randomImage),
            ];
        }

        return [[
            'value' => $selectedOption,
            'label' => $this->resolveOptionLabel($selectedOption),
            'isAllTime' => FindAvailableRewindOptions::ALL_TIME === $selectedOption,
            'totalActivities' => $totalActivityCount,
            'cardsCount' => count($items),
            'chartCardsCount' => count(array_filter(
                $items,
                static fn (array $item): bool => 'chart' === $item['kind'],
            )),
            'hasWorldMap' => in_array('activity-locations', array_column($items, 'id'), true),
            'hasPhoto' => in_array('photo', array_column($items, 'id'), true),
        ], $items];
    }

    private function resolveSelectedOption(string $requestedOption, array $availableOptions): string
    {
        if ('' !== $requestedOption && in_array($requestedOption, $availableOptions, true)) {
            return $requestedOption;
        }

        return $availableOptions[0] ?? FindAvailableRewindOptions::ALL_TIME;
    }

    /**
     * @return array{value: string, label: string, isAllTime: bool}
     */
    private function serializeOption(string $option): array
    {
        return [
            'value' => $option,
            'label' => $this->resolveOptionLabel($option),
            'isAllTime' => FindAvailableRewindOptions::ALL_TIME === $option,
        ];
    }

    private function resolveOptionLabel(string $option): string
    {
        return FindAvailableRewindOptions::ALL_TIME === $option
            ? $this->translator->trans('All time')
            : $option;
    }

    private function findRandomImage(Years $yearsToQuery): ?Image
    {
        try {
            return $this->imageRepository->findRandomFor(
                sportTypes: SportTypes::thatSupportImagesForStravaRewind(),
                years: $yearsToQuery,
            );
        } catch (EntityNotFound) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeActivity(Activity $activity): array
    {
        $distance = $activity->getDistance()->toUnitSystem($this->unitSystem);
        $elevation = $activity->getElevation()->toUnitSystem($this->unitSystem);

        return [
            'id' => $activity->getId()->toUnprefixedString(),
            'name' => $activity->getName(),
            'activityUrl' => $this->urlTwigExtension->toRelativeUrl('activity/'.$activity->getId()->toUnprefixedString().'.html'),
            'externalUrl' => $activity->getUrl(),
            'distanceLabel' => $this->formatMeasurement(
                measurement: $distance,
                precision: $activity->getSportType()->getActivityType()->getDistancePrecision(),
            ),
            'elevationLabel' => $this->formatMeasurement(
                measurement: $elevation,
                precision: 0,
            ),
            'movingTimeLabel' => sprintf(
                '%s %s',
                $activity->getMovingTimeInHours(),
                $this->translator->trans('hrs'),
            ),
            'map' => $this->serializeLeafletMap($activity->getLeafletMap(), $activity),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeLeafletMap(?LeafletMap $leafletMap, Activity $activity): ?array
    {
        if (!$leafletMap instanceof LeafletMap) {
            return null;
        }

        $overlayImageUrl = $leafletMap->getOverlayImageUrl();

        return [
            'polylineUrl' => $this->urlTwigExtension->toRelativeUrl(sprintf(
                'api/activity/%s/polylines.json',
                $activity->getId()->toUnprefixedString(),
            )),
            'tileLayer' => $leafletMap->getTileLayer(),
            'overlayImageUrl' => is_string($overlayImageUrl) ? $this->urlTwigExtension->toRelativeUrl($overlayImageUrl) : null,
            'bounds' => array_map(
                static fn (Coordinate $coordinate): array => [
                    'lat' => $coordinate->getLatitude()->toFloat(),
                    'lng' => $coordinate->getLongitude()->toFloat(),
                ],
                $leafletMap->getBounds(),
            ),
            'minZoom' => $leafletMap->getMinZoom(),
            'maxZoom' => $leafletMap->getMaxZoom(),
            'backgroundColor' => $leafletMap->getBackgroundColor(),
            'label' => $leafletMap instanceof RealWorldMap ? $this->translator->trans('Real world') : $leafletMap->getLabel(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePhoto(Image $image): array
    {
        $activity = $this->enrichedActivities->find($image->getActivityId());

        return [
            'imageUrl' => $image->getImageUrl(),
            'placeholderUrl' => $this->urlTwigExtension->placeholderImage($image->getOrientation()),
            'orientation' => $image->getOrientation()->name,
            'activityName' => $activity->getName(),
            'activityDateLabel' => $activity->getStartDate()->translatedFormat('M d, Y'),
            'activityUrl' => $this->urlTwigExtension->toRelativeUrl('activity/'.$activity->getId()->toUnprefixedString().'.html'),
        ];
    }

    private function formatMeasurement(Unit $measurement, int $precision): string
    {
        $value = $measurement->toFloat();
        $precisionToUse = $value < 100 ? $precision : 0;

        return sprintf(
            '%s %s',
            number_format(round($value, $precisionToUse), $precisionToUse, '.', ' '),
            $measurement->getSymbol(),
        );
    }
}