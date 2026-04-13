<?php

declare(strict_types=1);

namespace App\Application\Build\BuildDashboardHtml;

use App\Domain\Activity\ActivityIntensity;
use App\Domain\Activity\EnrichedActivities;
use App\Domain\Activity\Stream\StreamBasedActivityPowerRepository;
use App\Domain\Dashboard\Widget\Widgets;
use App\Infrastructure\CQRS\Command\Command;
use App\Infrastructure\CQRS\Command\CommandHandler;
use League\Flysystem\FilesystemOperator;
use Twig\Environment;

final readonly class BuildDashboardHtmlCommandHandler implements CommandHandler
{
    public function __construct(
        private Widgets $widgets,
        private Environment $twig,
        private FilesystemOperator $buildStorage,
    ) {
    }

    public function handle(Command $command): void
    {
        assert($command instanceof BuildDashboardHtml);

        EnrichedActivities::reset();
        ActivityIntensity::reset();
        StreamBasedActivityPowerRepository::reset();

        $this->buildStorage->write(
            'dashboard.html',
            $this->twig->load('html/dashboard/dashboard.html.twig')->render([
                'widgets' => $this->widgets,
            ]),
        );
    }
}
