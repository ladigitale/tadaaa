<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agent : réglages de l’assistant IA par utilisateur (fournisseur, modèle, clé chiffrée, URL libre)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE agent_settings (
            id UUID NOT NULL,
            user_id UUID NOT NULL,
            provider VARCHAR(32) NOT NULL,
            model VARCHAR(128) NOT NULL,
            api_key_cipher TEXT DEFAULT NULL,
            key_hint VARCHAR(8) DEFAULT NULL,
            custom_base_url_enabled BOOLEAN DEFAULT false NOT NULL,
            custom_base_url VARCHAR(512) DEFAULT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_AGENT_SETTINGS_USER ON agent_settings (user_id)');
        $this->addSql('ALTER TABLE agent_settings ADD CONSTRAINT FK_AGENT_SETTINGS_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql("COMMENT ON COLUMN agent_settings.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN agent_settings.user_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN agent_settings.updated_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE agent_settings');
    }
}
