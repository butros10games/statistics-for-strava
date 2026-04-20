<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner\Analysis;

final readonly class TrainingPlanQualityReport
{
    /**
     * @param list<TrainingPlanAnalysisIssue> $issues
     * @param array<string, mixed> $metrics
     * @param list<array<string, mixed>> $weekRows
     */
    private function __construct(
        private TrainingPlanAnalysisScenario $scenario,
        private int $score,
        private array $metrics,
        private array $issues,
        private array $weekRows,
    ) {
    }

    /**
     * @param list<TrainingPlanAnalysisIssue> $issues
     * @param array<string, mixed> $metrics
     * @param list<array<string, mixed>> $weekRows
     */
    public static function create(
        TrainingPlanAnalysisScenario $scenario,
        int $score,
        array $metrics,
        array $issues,
        array $weekRows,
    ): self {
        return new self(
            scenario: $scenario,
            score: max(0, min(100, $score)),
            metrics: $metrics,
            issues: $issues,
            weekRows: $weekRows,
        );
    }

    public function getScenario(): TrainingPlanAnalysisScenario
    {
        return $this->scenario;
    }

    public function getScore(): int
    {
        return $this->score;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetrics(): array
    {
        return $this->metrics;
    }

    /**
     * @return list<TrainingPlanAnalysisIssue>
     */
    public function getIssues(): array
    {
        return $this->issues;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getWeekRows(): array
    {
        return $this->weekRows;
    }

    /**
     * @return array{critical: int, warning: int, info: int}
     */
    public function getIssueCountsBySeverity(): array
    {
        $counts = [
            'critical' => 0,
            'warning' => 0,
            'info' => 0,
        ];

        foreach ($this->issues as $issue) {
            $severity = $issue->getSeverity();
            if (!array_key_exists($severity, $counts)) {
                continue;
            }

            ++$counts[$severity];
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'scenario' => $this->scenario->toArray(),
            'score' => $this->score,
            'metrics' => $this->metrics,
            'issueCounts' => $this->getIssueCountsBySeverity(),
            'issues' => array_map(
                static fn (TrainingPlanAnalysisIssue $issue): array => $issue->toArray(),
                $this->issues,
            ),
            'weeks' => $this->weekRows,
        ];
    }
}
