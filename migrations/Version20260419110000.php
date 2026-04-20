<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260419110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add multi-user auth, Strava connections, social graph, athlete profiles, and planner ownership columns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE AppUser (
            appUserId VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            passwordHash VARCHAR(255) NOT NULL,
            roles CLOB NOT NULL,
            emailVerified BOOLEAN NOT NULL DEFAULT 0,
            emailVerificationToken VARCHAR(255) DEFAULT NULL,
            passwordResetToken VARCHAR(255) DEFAULT NULL,
            passwordResetRequestedAt DATETIME DEFAULT NULL,
            createdAt DATETIME NOT NULL,
            updatedAt DATETIME NOT NULL,
            PRIMARY KEY (appUserId)
        )');
        $this->addSql('CREATE UNIQUE INDEX AppUser_email ON AppUser (email)');

        $this->addSql('CREATE TABLE AppUserStravaConnection (
            appUserId VARCHAR(255) NOT NULL,
            stravaAthleteId VARCHAR(255) NOT NULL,
            refreshToken VARCHAR(255) NOT NULL,
            scopes CLOB NOT NULL,
            accessTokenExpiresAt DATETIME DEFAULT NULL,
            tokenRefreshedAt DATETIME DEFAULT NULL,
            webhookCorrelationKey VARCHAR(255) DEFAULT NULL,
            createdAt DATETIME NOT NULL,
            updatedAt DATETIME NOT NULL,
            PRIMARY KEY (appUserId)
        )');
        $this->addSql('CREATE UNIQUE INDEX AppUserStravaConnection_athlete ON AppUserStravaConnection (stravaAthleteId)');

        $this->addSql('CREATE TABLE UserConnection (
            userConnectionId VARCHAR(255) NOT NULL,
            requesterUserId VARCHAR(255) NOT NULL,
            targetUserId VARCHAR(255) NOT NULL,
            type VARCHAR(32) NOT NULL,
            status VARCHAR(32) NOT NULL,
            createdAt DATETIME NOT NULL,
            updatedAt DATETIME NOT NULL,
            PRIMARY KEY (userConnectionId)
        )');
        $this->addSql('CREATE INDEX UserConnection_lookup ON UserConnection (requesterUserId, targetUserId, type, status)');

        $this->addSql('CREATE TABLE AthleteProfile (
            appUserId VARCHAR(255) NOT NULL,
            payload CLOB NOT NULL,
            PRIMARY KEY (appUserId)
        )');

        $this->addSql('ALTER TABLE TrainingPlan ADD ownerUserId VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE TrainingPlan ADD visibility VARCHAR(32) NOT NULL DEFAULT \'friends\'');
        $this->addSql('CREATE INDEX TrainingPlan_ownerUserId ON TrainingPlan (ownerUserId)');

        $this->addSql('ALTER TABLE PlannedSession ADD ownerUserId VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX PlannedSession_ownerUserId ON PlannedSession (ownerUserId)');

        $this->addSql('ALTER TABLE TrainingBlock ADD ownerUserId VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX TrainingBlock_ownerUserId ON TrainingBlock (ownerUserId)');

        $this->addSql('ALTER TABLE RaceEvent ADD ownerUserId VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX RaceEvent_ownerUserId ON RaceEvent (ownerUserId)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX TrainingPlan_ownerUserId');
        $this->addSql('DROP INDEX PlannedSession_ownerUserId');
        $this->addSql('DROP INDEX TrainingBlock_ownerUserId');
        $this->addSql('DROP INDEX RaceEvent_ownerUserId');

        $this->addSql('ALTER TABLE TrainingPlan DROP COLUMN ownerUserId');
        $this->addSql('ALTER TABLE TrainingPlan DROP COLUMN visibility');
        $this->addSql('ALTER TABLE PlannedSession DROP COLUMN ownerUserId');
        $this->addSql('ALTER TABLE TrainingBlock DROP COLUMN ownerUserId');
        $this->addSql('ALTER TABLE RaceEvent DROP COLUMN ownerUserId');

        $this->addSql('DROP TABLE AthleteProfile');
        $this->addSql('DROP TABLE UserConnection');
        $this->addSql('DROP TABLE AppUserStravaConnection');
        $this->addSql('DROP TABLE AppUser');
    }
}
