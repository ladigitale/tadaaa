<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Runs d’artefacts (contrat io : artefact utilisé comme outil)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE artifact_runs (
                id UUID NOT NULL,
                artifact_id UUID NOT NULL,
                artifact_version INT NOT NULL,
                created_by_id UUID DEFAULT NULL,
                mode VARCHAR(16) NOT NULL,
                status VARCHAR(16) NOT NULL,
                input JSON DEFAULT NULL,
                output JSON DEFAULT NULL,
                errors JSON NOT NULL,
                metrics JSON NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_artifact_run_artifact ON artifact_runs (artifact_id, created_at)');
        $this->addSql('CREATE INDEX idx_artifact_run_user ON artifact_runs (created_by_id)');
        $this->addSql('ALTER TABLE artifact_runs ADD CONSTRAINT FK_ARTIFACT_RUN_ARTIFACT FOREIGN KEY (artifact_id) REFERENCES artifacts (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE artifact_runs ADD CONSTRAINT FK_ARTIFACT_RUN_USER FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE artifact_runs');
    }
}
