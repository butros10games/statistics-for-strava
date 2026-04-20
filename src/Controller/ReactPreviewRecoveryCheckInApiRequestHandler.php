<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Build\BuildDashboardHtml\BuildDashboardHtml;
use App\Domain\Wellness\DailyRecoveryCheckIn;
use App\Domain\Wellness\DbalDailyRecoveryCheckInRepository;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class ReactPreviewRecoveryCheckInApiRequestHandler
{
    public function __construct(
        private CurrentAppUser $currentAppUser,
        private DbalDailyRecoveryCheckInRepository $repository,
        private CommandBus $commandBus,
        private Clock $clock,
    ) {
    }

    #[Route(path: '/react-preview/api/recovery-check-in', methods: ['GET'], priority: 6)]
    public function handle(): JsonResponse
    {
        $this->currentAppUser->require();

        return new JsonResponse($this->buildPayload());
    }

    #[Route(path: '/react-preview/api/recovery-check-in', methods: ['POST'], priority: 6)]
    public function save(Request $request): JsonResponse
    {
        $this->currentAppUser->require();

        $requestedAt = $this->clock->getCurrentDateTimeImmutable();
        $today = $requestedAt->setTime(0, 0);
        $payload = $this->readPayload($request);
        $day = SerializableDateTime::fromString((string) ($payload['day'] ?? $today->format('Y-m-d')));

        $dailyRecoveryCheckIn = DailyRecoveryCheckIn::create(
            day: $day,
            fatigue: $this->normalizeScore($payload['fatigue'] ?? 3),
            soreness: $this->normalizeScore($payload['soreness'] ?? 3),
            stress: $this->normalizeScore($payload['stress'] ?? 3),
            motivation: $this->normalizeScore($payload['motivation'] ?? 3),
            sleepQuality: $this->normalizeScore($payload['sleepQuality'] ?? 3),
            recordedAt: $requestedAt,
        );

        $this->repository->upsert($dailyRecoveryCheckIn);
        $this->commandBus->dispatch(new BuildDashboardHtml());

        return new JsonResponse($this->buildPayload($dailyRecoveryCheckIn->getDay()));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(?SerializableDateTime $savedDay = null): array
    {
        $requestedAt = $this->clock->getCurrentDateTimeImmutable();
        $today = $requestedAt->setTime(0, 0);
        $todayCheckIn = $this->repository->findByDay($today);
        $latestCheckIn = $this->repository->findLatest();
        $effectiveCheckIn = $todayCheckIn ?? $latestCheckIn;

        return [
            'requestedAt' => $requestedAt->format(DATE_ATOM),
            'legacyPath' => 'recovery-check-in?redirectTo=/dashboard',
            'savedDay' => $savedDay?->format('Y-m-d'),
            'summary' => [
                'state' => null !== $todayCheckIn
                    ? 'updated-today'
                    : (null !== $latestCheckIn ? 'stale' : 'empty'),
                'hasTodayCheckIn' => null !== $todayCheckIn,
                'latestDay' => $latestCheckIn?->getDay()->format('Y-m-d'),
                'averageScore' => null === $effectiveCheckIn ? null : $this->calculateAverageScore($effectiveCheckIn),
                'readinessScore' => null === $effectiveCheckIn ? null : $this->calculateReadinessScore($effectiveCheckIn),
            ],
            'form' => [
                'day' => $today->format('Y-m-d'),
                'defaults' => $this->recoveryCheckInFormDefaults($todayCheckIn),
                'scale' => [
                    'min' => 1,
                    'max' => 5,
                    'neutral' => 3,
                ],
            ],
            'todayCheckIn' => null === $todayCheckIn ? null : $this->serializeCheckIn($todayCheckIn),
            'latestCheckIn' => null === $latestCheckIn ? null : $this->serializeCheckIn($latestCheckIn),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readPayload(Request $request): array
    {
        if ([] !== $request->request->all()) {
            return $request->request->all();
        }

        $content = trim((string) $request->getContent());
        if ('' === $content) {
            return [];
        }

        try {
            $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($payload) ? $payload : [];
    }

    private function normalizeScore(mixed $value): int
    {
        return max(1, min((int) $value, 5));
    }

    private function calculateAverageScore(DailyRecoveryCheckIn $dailyRecoveryCheckIn): float
    {
        return round((
            $dailyRecoveryCheckIn->getFatigue()
            + $dailyRecoveryCheckIn->getSoreness()
            + $dailyRecoveryCheckIn->getStress()
            + $dailyRecoveryCheckIn->getMotivation()
            + $dailyRecoveryCheckIn->getSleepQuality()
        ) / 5, 1);
    }

    private function calculateReadinessScore(DailyRecoveryCheckIn $dailyRecoveryCheckIn): float
    {
        return round((
            (6 - $dailyRecoveryCheckIn->getFatigue())
            + (6 - $dailyRecoveryCheckIn->getSoreness())
            + (6 - $dailyRecoveryCheckIn->getStress())
            + $dailyRecoveryCheckIn->getMotivation()
            + $dailyRecoveryCheckIn->getSleepQuality()
        ) / 5, 1);
    }

    /**
     * @return array{day: string, fatigue: int, soreness: int, stress: int, motivation: int, sleepQuality: int, averageScore: float, readinessScore: float}
     */
    private function serializeCheckIn(DailyRecoveryCheckIn $dailyRecoveryCheckIn): array
    {
        return [
            'day' => $dailyRecoveryCheckIn->getDay()->format('Y-m-d'),
            'fatigue' => $dailyRecoveryCheckIn->getFatigue(),
            'soreness' => $dailyRecoveryCheckIn->getSoreness(),
            'stress' => $dailyRecoveryCheckIn->getStress(),
            'motivation' => $dailyRecoveryCheckIn->getMotivation(),
            'sleepQuality' => $dailyRecoveryCheckIn->getSleepQuality(),
            'averageScore' => $this->calculateAverageScore($dailyRecoveryCheckIn),
            'readinessScore' => $this->calculateReadinessScore($dailyRecoveryCheckIn),
        ];
    }

    /**
     * @return array{fatigue: int, soreness: int, stress: int, motivation: int, sleepQuality: int}
     */
    private function recoveryCheckInFormDefaults(?DailyRecoveryCheckIn $dailyRecoveryCheckIn): array
    {
        if ($dailyRecoveryCheckIn instanceof DailyRecoveryCheckIn) {
            return [
                'fatigue' => $dailyRecoveryCheckIn->getFatigue(),
                'soreness' => $dailyRecoveryCheckIn->getSoreness(),
                'stress' => $dailyRecoveryCheckIn->getStress(),
                'motivation' => $dailyRecoveryCheckIn->getMotivation(),
                'sleepQuality' => $dailyRecoveryCheckIn->getSleepQuality(),
            ];
        }

        return [
            'fatigue' => 3,
            'soreness' => 3,
            'stress' => 3,
            'motivation' => 3,
            'sleepQuality' => 3,
        ];
    }
}
