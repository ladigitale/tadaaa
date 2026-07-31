<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260731233000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Web Push subscriptions + user notification preferences';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE users ADD notification_prefs JSON DEFAULT '{}'::json NOT NULL");

        $this->addSql('CREATE TABLE push_subscriptions (id UUID NOT NULL, user_id UUID NOT NULL, endpoint TEXT NOT NULL, p256dh VARCHAR(255) NOT NULL, auth VARCHAR(255) NOT NULL, user_agent VARCHAR(512) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PUSH_SUB_ENDPOINT ON push_subscriptions (endpoint)');
        $this->addSql('CREATE INDEX IDX_PUSH_SUB_USER ON push_subscriptions (user_id)');
        $this->addSql('COMMENT ON COLUMN push_subscriptions.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN push_subscriptions.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN push_subscriptions.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN push_subscriptions.last_seen_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN push_subscriptions.revoked_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE push_subscriptions ADD CONSTRAINT FK_PUSH_SUB_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE push_subscriptions DROP CONSTRAINT FK_PUSH_SUB_USER');
        $this->addSql('DROP TABLE push_subscriptions');
        $this->addSql('ALTER TABLE users DROP notification_prefs');
    }
}
