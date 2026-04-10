<?php

declare(strict_types=1);

namespace App\Application\Build\BuildTrainingAdvisorExport;

use App\Domain\Integration\AI\TrainingAdvisorExportBuilder;
use App\Infrastructure\CQRS\Command\Command;
use App\Infrastructure\CQRS\Command\CommandHandler;
use App\Infrastructure\Serialization\Json;
use League\Flysystem\FilesystemOperator;

final readonly class BuildTrainingAdvisorExportCommandHandler implements CommandHandler
{
    public function __construct(
        private TrainingAdvisorExportBuilder $trainingAdvisorExportBuilder,
        private FilesystemOperator $apiStorage,
    ) {
    }

    public function handle(Command $command): void
    {
        assert($command instanceof BuildTrainingAdvisorExport);

        $this->apiStorage->write(
            'exports/training-advisor.json',
            (string) Json::encodeAndCompress(
                $this->trainingAdvisorExportBuilder->build($command->getCurrentDateTime())
            ),
        );
    }
}
