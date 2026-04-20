<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Dashboard\RenderedWidget;
use App\Domain\Dashboard\Widget\Widgets;
use App\Infrastructure\Time\Clock\Clock;
use App\Infrastructure\User\CurrentAppUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class ReactPreviewDashboardApiRequestHandler
{
    public function __construct(
        private CurrentAppUser $currentAppUser,
        private Clock $clock,
        private Widgets $widgets,
    ) {
    }

    #[Route(path: '/react-preview/api/dashboard', methods: ['GET'], priority: 6)]
    public function handle(): JsonResponse
    {
        $this->currentAppUser->require();

        $sections = [];
        $totalWidgets = 0;

        foreach ($this->widgets as $index => $renderedWidget) {
            assert($renderedWidget instanceof RenderedWidget);

            $sectionLabel = $renderedWidget->getSection() ?? 'General';
            $sectionId = $this->buildSectionId($sectionLabel);
            if (!isset($sections[$sectionId])) {
                $sections[$sectionId] = [
                    'id' => $sectionId,
                    'label' => $sectionLabel,
                    'widgets' => [],
                ];
            }

            $sections[$sectionId]['widgets'][] = [
                'id' => sprintf('dashboard-widget-%d', $index + 1),
                'width' => $renderedWidget->getWidth(),
                'html' => $this->normalizeWidgetHtml($renderedWidget->getRenderedHtml()),
            ];
            ++$totalWidgets;
        }

        return new JsonResponse([
            'requestedAt' => $this->clock->getCurrentDateTimeImmutable()->format(DATE_ATOM),
            'summary' => [
                'totalWidgets' => $totalWidgets,
                'sectionCount' => count($sections),
            ],
            'sections' => array_values($sections),
        ]);
    }

    private function buildSectionId(string $sectionLabel): string
    {
        $normalized = strtolower(trim($sectionLabel));
        $normalized = preg_replace('/[^a-z0-9]+/', '-', $normalized) ?? 'dashboard';

        return trim($normalized, '-') ?: 'dashboard';
    }

    private function normalizeWidgetHtml(string $html): string
    {
        $html = preg_replace('/href="#"\s+data-model-content-url="([^"]+)"/', 'href="$1"', $html) ?? $html;
        $html = preg_replace('/\sdata-model-content-url="[^"]+"/', '', $html) ?? $html;

        return $html;
    }
}
