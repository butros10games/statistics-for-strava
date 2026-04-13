<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260413110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add race event family/profile classification while preserving legacy type values';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE RaceEvent ADD COLUMN family VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE RaceEvent ADD COLUMN profile VARCHAR(255) DEFAULT NULL');
        $this->addSql("UPDATE RaceEvent SET family = CASE type
            WHEN 'sprintTriathlon' THEN 'triathlon'
            WHEN 'olympicTriathlon' THEN 'triathlon'
            WHEN 'halfDistanceTriathlon' THEN 'triathlon'
            WHEN 'fullDistanceTriathlon' THEN 'triathlon'
            WHEN 'duathlon' THEN 'multisport'
            WHEN 'aquathlon' THEN 'multisport'
            WHEN 'swim' THEN 'swim'
            WHEN 'ride' THEN 'ride'
            WHEN 'run5k' THEN 'run'
            WHEN 'run10k' THEN 'run'
            WHEN 'halfMarathon' THEN 'run'
            WHEN 'marathon' THEN 'run'
            WHEN 'run' THEN 'run'
            ELSE 'other'
        END");
        $this->addSql('UPDATE RaceEvent SET profile = type WHERE profile IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException();
    }
}
