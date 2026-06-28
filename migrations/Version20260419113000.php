<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260419113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add owner scoping to reusable training sessions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE TrainingSession ADD ownerUserId VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX TrainingSession_ownerUserId ON TrainingSession (ownerUserId)');
        $this->addSql('UPDATE TrainingSession
            SET ownerUserId = (
                SELECT PlannedSession.ownerUserId
                FROM PlannedSession
                WHERE PlannedSession.plannedSessionId = TrainingSession.sourcePlannedSessionId
            )
            WHERE sourcePlannedSessionId IS NOT NULL');
        $this->addSql('UPDATE TrainingSession
            SET ownerUserId = (SELECT appUserId FROM AppUser LIMIT 1)
            WHERE ownerUserId IS NULL
              AND (SELECT COUNT(*) FROM AppUser) = 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX TrainingSession_ownerUserId');
        $this->addSql('ALTER TABLE TrainingSession DROP COLUMN ownerUserId');
    }
}
