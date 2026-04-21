<?php

declare(strict_types=1);

namespace App\Application\React;

use App\Application\AppUrl;

final readonly class BuildReactAppBootstrap
{
    public function __construct(
        private AppUrl $appUrl,
    ) {
    }

    /**
     * @param array<string, mixed> $indexContext
     *
     * @return array<string, mixed>
     */
    public function build(array $indexContext, string $experience): array
    {
        $athlete = $indexContext['athlete'];
        $subtitle = $indexContext['subTitle'];
        $profilePictureUrl = $indexContext['profilePictureUrl'];

        return [
            'appName' => 'Statistics for Strava',
            'subtitle' => null !== $subtitle ? (string) $subtitle : null,
            'experience' => $experience,
            'routerBasePath' => $this->buildRouterBasePath($experience),
            'athlete' => [
                'name' => $athlete->getName(),
                'initial' => strtoupper($athlete->getFirstLetterOfFirstName()),
            ],
            'profilePictureUrl' => null !== $profilePictureUrl ? (string) $profilePictureUrl : null,
            'counts' => [
                'activities' => $indexContext['totalActivityCount'],
                'challenges' => $indexContext['completedChallenges'],
                'photos' => $indexContext['totalPhotoCount'],
                'hasGear' => $indexContext['hasGear'],
                'hasBestEfforts' => $indexContext['hasBestEfforts'],
            ],
            'basePath' => $this->appUrl->getBasePath() ?? '',
        ];
    }

    private function buildRouterBasePath(string $experience): string
    {
        $segments = [];
        $basePath = trim((string) ($this->appUrl->getBasePath() ?? ''), '/');

        if ('' !== $basePath) {
            $segments[] = $basePath;
        }

        if ('preview' === $experience) {
            $segments[] = 'react-preview';
        }

        if ([] === $segments) {
            return '';
        }

        return '/'.implode('/', $segments);
    }
}
