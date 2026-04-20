<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner\PlanGenerator;

use App\Domain\TrainingPlanner\RaceEventFamily;
use App\Domain\TrainingPlanner\RaceEventProfile;

final readonly class RaceProfileTrainingRules
{
    private function __construct(
        private int $minimumPlanWeeks,
        private int $idealPlanWeeks,
        private int $maximumPlanWeeks,
        private int $taperWeeks,
        private int $peakWeeks,
        private int $postRaceRecoveryWeeks,
        private int $baseWeeksMinimum,
        private int $sessionsPerWeekMinimum,
        private int $sessionsPerWeekIdeal,
        private int $sessionsPerWeekMaximum,
        private int $hardSessionsPerWeek,
        private int $longSessionsPerWeek,
        private bool $needsBrickSessions,
        private bool $needsSwimSessions,
        private bool $needsBikeSessions,
        private bool $needsRunSessions,
        private int $bRaceTaperDays,
        private int $cRaceRecoveryDays,
    ) {
    }

    public static function forProfile(RaceEventProfile $profile): self
    {
        return match ($profile) {
            RaceEventProfile::FULL_DISTANCE_TRIATHLON => new self(
                minimumPlanWeeks: 20,
                idealPlanWeeks: 30,
                maximumPlanWeeks: 40,
                taperWeeks: 3,
                peakWeeks: 2,
                postRaceRecoveryWeeks: 3,
                baseWeeksMinimum: 6,
                sessionsPerWeekMinimum: 6,
                sessionsPerWeekIdeal: 9,
                sessionsPerWeekMaximum: 12,
                hardSessionsPerWeek: 2,
                longSessionsPerWeek: 2,
                needsBrickSessions: true,
                needsSwimSessions: true,
                needsBikeSessions: true,
                needsRunSessions: true,
                bRaceTaperDays: 4,
                cRaceRecoveryDays: 2,
            ),
            RaceEventProfile::HALF_DISTANCE_TRIATHLON => new self(
                minimumPlanWeeks: 14,
                idealPlanWeeks: 20,
                maximumPlanWeeks: 30,
                taperWeeks: 2,
                peakWeeks: 2,
                postRaceRecoveryWeeks: 2,
                baseWeeksMinimum: 4,
                sessionsPerWeekMinimum: 5,
                sessionsPerWeekIdeal: 8,
                sessionsPerWeekMaximum: 10,
                hardSessionsPerWeek: 2,
                longSessionsPerWeek: 2,
                needsBrickSessions: true,
                needsSwimSessions: true,
                needsBikeSessions: true,
                needsRunSessions: true,
                bRaceTaperDays: 3,
                cRaceRecoveryDays: 1,
            ),
            RaceEventProfile::OLYMPIC_TRIATHLON => new self(
                minimumPlanWeeks: 10,
                idealPlanWeeks: 16,
                maximumPlanWeeks: 24,
                taperWeeks: 1,
                peakWeeks: 2,
                postRaceRecoveryWeeks: 2,
                baseWeeksMinimum: 3,
                sessionsPerWeekMinimum: 5,
                sessionsPerWeekIdeal: 7,
                sessionsPerWeekMaximum: 10,
                hardSessionsPerWeek: 2,
                longSessionsPerWeek: 1,
                needsBrickSessions: true,
                needsSwimSessions: true,
                needsBikeSessions: true,
                needsRunSessions: true,
                bRaceTaperDays: 3,
                cRaceRecoveryDays: 1,
            ),
            RaceEventProfile::SPRINT_TRIATHLON => new self(
                minimumPlanWeeks: 8,
                idealPlanWeeks: 12,
                maximumPlanWeeks: 20,
                taperWeeks: 1,
                peakWeeks: 1,
                postRaceRecoveryWeeks: 1,
                baseWeeksMinimum: 2,
                sessionsPerWeekMinimum: 4,
                sessionsPerWeekIdeal: 6,
                sessionsPerWeekMaximum: 9,
                hardSessionsPerWeek: 2,
                longSessionsPerWeek: 1,
                needsBrickSessions: true,
                needsSwimSessions: true,
                needsBikeSessions: true,
                needsRunSessions: true,
                bRaceTaperDays: 2,
                cRaceRecoveryDays: 1,
            ),
            RaceEventProfile::DUATHLON => new self(
                minimumPlanWeeks: 8,
                idealPlanWeeks: 14,
                maximumPlanWeeks: 20,
                taperWeeks: 1,
                peakWeeks: 2,
                postRaceRecoveryWeeks: 1,
                baseWeeksMinimum: 3,
                sessionsPerWeekMinimum: 4,
                sessionsPerWeekIdeal: 6,
                sessionsPerWeekMaximum: 9,
                hardSessionsPerWeek: 2,
                longSessionsPerWeek: 1,
                needsBrickSessions: true,
                needsSwimSessions: false,
                needsBikeSessions: true,
                needsRunSessions: true,
                bRaceTaperDays: 2,
                cRaceRecoveryDays: 1,
            ),
            RaceEventProfile::AQUATHLON => new self(
                minimumPlanWeeks: 8,
                idealPlanWeeks: 12,
                maximumPlanWeeks: 18,
                taperWeeks: 1,
                peakWeeks: 1,
                postRaceRecoveryWeeks: 1,
                baseWeeksMinimum: 2,
                sessionsPerWeekMinimum: 4,
                sessionsPerWeekIdeal: 6,
                sessionsPerWeekMaximum: 8,
                hardSessionsPerWeek: 2,
                longSessionsPerWeek: 1,
                needsBrickSessions: false,
                needsSwimSessions: true,
                needsBikeSessions: false,
                needsRunSessions: true,
                bRaceTaperDays: 2,
                cRaceRecoveryDays: 1,
            ),
            RaceEventProfile::MARATHON => new self(
                minimumPlanWeeks: 16,
                idealPlanWeeks: 20,
                maximumPlanWeeks: 30,
                taperWeeks: 3,
                peakWeeks: 2,
                postRaceRecoveryWeeks: 2,
                baseWeeksMinimum: 4,
                sessionsPerWeekMinimum: 4,
                sessionsPerWeekIdeal: 6,
                sessionsPerWeekMaximum: 8,
                hardSessionsPerWeek: 2,
                longSessionsPerWeek: 1,
                needsBrickSessions: false,
                needsSwimSessions: false,
                needsBikeSessions: false,
                needsRunSessions: true,
                bRaceTaperDays: 5,
                cRaceRecoveryDays: 2,
            ),
            RaceEventProfile::HALF_MARATHON => new self(
                minimumPlanWeeks: 10,
                idealPlanWeeks: 14,
                maximumPlanWeeks: 20,
                taperWeeks: 2,
                peakWeeks: 2,
                postRaceRecoveryWeeks: 1,
                baseWeeksMinimum: 3,
                sessionsPerWeekMinimum: 4,
                sessionsPerWeekIdeal: 5,
                sessionsPerWeekMaximum: 7,
                hardSessionsPerWeek: 2,
                longSessionsPerWeek: 1,
                needsBrickSessions: false,
                needsSwimSessions: false,
                needsBikeSessions: false,
                needsRunSessions: true,
                bRaceTaperDays: 3,
                cRaceRecoveryDays: 1,
            ),
            RaceEventProfile::RUN_10K => new self(
                minimumPlanWeeks: 8,
                idealPlanWeeks: 12,
                maximumPlanWeeks: 16,
                taperWeeks: 1,
                peakWeeks: 2,
                postRaceRecoveryWeeks: 1,
                baseWeeksMinimum: 2,
                sessionsPerWeekMinimum: 3,
                sessionsPerWeekIdeal: 5,
                sessionsPerWeekMaximum: 7,
                hardSessionsPerWeek: 2,
                longSessionsPerWeek: 1,
                needsBrickSessions: false,
                needsSwimSessions: false,
                needsBikeSessions: false,
                needsRunSessions: true,
                bRaceTaperDays: 2,
                cRaceRecoveryDays: 1,
            ),
            RaceEventProfile::RUN_5K => new self(
                minimumPlanWeeks: 6,
                idealPlanWeeks: 10,
                maximumPlanWeeks: 14,
                taperWeeks: 1,
                peakWeeks: 1,
                postRaceRecoveryWeeks: 1,
                baseWeeksMinimum: 2,
                sessionsPerWeekMinimum: 3,
                sessionsPerWeekIdeal: 5,
                sessionsPerWeekMaximum: 7,
                hardSessionsPerWeek: 2,
                longSessionsPerWeek: 1,
                needsBrickSessions: false,
                needsSwimSessions: false,
                needsBikeSessions: false,
                needsRunSessions: true,
                bRaceTaperDays: 2,
                cRaceRecoveryDays: 1,
            ),
            RaceEventProfile::RUN => new self(
                minimumPlanWeeks: 8,
                idealPlanWeeks: 12,
                maximumPlanWeeks: 20,
                taperWeeks: 1,
                peakWeeks: 2,
                postRaceRecoveryWeeks: 1,
                baseWeeksMinimum: 3,
                sessionsPerWeekMinimum: 3,
                sessionsPerWeekIdeal: 5,
                sessionsPerWeekMaximum: 7,
                hardSessionsPerWeek: 2,
                longSessionsPerWeek: 1,
                needsBrickSessions: false,
                needsSwimSessions: false,
                needsBikeSessions: false,
                needsRunSessions: true,
                bRaceTaperDays: 2,
                cRaceRecoveryDays: 1,
            ),
            RaceEventProfile::RIDE,
            RaceEventProfile::TIME_TRIAL,
            RaceEventProfile::GRAVEL_RACE => new self(
                minimumPlanWeeks: 8,
                idealPlanWeeks: 14,
                maximumPlanWeeks: 24,
                taperWeeks: 1,
                peakWeeks: 2,
                postRaceRecoveryWeeks: 1,
                baseWeeksMinimum: 3,
                sessionsPerWeekMinimum: 3,
                sessionsPerWeekIdeal: 5,
                sessionsPerWeekMaximum: 7,
                hardSessionsPerWeek: 2,
                longSessionsPerWeek: 1,
                needsBrickSessions: false,
                needsSwimSessions: false,
                needsBikeSessions: true,
                needsRunSessions: false,
                bRaceTaperDays: 2,
                cRaceRecoveryDays: 1,
            ),
            RaceEventProfile::SWIM,
            RaceEventProfile::OPEN_WATER_SWIM => new self(
                minimumPlanWeeks: 8,
                idealPlanWeeks: 12,
                maximumPlanWeeks: 20,
                taperWeeks: 1,
                peakWeeks: 1,
                postRaceRecoveryWeeks: 1,
                baseWeeksMinimum: 3,
                sessionsPerWeekMinimum: 3,
                sessionsPerWeekIdeal: 5,
                sessionsPerWeekMaximum: 7,
                hardSessionsPerWeek: 2,
                longSessionsPerWeek: 1,
                needsBrickSessions: false,
                needsSwimSessions: true,
                needsBikeSessions: false,
                needsRunSessions: false,
                bRaceTaperDays: 2,
                cRaceRecoveryDays: 1,
            ),
            RaceEventProfile::CUSTOM => self::forFamily(RaceEventFamily::OTHER),
        };
    }

    public static function forFamily(RaceEventFamily $family): self
    {
        return match ($family) {
            RaceEventFamily::TRIATHLON => self::forProfile(RaceEventProfile::OLYMPIC_TRIATHLON),
            RaceEventFamily::MULTISPORT => self::forProfile(RaceEventProfile::DUATHLON),
            RaceEventFamily::RUN => self::forProfile(RaceEventProfile::HALF_MARATHON),
            RaceEventFamily::RIDE => self::forProfile(RaceEventProfile::RIDE),
            RaceEventFamily::SWIM => self::forProfile(RaceEventProfile::SWIM),
            RaceEventFamily::OTHER => new self(
                minimumPlanWeeks: 8,
                idealPlanWeeks: 12,
                maximumPlanWeeks: 20,
                taperWeeks: 1,
                peakWeeks: 2,
                postRaceRecoveryWeeks: 1,
                baseWeeksMinimum: 3,
                sessionsPerWeekMinimum: 3,
                sessionsPerWeekIdeal: 5,
                sessionsPerWeekMaximum: 7,
                hardSessionsPerWeek: 2,
                longSessionsPerWeek: 1,
                needsBrickSessions: false,
                needsSwimSessions: false,
                needsBikeSessions: false,
                needsRunSessions: false,
                bRaceTaperDays: 2,
                cRaceRecoveryDays: 1,
            ),
        };
    }

    public function getMinimumPlanWeeks(): int
    {
        return $this->minimumPlanWeeks;
    }

    public function getIdealPlanWeeks(): int
    {
        return $this->idealPlanWeeks;
    }

    public function getMaximumPlanWeeks(): int
    {
        return $this->maximumPlanWeeks;
    }

    public function getTaperWeeks(): int
    {
        return $this->taperWeeks;
    }

    public function getPeakWeeks(): int
    {
        return $this->peakWeeks;
    }

    public function getPostRaceRecoveryWeeks(): int
    {
        return $this->postRaceRecoveryWeeks;
    }

    public function getBaseWeeksMinimum(): int
    {
        return $this->baseWeeksMinimum;
    }

    public function getSessionsPerWeekMinimum(): int
    {
        return $this->sessionsPerWeekMinimum;
    }

    public function getSessionsPerWeekIdeal(): int
    {
        return $this->sessionsPerWeekIdeal;
    }

    public function getSessionsPerWeekMaximum(): int
    {
        return $this->sessionsPerWeekMaximum;
    }

    public function getHardSessionsPerWeek(): int
    {
        return $this->hardSessionsPerWeek;
    }

    public function getLongSessionsPerWeek(): int
    {
        return $this->longSessionsPerWeek;
    }

    public function needsBrickSessions(): bool
    {
        return $this->needsBrickSessions;
    }

    public function needsSwimSessions(): bool
    {
        return $this->needsSwimSessions;
    }

    public function needsBikeSessions(): bool
    {
        return $this->needsBikeSessions;
    }

    public function needsRunSessions(): bool
    {
        return $this->needsRunSessions;
    }

    public function getBRaceTaperDays(): int
    {
        return $this->bRaceTaperDays;
    }

    public function getCRaceRecoveryDays(): int
    {
        return $this->cRaceRecoveryDays;
    }
}
