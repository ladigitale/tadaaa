<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260802140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Webhooks, audit logs and usage counters';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE webhook_endpoints (id UUID NOT NULL, owner_id UUID NOT NULL, dataset_id UUID DEFAULT NULL, url VARCHAR(2048) NOT NULL, signing_secret VARCHAR(128) NOT NULL, secret_prefix VARCHAR(16) NOT NULL, events JSON NOT NULL, active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_delivery_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, failure_count INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_webhook_owner ON webhook_endpoints (owner_id)');
        $this->addSql('CREATE INDEX IDX_WEBHOOK_DATASET ON webhook_endpoints (dataset_id)');
        $this->addSql('COMMENT ON COLUMN webhook_endpoints.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN webhook_endpoints.owner_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN webhook_endpoints.dataset_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN webhook_endpoints.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN webhook_endpoints.last_delivery_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE webhook_endpoints ADD CONSTRAINT FK_WEBHOOK_OWNER FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE webhook_endpoints ADD CONSTRAINT FK_WEBHOOK_DATASET FOREIGN KEY (dataset_id) REFERENCES datasets (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE webhook_deliveries (id UUID NOT NULL, endpoint_id UUID NOT NULL, event_id VARCHAR(64) NOT NULL, event_type VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, http_status INT DEFAULT NULL, response_ms INT DEFAULT NULL, error VARCHAR(1024) DEFAULT NULL, request_bytes INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_webhook_delivery_endpoint ON webhook_deliveries (endpoint_id, created_at)');
        $this->addSql('COMMENT ON COLUMN webhook_deliveries.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN webhook_deliveries.endpoint_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN webhook_deliveries.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE webhook_deliveries ADD CONSTRAINT FK_WEBHOOK_DELIVERY_EP FOREIGN KEY (endpoint_id) REFERENCES webhook_endpoints (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE audit_logs (id UUID NOT NULL, owner_id UUID NOT NULL, category VARCHAR(32) NOT NULL, action VARCHAR(64) NOT NULL, meta JSON NOT NULL, ip VARCHAR(64) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_audit_owner_created ON audit_logs (owner_id, created_at)');
        $this->addSql('CREATE INDEX idx_audit_category ON audit_logs (owner_id, category)');
        $this->addSql('COMMENT ON COLUMN audit_logs.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN audit_logs.owner_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN audit_logs.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE audit_logs ADD CONSTRAINT FK_AUDIT_OWNER FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE usage_daily (id UUID NOT NULL, owner_id UUID NOT NULL, dataset_id UUID DEFAULT NULL, day DATE NOT NULL, dataset_key VARCHAR(40) NOT NULL, counters JSON NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_usage_owner_day_dataset ON usage_daily (owner_id, day, dataset_key)');
        $this->addSql('CREATE INDEX IDX_USAGE_OWNER ON usage_daily (owner_id)');
        $this->addSql('CREATE INDEX IDX_USAGE_DATASET ON usage_daily (dataset_id)');
        $this->addSql('COMMENT ON COLUMN usage_daily.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN usage_daily.owner_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN usage_daily.dataset_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN usage_daily.day IS \'(DC2Type:date_immutable)\'');
        $this->addSql('ALTER TABLE usage_daily ADD CONSTRAINT FK_USAGE_OWNER FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE usage_daily ADD CONSTRAINT FK_USAGE_DATASET FOREIGN KEY (dataset_id) REFERENCES datasets (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE webhook_deliveries DROP CONSTRAINT FK_WEBHOOK_DELIVERY_EP');
        $this->addSql('ALTER TABLE webhook_endpoints DROP CONSTRAINT FK_WEBHOOK_OWNER');
        $this->addSql('ALTER TABLE webhook_endpoints DROP CONSTRAINT FK_WEBHOOK_DATASET');
        $this->addSql('ALTER TABLE audit_logs DROP CONSTRAINT FK_AUDIT_OWNER');
        $this->addSql('ALTER TABLE usage_daily DROP CONSTRAINT FK_USAGE_OWNER');
        $this->addSql('ALTER TABLE usage_daily DROP CONSTRAINT FK_USAGE_DATASET');
        $this->addSql('DROP TABLE webhook_deliveries');
        $this->addSql('DROP TABLE webhook_endpoints');
        $this->addSql('DROP TABLE audit_logs');
        $this->addSql('DROP TABLE usage_daily');
    }
}
