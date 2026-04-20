<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner\Analysis;

use App\Domain\TrainingPlanner\RaceEvent;
use App\Domain\TrainingPlanner\RaceEventId;
use App\Domain\TrainingPlanner\RaceEventProfile;
use App\Domain\TrainingPlanner\RaceEventPriority;
use App\Domain\TrainingPlanner\RaceEventType;
use App\Domain\TrainingPlanner\TrainingFocus;
use App\Domain\TrainingPlanner\TrainingPlan;
use App\Domain\TrainingPlanner\TrainingPlanDiscipline;
use App\Domain\TrainingPlanner\TrainingPlanId;
use App\Domain\TrainingPlanner\TrainingPlanType;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final class TrainingPlanAnalysisScenarioMatrix
{
    /**
     * @return list<TrainingPlanAnalysisScenario>
     */
    public function build(
        ?TrainingPlanDiscipline $disciplineFilter = null,
        ?TrainingPlanType $typeFilter = null,
        ?string $scenarioNameFilter = null,
    ): array {
        $scenarios = [
            ...$this->buildRunningRaceScenarios(),
            ...$this->buildCyclingRaceScenarios(),
            ...$this->buildTriathlonRaceScenarios(),
            ...$this->buildTrainingScenarios(),
            ...$this->buildStressScenarios(),
        ];

        $scenarioNameFilter = null === $scenarioNameFilter ? null : trim(mb_strtolower($scenarioNameFilter));

        return array_values(array_filter(
            $scenarios,
            static function (TrainingPlanAnalysisScenario $scenario) use ($disciplineFilter, $typeFilter, $scenarioNameFilter): bool {
                if ($disciplineFilter instanceof TrainingPlanDiscipline && $scenario->getDiscipline() !== $disciplineFilter) {
                    return false;
                }

                if ($typeFilter instanceof TrainingPlanType && $scenario->getPlanType() !== $typeFilter) {
                    return false;
                }

                if (null === $scenarioNameFilter || '' === $scenarioNameFilter) {
                    return true;
                }

                return str_contains(mb_strtolower($scenario->getName()), $scenarioNameFilter)
                    || str_contains(mb_strtolower($scenario->getLabel()), $scenarioNameFilter);
            },
        ));
    }

    /**
     * @return list<TrainingPlanAnalysisScenario>
     */
    private function buildRunningRaceScenarios(): array
    {
        $profiles = [
            [
                'profile' => RaceEventProfile::RUN_5K,
                'label' => '5K',
                'finishTimes' => ['starter' => 1_650, 'steady' => 1_260, 'advanced' => 1_080],
            ],
            [
                'profile' => RaceEventProfile::RUN_10K,
                'label' => '10K',
                'finishTimes' => ['starter' => 3_300, 'steady' => 2_700, 'advanced' => 2_280],
            ],
            [
                'profile' => RaceEventProfile::HALF_MARATHON,
                'label' => 'Half marathon',
                'finishTimes' => ['starter' => 7_200, 'steady' => 5_700, 'advanced' => 4_800],
            ],
            [
                'profile' => RaceEventProfile::MARATHON,
                'label' => 'Marathon',
                'finishTimes' => ['starter' => 15_300, 'steady' => 12_600, 'advanced' => 10_200],
            ],
        ];
        $tiers = [
            'starter' => ['label' => 'Starter', 'runningThresholdPace' => 330, 'weeklyRunningVolume' => 24.0],
            'steady' => ['label' => 'Steady', 'runningThresholdPace' => 255, 'weeklyRunningVolume' => 48.0],
            'advanced' => ['label' => 'Advanced', 'runningThresholdPace' => 220, 'weeklyRunningVolume' => 78.0],
        ];

        $scenarios = [];
        foreach ($profiles as $profileConfig) {
            foreach ($tiers as $tierSlug => $tierConfig) {
                $profile = $profileConfig['profile'];
                $scenarios[] = $this->createRaceScenario(
                    name: sprintf('run-race-%s-%s', $profile->value, $tierSlug),
                    label: sprintf('Running race · %s · %s', $profileConfig['label'], $tierConfig['label']),
                    profile: $profile,
                    discipline: TrainingPlanDiscipline::RUNNING,
                    performanceMetrics: [
                        'runningThresholdPace' => $tierConfig['runningThresholdPace'],
                        'weeklyRunningVolume' => $tierConfig['weeklyRunningVolume'],
                    ],
                    sportSchedule: [
                        'runDays' => [2, 4, 6, 7],
                        'longRunDays' => [7],
                    ],
                    targetFinishTimeInSeconds: $profileConfig['finishTimes'][$tierSlug],
                );
            }
        }

        return $scenarios;
    }

    /**
     * @return list<TrainingPlanAnalysisScenario>
     */
    private function buildCyclingRaceScenarios(): array
    {
        $profiles = [
            [
                'profile' => RaceEventProfile::TIME_TRIAL,
                'label' => 'Time trial',
                'finishTimes' => ['starter' => 4_200, 'steady' => 3_600, 'advanced' => 3_000],
            ],
            [
                'profile' => RaceEventProfile::RIDE,
                'label' => 'Road race',
                'finishTimes' => ['starter' => 16_200, 'steady' => 14_400, 'advanced' => 12_000],
            ],
            [
                'profile' => RaceEventProfile::GRAVEL_RACE,
                'label' => 'Gravel race',
                'finishTimes' => ['starter' => 19_800, 'steady' => 17_100, 'advanced' => 14_400],
            ],
        ];
        $tiers = [
            'starter' => ['label' => 'Starter', 'cyclingFtp' => 175, 'weeklyBikingVolume' => 4.0],
            'steady' => ['label' => 'Steady', 'cyclingFtp' => 250, 'weeklyBikingVolume' => 7.5],
            'advanced' => ['label' => 'Advanced', 'cyclingFtp' => 320, 'weeklyBikingVolume' => 11.0],
        ];

        $scenarios = [];
        foreach ($profiles as $profileConfig) {
            foreach ($tiers as $tierSlug => $tierConfig) {
                $profile = $profileConfig['profile'];
                $scenarios[] = $this->createRaceScenario(
                    name: sprintf('bike-race-%s-%s', $profile->value, $tierSlug),
                    label: sprintf('Cycling race · %s · %s', $profileConfig['label'], $tierConfig['label']),
                    profile: $profile,
                    discipline: TrainingPlanDiscipline::CYCLING,
                    performanceMetrics: [
                        'cyclingFtp' => $tierConfig['cyclingFtp'],
                        'weeklyBikingVolume' => $tierConfig['weeklyBikingVolume'],
                    ],
                    sportSchedule: [
                        'bikeDays' => [2, 4, 6, 7],
                        'longRideDays' => [7],
                    ],
                    targetFinishTimeInSeconds: $profileConfig['finishTimes'][$tierSlug],
                );
            }
        }

        return $scenarios;
    }

    /**
     * @return list<TrainingPlanAnalysisScenario>
     */
    private function buildTriathlonRaceScenarios(): array
    {
        $profiles = [
            [
                'profile' => RaceEventProfile::SPRINT_TRIATHLON,
                'label' => 'Sprint triathlon',
                'finishTimes' => ['starter' => 5_700, 'steady' => 4_500, 'advanced' => 3_900],
            ],
            [
                'profile' => RaceEventProfile::OLYMPIC_TRIATHLON,
                'label' => 'Olympic triathlon',
                'finishTimes' => ['starter' => 9_600, 'steady' => 8_400, 'advanced' => 7_200],
            ],
            [
                'profile' => RaceEventProfile::HALF_DISTANCE_TRIATHLON,
                'label' => '70.3 triathlon',
                'finishTimes' => ['starter' => 22_500, 'steady' => 19_800, 'advanced' => 18_000],
            ],
            [
                'profile' => RaceEventProfile::FULL_DISTANCE_TRIATHLON,
                'label' => 'Full-distance triathlon',
                'finishTimes' => ['starter' => 46_800, 'steady' => 39_600, 'advanced' => 34_200],
            ],
        ];
        $tiers = [
            'starter' => [
                'label' => 'Starter',
                'runningThresholdPace' => 330,
                'weeklyRunningVolume' => 22.0,
                'cyclingFtp' => 170,
                'weeklyBikingVolume' => 3.5,
            ],
            'steady' => [
                'label' => 'Steady',
                'runningThresholdPace' => 255,
                'weeklyRunningVolume' => 42.0,
                'cyclingFtp' => 250,
                'weeklyBikingVolume' => 7.0,
            ],
            'advanced' => [
                'label' => 'Advanced',
                'runningThresholdPace' => 225,
                'weeklyRunningVolume' => 65.0,
                'cyclingFtp' => 315,
                'weeklyBikingVolume' => 10.0,
            ],
        ];

        $scenarios = [];
        foreach ($profiles as $profileConfig) {
            foreach ($tiers as $tierSlug => $tierConfig) {
                $profile = $profileConfig['profile'];
                $scenarios[] = $this->createRaceScenario(
                    name: sprintf('tri-race-%s-%s', $profile->value, $tierSlug),
                    label: sprintf('Triathlon race · %s · %s', $profileConfig['label'], $tierConfig['label']),
                    profile: $profile,
                    discipline: TrainingPlanDiscipline::TRIATHLON,
                    performanceMetrics: [
                        'runningThresholdPace' => $tierConfig['runningThresholdPace'],
                        'weeklyRunningVolume' => $tierConfig['weeklyRunningVolume'],
                        'cyclingFtp' => $tierConfig['cyclingFtp'],
                        'weeklyBikingVolume' => $tierConfig['weeklyBikingVolume'],
                    ],
                    sportSchedule: [
                        'swimDays' => [1, 3, 5],
                        'bikeDays' => [1, 4, 6],
                        'runDays' => [2, 4, 6, 7],
                        'longRideDays' => [6],
                        'longRunDays' => [7],
                    ],
                    targetFinishTimeInSeconds: $profileConfig['finishTimes'][$tierSlug],
                );
            }
        }

        return $scenarios;
    }

    /**
     * @return list<TrainingPlanAnalysisScenario>
     */
    private function buildTrainingScenarios(): array
    {
        return [
            $this->createTrainingScenario(
                name: 'run-training-steady',
                label: 'Running development · Steady',
                discipline: TrainingPlanDiscipline::RUNNING,
                profile: RaceEventProfile::HALF_MARATHON,
                performanceMetrics: ['runningThresholdPace' => 255, 'weeklyRunningVolume' => 46.0],
                sportSchedule: ['runDays' => [2, 4, 6, 7], 'longRunDays' => [7]],
            ),
            $this->createTrainingScenario(
                name: 'run-training-advanced',
                label: 'Running development · Advanced',
                discipline: TrainingPlanDiscipline::RUNNING,
                profile: RaceEventProfile::MARATHON,
                performanceMetrics: ['runningThresholdPace' => 225, 'weeklyRunningVolume' => 72.0],
                sportSchedule: ['runDays' => [2, 3, 5, 6, 7], 'longRunDays' => [7]],
            ),
            $this->createTrainingScenario(
                name: 'bike-training-steady',
                label: 'Cycling development · Steady',
                discipline: TrainingPlanDiscipline::CYCLING,
                profile: RaceEventProfile::RIDE,
                performanceMetrics: ['cyclingFtp' => 250, 'weeklyBikingVolume' => 7.5],
                sportSchedule: ['bikeDays' => [2, 4, 6, 7], 'longRideDays' => [7]],
            ),
            $this->createTrainingScenario(
                name: 'bike-training-advanced',
                label: 'Cycling development · Advanced',
                discipline: TrainingPlanDiscipline::CYCLING,
                profile: RaceEventProfile::TIME_TRIAL,
                performanceMetrics: ['cyclingFtp' => 320, 'weeklyBikingVolume' => 11.0],
                sportSchedule: ['bikeDays' => [2, 3, 5, 6, 7], 'longRideDays' => [7]],
            ),
            $this->createTrainingScenario(
                name: 'tri-training-run-focus',
                label: 'Triathlon development · Run focus',
                discipline: TrainingPlanDiscipline::TRIATHLON,
                profile: RaceEventProfile::HALF_DISTANCE_TRIATHLON,
                performanceMetrics: [
                    'runningThresholdPace' => 255,
                    'weeklyRunningVolume' => 50.0,
                    'cyclingFtp' => 245,
                    'weeklyBikingVolume' => 6.0,
                ],
                sportSchedule: [
                    'swimDays' => [1, 4, 7],
                    'bikeDays' => [1, 3, 5, 6],
                    'runDays' => [2, 4, 6, 7],
                    'longRideDays' => [6],
                    'longRunDays' => [7],
                ],
                focus: TrainingFocus::RUN,
            ),
            $this->createTrainingScenario(
                name: 'tri-training-bike-focus',
                label: 'Triathlon development · Bike focus',
                discipline: TrainingPlanDiscipline::TRIATHLON,
                profile: RaceEventProfile::OLYMPIC_TRIATHLON,
                performanceMetrics: [
                    'runningThresholdPace' => 265,
                    'weeklyRunningVolume' => 36.0,
                    'cyclingFtp' => 265,
                    'weeklyBikingVolume' => 8.0,
                ],
                sportSchedule: [
                    'swimDays' => [1, 4],
                    'bikeDays' => [2, 4, 6, 7],
                    'runDays' => [2, 5, 7],
                    'longRideDays' => [7],
                    'longRunDays' => [5],
                ],
                focus: TrainingFocus::BIKE,
            ),
        ];
    }

    /**
     * @return list<TrainingPlanAnalysisScenario>
     */
    private function buildStressScenarios(): array
    {
        $targetRaceDay = SerializableDateTime::fromString('2026-10-25 00:00:00');

        return [
            $this->createRaceScenario(
                name: 'run-race-half-marathon-compressed',
                label: 'Running race · Half marathon · Compressed build',
                profile: RaceEventProfile::HALF_MARATHON,
                discipline: TrainingPlanDiscipline::RUNNING,
                performanceMetrics: [
                    'runningThresholdPace' => 255,
                    'weeklyRunningVolume' => 42.0,
                ],
                sportSchedule: [
                    'runDays' => [2, 4, 6, 7],
                    'longRunDays' => [7],
                ],
                targetFinishTimeInSeconds: 5_700,
                planStartDay: SerializableDateTime::fromString($targetRaceDay->modify('-8 weeks')->format('Y-m-d 00:00:00')),
            ),
            $this->createRaceScenario(
                name: 'run-race-marathon-compressed',
                label: 'Running race · Marathon · Compressed build',
                profile: RaceEventProfile::MARATHON,
                discipline: TrainingPlanDiscipline::RUNNING,
                performanceMetrics: [
                    'runningThresholdPace' => 240,
                    'weeklyRunningVolume' => 54.0,
                ],
                sportSchedule: [
                    'runDays' => [2, 4, 6, 7],
                    'longRunDays' => [7],
                ],
                targetFinishTimeInSeconds: 12_600,
                planStartDay: SerializableDateTime::fromString($targetRaceDay->modify('-10 weeks')->format('Y-m-d 00:00:00')),
            ),
            $this->createRaceScenario(
                name: 'bike-race-gravel-extended',
                label: 'Cycling race · Gravel race · Extended runway',
                profile: RaceEventProfile::GRAVEL_RACE,
                discipline: TrainingPlanDiscipline::CYCLING,
                performanceMetrics: [
                    'cyclingFtp' => 250,
                    'weeklyBikingVolume' => 7.5,
                ],
                sportSchedule: [
                    'bikeDays' => [2, 4, 6, 7],
                    'longRideDays' => [7],
                ],
                targetFinishTimeInSeconds: 17_100,
                planStartDay: SerializableDateTime::fromString($targetRaceDay->modify('-30 weeks')->format('Y-m-d 00:00:00')),
            ),
            $this->createRaceScenario(
                name: 'run-race-10k-b-race-taper',
                label: 'Running race · 10K · B-race in taper',
                profile: RaceEventProfile::RUN_10K,
                discipline: TrainingPlanDiscipline::RUNNING,
                performanceMetrics: [
                    'runningThresholdPace' => 250,
                    'weeklyRunningVolume' => 46.0,
                ],
                sportSchedule: [
                    'runDays' => [2, 4, 6, 7],
                    'longRunDays' => [7],
                ],
                targetFinishTimeInSeconds: 2_700,
                additionalRaceEvents: [
                    $this->createSupportingRace(
                        day: SerializableDateTime::fromString($targetRaceDay->modify('-10 days')->format('Y-m-d 00:00:00')),
                        profile: RaceEventProfile::RUN_5K,
                        priority: RaceEventPriority::B,
                        title: 'Tune-up 5K',
                        targetFinishTimeInSeconds: 1_260,
                    ),
                ],
            ),
            $this->createRaceScenario(
                name: 'tri-race-half-distance-b-race-taper',
                label: 'Triathlon race · 70.3 triathlon · B-race in taper',
                profile: RaceEventProfile::HALF_DISTANCE_TRIATHLON,
                discipline: TrainingPlanDiscipline::TRIATHLON,
                performanceMetrics: [
                    'runningThresholdPace' => 255,
                    'weeklyRunningVolume' => 42.0,
                    'cyclingFtp' => 250,
                    'weeklyBikingVolume' => 7.0,
                ],
                sportSchedule: [
                    'swimDays' => [1, 3, 5],
                    'bikeDays' => [1, 4, 6],
                    'runDays' => [2, 4, 6, 7],
                    'longRideDays' => [6],
                    'longRunDays' => [7],
                ],
                targetFinishTimeInSeconds: 19_800,
                additionalRaceEvents: [
                    $this->createSupportingRace(
                        day: SerializableDateTime::fromString($targetRaceDay->modify('-7 days')->format('Y-m-d 00:00:00')),
                        profile: RaceEventProfile::OLYMPIC_TRIATHLON,
                        priority: RaceEventPriority::B,
                        title: 'Olympic tune-up',
                        targetFinishTimeInSeconds: 8_400,
                    ),
                ],
            ),
            $this->createRaceScenario(
                name: 'tri-race-olympic-multi-a-race',
                label: 'Triathlon race · Olympic triathlon · Multiple A races',
                profile: RaceEventProfile::OLYMPIC_TRIATHLON,
                discipline: TrainingPlanDiscipline::TRIATHLON,
                performanceMetrics: [
                    'runningThresholdPace' => 255,
                    'weeklyRunningVolume' => 40.0,
                    'cyclingFtp' => 250,
                    'weeklyBikingVolume' => 6.5,
                ],
                sportSchedule: [
                    'swimDays' => [1, 3, 5],
                    'bikeDays' => [1, 4, 6],
                    'runDays' => [2, 4, 6, 7],
                    'longRideDays' => [6],
                    'longRunDays' => [7],
                ],
                targetFinishTimeInSeconds: 8_400,
                additionalRaceEvents: [
                    $this->createSupportingRace(
                        day: SerializableDateTime::fromString($targetRaceDay->modify('-28 days')->format('Y-m-d 00:00:00')),
                        profile: RaceEventProfile::SPRINT_TRIATHLON,
                        priority: RaceEventPriority::A,
                        title: 'Early A-race sprint',
                        targetFinishTimeInSeconds: 4_500,
                    ),
                ],
            ),
        ];
    }

    /**
     * @param array<string, mixed>|null $sportSchedule
     * @param array<string, mixed>|null $performanceMetrics
     */
    private function createRaceScenario(
        string $name,
        string $label,
        RaceEventProfile $profile,
        TrainingPlanDiscipline $discipline,
        ?array $performanceMetrics,
        ?array $sportSchedule,
        int $targetFinishTimeInSeconds,
        ?SerializableDateTime $planStartDay = null,
        array $additionalRaceEvents = [],
    ): TrainingPlanAnalysisScenario {
        $raceDay = SerializableDateTime::fromString('2026-10-25 00:00:00');
        $idealWeeks = $this->resolveIdealWeeks($profile, TrainingPlanType::RACE);
        $planStartDay ??= SerializableDateTime::fromString($raceDay->modify(sprintf('-%d weeks', $idealWeeks))->format('Y-m-d 00:00:00'));
        $referenceDate = SerializableDateTime::fromString($planStartDay->modify('-7 days')->format('Y-m-d 00:00:00'));
        $targetRaceId = RaceEventId::random();

        $targetRace = RaceEvent::create(
            raceEventId: $targetRaceId,
            day: $raceDay,
            type: RaceEventType::fromProfile($profile),
            title: $label,
            location: 'Analysis lab',
            notes: 'Synthetic analysis scenario',
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: $targetFinishTimeInSeconds,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );

        $linkedPlan = TrainingPlan::create(
            trainingPlanId: TrainingPlanId::random(),
            type: TrainingPlanType::RACE,
            startDay: $planStartDay,
            endDay: $raceDay,
            targetRaceEventId: $targetRaceId,
            title: $label,
            notes: 'Synthetic analysis scenario',
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            discipline: $discipline,
            sportSchedule: $sportSchedule,
            performanceMetrics: $performanceMetrics,
            targetRaceProfile: $profile,
            trainingFocus: null,
        );

        $allRaceEvents = [$targetRace, ...$additionalRaceEvents];
        usort($allRaceEvents, static fn (RaceEvent $left, RaceEvent $right): int => $left->getDay() <=> $right->getDay());

        return TrainingPlanAnalysisScenario::create(
            name: $name,
            label: $label,
            planType: TrainingPlanType::RACE,
            discipline: $discipline,
            targetRaceProfile: $profile,
            trainingFocus: null,
            planStartDay: $planStartDay,
            referenceDate: $referenceDate,
            targetRace: $targetRace,
            allRaceEvents: $allRaceEvents,
            linkedTrainingPlan: $linkedPlan,
        );
    }

    private function createSupportingRace(
        SerializableDateTime $day,
        RaceEventProfile $profile,
        RaceEventPriority $priority,
        string $title,
        int $targetFinishTimeInSeconds,
    ): RaceEvent {
        return RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: $day,
            type: RaceEventType::fromProfile($profile),
            title: $title,
            location: 'Analysis lab',
            notes: 'Synthetic supporting race for analysis',
            priority: $priority,
            targetFinishTimeInSeconds: $targetFinishTimeInSeconds,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );
    }

    /**
     * @param array<string, mixed>|null $sportSchedule
     * @param array<string, mixed>|null $performanceMetrics
     */
    private function createTrainingScenario(
        string $name,
        string $label,
        TrainingPlanDiscipline $discipline,
        RaceEventProfile $profile,
        ?array $performanceMetrics,
        ?array $sportSchedule,
        ?TrainingFocus $focus = null,
    ): TrainingPlanAnalysisScenario {
        $planStartDay = SerializableDateTime::fromString('2026-07-06 00:00:00');
        $idealWeeks = $this->resolveIdealWeeks($profile, TrainingPlanType::TRAINING);
        $planEndDay = SerializableDateTime::fromString($planStartDay->modify(sprintf('+%d weeks -1 day', $idealWeeks))->format('Y-m-d 00:00:00'));
        $referenceDate = SerializableDateTime::fromString($planStartDay->modify('-7 days')->format('Y-m-d 00:00:00'));

        $targetRace = RaceEvent::create(
            raceEventId: RaceEventId::random(),
            day: $planEndDay,
            type: RaceEventType::fromProfile($profile),
            title: $label,
            location: 'Analysis lab',
            notes: 'Synthetic development scenario',
            priority: RaceEventPriority::A,
            targetFinishTimeInSeconds: 0,
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
        );

        $linkedPlan = TrainingPlan::create(
            trainingPlanId: TrainingPlanId::random(),
            type: TrainingPlanType::TRAINING,
            startDay: $planStartDay,
            endDay: $planEndDay,
            targetRaceEventId: null,
            title: $label,
            notes: 'Synthetic development scenario',
            createdAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            updatedAt: SerializableDateTime::fromString('2026-01-01 08:00:00'),
            discipline: $discipline,
            sportSchedule: $sportSchedule,
            performanceMetrics: $performanceMetrics,
            targetRaceProfile: $profile,
            trainingFocus: $focus,
        );

        return TrainingPlanAnalysisScenario::create(
            name: $name,
            label: $label,
            planType: TrainingPlanType::TRAINING,
            discipline: $discipline,
            targetRaceProfile: $profile,
            trainingFocus: $focus,
            planStartDay: $planStartDay,
            referenceDate: $referenceDate,
            targetRace: $targetRace,
            allRaceEvents: [$targetRace],
            linkedTrainingPlan: $linkedPlan,
        );
    }

    private function resolveIdealWeeks(RaceEventProfile $profile, TrainingPlanType $type): int
    {
        $idealWeeks = match ($profile) {
            RaceEventProfile::FULL_DISTANCE_TRIATHLON => 24,
            RaceEventProfile::HALF_DISTANCE_TRIATHLON => 16,
            RaceEventProfile::OLYMPIC_TRIATHLON => 16,
            RaceEventProfile::SPRINT_TRIATHLON => 12,
            RaceEventProfile::MARATHON => 18,
            RaceEventProfile::HALF_MARATHON => 14,
            RaceEventProfile::RUN_10K => 12,
            RaceEventProfile::RUN_5K => 10,
            RaceEventProfile::GRAVEL_RACE, RaceEventProfile::RIDE, RaceEventProfile::TIME_TRIAL => 14,
            default => 12,
        };

        if (TrainingPlanType::TRAINING === $type) {
            return max(12, min(18, $idealWeeks));
        }

        return $idealWeeks;
    }
}
