<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260407120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add daily wellness storage for bridge-backed Garmin imports';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE DailyWellness (day DATETIME NOT NULL, source VARCHAR(255) NOT NULL, stepsCount INTEGER DEFAULT NULL, sleepDurationInSeconds INTEGER DEFAULT NULL, sleepScore INTEGER DEFAULT NULL, hrv DOUBLE PRECISION DEFAULT NULL, payload CLOB NOT NULL, importedAt DATETIME NOT NULL, PRIMARY KEY(day, source))');
        $this->addSql('CREATE INDEX DailyWellness_day ON DailyWellness (day)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE DailyWellness');
    }
}