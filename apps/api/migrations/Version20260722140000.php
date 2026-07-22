<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260722140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Personal access tokens (PAT) pour MCP';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE access_tokens (id UUID NOT NULL, owner_id UUID NOT NULL, name VARCHAR(120) NOT NULL, token_hash VARCHAR(64) NOT NULL, token_prefix VARCHAR(16) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B3AD15F9B548B0F ON access_tokens (token_hash)');
        $this->addSql('CREATE INDEX IDX_B3AD15F97E3C61F9 ON access_tokens (owner_id)');
        $this->addSql('COMMENT ON COLUMN access_tokens.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN access_tokens.owner_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN access_tokens.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN access_tokens.last_used_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN access_tokens.revoked_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE access_tokens ADD CONSTRAINT FK_B3AD15F97E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE access_tokens DROP CONSTRAINT FK_B3AD15F97E3C61F9');
        $this->addSql('DROP TABLE access_tokens');
    }
}
