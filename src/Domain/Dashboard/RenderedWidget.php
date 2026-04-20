<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

final readonly class RenderedWidget
{
    public function __construct(
        private string $renderedHtml,
        private int $width,
        private ?string $section = null,
    ) {
    }

    public function getRenderedHtml(): string
    {
        return $this->renderedHtml;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getSection(): ?string
    {
        return $this->section;
    }
}
