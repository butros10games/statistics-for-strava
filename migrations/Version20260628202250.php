<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260628202250 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add planned session provenance for safe training plan mutations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE PlannedSession ADD sessionSource VARCHAR(255) NOT NULL DEFAULT 'manual'");
        $this->addSql('ALTER TABLE PlannedSession ADD sourceTrainingPlanId VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE PlannedSession ADD protectedFromPlanMutation BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql('CREATE INDEX PlannedSession_sourceTrainingPlanId ON PlannedSession (sourceTrainingPlanId)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX PlannedSession_sourceTrainingPlanId');
        $this->addSql('ALTER TABLE PlannedSession DROP COLUMN protectedFromPlanMutation');
        $this->addSql('ALTER TABLE PlannedSession DROP COLUMN sourceTrainingPlanId');
        $this->addSql('ALTER TABLE PlannedSession DROP COLUMN sessionSource');
    }
}
