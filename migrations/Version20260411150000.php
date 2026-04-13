<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260411150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add training blocks for season and phase planning';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE TrainingBlock (
            trainingBlockId VARCHAR(255) NOT NULL,
            startDay DATETIME NOT NULL,
            endDay DATETIME NOT NULL,
            phase VARCHAR(255) NOT NULL,
            title VARCHAR(255) DEFAULT NULL,
            focus VARCHAR(255) DEFAULT NULL,
            notes CLOB DEFAULT NULL,
            createdAt DATETIME NOT NULL,
            updatedAt DATETIME NOT NULL,
            PRIMARY KEY(trainingBlockId)
        )');
        $this->addSql('CREATE INDEX TrainingBlock_startDay ON TrainingBlock (startDay)');
        $this->addSql('CREATE INDEX TrainingBlock_endDay ON TrainingBlock (endDay)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException();
    }
}
