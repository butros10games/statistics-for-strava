<?php

declare(strict_types=1);

namespace App\Domain\Performance\PerformanceAnchor;

enum PerformanceAnchorSource: string
{
    case MANUAL = 'manual';
    case FTP_HISTORY = 'ftp_history';
    case BEST_EFFORTS = 'best_efforts';
    case POWER_DURATION_MODEL = 'power_duration_model';
    case CRITICAL_SPEED_MODEL = 'critical_speed_model';
}