<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260418113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add hill session preference to training plans';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE TrainingPlan ADD runHillSessionsEnabled BOOLEAN DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE TrainingPlan DROP COLUMN runHillSessionsEnabled');
    }
}