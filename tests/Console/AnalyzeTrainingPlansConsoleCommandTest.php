<?php

declare(strict_types=1);

namespace App\Tests\Console;

use App\Console\AnalyzeTrainingPlansConsoleCommand;
use App\Domain\TrainingPlanner\Analysis\TrainingPlanAnalysisScenarioMatrix;
use App\Domain\TrainingPlanner\Analysis\TrainingPlanQualityAnalyzer;
use App\Domain\TrainingPlanner\PlanGenerator\TrainingPlanGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AnalyzeTrainingPlansConsoleCommandTest extends ConsoleCommandTestCase
{
    public function testExecuteCanEmitJsonPayloadForASingleScenario(): void
    {
        $outputPath = sprintf('%s/plan-analysis-%s.json', sys_get_temp_dir(), uniqid('', true));
        $command = $this->getCommandInApplication('app:training:analyze-plans');
        $commandTester = new CommandTester($command);

        $statusCode = $commandTester->execute([
            '--format' => 'json',
            '--scenario' => 'tri-training-run-focus',
            '--output' => $outputPath,
        ]);

        $payload = json_decode($commandTester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $filePayload = json_decode((string) file_get_contents($outputPath), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Command::SUCCESS, $statusCode);
        self::assertSame(1, $payload['scenarioCount']);
        self::assertSame('tri-training-run-focus', $payload['reports'][0]['scenario']['name']);
        self::assertArrayHasKey('aggregates', $payload);
        self::assertArrayHasKey('metrics', $payload['reports'][0]);
        self::assertSame($payload, $filePayload);

        @unlink($outputPath);
    }

    protected function getConsoleCommand(): Command
    {
        return new AnalyzeTrainingPlansConsoleCommand(
            trainingPlanGenerator: $this->getContainer()->get(TrainingPlanGenerator::class),
            scenarioMatrix: $this->getContainer()->get(TrainingPlanAnalysisScenarioMatrix::class),
            qualityAnalyzer: $this->getContainer()->get(TrainingPlanQualityAnalyzer::class),
        );
    }
}
