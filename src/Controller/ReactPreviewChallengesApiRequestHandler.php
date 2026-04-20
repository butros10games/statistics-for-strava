<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Challenge\Challenge;
use App\Domain\Challenge\ChallengeRepository;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class ReactPreviewChallengesApiRequestHandler
{
    public function __construct(
        private CurrentAppUser $currentAppUser,
        private Clock $clock,
        private ChallengeRepository $challengeRepository,
    ) {
    }

    #[Route(path: '/react-preview/api/challenges', methods: ['GET'], priority: 6)]
    public function handle(): JsonResponse
    {
        $this->currentAppUser->require();

        $groups = $this->buildGroups();

        return new JsonResponse([
            'requestedAt' => $this->clock->getCurrentDateTimeImmutable()->format(DATE_ATOM),
            'summary' => [
                'totalChallenges' => array_sum(array_map(static fn (array $group): int => $group['count'], $groups)),
                'monthsCount' => count($groups),
                'localLogoCount' => array_sum(array_map(
                    static fn (array $group): int => count(array_filter(
                        $group['challenges'],
                        static fn (array $challenge): bool => true === $challenge['hasLocalLogo'],
                    )),
                    $groups,
                )),
                'remoteLogoCount' => array_sum(array_map(
                    static fn (array $group): int => count(array_filter(
                        $group['challenges'],
                        static fn (array $challenge): bool => false === $challenge['hasLocalLogo'] && null !== $challenge['logoUrl'],
                    )),
                    $groups,
                )),
            ],
            'filters' => [
                'years' => $this->buildYearFilters($groups),
            ],
            'groups' => $groups,
        ]);
    }

    /**
     * @return list<array{monthId: string, monthLabel: string, year: int, count: int, challenges: list<array<string, mixed>>}>
     */
    private function buildGroups(): array
    {
        $grouped = [];

        foreach ($this->challengeRepository->findAll() as $challenge) {
            $monthId = $challenge->getCreatedOn()->format('Y-m');

            if (!isset($grouped[$monthId])) {
                $grouped[$monthId] = [
                    'monthId' => $monthId,
                    'monthLabel' => $challenge->getCreatedOn()->translatedFormat('F Y'),
                    'year' => (int) $challenge->getCreatedOn()->format('Y'),
                    'count' => 0,
                    'challenges' => [],
                ];
            }

            $grouped[$monthId]['count']++;
            $grouped[$monthId]['challenges'][] = $this->serializeChallenge($challenge);
        }

        foreach ($grouped as &$group) {
            usort(
                $group['challenges'],
                static fn (array $left, array $right): int => strcmp($right['completedDate'], $left['completedDate']),
            );
        }
        unset($group);

        krsort($grouped);

        return array_values($grouped);
    }

    /**
     * @return array{id: string, name: string, logoUrl: string|null, externalUrl: string, completedDate: string, hasLocalLogo: bool}
     */
    private function serializeChallenge(Challenge $challenge): array
    {
        $localLogoUrl = $challenge->getLocalLogoUrl();

        return [
            'id' => $challenge->getId()->toUnprefixedString(),
            'name' => $challenge->getName(),
            'logoUrl' => $localLogoUrl ?? $challenge->getLogoUrl(),
            'externalUrl' => $challenge->getUrl(),
            'completedDate' => $challenge->getCreatedOn()->format(DATE_ATOM),
            'hasLocalLogo' => null !== $localLogoUrl,
        ];
    }

    /**
     * @param list<array{monthId: string, monthLabel: string, year: int, count: int, challenges: list<array<string, mixed>>}> $groups
     *
     * @return list<array{value: string, label: string, count: int}>
     */
    private function buildYearFilters(array $groups): array
    {
        $counts = [];

        foreach ($groups as $group) {
            $year = (string) $group['year'];
            $counts[$year] = ($counts[$year] ?? 0) + $group['count'];
        }

        krsort($counts);

        $filters = [];
        foreach ($counts as $year => $count) {
            $filters[] = [
                'value' => $year,
                'label' => $year,
                'count' => $count,
            ];
        }

        return $filters;
    }
}