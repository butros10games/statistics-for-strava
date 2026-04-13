<?php

namespace App\Tests\Application\Build\BuildDashboardHtml;

use App\Application\Build\BuildDashboardHtml\BuildDashboardHtml;
use App\Domain\Activity\Activity;
use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityIntensity;
use App\Domain\Activity\EnrichedActivities;
use App\Domain\Activity\Route\RouteGeography;
use App\Domain\Activity\SportType\SportType;
use App\Domain\Activity\Stream\PowerOutputs;
use App\Domain\Activity\Stream\StreamBasedActivityPowerRepository;
use App\Infrastructure\ValueObject\Measurement\Length\Kilometer;
use App\Infrastructure\ValueObject\Measurement\Length\Meter;
use App\Infrastructure\ValueObject\Measurement\Velocity\KmPerHour;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\Application\BuildAppFilesTestCase;

class BuildDashboardHtmlCommandHandlerTest extends BuildAppFilesTestCase
{
    public function testHandle(): void
    {
        $this->provideFullTestSet();

        $this->commandBus->dispatch(new BuildDashboardHtml());
        $this->assertFileSystemWrites($this->getContainer()->get('build.storage'));
    }

    public function testHandleShouldResetStaleActivityCaches(): void
    {
        $this->provideFullTestSet();

        $reflection = new \ReflectionClass(EnrichedActivities::class);

        $cachedActivities = $reflection->getProperty('cachedActivities');
        $cachedActivities->setAccessible(true);
        $cachedActivities->setValue(null, [
            (string) ActivityId::fromUnprefixed('stale') => Activity::fromState(
                activityId: ActivityId::fromUnprefixed('stale'),
                startDateTime: SerializableDateTime::fromString('2026-04-12 06:00:00'),
                sportType: SportType::RIDE,
                worldType: \App\Domain\Activity\WorldType::REAL_WORLD,
                name: 'Stale Cache Ride',
                description: '',
                distance: Kilometer::from(1),
                elevation: Meter::from(0),
                startingCoordinate: null,
                calories: 0,
                averagePower: null,
                maxPower: null,
                averageSpeed: KmPerHour::from(30),
                maxSpeed: KmPerHour::from(35),
                averageHeartRate: null,
                maxHeartRate: null,
                averageCadence: null,
                movingTimeInSeconds: 120,
                kudoCount: 0,
                deviceName: '',
                totalImageCount: 0,
                localImagePaths: [],
                polyline: '',
                routeGeography: RouteGeography::create([]),
                weather: null,
                gearId: null,
                isCommute: false,
                workoutType: null,
            ),
        ]);

        $cachedActivitiesPerActivityType = $reflection->getProperty('cachedActivitiesPerActivityType');
        $cachedActivitiesPerActivityType->setAccessible(true);
        $cachedActivitiesPerActivityType->setValue(null, [
            'ride' => [(string) ActivityId::fromUnprefixed('stale')],
        ]);

        ActivityIntensity::$cachedIntensities[(string) ActivityId::fromUnprefixed('stale')] = 99;

        $this->commandBus->dispatch(new BuildDashboardHtml());

        $dashboardHtml = $this->getContainer()->get('build.storage')->read('dashboard.html');

        $this->assertStringContainsString('Weight history', $dashboardHtml);
        $this->assertStringNotContainsString('Stale Cache Ride', $dashboardHtml);
    }

    public function testHandleShouldResetStalePowerOutputCache(): void
    {
        $this->provideFullTestSet();

        StreamBasedActivityPowerRepository::$cachedPowerOutputs = [
            (string) ActivityId::fromUnprefixed('stale') => PowerOutputs::empty(),
        ];

        $this->commandBus->dispatch(new BuildDashboardHtml());

        $dashboardHtml = $this->getContainer()->get('build.storage')->read('dashboard.html');

        $this->assertStringContainsString('Weight history', $dashboardHtml);
        $this->assertArrayNotHasKey((string) ActivityId::fromUnprefixed('stale'), StreamBasedActivityPowerRepository::$cachedPowerOutputs);
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        EnrichedActivities::reset();
        ActivityIntensity::reset();
        StreamBasedActivityPowerRepository::reset();
    }
}
