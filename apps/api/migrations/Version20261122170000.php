<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Parcours entre les lieux des artefacts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE artifact_routes (id UUID NOT NULL, artifact_id UUID NOT NULL, collection VARCHAR(64) NOT NULL, title VARCHAR(80) NOT NULL, description VARCHAR(500) DEFAULT NULL, ordered BOOLEAN NOT NULL, mode VARCHAR(10) NOT NULL, steps JSON NOT NULL, min_lat DOUBLE PRECISION NOT NULL, max_lat DOUBLE PRECISION NOT NULL, min_lon DOUBLE PRECISION NOT NULL, max_lon DOUBLE PRECISION NOT NULL, length_m INT NOT NULL, pseudo VARCHAR(40) DEFAULT NULL, ip_hash VARCHAR(64) NOT NULL, delete_token_hash VARCHAR(64) NOT NULL, reports INT NOT NULL, hidden BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_artifact_route_box ON artifact_routes (artifact_id, collection, min_lat, max_lat, min_lon, max_lon)');
        $this->addSql('CREATE INDEX IDX_ARTIFACT_ROUTES_ARTIFACT ON artifact_routes (artifact_id)');
        $this->addSql('CREATE TABLE artifact_route_sounds (id UUID NOT NULL, route_id UUID NOT NULL, step INT NOT NULL, media TEXT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_artifact_route_sound ON artifact_route_sounds (route_id, step)');
        $this->addSql('ALTER TABLE artifact_route_sounds ADD CONSTRAINT FK_ARTIFACT_ROUTE_SOUNDS_ROUTE FOREIGN KEY (route_id) REFERENCES artifact_routes (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE artifact_routes ADD CONSTRAINT FK_ARTIFACT_ROUTES_ARTIFACT FOREIGN KEY (artifact_id) REFERENCES artifacts (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE artifact_route_sounds');
        $this->addSql('DROP TABLE artifact_routes');
    }
}
