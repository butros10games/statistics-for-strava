<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\Wellness\FindWellnessMetrics;

use App\Domain\Wellness\WellnessSource;
use App\Infrastructure\CQRS\Query\Query;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

/**
 * @implements Query<\App\Domain\Dashboard\Widget\Wellness\FindWellnessMetrics\FindWellnessMetricsResponse>
 */
final readonly class FindWellnessMetrics implements Query
{
    public function __construct(
        private DateRange $dateRange,
        private WellnessSource $source,
    ) {
    }

    public function getFrom(): SerializableDateTime
    {
        return $this->dateRange->getFrom();
    }

    public function getTo(): SerializableDateTime
    {
        return $this->dateRange->getTill();
    }

    public function getSource(): WellnessSource
    {
        return $this->source;
    }
}