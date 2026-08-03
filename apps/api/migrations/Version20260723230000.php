<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260723230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'OAuth 2.1 clients, auth codes and tokens for MCP Claude connectors';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE oauth_clients (id UUID NOT NULL, client_id VARCHAR(64) NOT NULL, client_secret_hash VARCHAR(64) DEFAULT NULL, client_name VARCHAR(255) DEFAULT NULL, redirect_uris JSON NOT NULL, token_endpoint_auth_method VARCHAR(40) NOT NULL, grant_types JSON NOT NULL, response_types JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_oauth_client_id ON oauth_clients (client_id)');
        $this->addSql('COMMENT ON COLUMN oauth_clients.id IS \'(DC2Type:uuid)\'');

        $this->addSql('CREATE TABLE oauth_auth_codes (id UUID NOT NULL, client_id UUID NOT NULL, user_id UUID NOT NULL, code_hash VARCHAR(64) NOT NULL, redirect_uri VARCHAR(2048) NOT NULL, code_challenge VARCHAR(128) NOT NULL, code_challenge_method VARCHAR(16) NOT NULL, scopes JSON NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_oauth_auth_code_hash ON oauth_auth_codes (code_hash)');
        $this->addSql('CREATE INDEX IDX_OAUTH_AUTH_CODES_CLIENT ON oauth_auth_codes (client_id)');
        $this->addSql('CREATE INDEX IDX_OAUTH_AUTH_CODES_USER ON oauth_auth_codes (user_id)');
        $this->addSql('COMMENT ON COLUMN oauth_auth_codes.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN oauth_auth_codes.client_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN oauth_auth_codes.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE oauth_auth_codes ADD CONSTRAINT FK_OAUTH_AUTH_CODES_CLIENT FOREIGN KEY (client_id) REFERENCES oauth_clients (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE oauth_auth_codes ADD CONSTRAINT FK_OAUTH_AUTH_CODES_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE oauth_tokens (id UUID NOT NULL, client_id UUID NOT NULL, user_id UUID NOT NULL, access_token_hash VARCHAR(64) NOT NULL, refresh_token_hash VARCHAR(64) DEFAULT NULL, scopes JSON NOT NULL, access_expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, refresh_expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_oauth_access_hash ON oauth_tokens (access_token_hash)');
        $this->addSql('CREATE INDEX idx_oauth_refresh_hash ON oauth_tokens (refresh_token_hash)');
        $this->addSql('CREATE INDEX IDX_OAUTH_TOKENS_CLIENT ON oauth_tokens (client_id)');
        $this->addSql('CREATE INDEX IDX_OAUTH_TOKENS_USER ON oauth_tokens (user_id)');
        $this->addSql('COMMENT ON COLUMN oauth_tokens.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN oauth_tokens.client_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN oauth_tokens.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE oauth_tokens ADD CONSTRAINT FK_OAUTH_TOKENS_CLIENT FOREIGN KEY (client_id) REFERENCES oauth_clients (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE oauth_tokens ADD CONSTRAINT FK_OAUTH_TOKENS_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE oauth_auth_codes DROP CONSTRAINT FK_OAUTH_AUTH_CODES_CLIENT');
        $this->addSql('ALTER TABLE oauth_auth_codes DROP CONSTRAINT FK_OAUTH_AUTH_CODES_USER');
        $this->addSql('ALTER TABLE oauth_tokens DROP CONSTRAINT FK_OAUTH_TOKENS_CLIENT');
        $this->addSql('ALTER TABLE oauth_tokens DROP CONSTRAINT FK_OAUTH_TOKENS_USER');
        $this->addSql('DROP TABLE oauth_auth_codes');
        $this->addSql('DROP TABLE oauth_tokens');
        $this->addSql('DROP TABLE oauth_clients');
    }
}
