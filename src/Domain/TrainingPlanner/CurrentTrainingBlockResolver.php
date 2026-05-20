<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final readonly class CurrentTrainingBlockResolver
{
    /**
     * @param list<TrainingBlock> $trainingBlocks
     */
    public function findCurrent(array $trainingBlocks, SerializableDateTime $referenceDate): ?TrainingBlock
    {
        foreach ($trainingBlocks as $trainingBlock) {
            if ($trainingBlock->containsDay($referenceDate)) {
                return $trainingBlock;
            }
        }

        return null;
    }
}