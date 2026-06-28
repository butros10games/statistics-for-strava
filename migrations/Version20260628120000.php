<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260628120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make Strava webhook queue idempotent per aspect and user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE WebhookEvent_new (
            eventId VARCHAR(255) NOT NULL,
            objectId VARCHAR(255) NOT NULL,
            objectType VARCHAR(255) NOT NULL,
            aspectType VARCHAR(255) NOT NULL,
            ownerAthleteId VARCHAR(255) DEFAULT NULL,
            appUserId VARCHAR(255) DEFAULT NULL,
            payload CLOB NOT NULL,
            PRIMARY KEY (eventId)
        )');
        $this->addSql('INSERT INTO WebhookEvent_new (
            eventId, objectId, objectType, aspectType, ownerAthleteId, appUserId, payload
        )
        SELECT
            objectType || \':\' || objectId || \':\' || aspectType || \':global:unknown-owner\',
            objectId,
            objectType,
            aspectType,
            NULL,
            NULL,
            payload
        FROM WebhookEvent');
        $this->addSql('DROP TABLE WebhookEvent');
        $this->addSql('ALTER TABLE WebhookEvent_new RENAME TO WebhookEvent');
        $this->addSql('CREATE INDEX WebhookEvent_object_aspect ON WebhookEvent (objectType, objectId, aspectType)');
        $this->addSql('CREATE INDEX WebhookEvent_appUserId ON WebhookEvent (appUserId)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE WebhookEvent_old (
            objectId VARCHAR(255) NOT NULL,
            objectType VARCHAR(255) NOT NULL,
            aspectType VARCHAR(255) NOT NULL,
            payload CLOB NOT NULL,
            PRIMARY KEY (objectId)
        )');
        $this->addSql('INSERT OR IGNORE INTO WebhookEvent_old (objectId, objectType, aspectType, payload)
            SELECT objectId, objectType, aspectType, payload FROM WebhookEvent');
        $this->addSql('DROP TABLE WebhookEvent');
        $this->addSql('ALTER TABLE WebhookEvent_old RENAME TO WebhookEvent');
    }
}
