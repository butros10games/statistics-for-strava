<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Activity\Activities;
use App\Domain\Activity\Activity;
use App\Domain\Activity\EnrichedActivities;
use App\Domain\Calendar\Months;
use App\Domain\Gear\CustomGear\CustomGearConfig;
use App\Domain\Gear\DistanceOverTimePerGearChart;
use App\Domain\Gear\DistancePerMonthPerGearChart;
use App\Domain\Gear\FindGearStatsPerDay\FindGearStatsPerDay;
use App\Domain\Gear\Gear;
use App\Domain\Gear\GearId;
use App\Domain\Gear\GearRepository;
use App\Domain\Gear\ImportedGear\ImportedGear;
use App\Domain\Gear\Maintenance\Task\Progress\MaintenanceTaskProgressCalculator;
use App\Infrastructure\CQRS\Query\Bus\QueryBus;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use App\Infrastructure\ValueObject\Measurement\Length\Meter;
use App\Infrastructure\ValueObject\Measurement\Time\Seconds;
use App\Infrastructure\ValueObject\Measurement\UnitSystem;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Money\Money;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsController]
final readonly class ReactPreviewGearApiRequestHandler
{
    public function __construct(
        private CurrentAppUser $currentAppUser,
        private Clock $clock,
        private GearRepository $gearRepository,
        private CustomGearConfig $customGearConfig,
        private MaintenanceTaskProgressCalculator $maintenanceTaskProgressCalculator,
        private EnrichedActivities $enrichedActivities,
        private UnitSystem $unitSystem,
        private QueryBus $queryBus,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/react-preview/api/gear', methods: ['GET'], priority: 6)]
    public function handle(): JsonResponse
    {
        $this->currentAppUser->require();

        $now = $this->clock->getCurrentDateTimeImmutable();
        $activities = $this->enrichedActivities->findAll();
        $chartStartDate = $this->resolveChartStartDate($activities, $now);
        $allUsedGear = $this->gearRepository->findAllUsed();
        $gearStats = $this->queryBus->ask(new FindGearStatsPerDay());
        $allMonths = Months::create(
            startDate: $chartStartDate,
            endDate: $now,
        );

        $activeGear = $allUsedGear->filter(fn (Gear $gear): bool => !$gear->isRetired());
        $unspecifiedGear = $this->buildUnspecifiedGear($activities);
        if ($unspecifiedGear instanceof Gear) {
            $activeGear->add($unspecifiedGear);
        }

        $retiredGear = $allUsedGear->filter(fn (Gear $gear): bool => $gear->isRetired());

        return new JsonResponse([
            'requestedAt' => $now->format(DATE_ATOM),
            'customGearEnabled' => $this->customGearConfig->isFeatureEnabled(),
            'maintenanceTaskIsDue' => !$this->maintenanceTaskProgressCalculator->getGearIdsThatHaveDueTasks()->isEmpty(),
            'unitSystem' => [
                'value' => $this->unitSystem->value,
                'label' => $this->unitSystem->trans($this->translator),
                'distanceSymbol' => $this->unitSystem->distanceSymbol(),
                'elevationSymbol' => $this->unitSystem->elevationSymbol(),
                'speedSymbol' => $this->unitSystem->speedSymbol(),
            ],
            'summary' => [
                'activeGearCount' => count($activeGear),
                'retiredGearCount' => count($retiredGear),
                'totalActivities' => array_sum(array_map(fn (Gear $gear): int => $gear->getNumberOfActivities(), $activeGear->toArray())),
                'totalDistance' => round(array_sum(array_map(fn (Gear $gear): float => $gear->getDistance()->toUnitSystem($this->unitSystem)->toFloat(), $activeGear->toArray())), 1),
            ],
            'activeGear' => array_map(fn (Gear $gear): array => $this->serializeGear($gear), $activeGear->toArray()),
            'retiredGear' => array_map(fn (Gear $gear): array => $this->serializeGear($gear), $retiredGear->toArray()),
            'charts' => [
                'distancePerMonthPerGear' => DistancePerMonthPerGearChart::create(
                    gearCollection: $allUsedGear,
                    activityCollection: $activities,
                    unitSystem: $this->unitSystem,
                    months: $allMonths,
                )->build(),
                'distanceOverTimePerGear' => DistanceOverTimePerGearChart::create(
                    gears: $allUsedGear,
                    gearStats: $gearStats,
                    startDate: $chartStartDate,
                    unitSystem: $this->unitSystem,
                    translator: $this->translator,
                    now: $now,
                )->build(),
            ],
        ]);
    }

    private function resolveChartStartDate(Activities $activities, \DateTimeImmutable $now): SerializableDateTime
    {
        try {
            return $activities->getFirstActivityStartDate();
        } catch (\RuntimeException) {
            return SerializableDateTime::fromDateTimeImmutable($now);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeGear(Gear $gear): array
    {
        return [
            'id' => (string) $gear->getId(),
            'name' => $gear->getName(),
            'imageSrc' => $gear->getImageSrc(),
            'isRetired' => $gear->isRetired(),
            'numberOfActivities' => $gear->getNumberOfActivities(),
            'distance' => [
                'value' => round($gear->getDistance()->toUnitSystem($this->unitSystem)->toFloat(), 1),
                'symbol' => $this->unitSystem->distanceSymbol(),
            ],
            'averageDistance' => [
                'value' => round($gear->getAverageDistance()->toUnitSystem($this->unitSystem)->toFloat(), 1),
                'symbol' => $this->unitSystem->distanceSymbol(),
            ],
            'elevation' => [
                'value' => round($gear->getElevation()->toUnitSystem($this->unitSystem)->toFloat()),
                'symbol' => $this->unitSystem->elevationSymbol(),
            ],
            'movingTime' => [
                'formatted' => $gear->getMovingTimeFormatted(),
                'hours' => round($gear->getMovingTimeInHours()->toFloat(), 1),
            ],
            'averageSpeed' => [
                'value' => round($gear->getAverageSpeed()->toUnitSystem($this->unitSystem)->toFloat(), 1),
                'symbol' => $this->unitSystem->speedSymbol(),
            ],
            'totalCalories' => $gear->getTotalCalories(),
            'purchasePrice' => $this->serializeMoney($gear->getPurchasePrice()),
            'relativeCostPerHour' => $this->serializeMoney($gear->getRelativeCostPerHour()),
            'relativeCostPerWorkout' => $this->serializeMoney($gear->getRelativeCostPerWorkout()),
            'relativeCostPerDistanceUnit' => $this->serializeMoney($gear->getRelativeCostPerDistanceUnit($this->unitSystem)),
        ];
    }

    /**
     * @return array{amountInCents: int, currency: string}|null
     */
    private function serializeMoney(?Money $money): ?array
    {
        if (null === $money) {
            return null;
        }

        return [
            'amountInCents' => (int) $money->getAmount(),
            'currency' => $money->getCurrency()->getCode(),
        ];
    }

    private function buildUnspecifiedGear(Activities $activities): ?Gear
    {
        $activitiesWithoutGear = $activities->filter(fn (Activity $activity): bool => !$activity->getGearId() instanceof GearId);
        $count = count($activitiesWithoutGear);

        if (0 === $count) {
            return null;
        }

        $distanceInMeter = Meter::from($activitiesWithoutGear->sum(fn (Activity $activity): float => $activity->getDistance()->toMeter()->toFloat()));
        $movingTimeInSeconds = (int) $activitiesWithoutGear->sum(fn (Activity $activity): int => $activity->getMovingTimeInSeconds());
        $elevation = Meter::from($activitiesWithoutGear->sum(fn (Activity $activity): float => $activity->getElevation()->toFloat()));
        $totalCalories = (int) $activitiesWithoutGear->sum(fn (Activity $activity): ?int => $activity->getCalories());

        return ImportedGear::create(
            gearId: GearId::none(),
            distanceInMeter: $distanceInMeter,
            createdOn: SerializableDateTime::fromString('1970-01-01'),
            name: 'Unspecified',
            isRetired: false,
        )
            ->withMovingTime(Seconds::from($movingTimeInSeconds))
            ->withElevation($elevation)
            ->withNumberOfActivities($count)
            ->withTotalCalories($totalCalories);
    }
}