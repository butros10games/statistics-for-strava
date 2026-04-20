<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Activity\Eddington\Eddington;
use App\Domain\Activity\Eddington\EddingtonCalculator;
use App\Domain\Activity\Eddington\EddingtonChart;
use App\Domain\Activity\Eddington\EddingtonHistoryChart;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use App\Infrastructure\ValueObject\Measurement\UnitSystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsController]
final readonly class ReactPreviewEddingtonApiRequestHandler
{
    public function __construct(
        private CurrentAppUser $currentAppUser,
        private Clock $clock,
        private UnitSystem $activeUnitSystem,
        private EddingtonCalculator $eddingtonCalculator,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/react-preview/api/eddington', methods: ['GET'], priority: 6)]
    public function handle(): JsonResponse
    {
        $this->currentAppUser->require();

        return new JsonResponse([
            'requestedAt' => $this->clock->getCurrentDateTimeImmutable()->format(DATE_ATOM),
            'activeUnitSystem' => $this->activeUnitSystem->value,
            'unitSystems' => array_map(
                fn (UnitSystem $unitSystem): array => $this->buildUnitSystemPayload($unitSystem),
                $this->activeUnitSystem->casesWithPreferredFirst(),
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildUnitSystemPayload(UnitSystem $unitSystem): array
    {
        $eddingtons = $this->eddingtonCalculator->calculate($unitSystem);

        return [
            'value' => $unitSystem->value,
            'label' => $unitSystem->trans($this->translator),
            'distanceSymbol' => $unitSystem->distanceSymbol(),
            'eddingtons' => array_map(
                fn (Eddington $eddington): array => $this->buildEddingtonPayload($eddington),
                $eddingtons,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEddingtonPayload(Eddington $eddington): array
    {
        $nextNumber = $eddington->getNumber() + 1;
        $daysNeededForFutureNumbers = $eddington->getDaysToCompleteForFutureNumbers();

        return [
            'id' => $eddington->getId(),
            'label' => $eddington->getLabel(),
            'number' => $eddington->getNumber(),
            'longestDistanceInADay' => $eddington->getLongestDistanceInADay(),
            'nextNumber' => $nextNumber,
            'daysToNextNumber' => $daysNeededForFutureNumbers[$nextNumber] ?? null,
            'historyLength' => count($eddington->getEddingtonHistory()),
            'chartOptions' => EddingtonChart::create(
                eddington: $eddington,
                unitSystem: $eddington->getUnitSystem(),
                translator: $this->translator,
            )->build(),
            'historyChartOptions' => EddingtonHistoryChart::create(
                eddington: $eddington,
            )->build(),
        ];
    }
}