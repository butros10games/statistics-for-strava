<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Widget\Wellness\FindDailyRecoveryCheckIns;

use App\Infrastructure\CQRS\Query\Query;
use App\Infrastructure\ValueObject\Time\DateRange;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

/**
 * @implements Query<\App\Domain\Dashboard\Widget\Wellness\FindDailyRecoveryCheckIns\FindDailyRecoveryCheckInsResponse>
 */
final readonly class FindDailyRecoveryCheckIns implements Query
{
    public function __construct(
        private DateRange $dateRange,
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
}