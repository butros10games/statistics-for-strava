<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Countries;
use App\Domain\Activity\Device\DeviceRepository;
use App\Domain\Activity\SportType\SportType;
use App\Domain\Activity\SportType\SportTypeRepository;
use App\Domain\Activity\WorkoutType;
use App\Domain\Gear\Gear;
use App\Domain\Gear\GearRepository;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToReadFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsController]
final readonly class ReactPreviewActivitiesApiRequestHandler
{
    public function __construct(
        private CurrentAppUser $currentAppUser,
        private Clock $clock,
        private FilesystemOperator $apiStorage,
        private SportTypeRepository $sportTypeRepository,
        private DeviceRepository $deviceRepository,
        private GearRepository $gearRepository,
        private Countries $countries,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/react-preview/api/activities', methods: ['GET'], priority: 6)]
    public function handle(): JsonResponse
    {
        $this->currentAppUser->require();

        return new JsonResponse([
            'requestedAt' => $this->clock->getCurrentDateTimeImmutable()->format(DATE_ATOM),
            'rows' => $this->loadRows(),
            'filters' => [
                'sportTypes' => $this->buildSportTypeFilters(),
                'countries' => $this->buildCountryFilters(),
                'gears' => $this->buildGearFilters(),
                'devices' => $this->buildDeviceFilters(),
                'workoutTypes' => $this->buildWorkoutTypeFilters(),
            ],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadRows(): array
    {
        try {
            if (!$this->apiStorage->fileExists('activity/data-table.json')) {
                return [];
            }

            $payload = Json::uncompressAndDecode($this->apiStorage->read('activity/data-table.json'));

            return is_array($payload) ? array_values($payload) : [];
        } catch (UnableToReadFile) {
            return [];
        }
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
        foreach ($this->countries->getUsedInActivities() as $countryCode => $countryName) {
            $options[] = [
                'value' => $countryCode,
                'label' => $countryName,
            ];
        }

        return $options;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function buildGearFilters(): array
    {
        $options = [];
        foreach ($this->gearRepository->findAllUsed() as $gear) {
            if (!$gear instanceof Gear) {
                continue;
            }

            $options[] = [
                'value' => (string) $gear->getId(),
                'label' => $gear->getName(),
            ];
        }

        $options[] = [
            'value' => 'gear-none',
            'label' => $this->translator->trans('Unspecified'),
        ];

        return $options;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function buildDeviceFilters(): array
    {
        $options = [];
        foreach ($this->deviceRepository->findAll() as $device) {
            $options[] = [
                'value' => $device->getId(),
                'label' => $device->getName(),
            ];
        }

        $options[] = [
            'value' => 'device-none',
            'label' => $this->translator->trans('Unspecified'),
        ];

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