<?php

declare(strict_types=1);

namespace App\Domain\Wellness;

final readonly class WellnessImportConfig
{
    private function __construct(
        private bool $enabled,
        private string $bridgeSourcePath,
    ) {
    }

    public static function create(bool $enabled, string $bridgeSourcePath): self
    {
        return new self(
            enabled: $enabled,
            bridgeSourcePath: $bridgeSourcePath,
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getBridgeSourcePath(): string
    {
        return $this->bridgeSourcePath;
    }
}