<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260407153000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add daily recovery questionnaire storage';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE DailyRecoveryCheckIn (day DATETIME NOT NULL, fatigue SMALLINT NOT NULL, soreness SMALLINT NOT NULL, stress SMALLINT NOT NULL, motivation SMALLINT NOT NULL, sleepQuality SMALLINT NOT NULL, recordedAt DATETIME NOT NULL, PRIMARY KEY(day))');
        $this->addSql('CREATE INDEX DailyRecoveryCheckIn_day ON DailyRecoveryCheckIn (day)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE DailyRecoveryCheckIn');
    }
}