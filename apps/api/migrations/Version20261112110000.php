<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Workflows d’artefacts (enchaînement d’artefacts-outils) et leurs runs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE artifact_workflows (
                id UUID NOT NULL,
                dataset_id UUID NOT NULL,
                slug VARCHAR(64) NOT NULL,
                title VARCHAR(200) NOT NULL,
                definition JSON NOT NULL,
                created_by_id UUID DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_artifact_workflow_slug ON artifact_workflows (dataset_id, slug)');
        $this->addSql('ALTER TABLE artifact_workflows ADD CONSTRAINT FK_ARTIFACT_WORKFLOW_DATASET FOREIGN KEY (dataset_id) REFERENCES datasets (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE artifact_workflows ADD CONSTRAINT FK_ARTIFACT_WORKFLOW_USER FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE artifact_workflow_runs (
                id UUID NOT NULL,
                workflow_id UUID NOT NULL,
                created_by_id UUID DEFAULT NULL,
                status VARCHAR(16) NOT NULL,
                input JSON DEFAULT NULL,
                definition JSON NOT NULL,
                steps JSON NOT NULL,
                step_cursor INT NOT NULL,
                output JSON DEFAULT NULL,
                errors JSON NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_artifact_workflow_run_wf ON artifact_workflow_runs (workflow_id, created_at)');
        $this->addSql('ALTER TABLE artifact_workflow_runs ADD CONSTRAINT FK_ARTIFACT_WORKFLOW_RUN_WF FOREIGN KEY (workflow_id) REFERENCES artifact_workflows (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE artifact_workflow_runs ADD CONSTRAINT FK_ARTIFACT_WORKFLOW_RUN_USER FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE artifact_runs ADD workflow_run_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE artifact_runs ADD workflow_step VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_artifact_run_workflow ON artifact_runs (workflow_run_id)');
        $this->addSql('ALTER TABLE artifact_runs ADD CONSTRAINT FK_ARTIFACT_RUN_WORKFLOW_RUN FOREIGN KEY (workflow_run_id) REFERENCES artifact_workflow_runs (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artifact_runs DROP CONSTRAINT FK_ARTIFACT_RUN_WORKFLOW_RUN');
        $this->addSql('DROP INDEX idx_artifact_run_workflow');
        $this->addSql('ALTER TABLE artifact_runs DROP workflow_run_id');
        $this->addSql('ALTER TABLE artifact_runs DROP workflow_step');
        $this->addSql('DROP TABLE artifact_workflow_runs');
        $this->addSql('DROP TABLE artifact_workflows');
    }
}
