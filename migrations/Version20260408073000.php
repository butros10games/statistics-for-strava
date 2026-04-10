<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260408073000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add structured workout steps to planned sessions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE PlannedSession ADD COLUMN workoutSteps CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException();
    }
}
