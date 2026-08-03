<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260803240000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Public embed keys (tokenised read feeds + usage + CORS allowlist)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE embed_keys (
            id UUID NOT NULL,
            owner_id UUID NOT NULL,
            dataset_id UUID NOT NULL,
            name VARCHAR(120) NOT NULL,
            token_hash VARCHAR(64) NOT NULL,
            token_prefix VARCHAR(16) NOT NULL,
            allowed_origins JSON NOT NULL,
            tag_ids JSON NOT NULL,
            include_done BOOLEAN NOT NULL,
            include_description BOOLEAN NOT NULL,
            active BOOLEAN NOT NULL,
            rate_limit_per_minute INT NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            last_origin VARCHAR(512) DEFAULT NULL,
            request_count INT NOT NULL,
            bytes_served BIGINT NOT NULL,
            revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_embed_token_hash ON embed_keys (token_hash)');
        $this->addSql('CREATE INDEX idx_embed_owner ON embed_keys (owner_id)');
        $this->addSql('ALTER TABLE embed_keys ADD CONSTRAINT FK_EMBED_OWNER FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE embed_keys ADD CONSTRAINT FK_EMBED_DATASET FOREIGN KEY (dataset_id) REFERENCES datasets (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE embed_keys DROP CONSTRAINT FK_EMBED_OWNER');
        $this->addSql('ALTER TABLE embed_keys DROP CONSTRAINT FK_EMBED_DATASET');
        $this->addSql('DROP TABLE embed_keys');
    }
}
