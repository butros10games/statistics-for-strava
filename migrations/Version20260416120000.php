<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260416120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add targetRaceProfile and trainingFocus columns to TrainingPlan';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE TrainingPlan ADD COLUMN targetRaceProfile VARCHAR DEFAULT NULL');
        $this->addSql('ALTER TABLE TrainingPlan ADD COLUMN trainingFocus VARCHAR DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE TrainingPlan DROP COLUMN trainingFocus');
        $this->addSql('ALTER TABLE TrainingPlan DROP COLUMN targetRaceProfile');
    }
}
