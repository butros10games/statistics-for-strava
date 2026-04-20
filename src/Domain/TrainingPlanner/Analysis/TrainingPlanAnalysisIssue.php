<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner\Analysis;

final readonly class TrainingPlanAnalysisIssue
{
    private function __construct(
        private string $code,
        private string $severity,
        private string $message,
        private array $examples,
    ) {
    }

    /**
     * @param list<string> $examples
     */
    public static function create(string $code, string $severity, string $message, array $examples = []): self
    {
        return new self(
            code: $code,
            severity: $severity,
            message: $message,
            examples: array_values($examples),
        );
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getSeverity(): string
    {
        return $this->severity;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @return list<string>
     */
    public function getExamples(): array
    {
        return $this->examples;
    }

    /**
     * @return array{code: string, severity: string, message: string, examples: list<string>}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity,
            'message' => $this->message,
            'examples' => $this->examples,
        ];
    }
}
