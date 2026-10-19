<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Artefacts : salons éphémères (artifact_rooms) et participants invités (artifact_room_members)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE artifact_rooms (
                id UUID NOT NULL,
                artifact_id UUID NOT NULL,
                topic_key VARCHAR(32) NOT NULL,
                name VARCHAR(40) NOT NULL,
                access VARCHAR(16) NOT NULL,
                code VARCHAR(24) DEFAULT NULL,
                listed BOOLEAN NOT NULL,
                max_members INT NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_artifact_room_artifact ON artifact_rooms (artifact_id)');
        $this->addSql('CREATE INDEX idx_artifact_room_expires ON artifact_rooms (expires_at)');
        $this->addSql('ALTER TABLE artifact_rooms ADD CONSTRAINT FK_ARTIFACT_ROOM_ARTIFACT FOREIGN KEY (artifact_id) REFERENCES artifacts (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE artifact_room_members (
                id UUID NOT NULL,
                room_id UUID NOT NULL,
                pseudo VARCHAR(32) NOT NULL,
                host BOOLEAN NOT NULL,
                status VARCHAR(16) NOT NULL,
                key_hash VARCHAR(64) NOT NULL,
                joined_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_artifact_room_member_room ON artifact_room_members (room_id)');
        $this->addSql('CREATE INDEX idx_artifact_room_member_seen ON artifact_room_members (last_seen_at)');
        $this->addSql('ALTER TABLE artifact_room_members ADD CONSTRAINT FK_ARTIFACT_ROOM_MEMBER_ROOM FOREIGN KEY (room_id) REFERENCES artifact_rooms (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE artifact_room_members');
        $this->addSql('DROP TABLE artifact_rooms');
    }
}
