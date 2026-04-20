<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final readonly class RacePlannerExistingBlockSelector
{
    /**
     * @param list<TrainingBlock> $blocks
     *
     * @return list<TrainingBlock>
     */
    public function selectReusableBlocks(RaceEvent $targetRace, array $blocks, ?SerializableDateTime $planningEndDay = null): array
    {
        if ([] === $blocks) {
            return [];
        }

        $planningEndDay ??= $targetRace->getDay()->setTime(23, 59, 59);

        usort($blocks, static fn (TrainingBlock $left, TrainingBlock $right): int => [$left->getStartDay(), $left->getEndDay()] <=> [$right->getStartDay(), $right->getEndDay()]);

        $linkedBlocks = array_values(array_filter(
            $blocks,
            static fn (TrainingBlock $block): bool => null !== $block->getTargetRaceEventId()
                && (string) $block->getTargetRaceEventId() === (string) $targetRace->getId(),
        ));

        if ([] !== $linkedBlocks) {
            return $this->expandContiguousWindow($linkedBlocks, $blocks, $targetRace->getDay(), $planningEndDay);
        }

        $anchorBlock = $this->findFallbackAnchorBlock($blocks, $targetRace->getDay());

        return null === $anchorBlock
            ? []
            : $this->expandContiguousWindow([$anchorBlock], $blocks, $targetRace->getDay(), $planningEndDay);
    }

    /**
     * @param list<TrainingBlock> $blocks
     */
    private function findFallbackAnchorBlock(array $blocks, SerializableDateTime $raceDay): ?TrainingBlock
    {
        for ($index = count($blocks) - 1; $index >= 0; --$index) {
            $block = $blocks[$index];

            if ($block->getStartDay() > $raceDay) {
                continue;
            }

            if ($block->containsDay($raceDay)) {
                return $block;
            }

            if ($block->getEndDay() > $raceDay) {
                continue;
            }

            if ($this->isWithinGap($block->getEndDay(), $raceDay, 14)) {
                return $block;
            }
        }

        return null;
    }

    /**
     * @param list<TrainingBlock> $seedBlocks
     * @param list<TrainingBlock> $allBlocks
     *
     * @return list<TrainingBlock>
     */
    private function expandContiguousWindow(array $seedBlocks, array $allBlocks, SerializableDateTime $raceDay, SerializableDateTime $planningEndDay): array
    {
        $seedBlockIds = array_fill_keys(array_map(static fn (TrainingBlock $block): string => (string) $block->getId(), $seedBlocks), true);
        $selectedStartIndex = null;
        $selectedEndIndex = null;

        foreach ($allBlocks as $index => $block) {
            if (!isset($seedBlockIds[(string) $block->getId()])) {
                continue;
            }

            $selectedStartIndex ??= $index;
            $selectedEndIndex = $index;
        }

        if (null === $selectedStartIndex || null === $selectedEndIndex) {
            return [];
        }

        while ($selectedStartIndex > 0) {
            $candidate = $allBlocks[$selectedStartIndex - 1];
            $currentFirstBlock = $allBlocks[$selectedStartIndex];

            if ($candidate->getStartDay() > $raceDay || !$this->isWithinGap($candidate->getEndDay(), $currentFirstBlock->getStartDay(), 7)) {
                break;
            }

            --$selectedStartIndex;
        }

        while ($selectedEndIndex < count($allBlocks) - 1) {
            $candidate = $allBlocks[$selectedEndIndex + 1];
            $currentLastBlock = $allBlocks[$selectedEndIndex];

            if ($candidate->getStartDay() > $planningEndDay
                || !$this->isWithinGap($currentLastBlock->getEndDay(), $candidate->getStartDay(), 7)
                || ($candidate->getStartDay() > $raceDay && TrainingBlockPhase::RECOVERY !== $candidate->getPhase())) {
                break;
            }

            ++$selectedEndIndex;
        }

        return array_values(array_filter(
            array_slice($allBlocks, $selectedStartIndex, $selectedEndIndex - $selectedStartIndex + 1),
            static fn (TrainingBlock $block): bool => $block->getStartDay() <= $planningEndDay
                && ($block->getStartDay() <= $raceDay || TrainingBlockPhase::RECOVERY === $block->getPhase()),
        ));
    }

    private function isWithinGap(SerializableDateTime $earlierEnd, SerializableDateTime $laterStart, int $gapDays): bool
    {
        if ($earlierEnd >= $laterStart) {
            return true;
        }

        return $earlierEnd->modify(sprintf('+%d days', $gapDays)) >= $laterStart;
    }
}