<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Build\BuildPhotosHtml\DefaultEnabledPhotoFilters;
use App\Application\Countries;
use App\Domain\Activity\EnrichedActivities;
use App\Domain\Activity\Image\Image;
use App\Domain\Activity\Image\ImageOrientation;
use App\Domain\Activity\Image\ImageRepository;
use App\Domain\Activity\SportType\SportType;
use App\Domain\Activity\SportType\SportTypeRepository;
use App\Infrastructure\Exception\EntityNotFound;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsController]
final readonly class ReactPreviewPhotosApiRequestHandler
{
    public function __construct(
        private CurrentAppUser $currentAppUser,
        private Clock $clock,
        private ImageRepository $imageRepository,
        private SportTypeRepository $sportTypeRepository,
        private Countries $countries,
        private DefaultEnabledPhotoFilters $defaultEnabledPhotoFilters,
        private EnrichedActivities $enrichedActivities,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/react-preview/api/photos', methods: ['GET'], priority: 6)]
    public function handle(): JsonResponse
    {
        $this->currentAppUser->require();

        $images = $this->loadImages();

        return new JsonResponse([
            'requestedAt' => $this->clock->getCurrentDateTimeImmutable()->format(DATE_ATOM),
            'summary' => [
                'totalImages' => count($images),
                'portraitImages' => count(array_filter($images, fn (array $image): bool => ImageOrientation::PORTRAIT->name === $image['orientation'])),
                'landscapeImages' => count(array_filter($images, fn (array $image): bool => ImageOrientation::LANDSCAPE->name === $image['orientation'])),
                'countriesCount' => count(array_unique(array_merge(
                    [],
                    ...array_map(
                        fn (array $image): array => array_map(
                            fn (array $country): string => $country['value'],
                            $image['countries'],
                        ),
                        $images,
                    ),
                ))),
            ],
            'filters' => [
                'sportTypes' => $this->buildSportTypeFilters(),
                'countries' => $this->buildCountryFilters(),
            ],
            'defaultEnabledFilters' => $this->serializeDefaultEnabledFilters(),
            'images' => $images,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadImages(): array
    {
        $countryNames = $this->countries->getUsedInPhotos();

        return array_map(
            fn (Image $image): array => $this->serializeImage($image, $countryNames),
            $this->imageRepository->findAll()->toArray(),
        );
    }

    /**
     * @param array<string, string> $countryNames
     *
     * @return array<string, mixed>
     */
    private function serializeImage(Image $image, array $countryNames): array
    {
        try {
            $activity = $this->enrichedActivities->find($image->getActivityId());
            $activityName = $activity->getName();
            $activityDate = $activity->getStartDate()->format(DATE_ATOM);
            $activityUrl = $activity->getUrl();
        } catch (EntityNotFound) {
            $activityName = 'Activity unavailable';
            $activityDate = null;
            $activityUrl = null;
        }

        $countries = array_values(array_unique(array_map(
            static fn (string $countryCode): string => strtolower($countryCode),
            $image->getRelatedCountryCodes(),
        )));

        return [
            'id' => sprintf('%s:%s', $image->getActivityId()->toUnprefixedString(), ltrim($image->getImageUrl(), '/')),
            'imageUrl' => $image->getImageUrl(),
            'activityId' => $image->getActivityId()->toUnprefixedString(),
            'activityName' => $activityName,
            'activityDate' => $activityDate,
            'activityUrl' => $activityUrl,
            'sportType' => [
                'value' => $image->getSportType()->value,
                'label' => $image->getSportType()->trans($this->translator),
            ],
            'orientation' => $image->getOrientation()->name,
            'countries' => array_map(
                fn (string $countryCode): array => [
                    'value' => $countryCode,
                    'label' => $countryNames[$countryCode] ?? strtoupper($countryCode),
                ],
                $countries,
            ),
        ];
    }

    /**
     * @return array{sportTypes: list<string>, countryCode: string|null}
     */
    private function serializeDefaultEnabledFilters(): array
    {
        $serialized = json_decode(
            json_encode($this->defaultEnabledPhotoFilters, JSON_THROW_ON_ERROR),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return [
            'sportTypes' => array_values(array_filter(
                $serialized['sportType'] ?? [],
                static fn (mixed $value): bool => is_string($value) && '' !== $value,
            )),
            'countryCode' => isset($serialized['countryCode']) && is_string($serialized['countryCode'])
                ? strtolower($serialized['countryCode'])
                : null,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function buildSportTypeFilters(): array
    {
        $options = [];
        foreach ($this->sportTypeRepository->findForImages() as $sportType) {
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
        foreach ($this->countries->getUsedInPhotos() as $countryCode => $countryName) {
            $options[] = [
                'value' => $countryCode,
                'label' => $countryName,
            ];
        }

        return $options;
    }
}