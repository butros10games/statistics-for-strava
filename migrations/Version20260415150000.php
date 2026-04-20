<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260415150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add discipline, sportSchedule and performanceMetrics columns to TrainingPlan';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE TrainingPlan ADD COLUMN discipline VARCHAR DEFAULT NULL');
        $this->addSql('ALTER TABLE TrainingPlan ADD COLUMN sportSchedule CLOB DEFAULT NULL');
        $this->addSql('ALTER TABLE TrainingPlan ADD COLUMN performanceMetrics CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE TrainingPlan DROP COLUMN discipline');
        $this->addSql('ALTER TABLE TrainingPlan DROP COLUMN sportSchedule');
        $this->addSql('ALTER TABLE TrainingPlan DROP COLUMN performanceMetrics');
    }
}
