<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lieux partagés des artefacts (data.places) : objets JSON posés par les visiteurs à une position GPS';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE artifact_places (
                id UUID NOT NULL,
                artifact_id UUID NOT NULL,
                author_id UUID DEFAULT NULL,
                collection VARCHAR(64) NOT NULL,
                lat DOUBLE PRECISION NOT NULL,
                lon DOUBLE PRECISION NOT NULL,
                summary JSON NOT NULL,
                body JSON NOT NULL,
                size_bytes INT NOT NULL,
                delete_token_hash VARCHAR(64) NOT NULL,
                ip_hash VARCHAR(64) NOT NULL,
                reports INT NOT NULL,
                hidden BOOLEAN NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_artifact_place_geo ON artifact_places (artifact_id, collection, lat, lon)');
        $this->addSql('CREATE INDEX idx_artifact_place_ip ON artifact_places (artifact_id, ip_hash, created_at)');
        $this->addSql('CREATE INDEX IDX_ARTIFACT_PLACES_AUTHOR ON artifact_places (author_id)');
        $this->addSql('ALTER TABLE artifact_places ADD CONSTRAINT FK_ARTIFACT_PLACES_ARTIFACT FOREIGN KEY (artifact_id) REFERENCES artifacts (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE artifact_places ADD CONSTRAINT FK_ARTIFACT_PLACES_AUTHOR FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE artifact_places');
    }
}
