<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260418103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add running workout target mode to training plans';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE TrainingPlan ADD runningWorkoutTargetMode VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE TrainingPlan DROP COLUMN runningWorkoutTargetMode');
    }
}