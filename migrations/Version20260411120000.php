<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260411120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add race events for planner and calendar';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE RaceEvent (
            raceEventId VARCHAR(255) NOT NULL,
            day DATETIME NOT NULL,
            type VARCHAR(255) NOT NULL,
            title VARCHAR(255) DEFAULT NULL,
            location VARCHAR(255) DEFAULT NULL,
            notes CLOB DEFAULT NULL,
            priority VARCHAR(255) NOT NULL,
            targetFinishTimeInSeconds INTEGER DEFAULT NULL,
            createdAt DATETIME NOT NULL,
            updatedAt DATETIME NOT NULL,
            PRIMARY KEY(raceEventId)
        )');
        $this->addSql('CREATE INDEX RaceEvent_day ON RaceEvent (day)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException();
    }
}
