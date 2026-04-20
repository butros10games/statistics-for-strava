<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Build\BuildDashboardHtml\BuildDashboardHtml;
use App\Domain\Wellness\DailyRecoveryCheckIn;
use App\Domain\Wellness\DbalDailyRecoveryCheckInRepository;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[AsController]
final readonly class DailyRecoveryCheckInRequestHandler
{
    public function __construct(
        private DbalDailyRecoveryCheckInRepository $repository,
        private CommandBus $commandBus,
        private Clock $clock,
        private Environment $twig,
    ) {
    }

    #[Route(path: '/recovery-check-in', methods: ['GET', 'POST'])]
    public function handle(Request $request): Response
    {
        if ($request->isMethod('GET')) {
            return $this->renderModal($request->query->getString('redirectTo', '/dashboard'));
        }

        $redirectTo = $request->request->getString('redirectTo', '/dashboard');

        $day = $request->request->getString('day', $this->clock->getCurrentDateTimeImmutable()->format('Y-m-d'));

        $this->repository->upsert(DailyRecoveryCheckIn::create(
            day: SerializableDateTime::fromString($day),
            fatigue: $this->normalizeScore($request->request->getInt('fatigue', 3)),
            soreness: $this->normalizeScore($request->request->getInt('soreness', 3)),
            stress: $this->normalizeScore($request->request->getInt('stress', 3)),
            motivation: $this->normalizeScore($request->request->getInt('motivation', 3)),
            sleepQuality: $this->normalizeScore($request->request->getInt('sleepQuality', 3)),
            recordedAt: $this->clock->getCurrentDateTimeImmutable(),
        ));

        $this->commandBus->dispatch(new BuildDashboardHtml());

        return new RedirectResponse($redirectTo, Response::HTTP_FOUND);
    }

    private function renderModal(string $redirectTo): Response
    {
        $today = $this->clock->getCurrentDateTimeImmutable()->setTime(0, 0);
        $latestRecoveryCheckIn = $this->repository->findByDay($today);
        $latestRecoveryCheckInOverall = $this->repository->findLatest();

        return new Response($this->twig->render('html/dashboard/recovery-check-in.html.twig', [
            'latestRecoveryCheckIn' => null === $latestRecoveryCheckIn ? null : $this->toViewRecord($latestRecoveryCheckIn),
            'latestRecoveryCheckInOverall' => null === $latestRecoveryCheckInOverall ? null : $this->toViewRecord($latestRecoveryCheckInOverall),
            'recoveryCheckInDefaultDay' => $today->format('Y-m-d'),
            'recoveryCheckInFormDefaults' => $this->recoveryCheckInFormDefaults($latestRecoveryCheckIn),
            'redirectTo' => $redirectTo,
        ]));
    }

    private function normalizeScore(int $value): int
    {
        return max(1, min($value, 5));
    }

    /**
     * @return array{day: string, fatigue: int, soreness: int, stress: int, motivation: int, sleepQuality: int}
     */
    private function toViewRecord(DailyRecoveryCheckIn $dailyRecoveryCheckIn): array
    {
        return [
            'day' => $dailyRecoveryCheckIn->getDay()->format('Y-m-d'),
            'fatigue' => $dailyRecoveryCheckIn->getFatigue(),
            'soreness' => $dailyRecoveryCheckIn->getSoreness(),
            'stress' => $dailyRecoveryCheckIn->getStress(),
            'motivation' => $dailyRecoveryCheckIn->getMotivation(),
            'sleepQuality' => $dailyRecoveryCheckIn->getSleepQuality(),
        ];
    }

    /**
     * @return array{fatigue: int, soreness: int, stress: int, motivation: int, sleepQuality: int}
     */
    private function recoveryCheckInFormDefaults(?DailyRecoveryCheckIn $latestRecoveryCheckIn): array
    {
        if (null !== $latestRecoveryCheckIn) {
            return [
                'fatigue' => $latestRecoveryCheckIn->getFatigue(),
                'soreness' => $latestRecoveryCheckIn->getSoreness(),
                'stress' => $latestRecoveryCheckIn->getStress(),
                'motivation' => $latestRecoveryCheckIn->getMotivation(),
                'sleepQuality' => $latestRecoveryCheckIn->getSleepQuality(),
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