<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\AppUrl;
use App\Domain\Activity\ActivityType;
use App\Domain\Activity\BestEffort\BestEffortPeriod;
use App\Domain\Activity\BestEffort\BestEffortsCalculator;
use App\Domain\Activity\SportType\SportType;
use App\Domain\Zwift\ZwiftLevel;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsController]
final readonly class ReactPreviewBadgesApiRequestHandler
{
    public function __construct(
        private CurrentAppUser $currentAppUser,
        private Clock $clock,
        private BestEffortsCalculator $bestEffortsCalculator,
        private AppUrl $appUrl,
        private ?ZwiftLevel $zwiftLevel,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/react-preview/api/badges', methods: ['GET'], priority: 6)]
    public function handle(): JsonResponse
    {
        $this->currentAppUser->require();

        $sections = $this->buildSections();
        $personalBestSection = current(array_filter(
            $sections,
            static fn (array $section): bool => 'pb-badges' === $section['id'],
        )) ?: null;

        return new JsonResponse([
            'requestedAt' => $this->clock->getCurrentDateTimeImmutable()->format(DATE_ATOM),
            'summary' => [
                'totalBadges' => array_sum(array_map(static fn (array $section): int => $section['badgesCount'], $sections)),
                'categoryCount' => count($sections),
                'personalBestBadgeCount' => is_array($personalBestSection) ? $personalBestSection['badgesCount'] : 0,
                'hasZwiftBadge' => null !== $this->zwiftLevel,
            ],
            'sections' => $sections,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildSections(): array
    {
        $sections = [[
            'id' => 'user-badge',
            'label' => $this->translator->trans('User badge'),
            'description' => $this->translator->trans('These badges are dynamically created. You can use them in any <img> tag'),
            'badgesCount' => 1,
            'badges' => [
                $this->serializeBadge(
                    id: 'strava-badge',
                    name: $this->translator->trans('Your Strava badge'),
                    filePath: 'files/strava-badge.svg',
                    category: $this->translator->trans('User badge'),
                ),
            ],
        ]];

        $personalBestBadges = $this->buildPersonalBestBadges();
        if ([] !== $personalBestBadges) {
            $sections[] = [
                'id' => 'pb-badges',
                'label' => $this->translator->trans('PB badges'),
                'description' => $this->translator->trans('Personal-best badge variants for sport types that currently have all-time best efforts.'),
                'badgesCount' => count($personalBestBadges),
                'badges' => $personalBestBadges,
            ];
        }

        if ($this->zwiftLevel instanceof ZwiftLevel) {
            $sections[] = [
                'id' => 'zwift-badge',
                'label' => $this->translator->trans('Zwift badge'),
                'description' => $this->translator->trans('Your Zwift badge preview and embed snippet.'),
                'badgesCount' => 1,
                'badges' => [
                    $this->serializeBadge(
                        id: 'zwift-badge',
                        name: $this->translator->trans('Your Zwift badge'),
                        filePath: 'files/zwift-badge.svg',
                        category: $this->translator->trans('Zwift badge'),
                    ),
                ],
            ];
        }

        return $sections;
    }

    /**
     * @return list<array<string, string>>
     */
    private function buildPersonalBestBadges(): array
    {
        $badges = [];

        /** @var ActivityType $activityType */
        foreach ($this->bestEffortsCalculator->getActivityTypes() as $activityType) {
            foreach ($this->bestEffortsCalculator->getSportTypesFor(BestEffortPeriod::ALL_TIME, $activityType) as $sportType) {
                assert($sportType instanceof SportType);

                $badges[] = $this->serializeBadge(
                    id: sprintf('pb-%s-badge', strtolower($sportType->value)),
                    name: sprintf('%s PB badge', $sportType->trans($this->translator)),
                    filePath: strtolower(sprintf('files/pb-%s-badge.svg', $sportType->value)),
                    category: $this->translator->trans('PB badges'),
                );
            }
        }

        return $badges;
    }

    /**
     * @return array{id: string, name: string, category: string, imageUrl: string, absoluteUrl: string, embedCode: string}
     */
    private function serializeBadge(string $id, string $name, string $filePath, string $category): array
    {
        $absoluteUrl = sprintf('%s/%s', rtrim((string) $this->appUrl, '/'), ltrim($filePath, '/'));

        return [
            'id' => $id,
            'name' => $name,
            'category' => $category,
            'imageUrl' => $filePath,
            'absoluteUrl' => $absoluteUrl,
            'embedCode' => sprintf('<img src="%s" alt="%s"/>', $absoluteUrl, $name),
        ];
    }
}