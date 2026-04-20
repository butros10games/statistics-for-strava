<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260416123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add metadata columns to training session library for smarter recommendations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE TrainingSession ADD COLUMN sessionSource VARCHAR(255) NOT NULL DEFAULT 'plannedSession'");
        $this->addSql('ALTER TABLE TrainingSession ADD COLUMN sessionPhase VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE TrainingSession ADD COLUMN sessionObjective VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX TrainingSession_activityType_phase_objective_updatedAt ON TrainingSession (activityType, sessionPhase, sessionObjective, updatedAt)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX TrainingSession_activityType_phase_objective_updatedAt');
        $this->addSql('ALTER TABLE TrainingSession DROP COLUMN sessionObjective');
        $this->addSql('ALTER TABLE TrainingSession DROP COLUMN sessionPhase');
        $this->addSql('ALTER TABLE TrainingSession DROP COLUMN sessionSource');
    }
}