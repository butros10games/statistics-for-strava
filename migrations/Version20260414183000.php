<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260414183000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create managed training plans table for sequencing race and training plans';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE TrainingPlan (
            trainingPlanId VARCHAR(255) NOT NULL,
            type VARCHAR(255) NOT NULL,
            startDay DATETIME NOT NULL,
            endDay DATETIME NOT NULL,
            targetRaceEventId VARCHAR(255) DEFAULT NULL,
            title VARCHAR(255) DEFAULT NULL,
            notes CLOB DEFAULT NULL,
            createdAt DATETIME NOT NULL,
            updatedAt DATETIME NOT NULL,
            PRIMARY KEY(trainingPlanId)
        )');
        $this->addSql('CREATE INDEX TrainingPlan_startDay ON TrainingPlan (startDay)');
        $this->addSql('CREATE INDEX TrainingPlan_endDay ON TrainingPlan (endDay)');
        $this->addSql('CREATE INDEX TrainingPlan_targetRaceEventId ON TrainingPlan (targetRaceEventId)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE TrainingPlan');
    }
}