<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260416183000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop legacy TrainingPlan sessionsPerWeek and trainingDays columns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE TrainingPlan DROP COLUMN sessionsPerWeek');
        $this->addSql('ALTER TABLE TrainingPlan DROP COLUMN trainingDays');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE TrainingPlan ADD COLUMN sessionsPerWeek INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE TrainingPlan ADD COLUMN trainingDays CLOB DEFAULT NULL');
    }
}