<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agent : historique des conversations (journal, aperçu, mémoire de l’agent) pour les reprendre';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE agent_conversations (
            id UUID NOT NULL,
            user_id UUID NOT NULL,
            profile VARCHAR(32) NOT NULL,
            thread_id VARCHAR(100) NOT NULL,
            title VARCHAR(120) NOT NULL,
            artifact_slug VARCHAR(64) DEFAULT NULL,
            transcript TEXT NOT NULL,
            meta JSON NOT NULL,
            state TEXT DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_AGENT_CONVERSATION_THREAD ON agent_conversations (user_id, profile, thread_id)');
        $this->addSql('CREATE INDEX IDX_AGENT_CONVERSATION_LIST ON agent_conversations (user_id, profile, updated_at)');
        $this->addSql('ALTER TABLE agent_conversations ADD CONSTRAINT FK_AGENT_CONVERSATION_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql("COMMENT ON COLUMN agent_conversations.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN agent_conversations.user_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN agent_conversations.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN agent_conversations.updated_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE agent_conversations');
    }
}
