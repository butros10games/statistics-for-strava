<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Build\BuildHeatmapHtml\HeatmapConfig;
use App\Application\Countries;
use App\Domain\Activity\Route\Route;
use App\Domain\Activity\Route\RouteRepository;
use App\Domain\Activity\SportType\SportType;
use App\Domain\Activity\SportType\SportTypeRepository;
use App\Domain\Activity\WorkoutType;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\Time\Format\DateAndTimeFormat;
use App\Infrastructure\Twig\UrlTwigExtension;
use App\Infrastructure\User\CurrentAppUser;
use App\Infrastructure\ValueObject\Measurement\UnitSystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route as RouteAttribute;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsController]
final readonly class ReactPreviewHeatmapApiRequestHandler
{
    public function __construct(
        private CurrentAppUser $currentAppUser,
        private Clock $clock,
        private RouteRepository $routeRepository,
        private SportTypeRepository $sportTypeRepository,
        private Countries $countries,
        private HeatmapConfig $heatmapConfig,
        private UnitSystem $unitSystem,
        private DateAndTimeFormat $dateAndTimeFormat,
        private UrlTwigExtension $urlTwigExtension,
        private TranslatorInterface $translator,
    ) {
    }

    #[RouteAttribute(path: '/react-preview/api/heatmap', methods: ['GET'], priority: 6)]
    public function handle(): JsonResponse
    {
        $this->currentAppUser->require();

        $routes = $this->loadRoutes();

        return new JsonResponse([
            'requestedAt' => $this->clock->getCurrentDateTimeImmutable()->format(DATE_ATOM),
            'summary' => [
                'totalRoutes' => count($routes),
                'commuteRoutes' => count(array_filter($routes, fn (array $route): bool => 'true' === $route['filterables']['isCommute'])),
                'countriesCount' => count(array_unique(array_values(array_filter(
                    array_map(fn (array $route): ?string => $route['startLocation']['countryCode'], $routes),
                    static fn (?string $countryCode): bool => null !== $countryCode && '' !== $countryCode,
                )))),
                'workoutRoutes' => count(array_filter($routes, fn (array $route): bool => null !== $route['workoutType'])),
            ],
            'config' => $this->heatmapConfig,
            'filters' => [
                'sportTypes' => $this->buildSportTypeFilters(),
                'workoutTypes' => $this->buildWorkoutTypeFilters(),
            ],
            'places' => $this->buildPlaces($routes),
            'routes' => $routes,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadRoutes(): array
    {
        $countryNames = $this->countries->getUsedInActivities();

        return array_map(
            fn (Route $route): array => $this->serializeRoute($route, $countryNames),
            $this->routeRepository->findAll()->toArray(),
        );
    }

    /**
     * @param array<string, string> $countryNames
     *
     * @return array<string, mixed>
     */
    private function serializeRoute(Route $route, array $countryNames): array
    {
        $serializedRoute = $route
            ->withUnitSystemAndDateTimeFormat(
                unitSystem: $this->unitSystem,
                dateAndTimeFormat: $this->dateAndTimeFormat,
            )
            ->withRelativeActivityUri($this->urlTwigExtension->toRelativeUrl('activity/'.$route->getActivityId().'.html'))
            ->jsonSerialize();

        $countryCode = $serializedRoute['startLocation']['countryCode'];
        $countryCode = is_string($countryCode) ? strtolower($countryCode) : null;

        return [
            'id' => $route->getActivityId()->toUnprefixedString(),
            'activityId' => $route->getActivityId()->toUnprefixedString(),
            'activityUrl' => $serializedRoute['activityUrl'],
            'startDate' => $serializedRoute['startDate'],
            'distance' => $serializedRoute['distance'],
            'name' => $serializedRoute['name'],
            'sportType' => [
                'value' => $route->getSportType()->value,
                'label' => $route->getSportType()->trans($this->translator),
            ],
            'workoutType' => $route->getWorkoutType() ? [
                'value' => $route->getWorkoutType()->value,
                'label' => $route->getWorkoutType()->trans($this->translator),
            ] : null,
            'startLocation' => [
                'countryCode' => $countryCode,
                'countryName' => $countryCode ? ($countryNames[$countryCode] ?? strtoupper($countryCode)) : null,
                'state' => $serializedRoute['startLocation']['state'],
            ],
            'filterables' => $serializedRoute['filterables'],
            'coordinates' => $serializedRoute['coordinates'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $routes
     *
     * @return list<array{countryCode: string, label: string, routeCount: int}>
     */
    private function buildPlaces(array $routes): array
    {
        $places = [];

        foreach ($routes as $route) {
            $countryCode = $route['startLocation']['countryCode'];
            $countryName = $route['startLocation']['countryName'];

            if (!is_string($countryCode) || '' === $countryCode || !is_string($countryName) || '' === $countryName) {
                continue;
            }

            if (!isset($places[$countryCode])) {
                $places[$countryCode] = [
                    'countryCode' => $countryCode,
                    'label' => $countryName,
                    'routeCount' => 0,
                ];
            }

            $places[$countryCode]['routeCount']++;
        }

        usort($places, static function (array $left, array $right): int {
            if ($left['routeCount'] === $right['routeCount']) {
                return $left['label'] <=> $right['label'];
            }

            return $right['routeCount'] <=> $left['routeCount'];
        });

        return array_values($places);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function buildSportTypeFilters(): array
    {
        $options = [];

        foreach ($this->sportTypeRepository->findAll() as $sportType) {
            if (!$sportType instanceof SportType || !$sportType->supportsReverseGeocoding()) {
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
    private function buildWorkoutTypeFilters(): array
    {
        return array_map(
            fn (WorkoutType $workoutType): array => [
                'value' => $workoutType->value,
                'label' => $workoutType->trans($this->translator),
            ],
            WorkoutType::cases(),
        );
    }
}