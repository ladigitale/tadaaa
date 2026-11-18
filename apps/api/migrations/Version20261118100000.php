<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Flux des runs d’artefacts (io.stream) : jetons, état courant et journal des messages récents';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artifact_runs ADD stream_key VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE artifact_runs ADD emit_token_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE artifact_runs ADD watch_token_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE artifact_runs ADD stream_seq INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE artifact_runs ADD stream_document TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE artifact_runs ADD stream_score TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE artifact_runs ADD stream_snapshot TEXT DEFAULT NULL');
        $this->addSql("ALTER TABLE artifact_runs ADD stream_meta JSON DEFAULT '{}' NOT NULL");

        $this->addSql(<<<'SQL'
            CREATE TABLE artifact_run_stream_events (
                run_id UUID NOT NULL,
                seq INT NOT NULL,
                message JSON NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (run_id, seq)
            )
        SQL);
        $this->addSql('ALTER TABLE artifact_run_stream_events ADD CONSTRAINT FK_ARTIFACT_RUN_STREAM_RUN FOREIGN KEY (run_id) REFERENCES artifact_runs (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE artifact_run_stream_events');
        $this->addSql('ALTER TABLE artifact_runs DROP stream_key, DROP emit_token_hash, DROP watch_token_hash, DROP stream_seq, DROP stream_document, DROP stream_score, DROP stream_snapshot, DROP stream_meta');
    }
}
