<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Activity\ActivityType;
use App\Domain\Activity\BestEffort\ActivityBestEffort;
use App\Domain\Activity\BestEffort\BestEffortChart;
use App\Domain\Activity\BestEffort\BestEffortPeriod;
use App\Domain\Activity\BestEffort\BestEffortsCalculator;
use App\Domain\Activity\EnrichedActivities;
use App\Domain\Activity\SportType\SportType;
use App\Domain\Activity\SportType\SportTypes;
use App\Infrastructure\Exception\EntityNotFound;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use App\Infrastructure\ValueObject\Measurement\Length\ConvertableToMeter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsController]
final readonly class ReactPreviewBestEffortsApiRequestHandler
{
    public function __construct(
        private CurrentAppUser $currentAppUser,
        private Clock $clock,
        private BestEffortsCalculator $bestEffortsCalculator,
        private EnrichedActivities $enrichedActivities,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/react-preview/api/best-efforts', methods: ['GET'], priority: 6)]
    public function handle(): JsonResponse
    {
        $this->currentAppUser->require();

        return new JsonResponse([
            'requestedAt' => $this->clock->getCurrentDateTimeImmutable()->format(DATE_ATOM),
            'activityTypes' => array_values(array_filter(array_map(
                fn (ActivityType $activityType): ?array => $this->serializeActivityType($activityType),
                $this->bestEffortsCalculator->getActivityTypes()->toArray(),
            ))),
        ]);
    }

    #[Route(path: '/react-preview/api/best-efforts/history', methods: ['GET'], priority: 6)]
    public function handleHistory(Request $request): JsonResponse
    {
        $this->currentAppUser->require();

        try {
            $activityType = ActivityType::from($request->query->getString('activityType'));
            $distance = $this->resolveDistance(
                $activityType,
                $request->query->getString('distanceValue'),
                $request->query->getString('distanceSymbol'),
            );
        } catch (\Throwable) {
            return new JsonResponse([
                'message' => 'Best effort history target not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $sportTypes = $this->bestEffortsCalculator->getSportTypesFor(BestEffortPeriod::ALL_TIME, $activityType);

        return new JsonResponse([
            'requestedAt' => $this->clock->getCurrentDateTimeImmutable()->format(DATE_ATOM),
            'activityType' => [
                'value' => $activityType->value,
                'label' => $activityType->trans($this->translator),
            ],
            'distance' => $this->serializeDistance($distance),
            'sportTypes' => array_map(
                fn (SportType $sportType): array => $this->serializeSportType($sportType),
                $sportTypes->toArray(),
            ),
            'rankings' => array_map(
                fn (int $position): array => $this->serializeHistoryRanking($position, $sportTypes, $distance),
                range(0, 9),
            ),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeActivityType(ActivityType $activityType): ?array
    {
        $periods = array_values(array_filter(array_map(
            fn (BestEffortPeriod $period): ?array => $this->serializePeriod($activityType, $period),
            $this->bestEffortsCalculator->getPeriods(),
        )));

        if ([] === $periods) {
            return null;
        }

        return [
            'value' => $activityType->value,
            'label' => $activityType->trans($this->translator),
            'periods' => $periods,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializePeriod(ActivityType $activityType, BestEffortPeriod $period): ?array
    {
        $sportTypes = $this->bestEffortsCalculator->getSportTypesFor($period, $activityType);

        if ($sportTypes->isEmpty()) {
            return null;
        }

        return [
            'value' => $period->value,
            'label' => $period->trans($this->translator),
            'chartOptions' => BestEffortChart::create(
                activityType: $activityType,
                period: $period,
                bestEffortsCalculator: $this->bestEffortsCalculator,
                translator: $this->translator,
            )->build(),
            'sportTypes' => array_map(
                fn (SportType $sportType): array => $this->serializeSportType($sportType),
                $sportTypes->toArray(),
            ),
            'rows' => array_map(
                fn (ConvertableToMeter $distance): array => $this->serializeDistanceRow($period, $sportTypes, $distance),
                $activityType->getDistancesForBestEffortCalculation(),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDistanceRow(BestEffortPeriod $period, SportTypes $sportTypes, ConvertableToMeter $distance): array
    {
        return [
            'distance' => $this->serializeDistance($distance),
            'efforts' => array_map(
                fn (SportType $sportType): array => [
                    'sportType' => $this->serializeSportType($sportType),
                    'effort' => $this->serializeBestEffort($this->bestEffortsCalculator->for($period, $sportType, $distance)),
                ],
                $sportTypes->toArray(),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeHistoryRanking(int $position, SportTypes $sportTypes, ConvertableToMeter $distance): array
    {
        return [
            'rank' => $position + 1,
            'efforts' => array_map(
                fn (SportType $sportType): array => [
                    'sportType' => $this->serializeSportType($sportType),
                    'effort' => $this->serializeBestEffort($this->bestEffortsCalculator->historyFor($sportType, $distance, $position)),
                ],
                $sportTypes->toArray(),
            ),
        ];
    }

    /**
     * @return array{value: string, label: string}
     */
    private function serializeSportType(SportType $sportType): array
    {
        return [
            'value' => $sportType->value,
            'label' => $sportType->trans($this->translator),
        ];
    }

    /**
     * @return array{key: string, value: float, meterValue: int, symbol: string, label: string}
     */
    private function serializeDistance(ConvertableToMeter $distance): array
    {
        $displayValue = $distance->isLowerThanOne() ? round($distance->toFloat(), 1) : $distance->toInt();

        return [
            'key' => sprintf('%s-%s', $distance->toFloat(), $distance->getSymbol()),
            'value' => $distance->toFloat(),
            'meterValue' => $distance->toMeter()->toInt(),
            'symbol' => $distance->getSymbol(),
            'label' => sprintf('%s %s', $displayValue, $distance->getSymbol()),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeBestEffort(?ActivityBestEffort $bestEffort): ?array
    {
        if (null === $bestEffort) {
            return null;
        }

        $activityName = 'Activity unavailable';
        $activityUrl = null;
        $startDate = null;

        try {
            $activity = $this->enrichedActivities->find($bestEffort->getActivityId());
            $activityName = $activity->getName();
            $activityUrl = $activity->getUrl();
            $startDate = $activity->getStartDate()->format(DATE_ATOM);
        } catch (EntityNotFound) {
        }

        return [
            'id' => $bestEffort->getId(),
            'formattedTime' => $bestEffort->getFormattedTime(),
            'timeInSeconds' => $bestEffort->getTimeInSeconds(),
            'activityId' => $bestEffort->getActivityId()->toUnprefixedString(),
            'activityName' => $activityName,
            'activityUrl' => $activityUrl,
            'startDate' => $startDate,
        ];
    }

    private function resolveDistance(ActivityType $activityType, string $distanceValue, string $distanceSymbol): ConvertableToMeter
    {
        foreach ($activityType->getDistancesForBestEffortCalculation() as $distance) {
            if ($distance->getSymbol() !== $distanceSymbol) {
                continue;
            }

            if ((float) $distanceValue !== $distance->toFloat()) {
                continue;
            }

            return $distance;
        }

        throw new \RuntimeException('Distance not found.');
    }
}