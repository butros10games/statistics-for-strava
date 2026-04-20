<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260413153001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create training session library table for reusable planner sessions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE TrainingSession (
            trainingSessionId VARCHAR(255) NOT NULL,
            sourcePlannedSessionId VARCHAR(255) DEFAULT NULL,
            activityType VARCHAR(255) NOT NULL,
            title VARCHAR(255) DEFAULT NULL,
            notes CLOB DEFAULT NULL,
            targetLoad DOUBLE PRECISION DEFAULT NULL,
            targetDurationInSeconds INTEGER DEFAULT NULL,
            targetIntensity VARCHAR(255) DEFAULT NULL,
            templateActivityId VARCHAR(255) DEFAULT NULL,
            workoutSteps CLOB DEFAULT NULL,
            estimationSource VARCHAR(255) NOT NULL,
            lastPlannedOn DATETIME DEFAULT NULL,
            createdAt DATETIME NOT NULL,
            updatedAt DATETIME NOT NULL,
            PRIMARY KEY(trainingSessionId)
        )');
        $this->addSql('CREATE INDEX TrainingSession_sourcePlannedSessionId ON TrainingSession (sourcePlannedSessionId)');
        $this->addSql('CREATE INDEX TrainingSession_lastPlannedOn ON TrainingSession (lastPlannedOn)');
        $this->addSql('CREATE INDEX TrainingSession_activityType_updatedAt ON TrainingSession (activityType, updatedAt)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE TrainingSession');
    }
}
