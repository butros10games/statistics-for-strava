<?php

declare(strict_types=1);

namespace App\Application\Import\ImportWellness;

use App\Infrastructure\CQRS\Command\DomainCommand;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class ImportWellness extends DomainCommand
{
    public function __construct(
        private OutputInterface $output,
    ) {
    }

    public function getOutput(): OutputInterface
    {
        return $this->output;
    }
}