<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260407183000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create PlannedSession table for training planner persistence';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE PlannedSession (
            plannedSessionId VARCHAR(255) NOT NULL,
            day DATETIME NOT NULL,
            activityType VARCHAR(255) NOT NULL,
            title VARCHAR(255) DEFAULT NULL,
            notes CLOB DEFAULT NULL,
            targetLoad DOUBLE PRECISION DEFAULT NULL,
            targetDurationInSeconds INTEGER DEFAULT NULL,
            targetIntensity VARCHAR(255) DEFAULT NULL,
            templateActivityId VARCHAR(255) DEFAULT NULL,
            estimationSource VARCHAR(255) NOT NULL,
            linkedActivityId VARCHAR(255) DEFAULT NULL,
            linkStatus VARCHAR(255) NOT NULL,
            createdAt DATETIME NOT NULL,
            updatedAt DATETIME NOT NULL,
            PRIMARY KEY(plannedSessionId)
        )');
        $this->addSql('CREATE INDEX PlannedSession_day ON PlannedSession (day)');
        $this->addSql('CREATE INDEX PlannedSession_linkedActivityId ON PlannedSession (linkedActivityId)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE PlannedSession');
    }
}
