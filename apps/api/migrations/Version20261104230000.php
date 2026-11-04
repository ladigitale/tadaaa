<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bibliothèque de ressources du compte (resources, resource_versions) : shaders, modèles 3D, données, images, sons';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE resources (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                kind VARCHAR(16) NOT NULL,
                slug VARCHAR(64) NOT NULL,
                name VARCHAR(120) NOT NULL,
                description TEXT NOT NULL,
                tags JSON NOT NULL,
                prompt TEXT NOT NULL,
                content_type VARCHAR(100) NOT NULL,
                version INT NOT NULL,
                size_bytes BIGINT NOT NULL,
                sha256 VARCHAR(64) NOT NULL,
                data BYTEA NOT NULL,
                base_id VARCHAR(120) DEFAULT NULL,
                base_version INT DEFAULT NULL,
                base_sha256 VARCHAR(64) DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_resource_user_slug ON resources (user_id, slug)');
        $this->addSql('CREATE INDEX idx_resource_user_kind ON resources (user_id, kind)');
        $this->addSql('ALTER TABLE resources ADD CONSTRAINT FK_RESOURCE_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE resource_versions (
                resource_id UUID NOT NULL,
                version INT NOT NULL,
                content_type VARCHAR(100) NOT NULL,
                size_bytes BIGINT NOT NULL,
                sha256 VARCHAR(64) NOT NULL,
                prompt TEXT NOT NULL,
                data BYTEA NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (resource_id, version)
            )
        SQL);
        $this->addSql('ALTER TABLE resource_versions ADD CONSTRAINT FK_RESOURCE_VERSION_RESOURCE FOREIGN KEY (resource_id) REFERENCES resources (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE resource_versions');
        $this->addSql('DROP TABLE resources');
    }
}
