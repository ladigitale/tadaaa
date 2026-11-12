<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fichiers de sortie des runs d’artefacts (io.output.files)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE artifact_run_files (
                id UUID NOT NULL,
                run_id UUID NOT NULL,
                name VARCHAR(64) NOT NULL,
                content_type VARCHAR(64) NOT NULL,
                size_bytes INT NOT NULL,
                sha256 VARCHAR(64) NOT NULL,
                data BYTEA NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_artifact_run_file ON artifact_run_files (run_id, name)');
        $this->addSql('ALTER TABLE artifact_run_files ADD CONSTRAINT FK_ARTIFACT_RUN_FILE_RUN FOREIGN KEY (run_id) REFERENCES artifact_runs (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE artifact_run_files');
    }
}
