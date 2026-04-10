<?php

declare(strict_types=1);

namespace App\Tests\Application\Build\BuildTrainingAdvisorExport;

use App\Application\Build\BuildTrainingAdvisorExport\BuildTrainingAdvisorExport;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\Application\BuildAppFilesTestCase;

final class BuildTrainingAdvisorExportCommandHandlerTest extends BuildAppFilesTestCase
{
    public function testHandle(): void
    {
        $this->provideFullTestSet();

        $this->commandBus->dispatch(new BuildTrainingAdvisorExport(
            SerializableDateTime::fromString('2023-10-17 16:15:04')
        ));

        $apiStorage = $this->getContainer()->get('api.storage');

        self::assertTrue($apiStorage->fileExists('exports/training-advisor.json'));

        $payload = Json::uncompressAndDecode($apiStorage->read('exports/training-advisor.json'));

        self::assertSame(1, $payload['version']);
        self::assertSame('training-advisor', $payload['exportType']);
        self::assertSame('2023-10-17 16:15:04', $payload['generatedAt']);
        self::assertSame(42, $payload['windows']['recentActivityDays']);
        self::assertNotEmpty($payload['recentActivities']['items']);
        self::assertArrayHasKey('trainingMetrics', $payload['currentStatus']);
        self::assertArrayHasKey('readiness', $payload['currentStatus']);
        self::assertArrayHasKey('last42Days', $payload['trainingLoad']);
        self::assertArrayHasKey('items', $payload['upcomingPlannedSessions']);
        self::assertArrayHasKey('projection', $payload['upcomingPlannedSessions']);
    }
}
