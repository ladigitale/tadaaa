<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Discussion des lieux partagés : messages, vocaux et dessins laissés par les visiteurs, compteur par lieu';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE artifact_place_messages (
                id UUID NOT NULL,
                place_id UUID NOT NULL,
                kind VARCHAR(16) DEFAULT 'text' NOT NULL,
                text VARCHAR(2000) NOT NULL,
                media TEXT DEFAULT NULL,
                pseudo VARCHAR(160) DEFAULT NULL,
                ip_hash VARCHAR(64) NOT NULL,
                delete_token_hash VARCHAR(64) NOT NULL,
                reports INT NOT NULL,
                hidden BOOLEAN NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_artifact_place_message_place ON artifact_place_messages (place_id, created_at)');
        $this->addSql('ALTER TABLE artifact_place_messages ADD CONSTRAINT FK_ARTIFACT_PLACE_MESSAGES_PLACE FOREIGN KEY (place_id) REFERENCES artifact_places (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE artifact_places ADD message_count INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE artifact_place_messages');
        $this->addSql('ALTER TABLE artifact_places DROP message_count');
    }
}
