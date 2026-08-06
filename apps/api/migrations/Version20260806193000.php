<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260806193000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Dataset app documents (JSON payload per dataset + appId, e.g. belts)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE dataset_app_documents (id UUID NOT NULL, dataset_id UUID NOT NULL, app_id VARCHAR(64) NOT NULL, payload JSON NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_dataset_app ON dataset_app_documents (dataset_id, app_id)');
        $this->addSql('CREATE INDEX IDX_DATASET_APP_DOC_DATASET ON dataset_app_documents (dataset_id)');
        $this->addSql('ALTER TABLE dataset_app_documents ADD CONSTRAINT FK_DATASET_APP_DOC_DATASET FOREIGN KEY (dataset_id) REFERENCES datasets (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('COMMENT ON COLUMN dataset_app_documents.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN dataset_app_documents.dataset_id IS \'(DC2Type:uuid)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dataset_app_documents DROP CONSTRAINT FK_DATASET_APP_DOC_DATASET');
        $this->addSql('DROP TABLE dataset_app_documents');
    }
}
