<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260803230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Google Calendar OAuth connection, per-dataset bindings, todo↔event links';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE google_calendar_connections (id UUID NOT NULL, user_id UUID NOT NULL, google_account_email VARCHAR(255) NOT NULL, refresh_token_enc TEXT NOT NULL, access_token_enc TEXT DEFAULT NULL, access_token_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, status VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_gcal_connection_user ON google_calendar_connections (user_id)');
        $this->addSql('COMMENT ON COLUMN google_calendar_connections.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN google_calendar_connections.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN google_calendar_connections.access_token_expires_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN google_calendar_connections.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN google_calendar_connections.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE google_calendar_connections ADD CONSTRAINT FK_GCAL_CONN_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE google_calendar_bindings (id UUID NOT NULL, user_id UUID NOT NULL, dataset_id UUID NOT NULL, google_calendar_id VARCHAR(256) NOT NULL, google_calendar_summary VARCHAR(255) NOT NULL, tag_ids JSON NOT NULL, is_default BOOLEAN NOT NULL, export_enabled BOOLEAN NOT NULL, import_enabled BOOLEAN NOT NULL, priority INT NOT NULL, sync_token TEXT DEFAULT NULL, watch_channel_id VARCHAR(128) DEFAULT NULL, watch_resource_id VARCHAR(256) DEFAULT NULL, watch_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, watch_token VARCHAR(64) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_gcal_binding_user_dataset_cal ON google_calendar_bindings (user_id, dataset_id, google_calendar_id)');
        $this->addSql('CREATE INDEX idx_gcal_binding_user_dataset ON google_calendar_bindings (user_id, dataset_id)');
        $this->addSql('CREATE INDEX idx_gcal_binding_watch ON google_calendar_bindings (watch_channel_id)');
        $this->addSql('COMMENT ON COLUMN google_calendar_bindings.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN google_calendar_bindings.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN google_calendar_bindings.dataset_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN google_calendar_bindings.watch_expires_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN google_calendar_bindings.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN google_calendar_bindings.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE google_calendar_bindings ADD CONSTRAINT FK_GCAL_BIND_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE google_calendar_bindings ADD CONSTRAINT FK_GCAL_BIND_DATASET FOREIGN KEY (dataset_id) REFERENCES datasets (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE todo_google_event_links (id UUID NOT NULL, user_id UUID NOT NULL, dataset_id UUID NOT NULL, todo_id VARCHAR(64) NOT NULL, google_calendar_id VARCHAR(256) NOT NULL, google_event_id VARCHAR(256) NOT NULL, etag VARCHAR(255) DEFAULT NULL, content_hash VARCHAR(64) DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_todo_gcal_user_dataset_todo ON todo_google_event_links (user_id, dataset_id, todo_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_todo_gcal_user_cal_event ON todo_google_event_links (user_id, google_calendar_id, google_event_id)');
        $this->addSql('CREATE INDEX idx_todo_gcal_dataset_todo ON todo_google_event_links (dataset_id, todo_id)');
        $this->addSql('COMMENT ON COLUMN todo_google_event_links.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN todo_google_event_links.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN todo_google_event_links.dataset_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN todo_google_event_links.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE todo_google_event_links ADD CONSTRAINT FK_TODO_GCAL_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE todo_google_event_links ADD CONSTRAINT FK_TODO_GCAL_DATASET FOREIGN KEY (dataset_id) REFERENCES datasets (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE todo_google_event_links DROP CONSTRAINT FK_TODO_GCAL_USER');
        $this->addSql('ALTER TABLE todo_google_event_links DROP CONSTRAINT FK_TODO_GCAL_DATASET');
        $this->addSql('DROP TABLE todo_google_event_links');
        $this->addSql('ALTER TABLE google_calendar_bindings DROP CONSTRAINT FK_GCAL_BIND_USER');
        $this->addSql('ALTER TABLE google_calendar_bindings DROP CONSTRAINT FK_GCAL_BIND_DATASET');
        $this->addSql('DROP TABLE google_calendar_bindings');
        $this->addSql('ALTER TABLE google_calendar_connections DROP CONSTRAINT FK_GCAL_CONN_USER');
        $this->addSql('DROP TABLE google_calendar_connections');
    }
}
