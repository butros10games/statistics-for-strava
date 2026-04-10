<?php

declare(strict_types=1);

namespace App\Application\UpdateData;

use App\Domain\Wellness\WellnessImportConfig;
use App\Infrastructure\Process\ProcessFactory;
use App\Infrastructure\ValueObject\String\KernelProjectDir;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

final readonly class GarminBridgeUpdater
{
    public const string SOURCE_GARMIN_CONNECT = 'garminconnect';
    public const string SOURCE_GIVEMYDATA = 'givemydata';

    public function __construct(
        private WellnessImportConfig $wellnessImportConfig,
        private ProcessFactory $processFactory,
        private KernelProjectDir $kernelProjectDir,
    ) {
    }

    public function update(SymfonyStyle $output, string $source = self::SOURCE_GIVEMYDATA, ?int $days = null, bool $all = false): void
    {
        if (!$this->wellnessImportConfig->isEnabled()) {
            $output->comment('Skipping Garmin wellness sync because integrations.wellness.enabled is disabled.');

            return;
        }

        $output->section(sprintf('Refreshing Garmin wellness bridge (%s)', $source));

        $process = $this->processFactory->create($this->buildCommand($source, $days, $all));
        $process->setWorkingDirectory((string) $this->kernelProjectDir);
        $process->setTimeout(null);
        $process->run();

        $this->writeProcessOutput($output, $process);

        if ($process->isSuccessful()) {
            return;
        }

        $errorOutput = trim($process->getErrorOutput());
        $standardOutput = trim($process->getOutput());

        throw new \RuntimeException($errorOutput ?: $standardOutput ?: 'Garmin wellness bridge update failed.');
    }

    /**
     * @return string[]
     */
    private function buildCommand(string $source, ?int $days, bool $all): array
    {
        $command = ['uv', 'run'];

        if (self::SOURCE_GARMIN_CONNECT === $source) {
            $command[] = 'tools/garmin_wellness_bridge.py';
            if ($all) {
                $command[] = '--all';
            }
        } elseif (self::SOURCE_GIVEMYDATA === $source) {
            $command[] = 'tools/garmin_givemydata_bridge.py';
            if ($all) {
                $command[] = '--full';
            }
        } else {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported Garmin source "%s". Expected one of: %s.',
                $source,
                implode(', ', [self::SOURCE_GARMIN_CONNECT, self::SOURCE_GIVEMYDATA]),
            ));
        }

        if (null !== $days) {
            $command[] = '--days';
            $command[] = (string) $days;
        }

        return $command;
    }

    private function writeProcessOutput(SymfonyStyle $output, Process $process): void
    {
        $standardOutput = trim($process->getOutput());
        if ('' !== $standardOutput) {
            $output->writeln($standardOutput);
        }

        $errorOutput = trim($process->getErrorOutput());
        if ('' !== $errorOutput) {
            $output->writeln(sprintf('<error>%s</error>', $errorOutput));
        }
    }
}