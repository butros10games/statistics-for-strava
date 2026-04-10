<?php

declare(strict_types=1);

namespace App\Application\Import\ImportWellness;

use App\Domain\Wellness\DbalDailyWellnessRepository;
use App\Domain\Wellness\WellnessProvider;
use App\Infrastructure\CQRS\Command\Command;
use App\Infrastructure\CQRS\Command\CommandHandler;

final readonly class ImportWellnessCommandHandler implements CommandHandler
{
    public function __construct(
        private WellnessProvider $wellnessProvider,
        private DbalDailyWellnessRepository $dailyWellnessRepository,
    ) {
    }

    public function handle(Command $command): void
    {
        assert($command instanceof ImportWellness);

        $command->getOutput()->writeln('Importing wellness data...');
        $records = $this->wellnessProvider->fetch();

        foreach ($records as $record) {
            $this->dailyWellnessRepository->upsert($record);
        }

        $command->getOutput()->writeln(sprintf('Imported %d wellness day(s).', count($records)));
    }
}