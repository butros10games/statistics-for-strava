<?php

declare(strict_types=1);

namespace App\Console;

use App\Domain\TrainingPlanner\Analysis\TrainingPlanAnalysisScenarioMatrix;
use App\Domain\TrainingPlanner\Analysis\TrainingPlanQualityAnalyzer;
use App\Domain\TrainingPlanner\PlanGenerator\TrainingPlanGenerator;
use App\Domain\TrainingPlanner\TrainingPlanDiscipline;
use App\Domain\TrainingPlanner\TrainingPlanType;
use App\Infrastructure\Console\ProvideConsoleIntro;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:training:analyze-plans', description: 'Generate many running, cycling, and triathlon plans and analyze quality signals across the full scenario matrix')]
final class AnalyzeTrainingPlansConsoleCommand extends Command
{
    use ProvideConsoleIntro;

    public function __construct(
        private readonly TrainingPlanGenerator $trainingPlanGenerator,
        private readonly TrainingPlanAnalysisScenarioMatrix $scenarioMatrix,
        private readonly TrainingPlanQualityAnalyzer $qualityAnalyzer,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('discipline', null, InputOption::VALUE_REQUIRED, 'Filter scenarios by discipline (all|running|cycling|triathlon)', 'all')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filter scenarios by plan type (all|race|training)', 'all')
            ->addOption('scenario', null, InputOption::VALUE_REQUIRED, 'Filter scenarios by scenario slug or label substring')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format (table|json)', 'table')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Optional file path where the JSON payload should be written');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = mb_strtolower((string) $input->getOption('format'));
        if (!in_array($format, ['table', 'json'], true)) {
            throw new \InvalidArgumentException('The --format option must be either "table" or "json".');
        }

        $discipline = $this->parseDisciplineOption((string) $input->getOption('discipline'));
        $type = $this->parseTypeOption((string) $input->getOption('type'));
        $scenarioFilter = $input->getOption('scenario');
        $outputPath = $input->getOption('output');

        $scenarios = $this->scenarioMatrix->build(
            disciplineFilter: $discipline,
            typeFilter: $type,
            scenarioNameFilter: is_string($scenarioFilter) ? $scenarioFilter : null,
        );

        if ([] === $scenarios) {
            if ('json' === $format) {
                $output->writeln((string) json_encode([
                    'generatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
                    'scenarioCount' => 0,
                    'aggregates' => [],
                    'reports' => [],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return Command::SUCCESS;
            }

            $io = new SymfonyStyle($input, $output);
            $this->outputConsoleIntro($io);
            $io->warning('No analysis scenarios matched the selected filters.');

            return Command::SUCCESS;
        }

        $reports = [];
        foreach ($scenarios as $scenario) {
            $proposal = $this->trainingPlanGenerator->generate(
                targetRace: $scenario->getTargetRace(),
                planStartDay: $scenario->getPlanStartDay(),
                allRaceEvents: $scenario->getAllRaceEvents(),
                existingBlocks: [],
                existingSessions: [],
                referenceDate: $scenario->getReferenceDate(),
                linkedTrainingPlan: $scenario->getLinkedTrainingPlan(),
            );
            $reports[] = $this->qualityAnalyzer->analyze($scenario, $proposal);
        }

        $payload = [
            'generatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'scenarioCount' => count($reports),
            'aggregates' => $this->buildAggregatePayload($reports),
            'reports' => array_map(
                static fn ($report): array => $report->toArray(),
                $reports,
            ),
        ];

        if (is_string($outputPath) && '' !== trim($outputPath)) {
            $directory = dirname($outputPath);
            if (!is_dir($directory)) {
                @mkdir($directory, 0777, true);
            }
            file_put_contents($outputPath, (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        if ('json' === $format) {
            $output->writeln((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);
        $this->outputConsoleIntro($io);
        $io->title('Training plan analysis framework');
        $io->section('Scenario coverage');
        $aggregate = $payload['aggregates'];
        $io->table(
            ['Metric', 'Value'],
            [
                ['Scenarios', (string) $payload['scenarioCount']],
                ['Average score', number_format((float) $aggregate['averageScore'], 1)],
                ['Critical issues', (string) $aggregate['severityCounts']['critical']],
                ['Warnings', (string) $aggregate['severityCounts']['warning']],
                ['Info issues', (string) $aggregate['severityCounts']['info']],
            ],
        );

        $topIssueRows = array_map(
            static fn (array $row): array => [$row['code'], (string) $row['count']],
            array_slice($aggregate['issueFrequency'], 0, 8),
        );
        if ([] !== $topIssueRows) {
            $io->section('Most frequent quality signals');
            $io->table(['Issue code', 'Count'], $topIssueRows);
        }

        $scenarioRows = array_map(
            static fn ($report): array => [
                $report->getScenario()->getName(),
                (string) $report->getScore(),
                (string) $report->getMetrics()['totalSessions'],
                (string) $report->getMetrics()['shallowRecoveryWeekCount'],
                (string) $report->getMetrics()['missingRequiredDisciplineWeekCount'],
                (string) count($report->getIssues()),
            ],
            $reports,
        );
        $io->section('Scenario results');
        $io->table(
            ['Scenario', 'Score', 'Sessions', 'Shallow recovery', 'Missing disciplines', 'Issues'],
            $scenarioRows,
        );

        if (is_string($outputPath) && '' !== trim($outputPath)) {
            $io->success(sprintf('JSON analysis payload written to %s', $outputPath));
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<\App\Domain\TrainingPlanner\Analysis\TrainingPlanQualityReport> $reports
     *
     * @return array<string, mixed>
     */
    private function buildAggregatePayload(array $reports): array
    {
        $severityCounts = ['critical' => 0, 'warning' => 0, 'info' => 0];
        $issueFrequency = [];
        $scores = [];

        foreach ($reports as $report) {
            $scores[] = $report->getScore();
            foreach ($report->getIssues() as $issue) {
                ++$severityCounts[$issue->getSeverity()];
                $issueFrequency[$issue->getCode()] = ($issueFrequency[$issue->getCode()] ?? 0) + 1;
            }
        }

        arsort($issueFrequency);

        return [
            'averageScore' => [] === $scores ? 0.0 : array_sum($scores) / count($scores),
            'severityCounts' => $severityCounts,
            'issueFrequency' => array_map(
                static fn (string $code, int $count): array => ['code' => $code, 'count' => $count],
                array_keys($issueFrequency),
                array_values($issueFrequency),
            ),
        ];
    }

    private function parseDisciplineOption(string $value): ?TrainingPlanDiscipline
    {
        return match (mb_strtolower(trim($value))) {
            '', 'all' => null,
            'running', 'run' => TrainingPlanDiscipline::RUNNING,
            'cycling', 'bike' => TrainingPlanDiscipline::CYCLING,
            'triathlon', 'tri' => TrainingPlanDiscipline::TRIATHLON,
            default => throw new \InvalidArgumentException('The --discipline option must be one of: all, running, cycling, triathlon.'),
        };
    }

    private function parseTypeOption(string $value): ?TrainingPlanType
    {
        return match (mb_strtolower(trim($value))) {
            '', 'all' => null,
            'race' => TrainingPlanType::RACE,
            'training' => TrainingPlanType::TRAINING,
            default => throw new \InvalidArgumentException('The --type option must be one of: all, race, training.'),
        };
    }
}
