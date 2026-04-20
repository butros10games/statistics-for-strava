<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Countries;
use App\Domain\Activity\EnrichedActivities;
use App\Domain\Activity\SportType\SportType;
use App\Domain\Activity\SportType\SportTypeRepository;
use App\Domain\Segment\Segment;
use App\Domain\Segment\SegmentEffort\SegmentEffort;
use App\Domain\Segment\SegmentEffort\SegmentEffortHistoryChart;
use App\Domain\Segment\SegmentEffort\SegmentEffortRepository;
use App\Domain\Segment\SegmentEffort\SegmentEffortVsHeartRateChart;
use App\Domain\Segment\SegmentId;
use App\Domain\Segment\SegmentRepository;
use App\Infrastructure\Exception\EntityNotFound;
use App\Infrastructure\Repository\Pagination;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use App\Infrastructure\ValueObject\Measurement\UnitSystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsController]
final readonly class ReactPreviewSegmentsApiRequestHandler
{
    public function __construct(
        private CurrentAppUser $currentAppUser,
        private Clock $clock,
        private SegmentRepository $segmentRepository,
        private SegmentEffortRepository $segmentEffortRepository,
        private SportTypeRepository $sportTypeRepository,
        private Countries $countries,
        private EnrichedActivities $enrichedActivities,
        private UnitSystem $unitSystem,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/react-preview/api/segments', methods: ['GET'], priority: 6)]
    public function handle(): JsonResponse
    {
        $this->currentAppUser->require();

        $segments = $this->loadSegments();

        return new JsonResponse([
            'requestedAt' => $this->clock->getCurrentDateTimeImmutable()->format(DATE_ATOM),
            'summary' => [
                'totalSegments' => count($segments),
                'favouriteSegments' => count(array_filter($segments, fn (array $segment): bool => true === $segment['isFavourite'])),
                'komSegments' => count(array_filter($segments, fn (array $segment): bool => true === $segment['isKom'])),
                'countriesCount' => count(array_unique(array_values(array_filter(
                    array_map(fn (array $segment): ?string => $segment['countryCode'], $segments),
                    static fn (?string $countryCode): bool => null !== $countryCode && '' !== $countryCode,
                )))),
            ],
            'filters' => [
                'sportTypes' => $this->buildSportTypeFilters(),
                'countries' => $this->buildCountryFilters(),
            ],
            'segments' => $segments,
        ]);
    }

    #[Route(path: '/react-preview/api/segments/{segmentId}', methods: ['GET'], priority: 6)]
    public function handleDetail(string $segmentId): JsonResponse
    {
        $this->currentAppUser->require();

        try {
            $segment = $this->segmentRepository->find(SegmentId::fromString($segmentId));
        } catch (EntityNotFound) {
            return new JsonResponse([
                'message' => 'Segment not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $enrichedSegment = $this->enrichSegment($segment);
        $segmentEffortsTopTen = $this->segmentEffortRepository->findTopXBySegmentId($segment->getId(), 10);
        $segmentEfforts = $this->segmentEffortRepository->findBySegmentId($segment->getId());
        $effortVsHeartRateChart = SegmentEffortVsHeartRateChart::create(
            segmentEfforts: $segmentEfforts,
            sportType: $segment->getSportType(),
            unitSystem: $this->unitSystem,
            translator: $this->translator,
        )->build();

        return new JsonResponse([
            'requestedAt' => $this->clock->getCurrentDateTimeImmutable()->format(DATE_ATOM),
            'segment' => $this->serializeSegment($enrichedSegment, $this->countries->getUsedInSegments()),
            'effortCount' => count($segmentEfforts),
            'topEfforts' => array_map(
                fn (SegmentEffort $effort, int $index): array => $this->serializeSegmentEffort($effort, $index + 1),
                $segmentEffortsTopTen->toArray(),
                array_keys($segmentEffortsTopTen->toArray()),
            ),
            'charts' => [
                'history' => SegmentEffortHistoryChart::create($segmentEfforts)->build(),
                'effortVsHeartRate' => [] === $effortVsHeartRateChart ? null : $effortVsHeartRateChart,
            ],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadSegments(): array
    {
        $countryNames = $this->countries->getUsedInSegments();
        $pagination = Pagination::fromOffsetAndLimit(0, 100);
        $segments = [];

        do {
            $batch = $this->segmentRepository->findAll($pagination);

            foreach ($batch as $segment) {
                $segments[] = $this->serializeSegment($this->enrichSegment($segment), $countryNames);
            }

            $pagination = $pagination->next();
        } while (!$batch->isEmpty());

        return $segments;
    }

    private function enrichSegment(Segment $segment): Segment
    {
        $segmentEffortsTopTen = $this->segmentEffortRepository->findTopXBySegmentId($segment->getId(), 10);
        $segmentEfforts = $this->segmentEffortRepository->findBySegmentId($segment->getId());

        if (!$segmentEffortsTopTen->isEmpty()) {
            $segment = $segment->withBestEffort($segmentEffortsTopTen->getBestEffort());
        }

        return $segment
            ->withNumberOfTimesRidden($this->segmentEffortRepository->countBySegmentId($segment->getId()))
            ->withLastEffortDate($segmentEfforts->getFirst()?->getStartDateTime());
    }

    /**
     * @param array<string, string> $countryNames
     *
     * @return array<string, mixed>
     */
    private function serializeSegment(Segment $segment, array $countryNames): array
    {
        return [
            'id' => (string) $segment->getId(),
            'name' => (string) $segment->getOriginalName(),
            'displayName' => (string) $segment->getName(),
            'url' => $segment->getUrl(),
            'sportType' => [
                'value' => $segment->getSportType()->value,
                'label' => $segment->getSportType()->trans($this->translator),
            ],
            'countryCode' => $segment->getCountryCode(),
            'countryName' => $segment->getCountryCode() ? ($countryNames[$segment->getCountryCode()] ?? $segment->getCountryCode()) : null,
            'distance' => [
                'value' => round($segment->getDistance()->toUnitSystem($this->unitSystem)->toFloat(), 2),
                'symbol' => $this->unitSystem->distanceSymbol(),
            ],
            'averageGradient' => $segment->getAverageGradient(),
            'maxGradient' => $segment->getMaxGradient(),
            'numberOfTimesRidden' => $segment->getNumberOfTimesRidden(),
            'lastEffortDate' => $segment->getLastEffortDate()?->format(DATE_ATOM),
            'isFavourite' => $segment->isFavourite(),
            'isKom' => $segment->isKOM(),
            'bestEffort' => $this->serializeBestEffort($segment->getBestEffort()),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeBestEffort(?SegmentEffort $segmentEffort): ?array
    {
        if (null === $segmentEffort) {
            return null;
        }

        return [
            'elapsedTimeFormatted' => $segmentEffort->getElapsedTimeFormatted(),
            'averageSpeed' => [
                'value' => round($segmentEffort->getAverageSpeed()->toUnitSystem($this->unitSystem)->toFloat(), 1),
                'symbol' => $this->unitSystem->speedSymbol(),
            ],
            'averageWatts' => $segmentEffort->getAverageWatts(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSegmentEffort(SegmentEffort $segmentEffort, int $fallbackRanking): array
    {
        try {
            $activity = $this->enrichedActivities->find($segmentEffort->getActivityId());
            $activityName = $activity->getName();
            $activityUrl = $activity->getUrl();
            $gearName = $activity->getGearName() ?? $this->translator->trans('Unspecified');
        } catch (EntityNotFound) {
            $activityName = 'Activity unavailable';
            $activityUrl = null;
            $gearName = null;
        }

        return [
            'id' => $segmentEffort->getId()->toUnprefixedString(),
            'ranking' => $segmentEffort->getRank() ?? $fallbackRanking,
            'activityId' => $segmentEffort->getActivityId()->toUnprefixedString(),
            'activityName' => $activityName,
            'activityUrl' => $activityUrl,
            'startDate' => $segmentEffort->getStartDateTime()->format(DATE_ATOM),
            'elapsedTimeFormatted' => $segmentEffort->getElapsedTimeFormatted(),
            'averageSpeed' => [
                'value' => round($segmentEffort->getAverageSpeed()->toUnitSystem($this->unitSystem)->toFloat(), 1),
                'symbol' => $this->unitSystem->speedSymbol(),
            ],
            'averageHeartRate' => $segmentEffort->getAverageHeartRate(),
            'averageWatts' => $segmentEffort->getAverageWatts(),
            'gearName' => $gearName,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function buildSportTypeFilters(): array
    {
        $options = [];
        foreach ($this->sportTypeRepository->findAll() as $sportType) {
            if (!$sportType instanceof SportType) {
                continue;
            }

            $options[] = [
                'value' => $sportType->value,
                'label' => $sportType->trans($this->translator),
            ];
        }

        return $options;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function buildCountryFilters(): array
    {
        $options = [];
        foreach ($this->countries->getUsedInSegments() as $countryCode => $countryName) {
            $options[] = [
                'value' => $countryCode,
                'label' => $countryName,
            ];
        }

        return $options;
    }
}