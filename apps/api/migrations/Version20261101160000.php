<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Artefacts : assets binaires par artefact (artifact_assets) — images, sons, modèles 3D, JSON…';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE artifact_assets (
                id UUID NOT NULL,
                artifact_id UUID NOT NULL,
                name VARCHAR(64) NOT NULL,
                content_type VARCHAR(100) NOT NULL,
                size_bytes BIGINT NOT NULL,
                sha256 VARCHAR(64) NOT NULL,
                data BYTEA NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_artifact_asset_name ON artifact_assets (artifact_id, name)');
        $this->addSql('ALTER TABLE artifact_assets ADD CONSTRAINT FK_ARTIFACT_ASSET_ARTIFACT FOREIGN KEY (artifact_id) REFERENCES artifacts (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE artifact_assets');
    }
}
