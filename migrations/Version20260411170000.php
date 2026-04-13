<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260411170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Link training blocks to optional target races';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE TrainingBlock ADD targetRaceEventId VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX TrainingBlock_targetRaceEventId ON TrainingBlock (targetRaceEventId)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException();
    }
}
