<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260929210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Artefacts: Artifact, ArtifactVersion, ArtifactCollection, ArtifactRecord';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE artifacts (
                id UUID NOT NULL,
                dataset_id UUID NOT NULL,
                slug VARCHAR(64) NOT NULL,
                title VARCHAR(200) NOT NULL,
                description TEXT DEFAULT NULL,
                visibility VARCHAR(16) NOT NULL,
                link_token VARCHAR(64) DEFAULT NULL,
                current_version INT NOT NULL,
                concorde_version VARCHAR(32) NOT NULL,
                created_by_id UUID NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_artifact_slug ON artifacts (slug)');
        $this->addSql('CREATE INDEX idx_artifact_dataset ON artifacts (dataset_id)');
        $this->addSql('ALTER TABLE artifacts ADD CONSTRAINT FK_ARTIFACT_DATASET FOREIGN KEY (dataset_id) REFERENCES datasets (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE artifacts ADD CONSTRAINT FK_ARTIFACT_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE artifact_versions (
                id UUID NOT NULL,
                artifact_id UUID NOT NULL,
                version INT NOT NULL,
                document JSON NOT NULL,
                created_by_id UUID NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                note TEXT DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_artifact_version ON artifact_versions (artifact_id, version)');
        $this->addSql('ALTER TABLE artifact_versions ADD CONSTRAINT FK_ARTIFACT_VERSION_ARTIFACT FOREIGN KEY (artifact_id) REFERENCES artifacts (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE artifact_versions ADD CONSTRAINT FK_ARTIFACT_VERSION_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE artifact_collections (
                id UUID NOT NULL,
                artifact_id UUID NOT NULL,
                name VARCHAR(64) NOT NULL,
                public_read BOOLEAN NOT NULL,
                write_mode VARCHAR(20) NOT NULL,
                scope VARCHAR(16) NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_artifact_collection_name ON artifact_collections (artifact_id, name)');
        $this->addSql('ALTER TABLE artifact_collections ADD CONSTRAINT FK_ARTIFACT_COLLECTION_ARTIFACT FOREIGN KEY (artifact_id) REFERENCES artifacts (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE artifact_records (
                id UUID NOT NULL,
                collection_id UUID NOT NULL,
                data JSON NOT NULL,
                created_by_id UUID DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_artifact_record_collection ON artifact_records (collection_id)');
        $this->addSql('CREATE INDEX idx_artifact_record_owner ON artifact_records (created_by_id)');
        $this->addSql('ALTER TABLE artifact_records ADD CONSTRAINT FK_ARTIFACT_RECORD_COLLECTION FOREIGN KEY (collection_id) REFERENCES artifact_collections (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE artifact_records ADD CONSTRAINT FK_ARTIFACT_RECORD_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artifact_records DROP CONSTRAINT FK_ARTIFACT_RECORD_COLLECTION');
        $this->addSql('ALTER TABLE artifact_records DROP CONSTRAINT FK_ARTIFACT_RECORD_CREATED_BY');
        $this->addSql('ALTER TABLE artifact_collections DROP CONSTRAINT FK_ARTIFACT_COLLECTION_ARTIFACT');
        $this->addSql('ALTER TABLE artifact_versions DROP CONSTRAINT FK_ARTIFACT_VERSION_ARTIFACT');
        $this->addSql('ALTER TABLE artifact_versions DROP CONSTRAINT FK_ARTIFACT_VERSION_CREATED_BY');
        $this->addSql('ALTER TABLE artifacts DROP CONSTRAINT FK_ARTIFACT_DATASET');
        $this->addSql('ALTER TABLE artifacts DROP CONSTRAINT FK_ARTIFACT_CREATED_BY');
        $this->addSql('DROP TABLE artifact_records');
        $this->addSql('DROP TABLE artifact_collections');
        $this->addSql('DROP TABLE artifact_versions');
        $this->addSql('DROP TABLE artifacts');
    }
}
